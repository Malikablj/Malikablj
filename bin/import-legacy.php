<?php
declare(strict_types=1);

/**
 * Impor data project lama dari Excel lewat Terminal (alternatif halaman Pengaturan › Impor Data Lama, untuk file besar):
 *   php bin/import-legacy.php --template=template.xlsx            # tulis template (dropdown dari database saat ini)
 *   php bin/import-legacy.php --file=data.xlsx                    # PERIKSA saja (tidak menyimpan apa pun)
 *   php bin/import-legacy.php --file=data.xlsx --commit --as=admin@perusahaan.co.id
 * Kode keluar: 0 = lancar, 1 = ada kesalahan / impor gagal (tidak ada data tersimpan), 2 = pemakaian salah.
 * Aturan & format: docs/IMPORT_DATA_LAMA.md.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AppException;
use App\Core\Db;
use App\Core\RequestContext;
use App\Core\User;
use App\Core\ValidationException;
use App\Import\LegacyImportService;
use App\Import\LegacyTemplate;

$opts = getopt('', ['template:', 'file:', 'commit', 'as:']);
if (isset($opts['template'])) {
    $out = (string) $opts['template'];
    if (file_put_contents($out, LegacyTemplate::build()) === false) {
        fwrite(STDERR, "Tidak dapat menulis {$out}\n");
        exit(1);
    }
    echo "Template ditulis: {$out}\n";
    exit(0);
}
if (!isset($opts['file'])) {
    fwrite(STDERR, "Pemakaian: php bin/import-legacy.php --file=data.xlsx [--commit --as=email-admin] | --template=out.xlsx\n");
    exit(2);
}
$path = (string) $opts['file'];
if (!is_file($path)) {
    fwrite(STDERR, "File tidak ditemukan: {$path}\n");
    exit(2);
}
@set_time_limit(0);
$svc = new LegacyImportService();
$a = $svc->analyze($path);
$s = $a['summary'];
printf("%s: %d project (%d berjalan, %d hold, %d selesai, %d batal), %d part, proses %d selesai / %d berjalan / %d dilewati\n",
    basename($path), $s['projects'], $s['status_running'], $s['status_hold'], $s['status_completed'], $s['status_cancelled'], $s['parts'],
    $s['processes_completed'], $s['processes_current'], $s['processes_skipped']);
$line = static fn (array $x): string => sprintf('%s%s%s: %s', $x['sheet'] !== '' ? $x['sheet'] : 'File', $x['row'] > 0 ? ' baris ' . $x['row'] : '',
    $x['column'] !== '' ? ' [' . $x['column'] . ']' : '', $x['message']);
foreach ($a['warnings'] as $w) {
    echo '[PERINGATAN] ' . $line($w) . PHP_EOL;
}
foreach ($a['errors'] as $e) {
    echo '[GAGAL] ' . $line($e) . PHP_EOL;
}
if ($a['errors']) {
    echo count($a['errors']) . " kesalahan — perbaiki file lalu jalankan ulang. Tidak ada data yang disimpan.\n";
    exit(1);
}
if (!isset($opts['commit'])) {
    echo "Tidak ada kesalahan. Jalankan dengan --commit --as=<email Admin> untuk mengimpor.\n";
    exit(0);
}
$email = mb_strtolower(trim((string) ($opts['as'] ?? '')));
$id = $email !== '' ? Db::value('SELECT id FROM users WHERE LOWER(email) = ? AND is_active = 1', [$email]) : null;
$actor = $id ? User::find((int) $id) : null;
if ($actor === null) {
    fwrite(STDERR, "--as harus email user Admin yang aktif (pelaku impor tercatat di audit log).\n");
    exit(2);
}
RequestContext::set($actor, null, 'cli: bin/import-legacy.php');
try {
    $res = $svc->commit($actor, $path, basename($path));
} catch (ValidationException $e) {
    fwrite(STDERR, 'GAGAL: ' . implode(' ', $e->errors()) . "\n");
    exit(1);
} catch (AppException $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . " Tidak ada data yang disimpan.\n");
    exit(1);
}
foreach ($res['projects'] as $p) {
    printf("[OK] %s → %s (NPR %s, %s)\n", $p['ref'], $p['code'], $p['npr_number'], $p['status']);
}
echo count($res['projects']) . " project diimpor.\n";
exit(0);
