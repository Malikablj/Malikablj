<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Core\Db;
use App\Scheduling\ScheduleService;

/**
 * Tugas harian: aktifkan proses yang Planned Start-nya tiba (OQ-10) dan hitung ulang forecast seluruh
 * project berjalan (Forecast Finish = max(Planned Finish, hari ini) untuk proses yang terlambat).
 */
final class DailyActivation
{
    public function __construct(
        private WorkflowEngine $engine = new WorkflowEngine(),
        private ScheduleService $schedule = new ScheduleService(),
    ) {
    }

    /** @return array{projects:int,activated:int,failed:list<string>} */
    public function run(): array
    {
        $ids = Db::column("SELECT id FROM projects WHERE is_archived = 0 AND finished_at IS NULL AND cancelled_at IS NULL AND is_on_hold = 0 ORDER BY id");
        $activated = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                Db::transaction(function () use ($id, &$activated): void {
                    $this->schedule->recalculate((int) $id, 'auto_shift');
                    $activated += count($this->engine->activateReady((int) $id));
                });
            } catch (\Throwable $e) {
                $failed[] = '#' . $id . ': ' . $e->getMessage();
                error_log('[daily-activation] project ' . $id . ': ' . $e->getMessage());
            }
        }
        return ['projects' => count($ids), 'activated' => $activated, 'failed' => $failed];
    }
}
