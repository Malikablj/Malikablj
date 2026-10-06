<?php

declare(strict_types=1);

/*
 * Menjalankan pembaruan struktur database (database/migrations).
 *
 *   php database/migrate.php            # backup tabel terkait, lalu jalankan migrasi yang belum jalan
 *   php database/migrate.php --status   # hanya tampilkan status
 *
 * Migrasi hanya menambah tabel/kolom/index dan aman dijalankan ulang.
 * Tetap buat backup seluruh database (mysqldump / Export phpMyAdmin) sebelum
 * menjalankan di server production.
 */

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Database;
use App\Helpers\DbBackup;
use App\Helpers\Migrator;

try {
    echo 'Database : ' . Database::fetchValue('SELECT DATABASE()') . "\n";
    $pending = Migrator::pending();
    $all = Migrator::all();
    foreach ($all as $name) {
        echo '  ' . (in_array($name, $pending, true) ? '[BELUM] ' : '[SUDAH] ') . $name . "\n";
    }
    if ($pending === []) {
        echo "\nStruktur database sudah versi terbaru.\n";
        exit(0);
    }
    if (in_array('--status', $argv, true)) {
        echo "\n" . count($pending) . " migrasi belum dijalankan. Jalankan: php database/migrate.php\n";
        exit(0);
    }
    $backup = DbBackup::tables(['users', 'purchase_orders', 'po_lines', 'deliveries', 'returns', 'customers', 'migration_issues'], 'before-migrate');
    echo "\nBackup tabel terkait: {$backup}\n";
    Migrator::run(static function (string $name): void {
        echo "  selesai: {$name}\n";
    });
    echo "\nSelesai. Struktur database sudah versi terbaru.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Migrasi aman dijalankan ulang setelah penyebabnya diperbaiki.\n");
    exit(1);
}
