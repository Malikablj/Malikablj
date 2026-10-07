<?php
declare(strict_types=1);

/**
 * Kirim antrean email (PHPMailer SMTP) — disarankan tiap 5 menit: *\/5 * * * *
 * Retry 5m/15m/1j/4j, maks. 5 percobaan; gagal permanen terlihat di Pengaturan › Antrean Email.
 * Pemakaian: php cron/notifications.php [--limit=50]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Cron\JobRunner;
use App\Notification\MailQueue;

$limit = 50;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d{1,3})$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}
exit(JobRunner::run('notifications', static function () use ($limit): string {
    $r = (new MailQueue())->deliverDue($limit);
    return sprintf('terkirim=%d ulang=%d gagal=%d', $r['sent'], $r['retry'], $r['failed']);
}));
