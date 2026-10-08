<?php
declare(strict_types=1);

/**
 * Backup harian database + dokumen (NFR-09, docs/BACKUP_AND_RESTORE.md):
 *   php bin/backup.php                    # ke BACKUP_PATH (bawaan storage/backups), retensi BACKUP_RETENTION_DAYS (30)
 *   php bin/backup.php --dest=/mnt/backup --keep-days=45
 * Dicatat di job_runs (terlihat di Pengaturan › Antrean email & tugas terjadwal); kode keluar 0 = sukses.
 * Cron: 30 1 * * *  php /var/www/npd/bin/backup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Config;
use App\Cron\JobRunner;
use App\Ops\BackupService;

$opts = getopt('', ['dest:', 'keep-days:']);
$dest = (string) ($opts['dest'] ?? Config::get('backup.path'));
$keep = max(1, (int) ($opts['keep-days'] ?? Config::get('backup.retention_days', 30)));

exit(JobRunner::run('backup', static function () use ($dest, $keep): string {
    $svc = new BackupService();
    $m = $svc->create($dest);
    $removed = $svc->prune($dest, $keep);
    $mb = static fn (int $b): string => number_format($b / 1048576, 1) . ' MB';
    return sprintf('%s: %d tabel, %d baris, %d dokumen (%s); arsip DB %s%s; retensi %d hari, dihapus: %s',
        basename($m['dir']), count($m['tables']), array_sum($m['tables']), $m['documents']['files'], $mb($m['documents']['bytes']),
        $mb($m['files']['database.sql.gz']['bytes']), isset($m['files']['documents.tar.gz']) ? ', arsip dokumen ' . $mb($m['files']['documents.tar.gz']['bytes']) : '',
        $keep, $removed ? implode(', ', $removed) : '-');
}));
