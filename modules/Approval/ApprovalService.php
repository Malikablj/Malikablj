<?php
declare(strict_types=1);

namespace App\Approval;

use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\NumberSequence;
use App\Core\User;

/**
 * Approval (PRD §9.2, Lampiran B.2): permintaan Pending dibuat saat proses approval aktif (per iterasi),
 * keputusan dicatat saat proses diselesaikan (WorkflowEngine::recordApproval). Halaman Approval
 * menampilkan antrean & riwayat; role tanpa hak keputusan hanya melihat.
 */
final class ApprovalService
{
    public const TYPES = ['npr', 'artwork', 'masterbatch', '3d', 'layout_decoration', 'mold_drawing', 't0', 'trial', 'commissioning', 'validation'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'revision_required'];

    /** Buat permintaan approval Pending untuk iterasi proses yang baru aktif (idempoten). @param array<string,mixed> $p */
    public static function requestFor(array $p, ?User $actor): ?int
    {
        if (empty($p['approval_type']) || $p['approval_type'] === 'npr') {
            return null;
        }
        $existing = Db::value('SELECT id FROM approvals WHERE process_id = ? AND iteration = ?', [(int) $p['id'], (int) $p['iteration']]);
        if ($existing) {
            return (int) $existing;
        }
        $now = Clock::nowString();
        $id = Db::insert('approvals', [
            'code' => NumberSequence::nextApprovalCode(Clock::now()),
            'project_id' => (int) $p['project_id'],
            'part_id' => $p['part_id'] !== null ? (int) $p['part_id'] : null,
            'process_id' => (int) $p['id'],
            'approval_type' => (string) $p['approval_type'],
            'giver' => (string) ($p['approval_giver'] ?? 'internal'),
            'iteration' => (int) $p['iteration'],
            'status' => 'pending',
            'requested_by' => $actor?->id,
            'requested_at' => $now,
        ]);
        Db::insert('approval_history', ['approval_id' => $id, 'action' => 'request', 'status_from' => null, 'status_to' => 'pending', 'user_id' => $actor?->id, 'created_at' => $now]);
        return $id;
    }

    /** Batalkan permintaan Pending saat proses direset/dilewati (tetap tercatat di riwayat). */
    public static function withdrawPending(int $processId, ?User $actor, string $reason = 'reset'): void
    {
        foreach (Db::fetchAll("SELECT id FROM approvals WHERE process_id = ? AND status = 'pending'", [$processId]) as $a) {
            Db::insert('approval_history', ['approval_id' => (int) $a['id'], 'action' => 'withdraw', 'status_from' => 'pending', 'status_to' => 'revision_required',
                'comment' => $reason, 'user_id' => $actor?->id, 'created_at' => Clock::nowString()]);
            Db::update('approvals', ['status' => 'revision_required', 'comment' => $reason], ['id' => (int) $a['id']]);
        }
    }

    /** Pengguna boleh memutuskan approval ini (customer: Sales PIC/NPD/Admin; internal: NPD/Admin). @param array<string,mixed> $a */
    public static function canDecide(User $user, array $a): bool
    {
        return $a['giver'] === 'customer'
            ? Gate::can($user, 'approval.record_customer', ['owner_ids' => [$a['sales_pic_id'] ?? null]])
            : Gate::can($user, 'approval.decide_internal');
    }

    /**
     * Antrean / riwayat approval dengan filter.
     * @param array{status?:?string,type?:?string,giver?:?string,project_id?:?int,from?:?string,to?:?string,q?:?string,mine?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(User $user, array $f, int $page = 1, int $perPage = 30): array
    {
        $w = ['1 = 1'];
        $p = [];
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $w[] = 'a.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) {
            $w[] = 'a.approval_type = ?';
            $p[] = $f['type'];
        }
        if (!empty($f['giver']) && in_array($f['giver'], ['customer', 'internal'], true)) {
            $w[] = 'a.giver = ?';
            $p[] = $f['giver'];
        }
        if (!empty($f['project_id'])) {
            $w[] = 'a.project_id = ?';
            $p[] = (int) $f['project_id'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) {
                $w[] = "DATE(COALESCE(a.decided_at, a.requested_at)) $op ?";
                $p[] = $f[$k];
            }
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(a.code LIKE ? OR pj.code LIKE ? OR pj.name LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if (!empty($f['mine'])) {
            $w[] = '(pj.sales_pic_id = ? OR pj.npd_pic_id = ? OR pr.pic_user_id = ?)';
            array_push($p, $user->id, $user->id, $user->id);
        }
        $where = implode(' AND ', $w);
        $from = 'FROM approvals a JOIN projects pj ON pj.id = a.project_id LEFT JOIN processes pr ON pr.id = a.process_id LEFT JOIN project_parts pp ON pp.id = a.part_id';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $p);
        $perPage = max(5, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Db::fetchAll(
            "SELECT a.*, pj.code AS project_code, pj.name AS project_name, pj.sales_pic_id, pp.name AS part_name, pr.code AS process_code, pr.name AS process_name, pr.name_en AS process_name_en,
                    pr.status AS process_status, ur.name AS requested_by_name, ud.name AS decided_by_name,
                    v.original_name AS doc_name, v.version_no AS doc_version, v.id AS doc_version_id, ev.current_version_id AS evidence_version_id, evv.original_name AS evidence_name
             $from
             LEFT JOIN users ur ON ur.id = a.requested_by LEFT JOIN users ud ON ud.id = a.decided_by
             LEFT JOIN document_versions v ON v.id = a.document_version_id
             LEFT JOIN documents ev ON ev.id = a.evidence_document_id LEFT JOIN document_versions evv ON evv.id = ev.current_version_id
             WHERE $where ORDER BY a.status = 'pending' DESC, COALESCE(a.decided_at, a.requested_at) DESC, a.id DESC LIMIT $perPage OFFSET $offset",
            $p
        );
        foreach ($rows as &$r) {
            $r['can_decide'] = $r['status'] === 'pending' && self::canDecide($user, $r);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> riwayat keputusan approval */
    public function history(int $approvalId): array
    {
        return Db::fetchAll('SELECT h.*, u.name AS user_name FROM approval_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.approval_id = ? ORDER BY h.id', [$approvalId]);
    }

    public function pendingCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM approvals WHERE status = 'pending'");
    }
}
