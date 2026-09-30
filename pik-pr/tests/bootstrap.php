<?php

declare(strict_types=1);

/*
 * Bootstrap test: membuat ulang database test MySQL dari nol (migration + seed)
 * sebelum test dijalankan. Setiap test berjalan di dalam transaksi yang di-rollback.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use Database\Seeders\DemoSeeder;

$_ENV['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

require __DIR__ . '/../bootstrap/app.php';

$config = (array) Config::get('database');
if (!str_ends_with((string) $config['database'], '_test')) {
    fwrite(STDERR, "Database test harus berakhiran _test (sekarang: {$config['database']}). Periksa DB_TEST_DATABASE.\n");
    exit(1);
}

Migrator::createDatabase($config);
$migrator = new Migrator(Database::connection());
$migrator->dropAllTables();
$migrator->migrate(BASE_PATH . '/database/migrations');
(new DemoSeeder())->run();

// Bersihkan file upload dari run sebelumnya.
$uploads = BASE_PATH . '/storage/testing';
if (is_dir($uploads)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
}
