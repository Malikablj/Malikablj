<?php

declare(strict_types=1);

/*
 * Mengisi data demo (user, department, supplier, item, workflow, dan beberapa PR).
 *
 *   php bin/seed.php
 */

use App\Core\Config;
use Database\Seeders\DemoSeeder;

require __DIR__ . '/../bootstrap/app.php';

$force = in_array('--force', array_slice($argv, 1), true);
if (Config::get('app.env') === 'production' && !$force) {
    fwrite(STDERR, "APP_ENV=production: seeding data demo ditolak. Tambahkan --force jika benar-benar yakin.\n");
    exit(1);
}

try {
    (new DemoSeeder(static function (string $line): void {
        fwrite(STDOUT, $line . PHP_EOL);
    }))->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
