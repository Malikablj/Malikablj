<?php
declare(strict_types=1);

/**
 * Export dokumen yang dibuat di server (PDF via mPDF, Excel via PhpSpreadsheet).
 *   export.php?type=npr_pdf&id=<npr_id>
 *   export.php?type=timeline_pdf|timeline_xlsx&project=<id>&scope=project|part|all[&part=<id>]
 *   export.php?type=weekly_xlsx|analytics_xlsx|kpi_xlsx|kpi_pdf&period=week|month|range&... (filter laporan)
 *   export.php?type=projects_xlsx&<filter daftar project>
 * Setiap export dicatat di audit log (PRD §4.6, §9.4).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Clock;
use App\Report\AnalyticsService;
use App\Report\KpiService;
use App\Report\NprPdf;
use App\Report\ReportExports;
use App\Report\ReportPeriod;
use App\Report\WeeklyReport;
use App\Report\TimelineExcel;
use App\Report\TimelinePdf;

$user = require_login();
$type = (string) Request::query('type', '');

/** Kirim file biner ke browser. */
$send = static function (string $content, string $filename, string $mime, bool $inline = false): never {
    session_write_close();
    header_remove('Content-Security-Policy');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($content));
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $content;
    exit;
};

switch ($type) {
    case 'npr_pdf':
        Gate::authorize($user, 'export.npr_pdf');
        $id = (int) Request::int('id', 0);
        $pdf = (new NprPdf())->build($user, $id);
        $projectId = \App\Core\Db::value('SELECT id FROM projects WHERE npr_id = ?', [$id]);
        AuditLogger::log($pdf['final'] ? 'export.npr_pdf' : 'export.npr_pdf_preview', 'npr', $id, null, ['filename' => $pdf['filename']], null, $projectId !== null ? (int) $projectId : null, $user);
        $send($pdf['content'], $pdf['filename'], 'application/pdf', !$pdf['final']);
        // no break
    case 'timeline_pdf':
    case 'timeline_xlsx':
        Gate::authorize($user, 'export.timeline');
        $projectId = (int) Request::int('project', 0);
        $scope = (string) Request::query('scope', 'project');
        $partId = Request::int('part');
        $file = $type === 'timeline_pdf'
            ? (new TimelinePdf())->build($user, $projectId, $scope, $partId)
            : (new TimelineExcel())->build($user, $projectId, $scope, $partId);
        AuditLogger::log('export.' . $type, 'project', $projectId, null, ['filename' => $file['filename'], 'scope' => $scope, 'part_id' => $partId], null, $projectId, $user);
        $send($file['content'], $file['filename'], $type === 'timeline_pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // no break
    case 'weekly_xlsx':
    case 'analytics_xlsx':
    case 'kpi_xlsx':
    case 'kpi_pdf':
        // KPI per PIC hanya Admin & Management (PRD §10.3) — ditolak server walau URL diketik langsung
        Gate::authorize($user, str_starts_with($type, 'kpi_') ? 'kpi.view' : 'export.report');
        $period = ReportPeriod::fromInput($_GET, Clock::todayString());
        $x = new ReportExports();
        $file = match ($type) {
            'weekly_xlsx' => $x->weekly($user, $period, WeeklyReport::cleanFilters($_GET)),
            'analytics_xlsx' => $x->analytics($user, $period, AnalyticsService::cleanFilters($_GET)),
            'kpi_xlsx' => $x->kpiXlsx($user, $period, KpiService::cleanFilters($_GET)),
            default => $x->kpiPdf($user, $period, KpiService::cleanFilters($_GET)),
        };
        AuditLogger::log('export.' . $type, 'report', null, null, ['filename' => $file['filename'], 'from' => $period->from, 'to' => $period->to], null, null, $user);
        $send($file['content'], $file['filename'], $file['mime']);
        // no break
    case 'projects_xlsx':
        Gate::authorize($user, 'export.report');
        $filters = [
            'q' => Request::query('q'), 'status' => Request::query('status'), 'customer_id' => Request::int('customer_id'), 'npd_pic_id' => Request::int('npd_pic_id'),
            'mine' => Request::query('mine') === '1', 'archived' => Request::query('archived') === '1', 'overdue' => Request::query('overdue') === '1',
            'due_soon' => Request::query('due_soon') === '1', 'part_type' => Request::query('part_type'), 'priority' => Request::query('priority'),
        ];
        $file = (new ReportExports())->projects($user, $filters);
        AuditLogger::log('export.projects_xlsx', 'report', null, null, ['filename' => $file['filename'], 'filters' => array_filter($filters)], null, null, $user);
        $send($file['content'], $file['filename'], $file['mime']);
        // no break
    default:
        Response::error(404, I18n::t('error.not_found'));
}
