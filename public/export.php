<?php
declare(strict_types=1);

/**
 * Export dokumen yang dibuat di server (PDF via mPDF, Excel via PhpSpreadsheet).
 *   export.php?type=npr_pdf&id=<npr_id>
 * Setiap export dicatat di audit log (PRD §4.6, §9.4).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Report\NprPdf;

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
    default:
        Response::error(404, I18n::t('error.not_found'));
}
