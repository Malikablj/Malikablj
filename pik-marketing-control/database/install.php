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

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Database;
use App\Helpers\SqlFile;

try {
    $db = (string) Database::fetchValue('SELECT DATABASE()');
    echo "Database  : {$db}\n";
    $n = SqlFile::run(__DIR__ . '/schema.sql');
    echo "schema.sql: {$n} statement dijalankan\n";
    $n = SqlFile::run(__DIR__ . '/seed.sql');
    echo "seed.sql  : {$n} statement dijalankan\n";
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
