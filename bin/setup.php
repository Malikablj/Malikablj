<?php
declare(strict_types=1);

/**
 * Tahap instalasi terpandu untuk hosting cPanel — dipanggil oleh setup.sh (yang juga menangani semua pertanyaan),
 * tanpa proc_open/system/posix yang sering dinonaktifkan di hosting bersama.
 *   --phase=prepare    cek PHP & ekstensi, lengkapi .env (database dari NPD_SETUP_DB_*, APP_KEY, sesi, backup),
 *                      siapkan folder storage, uji koneksi & versi server database
 *   --phase=has-admin  kode keluar 0 bila sudah ada Admin aktif, 3 bila belum
 *   --phase=hardening  coba pasang trigger append-only audit_logs (dilewati bila hosting tidak memberi hak)
 *   --phase=finish     tampilkan baris Cron Jobs (--php=lokasi PHP CLI)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$opts = getopt('', ['phase:', 'php:']);
$phase = (string) ($opts['phase'] ?? 'prepare');
$envFile = $root . '/.env';
$ok = static fn (string $m) => print("  [OK] {$m}\n");
$warn = static fn (string $m) => print("  [PERINGATAN] {$m}\n");
$fail = static function (string $m): never {
    fwrite(STDERR, "  [GAGAL] {$m}\n");
    exit(1);
};

/** @return array<string,string> */
$readEnv = static function () use ($envFile): array {
    $out = [];
    foreach (is_file($envFile) ? (file($envFile, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $m)) {
            $v = trim($m[2]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $out[$m[1]] = $v;
        }
    }
    return $out;
};
/** Tulis nilai ke .env (kutip tunggal/ganda sesuai isi; parser .env mengambil isi kutip apa adanya). */
$setEnv = static function (array $values) use ($envFile, $fail): void {
    $lines = is_file($envFile) ? (file($envFile, FILE_IGNORE_NEW_LINES) ?: []) : [];
    foreach ($values as $key => $value) {
        $value = (string) $value;
        if (preg_match('/[\r\n]/', $value) || (str_contains($value, "'") && str_contains($value, '"'))) {
            $fail("nilai {$key} tidak boleh berisi baris baru atau kutip tunggal dan ganda sekaligus");
        }
        $quoted = preg_match('#^[A-Za-z0-9_.:/@+=,-]*$#', $value) ? $value : (str_contains($value, "'") ? '"' . $value . '"' : "'" . $value . "'");
        $done = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
                $lines[$i] = $key . '=' . $quoted;
                $done = true;
            }
        }
        if (!$done) {
            $lines[] = $key . '=' . $quoted;
        }
    }
    file_put_contents($envFile, implode("\n", $lines) . "\n");
    @chmod($envFile, 0600);
};
/** @param array<string,string> $env */
$connect = static function (array $env, bool $withDb) use ($fail): PDO {
    $host = ($env['DB_HOST'] ?? '') ?: 'localhost';
    $port = (int) (($env['DB_PORT'] ?? '') ?: 3306);
    $db = $withDb ? ';dbname=' . $env['DB_NAME'] : '';
    $dsn = !empty($env['DB_SOCKET'] ?? '') ? "mysql:unix_socket={$env['DB_SOCKET']}{$db};charset=utf8mb4" : "mysql:host={$host};port={$port}{$db};charset=utf8mb4";
    try {
        return new PDO($dsn, (string) ($env['DB_USER'] ?? ''), (string) ($env['DB_PASS'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        $hint = str_contains($e->getMessage(), '1045')
            ? ' — user/password salah, atau user belum ditambahkan ke database (cPanel › MySQL Databases › Add User To Database, ALL PRIVILEGES). Untuk mengisi ulang: hapus baris DB_USER & DB_PASS di .env lalu bash setup.sh'
            : '';
        $fail('koneksi database gagal: ' . $e->getMessage() . $hint);
    }
};

if ($phase === 'has-admin') {
    $pdo = $connect($readEnv(), true);
    $n = (int) $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1")->fetchColumn();
    if ($n > 0) {
        $ok("{$n} Admin aktif sudah ada");
        exit(0);
    }
    exit(3);
}

if ($phase === 'hardening') {
    // trigger append-only audit_logs (database/hardening.sql); butuh hak SUPER/log_bin_trust_function_creators bila
    // binary log aktif — di hosting bersama biasanya tidak tersedia, maka cukup dicoba lalu dilewati
    require $root . '/vendor/autoload.php';
    $pdo = $connect($readEnv(), true);
    $have = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_logs'")->fetchColumn();
    if ($have >= 2) {
        $ok('trigger append-only audit_logs sudah terpasang');
        exit(0);
    }
    try {
        \App\Core\SqlScript::runFile($pdo, $root . '/database/hardening.sql');
        $ok('trigger append-only audit_logs dipasang (UPDATE/DELETE audit log ditolak database)');
    } catch (\RuntimeException $e) {
        echo "  [INFO] trigger audit tidak dapat dipasang di hosting ini (" . (str_contains($e->getMessage(), '1419') || str_contains($e->getMessage(), 'SUPER') ? 'butuh hak SUPER' : 'hak tidak cukup') . ") — dilewati; audit log tetap append-only di aplikasi.\n";
    }
    exit(0);
}

if ($phase === 'finish') {
    $php = (string) ($opts['php'] ?? PHP_BINARY);
    $log = $root . '/storage/logs/cron.log';
    echo "\n== 6/6 Cron Jobs & pemeriksaan\n";
    echo "  Tambahkan di cPanel › Cron Jobs (kolom waktu, lalu perintah persis seperti di bawah):\n\n";
    foreach ([
        ['0 6-20 * * 1-5', 'cron/overdue.php', 'aktivasi proses & overdue — tiap jam 06–20, Senin–Jumat'],
        ['*/5 * * * *', 'cron/notifications.php', 'kirim email — tiap 5 menit'],
        ['5 7 * * 1-5', 'cron/daily-report.php', 'ringkasan harian — 07:05 hari kerja'],
        ['30 1 * * *', 'bin/backup.php', 'backup database & dokumen — 01:30 setiap hari'],
    ] as [$when, $script, $what]) {
        echo "    Waktu   : {$when}   ({$what})\n    Perintah: {$php} {$root}/{$script} >> {$log} 2>&1\n\n";
    }
    echo "  Jadwal memakai zona waktu server hosting; bila server tidak memakai WIB, sesuaikan jamnya.\n\n";
    exit(0);
}

// ------------------------------------------------------------------ prepare
echo "\n== 1/6 PHP\n";
$ok('PHP ' . PHP_VERSION);
$missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'json', 'fileinfo', 'sodium', 'gd', 'zip', 'xml', 'dom', 'iconv', 'zlib', 'openssl', 'curl'],
    static fn ($e) => !extension_loaded($e)));
