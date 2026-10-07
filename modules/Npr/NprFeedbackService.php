<?php
declare(strict_types=1);

namespace App\Npr;

use App\Core\AuditLogger;
use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\Notifier;
use App\Workflow\WorkflowEngine;
use App\Project\RevisionHistory;

/**
 * Feedback NPD per part (kolom pink, PRD §4.5).
 *  - Isian tersimpan sebagai DRAFT (published_at NULL) — tidak terlihat Sales.
 *  - Selesaikan Feedback mempublikasikan seluruh part; "Tidak Feasible" membatalkan part terkait;
 *    "Perlu Revisi" mengembalikan NPR ke Sales.
 *  - Kembalikan ke Sales: alasan wajib.
 */
final class NprFeedbackService
{
    public function __construct(private NprService $npr = new NprService())
    {
    }

    /**
     * Simpan kolom pink. $input = [partId => [field => value]].
     * @param array<int|string,mixed> $input
     * @return int lock_version baru
     */
    public function save(User $actor, int $nprId, array $input, ?int $lockVersion = null, ?string $reason = null, bool $auto = false): int
    {
        return Db::transaction(function () use ($actor, $nprId, $input, $lockVersion, $reason, $auto): int {
            $npr = $this->npr->find($nprId, true);
            $this->npr->assertLock($npr, $lockVersion);
            $can = $this->npr->abilities($actor, $npr);
            if (!$can['edit_pink']) {
                Gate::authorize(null, 'npr.edit_npd_fields');
            }
            $parts = [];
            foreach ($this->npr->activeParts($nprId) as $p) {
                $parts[(int) $p['id']] = $p;
            }
            $errors = [];
            $updates = [];
            $touchesPublished = false;
            foreach ($input as $pid => $fin) {
                $pid = (int) $pid;
                if (!isset($parts[$pid]) || !is_array($fin)) {
                    throw new ValidationException(['feedback' => I18n::t('npr.v.unknown_part')]);
                }
                [$data, $err] = NprFields::cleanFeedback($fin, 'feedback.' . $pid . '.');
                $errors += $err;
                $part = $parts[$pid];
                if ($part['published_at'] !== null) {
                    $touchesPublished = true;
                    // Setelah dipublikasikan, keputusan tidak diubah lewat form (gunakan Cancel part).
                    if (array_key_exists('decision', $data) && $data['decision'] !== $part['decision']) {
                        $errors['feedback.' . $pid . '.decision'] = I18n::t('npr.v.decision_locked');
                    }
                    unset($data['decision']);
                }
                $pik = array_key_exists('mould_price_pik_pct', $data) ? $data['mould_price_pik_pct'] : $part['mould_price_pik_pct'];
                $cust = array_key_exists('mould_price_cust_pct', $data) ? $data['mould_price_cust_pct'] : $part['mould_price_cust_pct'];
                // FR-NPR-09: % PIK + % Customer = 100 (divalidasi saat simpan bila keduanya diisi)
                if ($pik !== null && $pik !== '' && $cust !== null && $cust !== '' && abs((float) $pik + (float) $cust - 100.0) > 0.001) {
                    $errors['feedback.' . $pid . '.mould_price_cust_pct'] = I18n::t('npr.v.pct_sum');
                }
                $updates[$pid] = $data;
            }
            if ($touchesPublished && trim((string) $reason) === '') {
                $errors['reason'] = I18n::t('npr.v.reason_required');
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            $changes = [];
            foreach ($updates as $pid => $data) {
                $existing = Db::fetch('SELECT * FROM npr_feedback WHERE npr_part_id = ?', [$pid]);
                if ($existing) {
                    [$o, $n] = AuditLogger::diff($existing, $data);
                    if ($n) {
                        Db::update('npr_feedback', $data + ['updated_by' => $actor->id], ['npr_part_id' => $pid]);
                        $changes[$pid] = ['old' => $o, 'new' => $n];
                    }
                } elseif (array_filter($data, static fn ($v) => $v !== null && $v !== '')) {
                    Db::insert('npr_feedback', $data + ['npr_part_id' => $pid, 'updated_by' => $actor->id]);
                    $changes[$pid] = ['old' => null, 'new' => $data];
                }
            }
            $version = $this->npr->bumpLock($nprId);
            $projectId = $this->npr->projectIdOf($nprId);
            if ($changes && $touchesPublished) {
                RevisionHistory::record('feedback_change', I18n::t('npr.rev.feedback_changed', [], 'id'), ['changes' => $changes, 'reason' => $reason], $nprId, $projectId, null, null, $actor->id);
            }
            if ($changes && (!$auto || $touchesPublished)) {
                AuditLogger::log('npr.feedback_save', 'npr', $nprId, null, ['parts' => array_keys($changes)], $reason, $projectId, $actor);
            }
            return $version;
        });
    }

    /** Kembalikan NPR ke Sales (alasan wajib). Kolom biru terbuka kembali untuk Sales PIC. */
    public function returnToSales(User $actor, int $nprId, string $reason, ?int $lockVersion = null): void
    {
        Db::transaction(function () use ($actor, $nprId, $reason, $lockVersion): void {
            $npr = $this->npr->find($nprId, true);
            $this->npr->assertLock($npr, $lockVersion);
            if (!$this->npr->abilities($actor, $npr)['return']) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            if (trim($reason) === '') {
                throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
            }
            $this->doReturn($npr, $reason, $actor);
        });
    }

    /**
     * Selesaikan Feedback (FR-NPR-06/07): validasi per keputusan, publikasikan, batalkan part
     * Tidak Feasible, mulai part yang diterima. Bila ada "Perlu Revisi" → NPR dikembalikan ke Sales.
     * @return array{result:string,accepted:list<int>,cancelled:list<int>,project_cancelled:bool}
     */
    public function complete(User $actor, int $nprId, ?int $lockVersion = null): array
    {
        return Db::transaction(function () use ($actor, $nprId, $lockVersion): array {
            $npr = $this->npr->find($nprId, true);
            $this->npr->assertLock($npr, $lockVersion);
            if (!$this->npr->abilities($actor, $npr)['complete']) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            $pending = array_values(array_filter($this->npr->activeParts($nprId), static fn ($p) => $p['published_at'] === null));
            if ($pending === []) {
                throw new BusinessRuleException(I18n::t('npr.v.nothing_pending'));
            }
            $errors = [];
            foreach ($pending as $p) {
                $errors += NprFields::missingFeedback($p, $p);
            }
            if ($errors) {
                throw new ValidationException($errors, I18n::t('npr.v.feedback_incomplete'));
            }
            $revisionParts = array_values(array_filter($pending, static fn ($p) => $p['decision'] === 'needs_revision'));
            if ($revisionParts) {
                $lines = array_map(static fn ($p) => NprFields::partDisplayName($p) . ': ' . trim((string) $p['feedback_text']), $revisionParts);
                $this->doReturn($npr, I18n::t('npr.needs_revision_reason', [], 'id') . "\n" . implode("\n", $lines), $actor);
                return ['result' => 'returned', 'accepted' => [], 'cancelled' => [], 'project_cancelled' => false];
            }

            $now = Clock::nowString();
            $accepted = [];
            $cancelled = [];
            foreach ($pending as $p) {
                $pid = (int) $p['id'];
                Db::update('npr_feedback', ['published_at' => $now, 'published_by' => $actor->id], ['npr_part_id' => $pid]);
                Db::update('npr_parts', ['needs_review' => 0], ['id' => $pid]);
                if ($p['decision'] === 'not_feasible') {
                    $this->npr->markPartCancelled($pid, (string) $p['decision_reason'], $actor);
                    $cancelled[] = $pid;
                } else {
                    Db::execute(
                        'UPDATE project_parts SET accepted_at = ?, needs_new_masterbatch = ?, mold_supplier = COALESCE(?, mold_supplier) WHERE npr_part_id = ?',
                        [$now, $p['needs_new_masterbatch'], $p['mold_supplier'], $pid]
                    );
                    $accepted[] = $pid;
                }
            }
            Db::update('npr', [
                'status' => 'feedback_completed',
                'received_by_id' => $actor->id,
                'received_by_name' => $actor->name,
                'received_by_title' => $actor->jobTitle,
                'received_at' => $now,
            ], ['id' => $nprId]);
            $this->npr->bumpLock($nprId);
            $projectId = $this->npr->projectIdOf($nprId);
            $projectCancelled = $projectId !== null && Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]) === 'cancelled';

            $summary = [];
            foreach ($pending as $p) {
                $summary[] = ['part' => NprFields::partDisplayName($p), 'decision' => $p['decision']];
            }
            RevisionHistory::record('feedback_completed', I18n::t('npr.rev.feedback_completed', ['count' => count($pending)], 'id'), ['parts' => $summary], $nprId, $projectId, null, null, $actor->id);
            AuditLogger::log('npr.feedback_complete', 'npr', $nprId, ['status' => $npr['status']], ['status' => 'feedback_completed', 'accepted' => $accepted, 'cancelled' => $cancelled], null, $projectId, $actor);

            if ($projectId !== null) {
                // P2 selesai; part yang diterima mulai: proses template, jadwal, Baseline v1 (PRD §5.4 #1)
                $acceptedPartIds = $accepted && !$projectCancelled
                    ? array_map('intval', Db::column('SELECT id FROM project_parts WHERE npr_part_id IN (' . implode(',', array_fill(0, count($accepted), '?')) . ')', $accepted))
                    : [];
                (new WorkflowEngine())->onFeedbackCompleted($projectId, $acceptedPartIds, $actor);
            }
            $projectCode = $projectId ? (string) Db::value('SELECT code FROM projects WHERE id = ?', [$projectId]) : '';
            Notifier::send(
                [(int) $npr['sales_pic_id']],
                'npr_feedback_completed',
                'notif.npr_feedback_completed.title',
                'notif.npr_feedback_completed.body',
                ['number' => (string) $npr['npr_number'], 'product' => (string) $npr['product_name'], 'project' => $projectCode, 'accepted' => count($accepted), 'cancelled' => count($cancelled)],
                'npr-edit.php?id=' . $nprId,
                $projectId,
                null,
                'npr_feedback_completed:' . $nprId . ':' . $now,
                $actor->id
            );
            return ['result' => 'feedback_completed', 'accepted' => $accepted, 'cancelled' => $cancelled, 'project_cancelled' => $projectCancelled];
        });
    }

    /** @param array<string,mixed> $npr */
    private function doReturn(array $npr, string $reason, User $actor): void
    {
        $nprId = (int) $npr['id'];
        $now = Clock::nowString();
        Db::update('npr', ['status' => 'returned', 'return_reason' => $reason, 'returned_at' => $now, 'returned_by' => $actor->id], ['id' => $nprId]);
        $this->npr->bumpLock($nprId);
        $projectId = $this->npr->projectIdOf($nprId);
        RevisionHistory::record('npr_return', I18n::t('npr.rev.returned', [], 'id'), ['reason' => $reason], $nprId, $projectId, null, null, $actor->id);
        AuditLogger::log('npr.return', 'npr', $nprId, ['status' => $npr['status']], ['status' => 'returned'], $reason, $projectId, $actor);
        if ($projectId !== null) {
            (new WorkflowEngine())->onNprReturned($projectId, $actor); // P1 aktif kembali (Revision), P2 menunggu
        }
        Notifier::send(
            [(int) $npr['sales_pic_id']],
            'npr_returned',
            'notif.npr_returned.title',
            'notif.npr_returned.body',
            ['number' => (string) $npr['npr_number'], 'product' => (string) $npr['product_name'], 'reason' => $reason, 'by' => $actor->name],
            'npr-edit.php?id=' . $nprId,
            $projectId,
            null,
            'npr_returned:' . $nprId . ':' . $now,
            $actor->id
        );
    }
}
