<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Requirements;

group('Server · Cek ekstensi PHP');

test('requirements: ekstensi yang belum aktif terdeteksi beserta cara mengaktifkannya', function () {
    assert_same([], Requirements::missing(), 'server test memiliki semua ekstensi');
    assert_same(['pdo_mysql', 'mbstring'], Requirements::required());
    assert_same(['zip'], Requirements::missing(null, static fn (string $ext): bool => $ext !== 'zip'));
    assert_same(['xmlreader'], Requirements::missing(['zip', 'xmlreader'], static fn (string $ext): bool => $ext === 'zip'));
    $msg = Requirements::message(['zip', 'xmlreader'], 'Import Excel');
    assert_contains('extension=zip, extension=xmlreader', $msg);
    assert_contains('php.ini', $msg);
    assert_contains('restart Apache', $msg);
});

/**
 * Jalankan PHP tanpa php.ini (-n) dengan hanya ekstensi tertentu, untuk meniru server
 * (mis. XAMPP) yang extension=zip-nya masih dikomentari.
 * @return list<string> argumen PHP, atau skip bila lingkungan tidak memungkinkan
 */
function php_without_zip(): array
{
    $dir = (string) ini_get('extension_dir');
    if (!is_file($dir . '/zip.so')) {
        skip('ekstensi zip bukan modul terpisah di server ini, tidak bisa dinonaktifkan');
    }
    $args = [PHP_BINARY, '-n', '-d', 'extension_dir=' . $dir];
    foreach (['mysqlnd', 'pdo', 'pdo_mysql', 'mbstring', 'dom', 'xmlreader'] as $ext) {
        if (is_file($dir . '/' . $ext . '.so')) {
            array_push($args, '-d', 'extension=' . $ext);
        }
    }
    $probe = shell_exec(implode(' ', array_map('escapeshellarg', array_merge($args, ['-r', 'echo json_encode([extension_loaded("zip"), extension_loaded("pdo_mysql"), extension_loaded("mbstring")]);']))) . ' 2>/dev/null');
    if (trim((string) $probe) !== '[false,true,true]') {
        skip('tidak bisa menyiapkan PHP tanpa zip (hasil cek: ' . trim((string) $probe) . ')');
    }
    return $args;
}

/** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
function run_php(array $args, array $env): array
{
    $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

function test_env(): array
{
    return array_merge(getenv(), ['DB_DATABASE' => (string) Database::fetchValue('SELECT DATABASE()'), 'APP_ENV' => 'testing',
        'APP_DEBUG' => 'true', 'APP_BASE_PATH' => '/', 'APP_PRETTY_URLS' => 'true']);
}

test('install.php: menampilkan cek server dan aman dijalankan ulang', function () {
    [$code, $out, $err] = run_php([PHP_BINARY, APP_ROOT . '/database/install.php'], test_env());
    assert_same(0, $code, $err . $out);
    assert_contains('Cek server', $out);
    foreach (['pdo_mysql', 'mbstring', 'zip', 'xmlreader'] as $ext) {
        assert_true((bool) preg_match('/' . $ext . '\s+OK/', $out), $ext . ' OK di output');
    }
    assert_contains('schema.sql', $out);
});

test('server tanpa zip: install.php memberi tahu cara mengaktifkan, instalasi tetap jalan', function () {
    [$code, $out, $err] = run_php(array_merge(php_without_zip(), [APP_ROOT . '/database/install.php']), test_env());
    assert_same(0, $code, $err . $out);
    assert_true((bool) preg_match('/zip\s+BELUM AKTIF/', $out), 'zip ditandai BELUM AKTIF');
    assert_contains('extension=zip', $out);
    assert_contains('schema.sql', $out);
});

test('server tanpa zip: halaman Import & export Excel memberi pesan jelas, bukan error 500', function () {
    $args = php_without_zip();
    client_as('Admin'); // pastikan user QA Admin ada
    $port = random_int(19000, 19999);
    $server = proc_open(array_merge($args, ['-S', '127.0.0.1:' . $port, '-t', APP_ROOT . '/public', APP_ROOT . '/public/index.php']),
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, APP_ROOT, test_env());
    try {
        for ($i = 0; $i < 50 && !($fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)); $i++) {
            usleep(100000);
        }
        assert_true(isset($fp) && $fp !== false, 'server tanpa zip berjalan');
        fclose($fp);
        $c = new HttpClient('http://127.0.0.1:' . $port);
        assert_status(302, $c->login('admin.qa@pik.test', 'Rahasia123'));
        assert_status(200, $c->get('/'), 'aplikasi tetap bisa dipakai tanpa zip');

        $page = $c->get('/import');
        assert_status(200, $page);
        assert_contains('extension=zip', $page->body);
        assert_true(substr_count($page->body, 'name="mode" value="dry" disabled') === 1, 'tombol Cek dulu dinonaktifkan');
        $post = $c->post('/import', ['mode' => 'dry']);
        assert_status(422, $post);
        assert_contains('extension=zip', $post->body);

        $report = $c->get('/reports/customer');
        assert_status(200, $report);
        assert_not_contains('format=xlsx', $report->body, 'tombol Excel disembunyikan');
        assert_contains('format=csv', $report->body, 'CSV tetap tersedia');
        assert_redirect($c->get('/reports/customer/export', ['format' => 'xlsx', 'from' => '2026-01-01']), '/reports/customer?from=2026-01-01');
        assert_contains('Export Excel membutuhkan ekstensi PHP zip', $c->get('/reports/customer')->body);
        assert_status(200, $c->get('/reports/customer/export', ['format' => 'csv']));
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
});
