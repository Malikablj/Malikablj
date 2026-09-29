<?php

declare(strict_types=1);

/*
 * Test runner PIK Marketing Control.
 *
 *   php tests/run.php              # semua test
 *   php tests/run.php customer     # hanya test yang namanya mengandung "customer"
 *
 * Kebutuhan:
 *   - Database test terpisah yang namanya berakhiran _test (default pik_marketing_test),
 *     memakai user/password DB yang sama dengan .env. Override: TEST_DB_DATABASE=...
 *   - Ekstensi curl (untuk HTTP test).
 * Runner menjalankan server bawaan PHP (php -S) di port acak sementara.
 * DATABASE TEST AKAN DIKOSONGKAN setiap kali test dijalankan.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$testDb = getenv('TEST_DB_DATABASE') ?: 'pik_marketing_test';
if (!str_ends_with($testDb, '_test')) {
    fwrite(STDERR, "TEST_DB_DATABASE harus berakhiran _test\n");
    exit(1);
}
putenv('DB_DATABASE=' . $testDb);
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('APP_BASE_PATH=/');
putenv('APP_PRETTY_URLS=true');

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib.php';

TestState::$filter = $argv[1] ?? null;

reset_database();

// Jalankan server PHP bawaan dengan environment database test
$port = random_int(18000, 18999);
$env = array_merge(getenv(), ['DB_DATABASE' => $testDb, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'true', 'APP_BASE_PATH' => '/', 'APP_PRETTY_URLS' => 'true']);
$cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', APP_ROOT . '/public', APP_ROOT . '/public/index.php'];
$logFile = sys_get_temp_dir() . '/pik-test-server-' . $port . '.log';
$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes, APP_ROOT, $env);
if (!is_resource($server)) {
    fwrite(STDERR, "Gagal menjalankan php -S\n");
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) {
        fclose($fp);
        $ready = true;
        break;
    }
    usleep(100000);
}
if (!$ready) {
    fwrite(STDERR, "Server test tidak merespons di port {$port}\n");
    proc_terminate($server);
    exit(1);
}
define('TEST_BASE_URL', $base);

$start = microtime(true);
$files = glob(__DIR__ . '/cases/*.php') ?: [];
sort($files);
try {
    foreach ($files as $file) {
        require $file;
    }
} finally {
    proc_terminate($server);
    proc_close($server);
}

$elapsed = round(microtime(true) - $start, 1);
echo "\n" . str_repeat('─', 60) . "\n";
echo sprintf("\033[1m%d passed\033[0m, %s%d failed\033[0m  (%ss)\n", TestState::$passed, TestState::$failed ? "\033[31m" : '', TestState::$failed, $elapsed);
if (TestState::$failures) {
    echo "\nGagal:\n";
    foreach (TestState::$failures as $f) {
        echo "  - {$f}\n";
    }
    $log = @file_get_contents($logFile);
    if ($log && preg_match_all('/PHP (Fatal|Warning|Parse).*$/m', $log, $m)) {
        echo "\nServer log:\n  " . implode("\n  ", array_slice(array_unique($m[0]), 0, 10)) . "\n";
    }
}
@unlink($logFile);
exit(TestState::$failed > 0 ? 1 : 0);