if ($missing) {
    $fail('ekstensi PHP belum aktif: ' . implode(', ', $missing) . ' — aktifkan di cPanel › Select PHP Version (atau minta penyedia hosting), lalu ulangi');
}
$ok('ekstensi PHP lengkap');
if (!is_file($root . '/vendor/autoload.php')) {
    $fail('folder vendor/ tidak ada — ekstrak ulang zip lengkap (atau jalankan: composer install --no-dev)');
}
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
    $ok('proc_open dinonaktifkan hosting — backup otomatis memakai mode PHP (tanpa mysqldump/tar)');
}

echo "\n== 2/6 Konfigurasi .env\n";
if (!is_file($envFile)) {
    // paket rilis membawa .env.production (bukan .env) agar unggah ulang versi baru tidak menimpa .env server
    $template = is_file($root . '/.env.production') ? '.env.production' : '.env.example';
    copy($root . '/' . $template, $envFile) || $fail('tidak dapat membuat .env');
    $ok(".env dibuat dari {$template}");
}
$env = $readEnv();
$changes = [];
foreach (['NPD_SETUP_DB_NAME' => 'DB_NAME', 'NPD_SETUP_DB_USER' => 'DB_USER', 'NPD_SETUP_DB_PASS' => 'DB_PASS'] as $from => $to) {
    $v = getenv($from);
    if ($v !== false && $v !== '') {
        $changes[$to] = $v;
    }
}
$key = $env['APP_KEY'] ?? '';
$raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
if ($raw === false || strlen($raw) !== 32) {
    $changes['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
    $ok('APP_KEY dibuat — simpan salinan isi .env di tempat aman (password manager)');
}
if (($env['SESSION_SAVE_PATH'] ?? '') === '' || !is_dir((string) $env['SESSION_SAVE_PATH'])) {
    $changes['SESSION_SAVE_PATH'] = $root . '/storage/sessions';
}
if (($env['BACKUP_PATH'] ?? '') === '') {
    $home = (string) (getenv('HOME') ?: dirname($root));
    $changes['BACKUP_PATH'] = rtrim($home, '/') . '/npd-backups'; // di luar folder domain
}
if ($changes) {
    $setEnv($changes);
}
@chmod($envFile, 0600);
$env = $readEnv();
foreach (['APP_ENV' => 'production', 'APP_DEBUG' => '0'] as $k => $expected) {
    if (($env[$k] ?? '') !== $expected) {
        $warn("{$k}=" . ($env[$k] ?? '') . " (produksi seharusnya {$expected})");
    }
}
foreach (['DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
    if (($env[$k] ?? '') === '' || in_array(strtolower((string) $env[$k]), ['usernamenpd', 'passwordnpd'], true)) {
        $fail("{$k} di .env belum diisi data asli — jalankan bash setup.sh (bukan php bin/setup.php langsung)");
    }
}
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $env['DB_NAME'])) {
    $fail("DB_NAME '{$env['DB_NAME']}' tidak valid (huruf, angka, garis bawah)");
}
$ok('.env siap — APP_URL=' . ($env['APP_URL'] ?? '') . ', DB_NAME=' . $env['DB_NAME'] . ', DB_USER=' . $env['DB_USER']);
foreach (['documents', 'exports', 'logs', 'cache', 'sessions', 'backups'] as $d) {
    $p = $root . '/storage/' . $d;
    if (!is_dir($p) && !@mkdir($p, 0750, true)) {
        $fail("tidak dapat membuat {$p}");
    }
    if (!is_writable($p)) {
        $fail("{$p} tidak dapat ditulis — perbaiki izin folder (cPanel › File Manager › Permissions 755)");
    }
}
$bpath = (string) $env['BACKUP_PATH'];
if (!is_dir($bpath) && !@mkdir($bpath, 0700, true)) {
    $warn("folder backup {$bpath} tidak dapat dibuat — ubah BACKUP_PATH di .env");
}
$ok('folder storage & backup siap');

echo "\n== 3/6 Koneksi database\n";
$pdo = $connect($env, false);
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$isMaria = stripos($version, 'mariadb') !== false;
preg_match('/^(\d+\.\d+)/', $version, $vm);
if (version_compare($vm[1] ?? '0', $isMaria ? '10.6' : '8.0', '<')) {
    $fail("server database {$version} terlalu lama — butuh MySQL 8.0+ atau MariaDB 10.6+");
}
$ok(($isMaria ? 'MariaDB ' : 'MySQL ') . $version);
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$st->execute([$env['DB_NAME']]);
if ((int) $st->fetchColumn() === 0) {
    $fail("database {$env['DB_NAME']} tidak ditemukan / user tidak berhak — buat di cPanel › MySQL Databases dan tambahkan user dengan ALL PRIVILEGES");
}
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
$st->execute([$env['DB_NAME']]);
$tables = (int) $st->fetchColumn();
$ok("database {$env['DB_NAME']} dapat diakses" . ($tables > 0 ? " ({$tables} tabel — data yang ada tidak dihapus)" : ' (kosong)'));
exit(0);
