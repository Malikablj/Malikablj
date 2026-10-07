<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Core\Db;

/**
 * process_runs: satu baris per aktivasi (iterasi) proses — dasar KPI per PIC (PRD §10.3):
 * Planned Finish SAAT AKTIVASI, PIC saat selesai, hari Hold dikecualikan, tanggal mulai overdue.
 */
final class ProcessRuns
{
    /** @param array<string,mixed> $process */
    public static function open(array $process, string $activatedAt): int
    {
        $existing = Db::value('SELECT id FROM process_runs WHERE process_id = ? AND iteration = ?', [(int) $process['id'], (int) $process['iteration']]);
        if ($existing) {
            Db::update('process_runs', [
                'status' => 'open', 'activated_at' => $activatedAt,
                'planned_start_at_activation' => $process['planned_start'], 'planned_finish_at_activation' => $process['planned_finish'],
                'planned_duration_at_activation' => (int) $process['duration'], 'actual_start' => $process['actual_start'],
                'actual_finish' => null, 'outcome' => null, 'overdue_since' => null, 'hold_working_days' => 0,
            ], ['id' => (int) $existing]);
            return (int) $existing;
        }
        return Db::insert('process_runs', [
            'process_id' => (int) $process['id'],
            'iteration' => (int) $process['iteration'],
            'activated_at' => $activatedAt,
            'planned_start_at_activation' => $process['planned_start'],
            'planned_finish_at_activation' => $process['planned_finish'],
            'planned_duration_at_activation' => (int) $process['duration'],
            'actual_start' => $process['actual_start'],
            'pic_user_id' => $process['pic_user_id'],
            'status' => 'open',
        ]);
    }

    /** Tutup run terbuka. $status: completed | reset | skipped */
    public static function close(int $processId, string $status, ?string $actualStart, ?string $actualFinish, ?string $outcome, ?int $picUserId, ?int $completedBy): void
    {
        $run = Db::fetch("SELECT id FROM process_runs WHERE process_id = ? AND status = 'open' ORDER BY iteration DESC LIMIT 1", [$processId]);
        if (!$run) {
            return;
        }
        Db::update('process_runs', [
            'status' => $status,
            'actual_start' => $actualStart,
            'actual_finish' => $actualFinish,
            'outcome' => $outcome,
            'pic_user_id' => $picUserId,
            'completed_by' => $completedBy,
        ], ['id' => (int) $run['id']]);
    }

    /** Perbarui target run terbuka (mis. setelah Resume dengan jadwal baru — masa Hold tidak merugikan PIC). */
    public static function updateOpenPlan(int $processId, ?string $plannedFinish, int $holdDays = 0): void
    {
        Db::execute(
            "UPDATE process_runs SET planned_finish_at_activation = COALESCE(?, planned_finish_at_activation), hold_working_days = hold_working_days + ?
             WHERE process_id = ? AND status = 'open'",
            [$plannedFinish, $holdDays, $processId]
        );
    }
}
