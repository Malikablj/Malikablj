<?php
declare(strict_types=1);

namespace App\Npr;

use App\Core\AuditLogger;
use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\ConflictException;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\NumberSequence;
use App\Core\User;
use App\Core\ValidationException;
use App\Master\MasterService;
use App\Notification\Notifier;
use App\Project\ProjectService;
use App\Workflow\WorkflowEngine;
use App\Workflow\WorkflowInstantiator;
use App\Project\RevisionHistory;

/**
 * New Project Request digital (PRD §4). Kolom biru (Sales).
 *
 * Status: draft → submitted (Dikirim) → [returned (Dikembalikan) → submitted] → feedback_completed.
 * Kolom biru: draft (pembuat/Sales PIC/Admin), returned (Sales PIC/Admin), feedback_completed (Admin + alasan).
 * Setelah dikirim kolom biru TERKUNCI. Semua pengecekan di server.
 */
final class NprService
{
    public function __construct(private ProjectService $projects = new ProjectService())
    {
    }

    // ------------------------------------------------------------------ baca

    /** @return array<string,mixed> */
    public function find(int $id, bool $forUpdate = false): array
    {
        $row = Db::fetch('SELECT * FROM npr WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
        if (!$row) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $row;
    }

    /** NPR lengkap untuk tampilan: customer, PIC, project, part + feedback, lampiran. @return array<string,mixed> */
    public function load(int $id): array
    {
        $npr = Db::fetch(
            'SELECT n.*, c.name AS customer_name, c.code AS customer_code, s.name AS sales_pic_name, cb.name AS created_by_name,
                    p.id AS project_id, p.code AS project_code, p.status AS project_status
             FROM npr n
             LEFT JOIN customers c ON c.id = n.customer_id
             LEFT JOIN users s ON s.id = n.sales_pic_id
             LEFT JOIN users cb ON cb.id = n.created_by
             LEFT JOIN projects p ON p.npr_id = n.id
             WHERE n.id = ?',
            [$id]
        );
        if (!$npr) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        $npr['parts'] = $this->parts($id);
        $npr['attachments'] = Db::fetchAll(
            'SELECT d.id, d.npr_category, d.title, d.created_at, v.id AS version_id, v.original_name, v.mime_type, v.size_bytes, v.extension, u.name AS uploaded_by_name
             FROM documents d JOIN document_versions v ON v.id = d.current_version_id LEFT JOIN users u ON u.id = v.uploaded_by
             WHERE d.npr_id = ? AND d.is_removed = 0 ORDER BY d.id',
            [$id]
        );
        return $npr;
    }

    /** @return list<array<string,mixed>> part (termasuk dibatalkan) dengan feedback (prefix fb_) */
    public function parts(int $nprId): array
    {
        $rows = Db::fetchAll(
            'SELECT p.*, f.weight_gr, f.mould_method_code, f.mould_method_other, f.cavity, f.mould_price_pik_pct, f.mould_price_cust_pct,
                    f.mould_lead_time_days, f.mould_lead_time_note, f.needs_new_masterbatch, f.feedback_text, f.decision, f.decision_reason,
                    f.published_at, f.published_by, f.id AS feedback_id, pp.id AS project_part_id, pp.status AS project_part_status
             FROM npr_parts p
             LEFT JOIN npr_feedback f ON f.npr_part_id = p.id
             LEFT JOIN project_parts pp ON pp.npr_part_id = p.id
             WHERE p.npr_id = ? ORDER BY p.sort_order, p.id',
            [$nprId]
        );
        return $rows;
    }

    /** Pengguna boleh melihat NPR ini? (draft hanya pembuat, Sales PIC, Admin) */
    public function canView(User $user, array $npr): bool
    {
        if (in_array($npr['status'], ['draft', 'discarded'], true)) {
            return $user->isAdmin() || $user->id === (int) $npr['created_by'] || $user->id === (int) $npr['sales_pic_id'];
        }
        return Gate::can($user, 'project.view');
    }

    public function assertView(User $user, array $npr): void
    {
        if (!$this->canView($user, $npr)) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
    }

    /**
     * Kemampuan pengguna atas NPR (dipakai UI dan service — satu sumber aturan).
     * @return array<string,bool>
     */
    public function abilities(User $user, array $npr): array
    {
        $status = (string) $npr['status'];
        $blueOwners = $status === 'draft' ? [(int) $npr['created_by'], (int) $npr['sales_pic_id']] : [(int) $npr['sales_pic_id']];
        $editBlue = match ($status) {
            'draft', 'returned' => Gate::can($user, 'npr.edit_sales_fields', ['owner_ids' => $blueOwners]),
            'feedback_completed' => $user->isAdmin(),
            default => false,
        };
        $editPink = in_array($status, ['submitted', 'feedback_completed'], true) && Gate::can($user, 'npr.edit_npd_fields');
        $hasPending = false;
        if ($status === 'feedback_completed') {
            $hasPending = (int) Db::value(
                "SELECT COUNT(*) FROM npr_parts p LEFT JOIN npr_feedback f ON f.npr_part_id = p.id
                 WHERE p.npr_id = ? AND p.status = 'active' AND f.published_at IS NULL",
                [(int) $npr['id']]
            ) > 0;
        }
        return [
            'view' => $this->canView($user, $npr),
            'edit_blue' => $editBlue,
            'edit_pink' => $editPink,
            'reason_required' => $status === 'feedback_completed',
            'submit' => in_array($status, ['draft', 'returned'], true) && Gate::can($user, 'npr.submit', ['owner_ids' => $blueOwners]),
            'return' => $status === 'submitted' && Gate::can($user, 'npr.return'),
            'complete' => ($status === 'submitted' || $hasPending) && Gate::can($user, 'npr.complete_feedback'),
            'discard' => $status === 'draft' && ($user->isAdmin() || $user->id === (int) $npr['created_by']),
            'add_part' => $editBlue && $status !== 'feedback_completed'
                || (in_array($status, ['submitted', 'feedback_completed'], true) && Gate::can($user, 'npr.edit_npd_fields')),
            'cancel_part' => in_array($status, ['submitted', 'returned', 'feedback_completed'], true) && Gate::can($user, 'project.cancel'),
            'upload' => $editBlue,
            'pdf_final' => $status === 'feedback_completed' && Gate::can($user, 'export.npr_pdf'),
            'pdf_preview' => $this->canView($user, $npr) && Gate::can($user, 'export.npr_pdf'),
        ];
    }

    /**
     * Daftar NPR berhalaman (PRD §13.3: daftar besar dipaginasi — tidak ada NPR yang tersembunyi oleh batas tetap).
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function list(User $user, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ["n.status <> 'discarded'"];
        $params = [];
        // draft hanya terlihat oleh pembuat, Sales PIC, Admin
        if (!$user->isAdmin()) {
            $where[] = "(n.status <> 'draft' OR n.created_by = ? OR n.sales_pic_id = ?)";
            $params[] = $user->id;
            $params[] = $user->id;
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'submitted', 'returned', 'feedback_completed'], true)) {
            $where[] = 'n.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['customer_id'])) {
            $where[] = 'n.customer_id = ?';
            $params[] = (int) $filters['customer_id'];
        }
        if (!empty($filters['mine'])) {
            $where[] = '(n.created_by = ? OR n.sales_pic_id = ?)';
            $params[] = $user->id;
            $params[] = $user->id;
        }
        if (!empty($filters['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
            $where[] = '(n.product_name LIKE ? OR n.npr_number LIKE ? OR p.code LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) Db::value('SELECT COUNT(*) FROM npr n LEFT JOIN projects p ON p.npr_id = n.id WHERE ' . $sqlWhere, $params);
        $perPage = max(5, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Db::fetchAll(
            'SELECT n.id, n.npr_number, n.status, n.product_name, n.requested_at, n.updated_at, n.created_at,
                    c.name AS customer_name, s.name AS sales_pic_name, p.code AS project_code, p.id AS project_id,
                    (SELECT COUNT(*) FROM npr_parts x WHERE x.npr_id = n.id AND x.status = \'active\') AS part_count
             FROM npr n
             LEFT JOIN customers c ON c.id = n.customer_id
             LEFT JOIN users s ON s.id = n.sales_pic_id
             LEFT JOIN projects p ON p.npr_id = n.id
             WHERE ' . $sqlWhere . '
             ORDER BY FIELD(n.status, \'returned\', \'draft\', \'submitted\', \'feedback_completed\'), n.updated_at DESC, n.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    // ------------------------------------------------------------------ perintah

    /** Buat NPR draft dengan satu part kosong. */
    public function createDraft(User $actor, ?int $salesPicId = null): int
    {
        Gate::authorize($actor, 'npr.create');
        if ($actor->roleCode === 'admin_sales') {
            $salesPicId = $actor->id;
        } else {
            $ok = $salesPicId !== null && (int) Db::value(
                "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1 AND r.code = 'admin_sales'",
                [$salesPicId]
            ) === 1;
            if (!$ok) {
                throw new ValidationException(['sales_pic_id' => I18n::t('npr.v.sales_pic_required')]);
            }
        }
        return Db::transaction(function () use ($actor, $salesPicId): int {
            $id = Db::insert('npr', [
                'status' => 'draft',
                'product_name' => '',
                'created_by' => $actor->id,
                'sales_pic_id' => $salesPicId,
                'created_at' => Clock::nowString(),
            ]);
            Db::insert('npr_parts', ['npr_id' => $id, 'sort_order' => 10, 'part_name' => '']);
            AuditLogger::log('npr.create', 'npr', $id, null, ['sales_pic_id' => $salesPicId], null, null, $actor);
            return $id;
        });
    }

    /**
     * Simpan kolom biru (header + part). $input = ['npr' => [...], 'parts' => [partId => [...]]].
     * $auto = autosave (tanpa entri audit per ketikan; isi tercatat lengkap saat Kirim).
     * @param array<string,mixed> $input
     * @return int lock_version baru
     */
    public function saveSales(User $actor, int $id, array $input, ?int $lockVersion = null, ?string $reason = null, bool $auto = false): int
    {
        return Db::transaction(function () use ($actor, $id, $input, $lockVersion, $reason, $auto): int {
            $npr = $this->find($id, true);
            $this->assertLock($npr, $lockVersion);
            $can = $this->abilities($actor, $npr);
            if (!$can['edit_blue']) {
                Gate::authorize(null, 'npr.edit_sales_fields'); // lempar 403 seragam
            }
            if ($can['reason_required'] && trim((string) $reason) === '') {
                throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
            }
            $partsBefore = $this->activeParts($id);
            $before = NprFields::snapshot($npr, $partsBefore);

            [$header, $errors] = is_array($input['npr'] ?? null) ? NprFields::cleanHeader($input['npr']) : [[], []];
            if (array_key_exists('customer_id', $header) && $header['customer_id'] !== null && (int) $header['customer_id'] !== (int) $npr['customer_id']) {
                if (!Db::value('SELECT id FROM customers WHERE id = ? AND is_active = 1', [$header['customer_id']])) {
                    $errors['npr.customer_id'] = I18n::t('validation.invalid');
                }
            }
            $partUpdates = [];
            $partsInput = is_array($input['parts'] ?? null) ? $input['parts'] : [];
            $partIds = array_map(static fn ($p) => (int) $p['id'], $partsBefore);
            foreach ($partsInput as $pid => $pin) {
                $pid = (int) $pid;
                if (!in_array($pid, $partIds, true) || !is_array($pin)) {
                    throw new ValidationException(['parts' => I18n::t('npr.v.unknown_part')]);
                }
                [$pdata, $perr] = NprFields::cleanPart($pin, 'parts.' . $pid . '.');
                $errors += $perr;
                $partUpdates[$pid] = $pdata;
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            if ($header) {
                Db::update('npr', $header, ['id' => $id]);
            }
            foreach ($partUpdates as $pid => $pdata) {
                if ($pdata === []) {
                    continue;
                }
                $current = $this->partRow($pid);
                $merged = array_merge($current, $pdata);
                if (($merged['part_name_code'] ?? '') !== NprFields::OTHER_PART) {
                    $pdata['part_name_custom'] = null;
                    $merged['part_name_custom'] = null;
                }
                $pdata['part_name'] = mb_substr(NprFields::partDisplayName($merged), 0, 120);
                Db::update('npr_parts', $pdata, ['id' => $pid]);
            }
            $newVersion = $this->bumpLock($id);

            $after = NprFields::snapshot($this->find($id), $this->activeParts($id));
            $diff = NprFields::diff($before, $after);
            if ($npr['status'] === 'feedback_completed') {
                $this->recordChange($npr, $diff, $actor, $reason);
                $project = Db::fetch('SELECT id FROM projects WHERE npr_id = ?', [$id]);
                if ($project) {
                    $this->projects->syncFromNpr((int) $project['id'], $id);
                }
            } elseif (!$auto && ($diff['header'] || $diff['parts'])) {
                AuditLogger::log('npr.save_draft', 'npr', $id, null, ['changed' => $this->changedKeys($diff)], null, $this->projectIdOf($id), $actor);
            }
            return $newVersion;
        });
    }

    /**
     * Tambah part. Sales: saat draft/dikembalikan. Admin/NPD: kapan pun (PRD §4.3), dengan alasan
     * setelah dikirim; data biru part baru diisi pada saat penambahan.
     * @param array<string,mixed> $partInput
     */
    public function addPart(User $actor, int $nprId, array $partInput = [], ?string $reason = null): int
    {
        return Db::transaction(function () use ($actor, $nprId, $partInput, $reason): int {
            $npr = $this->find($nprId, true);
            $can = $this->abilities($actor, $npr);
            if (!$can['add_part']) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            $afterSubmit = in_array($npr['status'], ['submitted', 'feedback_completed'], true);
            if ($afterSubmit && trim((string) $reason) === '') {
                throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
            }
            [$pdata, $errors] = NprFields::cleanPart($partInput, 'new_part.');
            if ($afterSubmit) {
                $errors += NprFields::missingForPart($pdata, 'new_part.');
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            $sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM npr_parts WHERE npr_id = ?', [$nprId]);
            $row = array_merge(['npr_id' => $nprId, 'sort_order' => $sort], $pdata);
            if (($row['part_name_code'] ?? '') !== NprFields::OTHER_PART) {
                $row['part_name_custom'] = null;
            }
            $row['part_name'] = mb_substr(NprFields::partDisplayName($row), 0, 120);
            $partId = Db::insert('npr_parts', $row);
            $this->bumpLock($nprId);
            $project = Db::fetch('SELECT id FROM projects WHERE npr_id = ?', [$nprId]);
            if ($project && $afterSubmit) {
                $this->projects->syncFromNpr((int) $project['id'], $nprId);
            }
            if ($afterSubmit) {
                RevisionHistory::record('npr_change', I18n::t('npr.rev.part_added', ['part' => $row['part_name']], 'id'), ['part_id' => $partId, 'reason' => $reason], $nprId, $project ? (int) $project['id'] : null, null, null, $actor->id);
            }
            AuditLogger::log('npr.part_add', 'npr', $nprId, null, ['part_id' => $partId, 'name' => $row['part_name']], $reason, $project ? (int) $project['id'] : null, $actor);
            return $partId;
        });
    }

    /**
     * Hapus part. Tidak diizinkan bila part sudah punya feedback atau proses — harus Cancel (PRD §4.3).
     */
    public function removePart(User $actor, int $nprId, int $partId, ?string $reason = null): void
    {
        Db::transaction(function () use ($actor, $nprId, $partId, $reason): void {
            $npr = $this->find($nprId, true);
            $can = $this->abilities($actor, $npr);
            $part = $this->partRow($partId);
            if ((int) $part['npr_id'] !== $nprId) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            if (!$can['add_part']) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            $hasFeedback = (int) Db::value("SELECT COUNT(*) FROM npr_feedback WHERE npr_part_id = ? AND (published_at IS NOT NULL OR decision IS NOT NULL OR feedback_text IS NOT NULL OR weight_gr IS NOT NULL)", [$partId]) > 0;
            $hasProcess = (int) Db::value('SELECT COUNT(*) FROM processes pr JOIN project_parts pp ON pp.id = pr.part_id WHERE pp.npr_part_id = ?', [$partId]) > 0;
            if ($hasFeedback || $hasProcess) {
                throw new BusinessRuleException(I18n::t('npr.v.part_has_feedback'));
            }
            $active = (int) Db::value("SELECT COUNT(*) FROM npr_parts WHERE npr_id = ? AND status = 'active'", [$nprId]);
            if ($active <= 1) {
                throw new BusinessRuleException(I18n::t('npr.v.min_one_part'));
            }
            if (in_array($npr['status'], ['submitted', 'feedback_completed'], true) && trim((string) $reason) === '') {
                throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
            }
            $this->projects->deletePartForNprPart($partId);
            Db::execute('DELETE FROM npr_feedback WHERE npr_part_id = ?', [$partId]);
            Db::execute('DELETE FROM npr_parts WHERE id = ?', [$partId]);
            $this->bumpLock($nprId);
            $projectId = $this->projectIdOf($nprId);
            if ($npr['status'] !== 'draft') {
                RevisionHistory::record('npr_change', I18n::t('npr.rev.part_removed', ['part' => $part['part_name']], 'id'), ['reason' => $reason], $nprId, $projectId, null, null, $actor->id);
            }
            AuditLogger::log('npr.part_remove', 'npr', $nprId, ['part_id' => $partId, 'name' => $part['part_name']], null, $reason, $projectId, $actor);
        });
    }

    /** Batalkan part (alasan wajib). Project batal bila semua part batal (FR-NPR-07, FR-HLD-05). */
    public function cancelPart(User $actor, int $nprId, int $partId, string $reason): void
    {
        Db::transaction(function () use ($actor, $nprId, $partId, $reason): void {
            $npr = $this->find($nprId, true);
            if (!$this->abilities($actor, $npr)['cancel_part']) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            if (trim($reason) === '') {
                throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
            }
            $part = $this->partRow($partId);
            if ((int) $part['npr_id'] !== $nprId || $part['status'] !== 'active') {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $this->markPartCancelled($partId, $reason, $actor);
            $this->bumpLock($nprId);
            AuditLogger::log('npr.part_cancel', 'npr', $nprId, ['part_id' => $partId, 'status' => 'active'], ['status' => 'cancelled'], $reason, $this->projectIdOf($nprId), $actor);
        });
    }

    /** @internal dipakai juga oleh feedback "Tidak Feasible" */
    public function markPartCancelled(int $partId, string $reason, User $actor): void
    {
        $part = $this->partRow($partId);
        Db::update('npr_parts', ['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_by' => $actor->id, 'cancelled_at' => Clock::nowString()], ['id' => $partId]);
        $this->projects->cancelPartForNprPart($partId, $reason, $actor->id);
        $pp = Db::fetch('SELECT id FROM project_parts WHERE npr_part_id = ?', [$partId]);
        if ($pp && (int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [(int) $pp['id']]) > 0) {
            (new WorkflowEngine())->onPartCancelled((int) $pp['id'], $actor); // part sudah berjalan: lepas dari gate/PF, jadwal ulang
        }
        RevisionHistory::record('cancel', I18n::t('npr.rev.part_cancelled', ['part' => $part['part_name']], 'id'), ['reason' => $reason], (int) $part['npr_id'], $this->projectIdOf((int) $part['npr_id']), null, null, $actor->id);
    }

    /**
     * Kirim NPR: validasi kelengkapan, buat nomor NPR & kode project (pertama kali), kunci kolom biru,
     * catat Requested by, Revision History (kirim ulang), notifikasi NPD (web + email).
     * @return array{npr_number:string,project_code:string,resubmitted:bool}
     */
    public function submit(User $actor, int $id, ?int $lockVersion = null): array
    {
        return Db::transaction(function () use ($actor, $id, $lockVersion): array {
            $npr = $this->find($id, true);
            $this->assertLock($npr, $lockVersion);
            if (!$this->abilities($actor, $npr)['submit']) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            $parts = $this->activeParts($id);
            $missing = NprFields::missingForSubmit($npr, $parts);
            if ($npr['customer_id'] && !Db::value('SELECT id FROM customers WHERE id = ?', [(int) $npr['customer_id']])) {
                $missing['npr.customer_id'] = I18n::t('validation.invalid');
            }
            if ($missing) {
                throw new ValidationException($missing, I18n::t('npr.v.incomplete'));
            }
            $now = Clock::now();
            $snapshot = NprFields::snapshot($npr, $parts);
            $resubmit = $npr['npr_number'] !== null;
            $data = [
                'status' => 'submitted',
                'requested_by_id' => $actor->id,
                'requested_by_name' => $actor->name,
                'requested_by_title' => $actor->jobTitle,
                'requested_at' => $now->format('Y-m-d H:i:s'),
                'submit_count' => (int) $npr['submit_count'] + 1,
                'return_reason' => null,
            ];
            if (!$resubmit) {
                $num = NumberSequence::nextNprNumber($now);
                $data += ['npr_number' => $num['number'], 'seq_year' => $num['year'], 'seq_no' => $num['seq'], 'first_submitted_at' => $now->format('Y-m-d H:i:s')];
                Db::update('npr', $data, ['id' => $id]);
                $project = $this->projects->createFromNpr($this->find($id), $parts, $now);
                // Proses level project: P1 Request NPR (selesai), P2 Feedback NPR (aktif), G1, PF
                (new WorkflowInstantiator())->createProjectProcesses((int) $project['id'], (string) $npr['created_at'], $actor);
                RevisionHistory::record('npr_submit', I18n::t('npr.rev.submitted', ['number' => $num['number']], 'id'), ['snapshot' => $snapshot], $id, $project['id'], null, null, $actor->id);
                $number = $num['number'];
                $projectCode = $project['code'];
                $projectId = $project['id'];
            } else {
                Db::update('npr', $data, ['id' => $id]);
                $projectId = (int) Db::value('SELECT id FROM projects WHERE npr_id = ?', [$id]);
                $projectCode = (string) Db::value('SELECT code FROM projects WHERE id = ?', [$projectId]);
                $number = (string) $npr['npr_number'];
                $prev = $this->lastSubmittedSnapshot($id);
                $diff = $prev ? NprFields::diff($prev, $snapshot) : ['header' => [], 'parts' => []];
                // Part yang spesifikasinya diubah Sales → "Perlu ditinjau ulang", keputusan direset.
                foreach ($parts as $p) {
                    $pid = (string) $p['id'];
                    $changed = isset($diff['parts'][$pid]) && in_array($diff['parts'][$pid]['status'], ['changed', 'added'], true);
                    $needsRevision = ($p['decision'] ?? null) === 'needs_revision';
                    if ($changed || $needsRevision) {
                        Db::update('npr_parts', ['needs_review' => $changed ? 1 : (int) $p['needs_review']], ['id' => (int) $p['id']]);
                        Db::execute('UPDATE npr_feedback SET decision = NULL, published_at = NULL, published_by = NULL WHERE npr_part_id = ?', [(int) $p['id']]);
                    }
                }
                $this->projects->syncFromNpr($projectId, $id);
                (new WorkflowEngine())->onNprResubmitted($projectId, $actor);
                RevisionHistory::record('npr_resubmit', I18n::t('npr.rev.resubmitted', ['count' => $this->changeCount($diff)], 'id'), ['snapshot' => $snapshot, 'diff' => $diff], $id, $projectId, null, null, $actor->id);
            }
            $this->bumpLock($id);
            Db::update('projects', ['last_activity_at' => $now->format('Y-m-d H:i:s')], ['id' => $projectId]);
            AuditLogger::log($resubmit ? 'npr.resubmit' : 'npr.submit', 'npr', $id, ['status' => $npr['status']], ['status' => 'submitted', 'npr_number' => $number, 'project_code' => $projectCode], null, $projectId, $actor);

            $productName = (string) $npr['product_name'];
            Notifier::send(
                Notifier::usersWithRole('npd_staff'),
                'npr_submitted',
                'notif.npr_submitted.title',
                'notif.npr_submitted.body',
                ['number' => $number, 'product' => $productName, 'project' => $projectCode, 'sender' => $actor->name],
                'npr-edit.php?id=' . $id,
                $projectId,
                null,
                'npr_submitted:' . $id . ':' . ((int) $npr['submit_count'] + 1),
                $actor->id
            );
            return ['npr_number' => $number, 'project_code' => $projectCode, 'resubmitted' => $resubmit];
        });
    }

    /** Batalkan draft yang belum pernah dikirim (tidak dihapus permanen). */
    public function discard(User $actor, int $id): void
    {
        Db::transaction(function () use ($actor, $id): void {
            $npr = $this->find($id, true);
            if (!$this->abilities($actor, $npr)['discard']) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            Db::update('npr', ['status' => 'discarded'], ['id' => $id]);
            AuditLogger::log('npr.discard', 'npr', $id, ['status' => 'draft'], ['status' => 'discarded'], null, null, $actor);
        });
    }

    /** Snapshot saat pengiriman terakhir (untuk Revision History). @return array<string,mixed>|null */
    public function lastSubmittedSnapshot(int $nprId): ?array
    {
        $json = Db::value(
            "SELECT details_json FROM revision_history WHERE npr_id = ? AND revision_type IN ('npr_submit', 'npr_resubmit') ORDER BY id DESC LIMIT 1",
            [$nprId]
        );
        $d = $json ? json_decode((string) $json, true) : null;
        return is_array($d) && isset($d['snapshot']) ? $d['snapshot'] : null;
    }

    // ------------------------------------------------------------------ helper

    /** @return list<array<string,mixed>> */
    public function activeParts(int $nprId): array
    {
        return array_values(array_filter($this->parts($nprId), static fn ($p) => $p['status'] === 'active'));
    }

    /** @return array<string,mixed> */
    private function partRow(int $partId): array
    {
        $row = Db::fetch('SELECT * FROM npr_parts WHERE id = ?', [$partId]);
        if (!$row) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $row;
    }

    public function assertLock(array $npr, ?int $lockVersion): void
    {
        if ($lockVersion !== null && $lockVersion !== (int) $npr['lock_version']) {
            throw new ConflictException(I18n::t('error.conflict'));
        }
    }

    public function bumpLock(int $id): int
    {
        Db::execute('UPDATE npr SET lock_version = lock_version + 1 WHERE id = ?', [$id]);
        return (int) Db::value('SELECT lock_version FROM npr WHERE id = ?', [$id]);
    }

    public function projectIdOf(int $nprId): ?int
    {
        $v = Db::value('SELECT id FROM projects WHERE npr_id = ?', [$nprId]);
        return $v === null ? null : (int) $v;
    }

    /** @param array<string,mixed> $diff */
    private function changeCount(array $diff): int
    {
        $n = count($diff['header']);
        foreach ($diff['parts'] as $p) {
            $n += max(1, count($p['fields']));
        }
        return $n;
    }

    /** @param array<string,mixed> $diff @return list<string> */
    private function changedKeys(array $diff): array
    {
        $keys = array_map(static fn ($c) => $c['field'], $diff['header']);
        foreach ($diff['parts'] as $pid => $p) {
            $keys[] = 'part#' . $pid . ':' . $p['status'];
        }
        return $keys;
    }

    /** Koreksi kolom biru oleh Admin setelah Selesai Feedback (alasan wajib, tercatat). */
    private function recordChange(array $npr, array $diff, User $actor, ?string $reason): void
    {
        if (!$diff['header'] && !$diff['parts']) {
            return;
        }
        $projectId = $this->projectIdOf((int) $npr['id']);
        RevisionHistory::record('npr_change', I18n::t('npr.rev.corrected', ['count' => $this->changeCount($diff)], 'id'), ['diff' => $diff, 'reason' => $reason], (int) $npr['id'], $projectId, null, null, $actor->id);
        AuditLogger::log('npr.correct_sales_fields', 'npr', (int) $npr['id'], null, ['changed' => $this->changedKeys($diff)], $reason, $projectId, $actor);
    }

    /** Daftar Sales PIC aktif (untuk NPD/Admin yang membuat NPR atas nama Sales). @return list<array<string,mixed>> */
    public function salesUsers(): array
    {
        return Db::fetchAll("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code = 'admin_sales' ORDER BY u.name");
    }

    /** Opsi master untuk form. @return array<string,list<array<string,mixed>>> */
    public function formOptions(): array
    {
        $out = [];
        foreach (MasterService::CATEGORIES as $cat) {
            $out[$cat] = MasterService::options($cat);
        }
        $out['customers'] = Db::fetchAll('SELECT id, code, name, invoice_address, shipping_address, phone FROM customers WHERE is_active = 1 ORDER BY name');
        return $out;
    }
}
