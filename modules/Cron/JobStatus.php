<?php
declare(strict_types=1);

namespace App\Cron;

use App\Core\Clock;
use App\Core\Db;

/**
 * Pemantauan dasar tugas terjadwal (NFR-11): run terakhir & sukses terakhir tiap job dari job_runs.
 * Job ditandai "perlu dicek" bila run terakhir gagal, macet berjalan > 2 jam, atau sukses terakhir lebih tua
 * dari jeda maksimum wajar sesuai jadwal di docs/DEPLOYMENT.md (akhir pekan sudah diperhitungkan).
 */
final class JobStatus
{
    /** job => jeda maksimum (jam) antara dua run sukses */
    public const JOBS = [
        'overdue' => 64,        // tiap jam 06–20 hari kerja (Jumat 20:00 → Senin 06:00 = 58 jam)
        'notifications' => 1,   // tiap 5 menit
        'daily-report' => 80,   // hari kerja 07:00 (Jumat → Senin = 72 jam)
        'backup' => 26,         // tiap hari 01:30
    ];

    /** @return list<array{job:string,last:?array<string,mixed>,last_success_at:?string,state:string}> state: ok|warning|never */
    public function all(): array
    {
        $now = Clock::now();
        $out = [];
        foreach (self::JOBS as $job => $maxHours) {
            $last = Db::fetch('SELECT id, status, started_at, finished_at, message FROM job_runs WHERE job = ? ORDER BY id DESC LIMIT 1', [$job]);
            $success = Db::value("SELECT MAX(finished_at) FROM job_runs WHERE job = ? AND status = 'success'", [$job]);
            $state = 'ok';
            if ($last === null) {
                $state = 'never';
            } elseif ($last['status'] === 'failed'
                || ($last['status'] === 'running' && strtotime((string) $last['started_at']) < $now->getTimestamp() - 7200)
                || $success === null
                || strtotime((string) $success) < $now->getTimestamp() - $maxHours * 3600) {
                $state = 'warning';
            }
            $out[] = ['job' => $job, 'last' => $last, 'last_success_at' => $success !== null ? (string) $success : null, 'state' => $state];
        }
        return $out;
    }
}
