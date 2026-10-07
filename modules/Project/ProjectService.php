<?php
declare(strict_types=1);

namespace App\Project;

use App\Core\AuditLogger;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\NumberSequence;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\Notifier;
use App\Npr\NprFields;

/**
 * Project → Part: pembuatan dari NPR, sinkronisasi saat NPR dikirim ulang, pembatalan part,
 * penetapan PIC (part per peran, NPD PIC) dan prioritas.
 * Instansiasi proses & penjadwalan: App\Workflow\WorkflowInstantiator / App\Scheduling\ScheduleService.
 */
final class ProjectService
{
    /**
     * Buat project (kode NPD-YYYY-XXX) dan part dari NPR. Dipanggil di dalam transaksi submit.
     * @param array<string,mixed> $npr
     * @param list<array<string,mixed>> $parts part aktif NPR
     */
    public function createFromNpr(array $npr, array $parts, \DateTimeImmutable $now): array
    {
        $code = NumberSequence::nextProjectCode($now);
        $projectId = Db::insert('projects', [
            'code' => $code,
            'npr_id' => (int) $npr['id'],
            'customer_id' => (int) $npr['customer_id'],
            'name' => (string) $npr['product_name'],
            'priority' => 'normal',
            'sales_pic_id' => (int) $npr['sales_pic_id'],
            'start_date' => $now->format('Y-m-d'),
            // OQ-03: Target Finish awal = Launching Target NPR (dapat disetujui ulang NPD/Admin)
            'target_finish' => $npr['launching_target'] ?: null,
            'status' => 'not_started',
            'last_activity_at' => $now->format('Y-m-d H:i:s'),
        ]);
        foreach ($parts as $p) {
            $this->insertPart($projectId, $p);
        }
        // lampiran NPR yang diunggah saat draft ikut menjadi dokumen project
        Db::execute('UPDATE documents SET project_id = ? WHERE npr_id = ? AND project_id IS NULL', [$projectId, (int) $npr['id']]);
        AuditLogger::log('project.create', 'project', $projectId, null, ['code' => $code, 'npr_id' => (int) $npr['id'], 'parts' => count($parts)], null, $projectId);
        return ['id' => $projectId, 'code' => $code];
    }

    /**
     * Samakan data project & part dengan NPR (dipanggil saat kirim ulang / koreksi Admin).
     * Part baru dibuat; nama/jenis part yang belum berjalan diperbarui.
     */
    public function syncFromNpr(int $projectId, int $nprId): void
    {
        $npr = Db::fetch('SELECT * FROM npr WHERE id = ?', [$nprId]);
        Db::update('projects', [
            'name' => (string) $npr['product_name'],
            'customer_id' => (int) $npr['customer_id'],
            'sales_pic_id' => (int) $npr['sales_pic_id'],
        ], ['id' => $projectId]);
        $parts = Db::fetchAll("SELECT * FROM npr_parts WHERE npr_id = ? AND status = 'active' ORDER BY sort_order, id", [$nprId]);
        foreach ($parts as $p) {
            $pp = Db::fetch('SELECT id, accepted_at FROM project_parts WHERE npr_part_id = ?', [(int) $p['id']]);
            if (!$pp) {
                $this->insertPart($projectId, $p);
            } elseif ($pp['accepted_at'] === null) {
                Db::update('project_parts', [
                    'name' => NprFields::partDisplayName($p),
                    'part_type' => $p['part_type'],
                    'sort_order' => (int) $p['sort_order'],
                    'mold_supplier' => $p['mold_supplier'],
                ], ['id' => (int) $pp['id']]);
            } else {
                Db::update('project_parts', ['name' => NprFields::partDisplayName($p), 'sort_order' => (int) $p['sort_order']], ['id' => (int) $pp['id']]);
            }
        }
    }

    /** Hapus part project yang belum berjalan (part NPR dihapus sebelum ada feedback/proses). */
    public function deletePartForNprPart(int $nprPartId): void
    {
        $pp = Db::fetch('SELECT id FROM project_parts WHERE npr_part_id = ?', [$nprPartId]);
        if ($pp) {
            $hasProcess = (int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [(int) $pp['id']]);
            if ($hasProcess > 0) {
                throw new \LogicException('Part yang sudah memiliki proses tidak boleh dihapus');
            }
            Db::execute('DELETE FROM project_parts WHERE id = ?', [(int) $pp['id']]);
        }
    }

