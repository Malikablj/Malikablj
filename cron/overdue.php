<?php
declare(strict_types=1);

/**
 * Cron harian/jam kerja (lihat docs/ARCHITECTURE.md §7):
 *   - aktivasi proses yang Planned Start-nya tiba + hitung ulang forecast (fase 5)
 *   - deteksi overdue / due soon / target berisiko / pengingat Hold (fase 8)
 * Pemakaian: php cron/overdue.php   (crontab: 0 6-20 * * 1-5)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Cron\JobRunner;
use App\Workflow\DailyActivation;

exit(JobRunner::run('overdue', static function (): string {
    $r = (new DailyActivation())->run();
    return sprintf('project=%d aktif=%d gagal=%d%s', $r['projects'], $r['activated'], count($r['failed']), $r['failed'] ? ' — ' . implode('; ', $r['failed']) : '');
}));
