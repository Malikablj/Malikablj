<?php
declare(strict_types=1);

/**
 * Pemulihan backup (docs/BACKUP_AND_RESTORE.md). Tidak pernah menimpa data: target database harus
 * baru/kosong dan folder dokumen harus baru/kosong. Hasil diverifikasi terhadap manifest
 * (jumlah baris tiap tabel, jumlah & ukuran dokumen, SHA-256 arsip).
 *   php bin/restore.php --from=storage/backups/npd-npd_project_control-20261008-013000 \
 *                       --database=npd_restore_20261008 --documents=/srv/npd/storage-restore/documents
 *   php bin/restore.php --from=DIR --verify-only --database=NAMA --documents=DIR   # cek ulang tanpa memulihkan
 *   ... --without-triggers   # user pemulih tanpa hak SUPER (binary log aktif): pulihkan data, lalu admin
 *                            # menjalankan database/hardening.sql untuk memasang trigger append-only audit_logs
 * Jalankan dengan user MySQL admin (hak CREATE/ALTER/INDEX/REFERENCES/TRIGGER), mis. DB_USER=npd_admin DB_PASS=… php bin/restore.php …
 * Kode keluar: 0 = pulih & cocok, 1 = gagal / tidak cocok.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Ops\BackupService;

$opts = getopt('', ['from:', 'database:', 'documents:', 'verify-only', 'without-triggers']);
foreach (['from', 'database', 'documents'] as $req) {
    if (empty($opts[$req])) {
        fwrite(STDERR, "Wajib: --from=FOLDER_BACKUP --database=DATABASE_TUJUAN --documents=FOLDER_DOKUMEN_TUJUAN\n");
        exit(1);
    }
}
$from = rtrim((string) $opts['from'], '/\\');
$svc = new BackupService();
try {
    $manifest = $svc->readManifest($from);
    echo "Backup {$manifest['database']} dibuat {$manifest['created_at']} (aplikasi v{$manifest['app_version']}, MySQL {$manifest['mysql_version']})\n";
    $t = microtime(true);
    $r = isset($opts['verify-only'])
        ? $svc->verify($manifest, (string) $opts['database'], (string) $opts['documents'])
        : $svc->restore($from, (string) $opts['database'], (string) $opts['documents'], !isset($opts['without-triggers']));
} catch (\Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
printf("%s %s: %d tabel, %d baris, %d dokumen di %s (%.1f dtk)\n", isset($opts['verify-only']) ? 'Verifikasi' : 'Dipulihkan ke',
    $r['database'], $r['tables'], $r['rows'], $r['doc_files'], $r['documents'], microtime(true) - $t);
if (!in_array('trg_audit_logs_no_update', $r['triggers'], true)) {
    echo "Catatan: trigger append-only audit_logs tidak ada di hasil pemulihan. Bila produksi memakai hardening,\n"
        . "jalankan: mysql -u <admin> -p {$r['database']} < database/hardening.sql (dan pakai BACKUP_DB_USER ber-hak TRIGGER).\n";
}
if ($r['mismatches']) {
    fwrite(STDERR, "TIDAK COCOK dengan manifest:\n - " . implode("\n - ", $r['mismatches']) . PHP_EOL);
    exit(1);
}
echo "Cocok dengan manifest. Arahkan DB_NAME & STORAGE_PATH di .env ke hasil pemulihan bila akan dipakai.\n";
exit(0);
