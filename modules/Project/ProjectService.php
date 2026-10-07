<?php
declare(strict_types=1);

namespace App\Project;

use App\Core\AuditLogger;
use App\Core\Clock;
use App\Core\Db;
use App\Core\NumberSequence;
use App\Npr\NprFields;

/**
 * Project → Part. Fase 2: pembuatan project & part dari NPR saat pertama kali dikirim,
 * sinkronisasi part saat NPR dikirim ulang, pembatalan part.
 * (Instansiasi proses & penjadwalan ditambahkan di fase 3–5.)
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
