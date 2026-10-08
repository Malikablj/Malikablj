<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Db;
use App\Cron\JobRunner;
use App\Cron\JobStatus;
use Tests\Support\DbTestCase;

/** NFR-11: pemantauan dasar tugas terjadwal (gagal, macet, terlalu lama tidak sukses, belum pernah). */
final class JobStatusTest extends DbTestCase
{
    private function states(): array
    {
        return array_column((new JobStatus())->all(), 'state', 'job');
    }

    public function testStatesFollowLastRunAndScheduleGap(): void
    {
        Db::execute('DELETE FROM job_runs');
        Clock::freeze('2026-10-12 08:00:00'); // Senin
        $this->assertSame(['overdue' => 'never', 'notifications' => 'never', 'daily-report' => 'never', 'backup' => 'never'], $this->states());

        // akhir pekan: overdue terakhir sukses Jumat 20:00 → Senin 08:00 (60 jam) masih normal
        Db::insert('job_runs', ['job' => 'overdue', 'started_at' => '2026-10-09 20:00:00', 'finished_at' => '2026-10-09 20:00:05', 'status' => 'success']);
        // backup terakhir sukses 2 hari lalu → perlu dicek
        Db::insert('job_runs', ['job' => 'backup', 'started_at' => '2026-10-10 01:30:00', 'finished_at' => '2026-10-10 01:31:00', 'status' => 'success']);
        // notifikasi: sukses 3 menit lalu
        Db::insert('job_runs', ['job' => 'notifications', 'started_at' => '2026-10-12 07:57:00', 'finished_at' => '2026-10-12 07:57:01', 'status' => 'success']);
        // ringkasan harian: run terakhir gagal walau sukses sebelumnya baru
        Db::insert('job_runs', ['job' => 'daily-report', 'started_at' => '2026-10-09 07:00:00', 'finished_at' => '2026-10-09 07:00:02', 'status' => 'success']);
        Db::insert('job_runs', ['job' => 'daily-report', 'started_at' => '2026-10-12 07:00:00', 'finished_at' => '2026-10-12 07:00:01', 'status' => 'failed', 'message' => 'SMTP down']);
        $this->assertSame(['overdue' => 'ok', 'notifications' => 'ok', 'daily-report' => 'warning', 'backup' => 'warning'], $this->states());

        // macet: run 'running' lebih dari 2 jam
        Db::insert('job_runs', ['job' => 'notifications', 'started_at' => '2026-10-12 05:00:00', 'status' => 'running']);
        $this->assertSame('warning', $this->states()['notifications']);

        // JobRunner mencatat backup sukses → kembali normal
        JobRunner::run('backup', static fn (): string => 'uji', false);
        $all = array_column((new JobStatus())->all(), null, 'job');
        $this->assertSame('ok', $all['backup']['state']);
        $this->assertSame('uji', $all['backup']['last']['message']);
    }
}
