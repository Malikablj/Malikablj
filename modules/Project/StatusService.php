<?php
declare(strict_types=1);

namespace App\Project;

use App\Core\Db;

/**
 * Status turunan part & project (PRD §3.3). Status dasar disimpan agar dapat difilter/diindeks;
 * Overdue/Berisiko TIDAK disimpan — dihitung dari proses (hari kerja) dan Target Finish.
 */
final class StatusService
{
    /** Urutan "paling menunggu" untuk part dengan beberapa proses aktif (paralel). */
    private const PART_RANK = ['waiting_approval' => 3, 'waiting_external' => 2, 'on_progress' => 1];

    /** Hitung ulang & simpan status semua part dan project. @return array{project:string,parts:array<int,string>} */
    public function refresh(int $projectId): array
    {
        $project = Db::fetch('SELECT id, status, is_on_hold, finished_at, cancelled_at FROM projects WHERE id = ?', [$projectId]);
        if (!$project) {
            return ['project' => 'not_started', 'parts' => []];
        }
        $parts = Db::fetchAll('SELECT id, status, start_date, is_on_hold, completed_at, cancelled_at FROM project_parts WHERE project_id = ?', [$projectId]);
        $active = [];
        foreach (Db::fetchAll(
            "SELECT part_id, is_customer_approval, is_external FROM processes
             WHERE project_id = ? AND part_id IS NOT NULL AND status IN ('current', 'revision', 'problem')",
            [$projectId]
        ) as $r) {
            $active[(int) $r['part_id']][] = $r;
        }
        $statuses = [];
        foreach ($parts as $part) {
            $id = (int) $part['id'];
            $new = self::partStatus($part, $active[$id] ?? [], (int) $project['is_on_hold'] === 1);
            $statuses[$id] = $new;
            if ($new !== $part['status']) {
                Db::update('project_parts', ['status' => $new], ['id' => $id]);
            }
        }
        $projectStatus = self::projectStatus($project, $statuses);
        if ($projectStatus !== $project['status']) {
            Db::update('projects', ['status' => $projectStatus], ['id' => $projectId]);
        }
        return ['project' => $projectStatus, 'parts' => $statuses];
    }

    /**
     * @param array<string,mixed> $part
     * @param list<array<string,mixed>> $activeProcesses
     * @param bool $projectOnHold Hold level project berlaku untuk semua part aktif (PRD §8.1)
     */
    public static function partStatus(array $part, array $activeProcesses, bool $projectOnHold = false): string
    {
        if ($part['cancelled_at'] !== null) {
            return 'cancelled';
        }
        if ($part['completed_at'] !== null) {
            return 'completed';
        }
        if ((int) $part['is_on_hold'] === 1 || ($projectOnHold && $part['start_date'] !== null)) {
            return 'hold';
        }
        if ($part['start_date'] === null) {
            return 'not_started';
        }
        $best = 'on_progress';
        foreach ($activeProcesses as $p) {
            $s = (int) $p['is_customer_approval'] === 1 ? 'waiting_approval' : ((int) $p['is_external'] === 1 ? 'waiting_external' : 'on_progress');
            if (self::PART_RANK[$s] > self::PART_RANK[$best]) {
                $best = $s;
            }
        }
        return $best;
    }

    /**
     * @param array<string,mixed> $project
     * @param array<int,string> $partStatuses
     */
    public static function projectStatus(array $project, array $partStatuses): string
    {
        if ($project['cancelled_at'] !== null) {
            return 'cancelled';
        }
        if ($project['finished_at'] !== null) {
            return 'completed';
        }
        if ((int) $project['is_on_hold'] === 1) {
            return 'hold';
        }
        if ($partStatuses === []) {
            // belum ada part (mis. menunggu feedback NPR) — project sudah berjalan sejak NPR dikirim
            return 'on_progress';
        }
        $counts = array_count_values($partStatuses);
        $total = count($partStatuses);
        $cancelled = $counts['cancelled'] ?? 0;
        $completed = $counts['completed'] ?? 0;
        if ($cancelled === $total) {
            return 'cancelled';
        }
        if ($completed > 0 && $completed + $cancelled === $total) {
            return 'ready_to_finish';
        }
        $open = $total - $cancelled - $completed;
        if ($open > 0 && ($counts['hold'] ?? 0) === $open) {
            return 'hold';
        }
        if (($counts['waiting_approval'] ?? 0) + ($counts['waiting_external'] ?? 0) > 0) {
            return 'waiting';
        }
        return 'on_progress';
    }
}