    /** Batalkan part project (Tidak Feasible / cancel); project batal bila semua part batal. */
    public function cancelPartForNprPart(int $nprPartId, string $reason, ?int $userId): void
    {
        $pp = Db::fetch('SELECT * FROM project_parts WHERE npr_part_id = ?', [$nprPartId]);
        if (!$pp || $pp['status'] === 'cancelled') {
            return;
        }
        $now = Clock::nowString();
        Db::update('project_parts', ['status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => $userId, 'cancel_reason' => $reason], ['id' => (int) $pp['id']]);
        $this->cancelProjectIfAllPartsCancelled((int) $pp['project_id'], $userId);
    }

    public function cancelProjectIfAllPartsCancelled(int $projectId, ?int $userId): bool
    {
        $active = (int) Db::value("SELECT COUNT(*) FROM project_parts WHERE project_id = ? AND status <> 'cancelled'", [$projectId]);
        $total = (int) Db::value('SELECT COUNT(*) FROM project_parts WHERE project_id = ?', [$projectId]);
        if ($total > 0 && $active === 0) {
            $project = Db::fetch('SELECT status FROM projects WHERE id = ?', [$projectId]);
            if ($project && $project['status'] !== 'cancelled') {
                Db::update('projects', [
                    'status' => 'cancelled',
                    'cancelled_at' => Clock::nowString(),
                    'cancelled_by' => $userId,
                    'cancel_reason' => 'Semua part dibatalkan',
                ], ['id' => $projectId]);
                AuditLogger::log('project.cancel_all_parts', 'project', $projectId, ['status' => $project['status']], ['status' => 'cancelled'], 'Semua part dibatalkan', $projectId);
                return true;
            }
        }
        return false;
    }

    public const PART_ROLES = ['drafter', 'purchasing', 'production', 'quality'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * Tetapkan PIC per peran pada part (PRD §6.1). Proses aktif/belum mulai dengan peran tsb yang belum
     * punya PIC atau masih memakai PIC part lama ikut diperbarui; PIC proses aktif diberi notifikasi.
     * @param array<string,mixed> $input role => user_id|''
     */
    public function assignPartPics(User $actor, int $partId, array $input): void
    {
        Gate::authorize($actor, 'schedule.plan');
        Db::transaction(function () use ($actor, $partId, $input): void {
            $part = Db::fetch('SELECT pp.*, pj.code AS project_code FROM project_parts pp JOIN projects pj ON pj.id = pp.project_id WHERE pp.id = ? FOR UPDATE', [$partId]);
            if (!$part) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $errors = [];
            $data = [];
            foreach (self::PART_ROLES as $role) {
                if (!array_key_exists($role, $input)) {
                    continue;
                }
                $v = trim((string) $input[$role]);
                if ($v === '') {
                    $data[$role . '_pic_id'] = null;
                    continue;
                }
                $ok = Db::value('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1 AND r.code = ?', [(int) $v, $role]);
                if (!$ok) {
                    $errors['pic.' . $role] = I18n::t('wf.pic_role_mismatch');
                } else {
                    $data[$role . '_pic_id'] = (int) $v;
                }
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            [$old, $new] = AuditLogger::diff(array_intersect_key($part, $data), $data);
            if (!$new) {
                return;
            }
            Db::update('project_parts', $data, ['id' => $partId]);
            foreach (self::PART_ROLES as $role) {
                $k = $role . '_pic_id';
                if (!array_key_exists($k, $new)) {
                    continue;
                }
                $oldPic = $part[$k] !== null ? (int) $part[$k] : null;
                $newPic = $new[$k];
                $affected = Db::fetchAll(
                    "SELECT pr.id, pr.name, pr.status, pr.iteration FROM processes pr JOIN roles r ON r.id = pr.pic_role_id
                     WHERE pr.part_id = ? AND r.code = ? AND pr.status NOT IN ('completed', 'skipped') AND (pr.pic_user_id IS NULL OR pr.pic_user_id <=> ?)",
                    [$partId, $role, $oldPic]
                );
                foreach ($affected as $a) {
                    Db::update('processes', ['pic_user_id' => $newPic], ['id' => (int) $a['id']]);
                    Db::execute('UPDATE process_runs SET pic_user_id = ? WHERE process_id = ? AND status = \'open\'', [$newPic, (int) $a['id']]);
                    if ($newPic !== null && in_array($a['status'], ['current', 'revision', 'problem'], true)) {
                        Notifier::send([$newPic], 'project_assigned', 'notif.process_active.title', 'notif.process_active.body',
                            ['project' => (string) $part['project_code'], 'process' => $part['name'] . ' › ' . $a['name'], 'finish' => (string) Db::value('SELECT planned_finish FROM processes WHERE id = ?', [(int) $a['id']])],
                            'process.php?id=' . $a['id'], (int) $part['project_id'], (int) $a['id'], 'assigned:' . $a['id'] . ':' . $a['iteration'] . ':' . $newPic, $actor->id);
                    }
                }
            }
            AuditLogger::log('part.assign_pic', 'project_part', $partId, $old, $new, null, (int) $part['project_id'], $actor);
        });
    }

    /**
     * Ubah NPD PIC (Admin/NPD) dan/atau prioritas (project.edit; Sales hanya project miliknya).
     * @param array<string,mixed> $input npd_pic_id, priority
     */
    public function updateProject(User $actor, int $projectId, array $input): void
    {
        Db::transaction(function () use ($actor, $projectId, $input): void {
            $p = Db::fetch('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
            if (!$p) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $data = [];
            $errors = [];
            if (array_key_exists('priority', $input)) {
                Gate::authorize($actor, 'project.edit', ['owner_ids' => [$p['sales_pic_id'], $p['npd_pic_id']]]);
                $v = (string) $input['priority'];
                if (!in_array($v, self::PRIORITIES, true)) {
                    $errors['priority'] = I18n::t('validation.invalid');
                } else {
                    $data['priority'] = $v;
                }
            }
            if (array_key_exists('npd_pic_id', $input)) {
                Gate::authorize($actor, 'schedule.plan');
                $v = (int) $input['npd_pic_id'];
                $ok = Db::value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1 AND r.code IN ('npd_staff', 'admin')", [$v]);
                if (!$ok) {
                    $errors['npd_pic_id'] = I18n::t('wf.pic_role_mismatch');
                } else {
                    $data['npd_pic_id'] = $v;
                }
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            [$old, $new] = AuditLogger::diff(array_intersect_key($p, $data), $data);
            if (!$new) {
                return;
            }
            Db::update('projects', $new, ['id' => $projectId]);
            if (isset($new['npd_pic_id'])) {
                // proses NPD yang masih memakai NPD PIC lama (atau belum ber-PIC) mengikuti
                Db::execute(
                    "UPDATE processes pr JOIN roles r ON r.id = pr.pic_role_id SET pr.pic_user_id = ?
                     WHERE pr.project_id = ? AND r.code = 'npd_staff' AND pr.status NOT IN ('completed', 'skipped') AND (pr.pic_user_id IS NULL OR pr.pic_user_id <=> ?)",
                    [$new['npd_pic_id'], $projectId, $p['npd_pic_id']]
                );
                Db::execute(
                    "UPDATE process_runs pr JOIN processes x ON x.id = pr.process_id SET pr.pic_user_id = x.pic_user_id WHERE x.project_id = ? AND pr.status = 'open'",
                    [$projectId]
                );
            }
            AuditLogger::log('project.update', 'project', $projectId, $old, $new, null, $projectId, $actor);
        });
    }

    /** @param array<string,mixed> $p */
    private function insertPart(int $projectId, array $p): int
    {
        return Db::insert('project_parts', [
            'project_id' => $projectId,
            'npr_part_id' => (int) $p['id'],
            'name' => NprFields::partDisplayName($p),
            'sort_order' => (int) $p['sort_order'],
            'part_type' => (string) $p['part_type'],
            'status' => 'not_started',
            'mold_supplier' => $p['mold_supplier'] ?: null,
        ]);
    }
}
