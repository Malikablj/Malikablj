<?php

declare(strict_types=1);

/*
 * Menjalankan migration MySQL.
 *
 *   php bin/migrate.php                 jalankan migration yang belum dijalankan
 *   php bin/migrate.php --create-db     buat database (utf8mb4) bila belum ada, lalu migrate
 *   php bin/migrate.php --fresh         HAPUS semua tabel lalu migrate ulang dari nol
 *   php bin/migrate.php --fresh --seed  sama seperti di atas + isi data demo
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use Database\Seeders\DemoSeeder;

require __DIR__ . '/../bootstrap/app.php';

$options = array_slice($argv, 1);
$fresh = in_array('--fresh', $options, true);
$seed = in_array('--seed', $options, true);
$force = in_array('--force', $options, true);
$createDb = in_array('--create-db', $options, true);

$config = (array) Config::get('database');
$out = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

try {
    if ($createDb) {
        Migrator::createDatabase($config);
        $out("Database `{$config['database']}` siap (utf8mb4).");
    }

    if (($fresh || $seed) && Config::get('app.env') === 'production' && !$force) {
        fwrite(STDERR, "APP_ENV=production: --fresh/--seed ditolak. Tambahkan --force jika benar-benar yakin.\n");
        exit(1);
    }

    $migrator = new Migrator(Database::connection(), $out);
    $out("Database: {$config['database']} @ {$config['host']}:{$config['port']} (MySQL)");

    if ($fresh) {
        $out('Menghapus semua tabel...');
        $migrator->dropAllTables();
    }

    $ran = $migrator->migrate(BASE_PATH . '/database/migrations');
    $out($ran === [] ? 'Tidak ada migration baru.' : count($ran) . ' migration dijalankan.');

    if ($seed) {
        (new DemoSeeder($out))->run();
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
