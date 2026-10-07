<?php
declare(strict_types=1);

/**
 * Bootstrap test: membuat ulang database test (npd_test) dari database/schema.sql + seed.sql,
 * sehingga setiap run dimulai dari kondisi bersih. Database produksi tidak pernah disentuh:
 * nama database test dipaksa oleh phpunit.xml (DB_NAME=npd_test) dan harus berawalan "npd_test".
 */

putenv('APP_ENV=testing');
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Config;

$dbName = (string) Config::get('db.name');
if (!str_starts_with($dbName, 'npd_test')) {
    fwrite(STDERR, "Menolak menjalankan test pada database '{$dbName}' (harus berawalan npd_test)\n");
    exit(1);
}

$out = [];
$code = 0;
exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/install.php') . ' --database=' . escapeshellarg($dbName) . ' --fresh 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "Gagal menyiapkan database test:\n" . implode("\n", $out) . "\n");
    exit(1);
}

require __DIR__ . '/Support/TestCase.php';
require __DIR__ . '/Support/DbTestCase.php';
require __DIR__ . '/Support/HttpTestCase.php';
