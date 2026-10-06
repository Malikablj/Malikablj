<?php

declare(strict_types=1);

// Menjalankan otomasi: sinkron status Overdue follow up dan
// mengirim notifikasi pengingat (follow up, delivery, target closing lead).
//
//   php cron/automation.php           # jalan bila sudah lewat interval (Settings)
//   php cron/automation.php --force   # jalan sekarang
//
// Contoh crontab (setiap 15 menit):
//   */15 * * * * php /var/www/pik-marketing-control/cron/automation.php >> /var/www/pik-marketing-control/storage/logs/cron.log 2>&1
//
// Tanpa cron pun otomasi tetap berjalan saat aplikasi dipakai (maks. sekali per interval).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Jalankan dari command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Automation;

$force = in_array('--force', $argv, true);
try {
    $result = $force ? Automation::run() : Automation::runIfDue();
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($result === null) {
    echo date('c') . " Dilewati: belum waktunya atau otomasi lain sedang berjalan.\n";
    exit(0);
}
echo date('c') . ' OK · follow up overdue: ' . $result['followups_overdue'] . ' · notifikasi: ' . json_encode($result['notifications']) . "\n";
