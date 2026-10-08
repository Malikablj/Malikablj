<?php
declare(strict_types=1);

/**
 * Pemeriksaan pasca-deploy (docs/DEPLOYMENT.md §8). Jalankan di server sebagai user web:
 *   sudo -u www-data php bin/check-deployment.php --url=https://npd.example.co.id
 *   ... --insecure                      # sertifikat self-signed (staging)
 *   ... --resolve=npd.local:443:127.0.0.1   # uji sebelum DNS diarahkan
 *   ... --upload-limit                  # kirim 31 MB untuk memastikan batas upload web server
 * Hasil: [OK] / [PERINGATAN] / [GAGAL]; kode keluar 1 bila ada GAGAL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Cron\JobStatus;

$opts = getopt('', ['url:', 'insecure', 'resolve:', 'upload-limit', 'migrations:']);
$root = dirname(__DIR__);
$results = ['OK' => 0, 'PERINGATAN' => 0, 'GAGAL' => 0];
$report = static function (string $level, string $msg) use (&$results): void {
    $results[$level]++;
    printf("[%s] %s\n", $level, $msg);
};
$check = static function (bool $ok, string $okMsg, string $failMsg, string $failLevel = 'GAGAL') use ($report): void {
    $report($ok ? 'OK' : $failLevel, $ok ? $okMsg : $failMsg);
};

echo "== Konfigurasi & PHP\n";
$check(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION, 'PHP ' . PHP_VERSION . ' — butuh 8.2 atau lebih baru');
$missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'json', 'fileinfo', 'sodium', 'gd', 'zip', 'xml', 'dom', 'iconv', 'zlib', 'openssl'], static fn ($e) => !extension_loaded($e)));
$check(!$missing, 'ekstensi PHP lengkap', 'ekstensi PHP belum aktif: ' . implode(', ', $missing));
$check(Config::get('app.env') === 'production', 'APP_ENV=production', 'APP_ENV=' . Config::get('app.env') . ' (produksi wajib production)');
$check(!Config::get('app.debug'), 'APP_DEBUG mati', 'APP_DEBUG aktif — detail error dapat bocor ke pengguna');
$key = (string) Config::get('app.key');
$raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
$check($raw !== false && strlen($raw) === 32, 'APP_KEY 32 byte', 'APP_KEY kosong/tidak valid — buat dengan php bin/generate-key.php');
$url = (string) Config::get('app.url');
$check(str_starts_with($url, 'https://'), 'APP_URL ' . $url, 'APP_URL harus https:// (tautan email & cookie Secure): ' . ($url ?: '(kosong)'));
$check((string) Config::get('session.secure') === '1', 'SESSION_SECURE=1', 'SESSION_SECURE bukan 1 — set 1 di produksi (HTTPS)', 'PERINGATAN');
$check(!is_file($root . '/public/.env'), '.env di luar web root', 'public/.env ada — hapus segera');

echo "== Storage\n";
$public = realpath($root . '/public') ?: $root . '/public';
foreach (['documents', 'exports', 'logs', 'cache'] as $d) {
    $path = (string) Config::get('storage.' . $d);
    $real = realpath($path);
    if ($real === false) {
        $report('GAGAL', "storage/{$d} tidak ada: {$path}");
        continue;
    }
    $check(!str_starts_with($real . '/', $public . '/'), "storage/{$d} di luar web root", "storage/{$d} berada di bawah public/: {$real}");
    $check(is_writable($real), "storage/{$d} dapat ditulis", "storage/{$d} tidak dapat ditulis oleh user " . (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?'));
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $report('PERINGATAN', 'dijalankan sebagai root — hasil "dapat ditulis" kurang bermakna; jalankan: sudo -u www-data php bin/check-deployment.php');
}
$bpath = (string) Config::get('backup.path');
$check(is_dir($bpath) && is_writable($bpath), 'folder backup siap: ' . $bpath, 'folder backup tidak ada / tidak dapat ditulis: ' . $bpath, 'PERINGATAN');
if (!\App\Ops\BackupService::procAvailable() || (new \App\Ops\BackupService())->method() === 'php') {
    $report('OK', 'backup memakai mode PHP (PDO + zip; proc_open/mysqldump tidak diperlukan)');
} else {
    foreach (['mysqldump', 'mysql', 'tar'] as $bin) {
        $p = @proc_open([(string) Config::get('backup.' . $bin), '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $okBin = is_resource($p) && (stream_get_contents($pipes[1]) . stream_get_contents($pipes[2])) !== '' && proc_close($p) === 0;
        $check($okBin, "{$bin} tersedia (untuk backup)", "{$bin} tidak dapat dijalankan — set BACKUP_METHOD=php di .env, atau cek " . strtoupper($bin) . '_BIN', 'PERINGATAN');
    }
}

echo "== Database\n";
try {
    $ver = (string) Db::value('SELECT VERSION()');
    $maria = stripos($ver, 'mariadb') !== false;
    preg_match('/^(\d+\.\d+)/', $ver, $vm);
    $check(version_compare($vm[1] ?? '0', $maria ? '10.6' : '8.0', '>='), ($maria ? 'MariaDB ' : 'MySQL ') . $ver,
        ($maria ? 'MariaDB ' : 'MySQL ') . $ver . ' — butuh ' . ($maria ? 'MariaDB 10.6+' : 'MySQL 8.0+'));
    $expected = preg_match_all('/^CREATE TABLE IF NOT EXISTS/m', (string) file_get_contents($root . '/database/schema.sql'));
    $tables = (int) Db::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    $check($tables >= $expected, "{$tables} tabel", "hanya {$tables} dari {$expected} tabel — jalankan bin/install.php / bin/migrate.php");
    $applied = array_flip(Db::column('SELECT migration FROM schema_migrations'));
    $migDir = (string) ($opts['migrations'] ?? $root . '/database/migrations');
    $pending = array_filter(array_map('basename', glob($migDir . '/*.sql') ?: []), static fn ($m) => !isset($applied[$m]));
    $check(!$pending, 'migrasi skema terkini', 'migrasi belum dijalankan: ' . implode(', ', $pending) . ' — backup lalu php bin/migrate.php');
    $admins = (int) Db::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1");
    $check($admins > 0, "{$admins} Admin aktif", 'belum ada Admin — php bin/create-admin.php');
    $triggers = Db::column("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_logs'");
    // user aplikasi seharusnya tidak berhak mengubah/menghapus audit_logs — dibaca dari tabel hak information_schema
    // (hanya hak user yang sedang terhubung yang terlihat; tidak ada perintah yang dijalankan ke audit_logs)
    $grantee = (string) Db::value("SELECT CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')");
    $writePrivs = "PRIVILEGE_TYPE IN ('UPDATE', 'DELETE', 'DROP', 'ALTER')";
    $canUpdate = (int) Db::value("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND {$writePrivs}", [$grantee]) > 0
        || (int) Db::value("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ? AND ? LIKE TABLE_SCHEMA AND {$writePrivs}", [$grantee, (string) Db::value('SELECT DATABASE()')]) > 0
        || (int) Db::value("SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ? AND TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND {$writePrivs}", [$grantee]) > 0;
    $check(!$canUpdate || count($triggers) >= 2, 'audit_logs append-only di level database (' . ($canUpdate ? 'trigger' : 'hak user') . ')',
        'audit log append-only hanya dijaga aplikasi: user database berhak UPDATE/DELETE audit_logs dan trigger hardening tidak terpasang (di hosting bersama biasanya tidak tersedia; di server sendiri: bin/db-grants.php atau database/hardening.sql)', 'PERINGATAN');
    $super = false;
    try {
        $grants = implode("\n", Db::column('SHOW GRANTS FOR CURRENT_USER()'));
        $super = (bool) preg_match('/GRANT ALL PRIVILEGES ON \*\.\*|\bSUPER\b|CREATE USER|DROP\b.*ON \*\.\*/', $grants);
    } catch (\PDOException) {
    }
    $check(!$super, 'user aplikasi bukan admin MySQL', 'user aplikasi memiliki hak global/administratif — pakai user dengan hak minimal (bin/db-grants.php)', 'PERINGATAN');
} catch (\Throwable $e) {
    $report('GAGAL', 'koneksi database gagal: ' . $e->getMessage());
}

