<?php

declare(strict_types=1);

/*
 * Membuat semua tabel (schema.sql) dan pengaturan awal (seed.sql).
 *
 *   php database/install.php
 *
 * Aman dijalankan berulang (tidak menghapus data yang sudah ada).
 * Database & kredensial dibaca dari file .env.
 */

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line.\n");
}

use App\Helpers\Database;
use App\Helpers\Migrator;
use App\Helpers\Requirements;
use App\Helpers\SqlFile;

// Cek server dulu (sebelum bootstrap) agar ekstensi yang belum aktif terlihat jelas.
require dirname(__DIR__) . '/app/helpers/Requirements.php';
echo "Cek server\n";
printf("  %-12s %s\n", 'PHP', PHP_VERSION . (PHP_VERSION_ID >= 80100 ? '  OK' : '  TERLALU LAMA — butuh PHP 8.1 atau lebih baru'));
$missing = Requirements::missing();
foreach (Requirements::EXTENSIONS as $ext => [$required, $usage]) {
    printf("  %-12s %s\n", $ext, in_array($ext, $missing, true) ? "BELUM AKTIF — dibutuhkan untuk {$usage}" : 'OK');
}
echo '  php.ini      ' . (php_ini_loaded_file() ?: '(tidak ada)') . "\n\n";
if ($missing !== []) {
    echo Requirements::message($missing, 'Aplikasi') . "\n\n";
}
if (PHP_VERSION_ID < 80100 || array_intersect($missing, Requirements::required()) !== []) {
    fwrite(STDERR, "Instalasi dihentikan: perbaiki dulu yang BELUM AKTIF / TERLALU LAMA di atas.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $db = (string) Database::fetchValue('SELECT DATABASE()');
    echo "Database  : {$db}\n";
    $n = SqlFile::run(__DIR__ . '/schema.sql');
    echo "schema.sql: {$n} statement dijalankan\n";
    $n = SqlFile::run(__DIR__ . '/seed.sql');
    echo "seed.sql  : {$n} statement dijalankan\n";
    $ran = Migrator::run();
    echo 'migrasi   : ' . ($ran === [] ? 'sudah versi terbaru' : count($ran) . ' dijalankan (' . implode(', ', $ran) . ')') . "\n";
    $users = (int) Database::fetchValue('SELECT COUNT(*) FROM users');
    echo "\nSelesai. Jumlah user saat ini: {$users}\n";
    if ($users === 0) {
        echo "Langkah berikutnya: buka aplikasi di browser (halaman /setup) atau jalankan\n";
        echo "  php database/create_admin.php\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Periksa konfigurasi DB_* di file .env dan pastikan database sudah dibuat.\n");
    exit(1);
}
