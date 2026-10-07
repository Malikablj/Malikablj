<?php
declare(strict_types=1);

/**
 * Unduh/pratinjau dokumen. File berada di luar webroot; akses hanya lewat sesi + cek hak akses.
 *   download.php?v=<version_id>[&inline=1]
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Document\DocumentService;
use App\Document\DocumentStorage;
use App\Document\UploadValidator;

$user = require_permission('document.view');
$versionId = Request::int('v');
if (!$versionId) {
    Response::error(404, I18n::t('error.not_found'));
}
$version = (new DocumentService())->versionForDownload($user, $versionId);
$path = DocumentStorage::absolute((string) $version['stored_path']);
if (!is_file($path)) {
    Response::error(404, I18n::t('error.not_found'));
}

// Pratinjau inline hanya untuk gambar & PDF (PRD §9.1); lainnya selalu attachment.
$inline = Request::query('inline') === '1' && (in_array($version['extension'], UploadValidator::IMAGE_EXT, true) || $version['extension'] === 'pdf');
AuditLogger::log($inline ? 'document.preview' : 'document.download', 'document_version', $versionId, null, null, null,
    $version['project_id'] !== null ? (int) $version['project_id'] : null, $user);

$filename = (string) $version['original_name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'file';
header_remove('Content-Security-Policy');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Content-Type: ' . $version['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
session_write_close(); // jangan kunci sesi selama streaming file besar
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
