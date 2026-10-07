<?php
declare(strict_types=1);

/**
 * Ringkasan overdue harian: satu notifikasi + email per PIC / NPD PIC (hanya hari kerja, pagi).
 * Disarankan: 0 7 * * 1-5   (jalankan setelah cron/overdue.php)
 * Pemakaian: php cron/daily-report.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Cron\JobRunner;
use App\Notification\DailyDigest;

exit(JobRunner::run('daily-report', static function (): string {
    $r = (new DailyDigest())->run();
    return $r['skipped'] ? 'bukan hari kerja — dilewati' : sprintf('penerima=%d proses_overdue=%d', $r['recipients'], $r['items']);
}));
