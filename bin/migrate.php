<?php
declare(strict_types=1);

/**
 * Migrasi skema tanpa kehilangan data (NFR-11) untuk database yang sudah berjalan:
 *   php bin/migrate.php            # jalankan migrasi baru, berurutan sesuai nama file
 *   php bin/migrate.php --status   # daftar migrasi: sudah / belum dijalankan
 *   php bin/migrate.php --dir=PATH # folder migrasi lain (test)
 *
 * Konvensi: file database/migrations/YYYYMMDD_NNN_keterangan.sql, berisi ALTER/CREATE/UPDATE yang aman
 * untuk data yang ada; setiap perubahan juga dicerminkan di database/schema.sql (instalasi baru langsung
 * memakai skema terbaru dan menandai semua migrasi sudah dijalankan — lihat bin/install.php).
 * DDL MySQL tidak transaksional: BACKUP DULU (php bin/backup.php) sebelum migrasi di produksi.
 * Migrasi yang gagal TIDAK dicatat; perbaiki penyebabnya lalu jalankan ulang (tulis migrasi idempoten bila bisa).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\SqlScript;

$opts = getopt('', ['status', 'dir:']);
$dir = rtrim((string) ($opts['dir'] ?? dirname(__DIR__) . '/database/migrations'), '/');
if (!is_dir($dir)) {
    if (isset($opts['dir'])) {
        fwrite(STDERR, "Folder migrasi tidak ada: {$dir}\n");
        exit(1);
    }
    echo "Tidak ada migrasi baru.\n"; // paket rilis tanpa file migrasi
    exit(0);
}
$pdo = Db::pdo();
if (!(int) Db::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'")) {
    fwrite(STDERR, "Tabel schema_migrations belum ada. Jalankan dahulu: php bin/install.php\n");
    exit(1);
}
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);
$applied = array_flip(Db::column('SELECT migration FROM schema_migrations'));

if (isset($opts['status'])) {
    if (!$files) {
        echo "Tidak ada file migrasi.\n";
    }
    foreach ($files as $f) {
        $name = basename($f);
        echo (isset($applied[$name]) ? '[sudah] ' : '[belum] ') . $name . PHP_EOL;
    }
    exit(0);
}

$pending = array_values(array_filter($files, static fn ($f) => !isset($applied[basename($f)])));
if (!$pending) {
    echo "Tidak ada migrasi baru.\n";
    exit(0);
}
echo 'Database ' . Db::value('SELECT DATABASE()') . ': ' . count($pending) . " migrasi baru.\n";
foreach ($pending as $f) {
    $name = basename($f);
    $t = microtime(true);
    try {
        $n = SqlScript::runFile($pdo, $f);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, "GAGAL {$e->getMessage()}\nMigrasi {$name} tidak dicatat; migrasi berikutnya tidak dijalankan.\n");
        exit(1);
    }
    Db::execute('INSERT INTO schema_migrations (migration) VALUES (?)', [$name]);
    printf("OK    %s (%d pernyataan, %.0f ms)\n", $name, $n, (microtime(true) - $t) * 1000);
}
exit(0);
