<?php

declare(strict_types=1);

/*
 * Menyesuaikan struktur database yang sudah berjalan dengan versi aplikasi terbaru
 * (menambah kolom/tabel/index yang belum ada; tidak menghapus data).
 *
 *   php database/migrate.php
 *
 * Tidak wajib: migrasi juga berjalan otomatis saat aplikasi pertama kali dibuka
 * setelah update. Script ini berguna bila ingin menjalankannya secara manual.
 */

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Database;
use App\Helpers\Migrator;

try {
    echo 'Database : ' . Database::fetchValue('SELECT DATABASE()') . "\n";
    $steps = Migrator::run();
    echo 'Versi    : ' . Migrator::VERSION . "\n";
    echo $steps ? "Perubahan:\n  - " . implode("\n  - ", $steps) . "\n" : "Skema sudah terbaru, tidak ada perubahan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
