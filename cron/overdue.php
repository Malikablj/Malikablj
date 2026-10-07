<?php
declare(strict_types=1);

/**
 * Cron jam kerja (docs/ARCHITECTURE.md §7) — disarankan: 0 6-20 * * 1-5
 *   1. aktivasi proses yang Planned Start-nya tiba + hitung ulang forecast
 *   2. pemindaian: overdue hari pertama (web + email), due soon, dokumen wajib, next action,
 *      tidak ada update, perkiraan melewati target (dedupe; aman dijalankan berulang)
 *   3. pengingat Hold (bila modul Hold tersedia)
 * Pemakaian: php cron/overdue.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Cron\JobRunner;
use App\Notification\OverdueService;
use App\Workflow\DailyActivation;

exit(JobRunner::run('overdue', static function (): string {
    $a = (new DailyActivation())->run();
    $s = (new OverdueService())->scan();
    $hold = class_exists(\App\Project\HoldService::class) ? (new \App\Project\HoldService())->sendReminders() : 0;
    return sprintf(
        'project=%d aktif=%d gagal=%d | overdue_baru=%d due_soon=%d dokumen=%d next_action=%d no_update=%d target=%d hold=%d%s',
        $a['projects'], $a['activated'], count($a['failed']), $s['overdue_first'], $s['due_soon'], $s['missing_document'], $s['next_action'],
        $s['no_update'], $s['target_risk'], $hold, $a['failed'] ? ' — ' . implode('; ', $a['failed']) : ''
    );
}));
