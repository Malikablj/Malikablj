<?php

declare(strict_types=1);

/*
 * Worker untuk test konkurensi nomor PR: setiap nomor dibuat dalam transaksi
 * sendiri memakai koneksi MySQL milik proses ini, lalu dicetak ke stdout.
 *
 *   APP_ENV=testing php tests/scripts/generate_numbers.php <KODE_DEPT> <JUMLAH>
 */

use App\Core\Database;
use App\Services\PrNumberGenerator;

$_ENV['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
require __DIR__ . '/../../bootstrap/app.php';

$department = $argv[1] ?? 'ZZ';
$count = (int) ($argv[2] ?? 10);
$generator = new PrNumberGenerator();

for ($i = 0; $i < $count; $i++) {
    $number = Database::transactionWithRetry(static fn (): string => $generator->next($department, date('Y-m-d')));
    fwrite(STDOUT, $number . "\n");
}
