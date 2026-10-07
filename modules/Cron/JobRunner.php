<?php
declare(strict_types=1);

namespace App\Cron;

use App\Core\Clock;
use App\Core\Db;

/**
 * Menjalankan tugas cron dengan pencatatan job_runs dan kunci MySQL (GET_LOCK) agar tidak berjalan ganda.
 * Kegagalan satu project tidak menghentikan project lain; ringkasan disimpan di job_runs.message.
 */
final class JobRunner
{
    /**
     * @param callable():string $job mengembalikan ringkasan
     * @return int 0 = sukses, 1 = gagal, 2 = sedang berjalan di proses lain
     */
    public static function run(string $name, callable $job, bool $verbose = true): int
    {
        $lock = 'npd_job_' . $name;
        if ((int) Db::value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            $verbose && fwrite(STDERR, "[{$name}] masih berjalan di proses lain — dilewati\n");
            return 2;
        }
        $id = Db::insert('job_runs', ['job' => $name, 'started_at' => Clock::nowString(), 'status' => 'running']);
        try {
            $summary = $job();
            Db::update('job_runs', ['finished_at' => Clock::nowString(), 'status' => 'success', 'message' => mb_substr($summary, 0, 5000)], ['id' => $id]);
            $verbose && fwrite(STDOUT, "[{$name}] {$summary}\n");
            return 0;
        } catch (\Throwable $e) {
            error_log("[cron {$name}] " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            Db::update('job_runs', ['finished_at' => Clock::nowString(), 'status' => 'failed', 'message' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 5000)], ['id' => $id]);
            $verbose && fwrite(STDERR, "[{$name}] GAGAL: {$e->getMessage()}\n");
            return 1;
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