echo "== Tugas terjadwal\n";
try {
    foreach ((new JobStatus())->all() as $j) {
        $last = $j['last'] ? $j['last']['started_at'] . ' (' . $j['last']['status'] . ')' : 'belum pernah';
        $check($j['state'] === 'ok', "{$j['job']}: {$last}", "{$j['job']}: {$last} — periksa Cron Jobs (cPanel) atau deploy/cron.d-npd, dan storage/logs/cron.log", 'PERINGATAN');
    }
} catch (\Throwable $e) {
    $report('PERINGATAN', 'status tugas tidak terbaca: ' . $e->getMessage());
}

if (!empty($opts['url'])) {
    echo "== HTTP " . $opts['url'] . "\n";
    $base = rtrim((string) $opts['url'], '/');
    $req = static function (string $method, string $u, ?string $body = null, array $headers = []) use ($opts): array {
        $ch = curl_init($u);
        $h = [];
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => !isset($opts['insecure']), CURLOPT_SSL_VERIFYHOST => isset($opts['insecure']) ? 0 : 2,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$h): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $h[strtolower(trim($p[0]))][] = trim($p[1]);
                }
                return strlen($line);
            }]);
        if (!empty($opts['resolve'])) {
            curl_setopt($ch, CURLOPT_RESOLVE, [(string) $opts['resolve'], preg_replace('/:443:/', ':80:', (string) $opts['resolve'])]);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $b = (string) curl_exec($ch);
        $r = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $h, 'body' => $b, 'error' => curl_error($ch)];
        curl_close($ch);
        return $r;
    };
    if (str_starts_with($base, 'https://')) {
        $plain = $req('GET', 'http://' . substr($base, 8) . '/login.php');
        $check(in_array($plain['status'], [301, 302, 307, 308], true) && str_starts_with($plain['headers']['location'][0] ?? '', 'https://'),
            'HTTP dialihkan ke HTTPS', 'HTTP tidak dialihkan ke HTTPS (status ' . $plain['status'] . ')');
    }
    $login = $req('GET', $base . '/login.php');
    if ($login['status'] === 0 && stripos($login['error'], 'SSL') !== false) {
        // sertifikat belum sah (mis. AutoSSL cPanel belum terbit) — pemeriksaan HTTP lain tidak bermakna
        $report('GAGAL', 'sertifikat SSL ' . $base . ' belum valid (' . $login['error'] . ') — cek cPanel › SSL/TLS Status / AutoSSL, lalu ulangi');
        printf("\nRingkasan: %d OK, %d peringatan, %d gagal\n", $results['OK'], $results['PERINGATAN'], $results['GAGAL']);
        exit(1);
    }
    if ($login['status'] !== 200) {
        $report('GAGAL', 'halaman login: status ' . $login['status'] . ' ' . $login['error']);
    } else {
        $report('OK', 'halaman login 200');
        $hd = $login['headers'];
        $check(str_contains($hd['content-security-policy'][0] ?? '', "frame-ancestors 'none'"), 'Content-Security-Policy', 'header CSP tidak ada');
        $check(($hd['x-frame-options'][0] ?? '') === 'DENY', 'X-Frame-Options DENY', 'X-Frame-Options tidak ada');
        $check(($hd['x-content-type-options'][0] ?? '') === 'nosniff', 'X-Content-Type-Options nosniff', 'X-Content-Type-Options tidak ada');
        $check(!isset($hd['x-powered-by']), 'versi PHP tidak diumumkan', 'header X-Powered-By ada — set expose_php = Off', 'PERINGATAN');
        if (str_starts_with($base, 'https://')) {
            $check(isset($hd['strict-transport-security']), 'Strict-Transport-Security', 'HSTS tidak ada — pastikan PHP mengenali HTTPS (fastcgi_param HTTPS / TRUSTED_PROXIES)');
        }
        $cookie = implode('; ', $hd['set-cookie'] ?? []);
        $check(stripos($cookie, 'httponly') !== false && stripos($cookie, 'samesite') !== false, 'cookie sesi HttpOnly + SameSite', 'cookie sesi tanpa HttpOnly/SameSite');
        if (str_starts_with($base, 'https://')) {
            $check(stripos($cookie, 'secure') !== false, 'cookie sesi Secure', 'cookie sesi tanpa Secure — set SESSION_SECURE=1 / TRUSTED_PROXIES');
        }
    }
    foreach (['/.env', '/.git/HEAD', '/composer.json', '/vendor/autoload.php', '/config/config.php', '/storage/logs/app.log', '/database/schema.sql', '/bin/backup.php', '/includes/bootstrap.php'] as $p) {
        $r = $req('GET', $base . $p);
        if ($r['status'] === 0) {
            $report('GAGAL', "{$p}: koneksi gagal — {$r['error']}");
            continue;
        }
        $leak = $r['status'] === 200 && (str_contains($r['body'], 'APP_KEY') || str_contains($r['body'], '<?php') || str_contains($r['body'], 'CREATE TABLE') || str_contains($r['body'], 'ref:') || $p === '/storage/logs/app.log');
        $check(!$leak && in_array($r['status'], [403, 404], true), "{$p} tidak dapat diakses ({$r['status']})", "{$p} dapat diakses dari web (status {$r['status']})");
    }
    $css = $req('GET', $base . '/assets/css/app.css', null, ['Accept-Encoding: gzip']);
    $check($css['status'] === 200, 'aset statis 200', 'aset statis /assets/css/app.css status ' . $css['status']);
    $check(isset($css['headers']['content-encoding']) || isset($css['headers']['cache-control']), 'aset dikompresi/di-cache', 'aset tanpa gzip maupun Cache-Control (lihat deploy/nginx.conf)', 'PERINGATAN');
    $api = $req('POST', $base . '/api/schedule-preview.php', '{}', ['Content-Type: application/json', 'Accept: application/json']);
    $check(in_array($api['status'], [401, 419], true) && !str_contains($api['body'], 'Stack trace') && !str_contains($api['body'], '#0 '), 'API tanpa sesi ditolak tanpa jejak error (' . $api['status'] . ')', 'API tanpa sesi: status ' . $api['status']);
    if (isset($opts['upload-limit'])) {
        $big = $req('POST', $base . '/login.php', str_repeat('a', 31 * 1024 * 1024), ['Content-Type: application/octet-stream']);
        $check($big['status'] === 413, 'request > 30 MB ditolak web server (413)', 'request 31 MB tidak ditolak web server (status ' . $big['status'] . ') — set client_max_body_size / LimitRequestBody', 'PERINGATAN');
    }
}

printf("\nRingkasan: %d OK, %d peringatan, %d gagal\n", $results['OK'], $results['PERINGATAN'], $results['GAGAL']);
exit($results['GAGAL'] > 0 ? 1 : 0);
