<?php
declare(strict_types=1);

/**
 * Instalasi / pembaruan database:
 *   php bin/install.php                 # pakai DB dari .env: jalankan schema.sql + seed.sql
 *   php bin/install.php --database=npd_test --fresh   # (test) hapus & buat ulang database
 *
 * schema.sql dan seed.sql idempoten, sehingga aman dijalankan ulang di server berisi data.
 * Opsi --fresh MENGHAPUS database; ditolak bila APP_ENV=production.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Config;
use App\Core\Db;

$opts = getopt('', ['database:', 'fresh', 'with-hardening']);
$dbName = $opts['database'] ?? (string) Config::get('db.name');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
    fwrite(STDERR, "Nama database tidak valid\n");
    exit(1);
}
$fresh = isset($opts['fresh']);
if ($fresh && Config::get('app.env') === 'production') {
    fwrite(STDERR, "--fresh ditolak pada APP_ENV=production\n");
    exit(1);
}

$cfg = (array) Config::get('db');
$cfg['name'] = '';
$dsn = !empty($cfg['socket'])
    ? sprintf('mysql:unix_socket=%s;charset=utf8mb4', $cfg['socket'])
    : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']);
$pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($fresh) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$dbName}`");

/** Jalankan file SQL (tanpa baris CREATE DATABASE/USE agar bisa ke database mana pun). */
$run = static function (PDO $pdo, string $file): int {
    $sql = (string) file_get_contents($file);
    $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $count = 0;
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
        $count++;
    }
    return $count;
};

$root = dirname(__DIR__);
$n1 = $run($pdo, $root . '/database/schema.sql');
$n2 = $run($pdo, $root . '/database/seed.sql');
echo "Database `{$dbName}` siap: {$n1} pernyataan skema, {$n2} pernyataan seed.\n";
if (isset($opts['with-hardening'])) {
    $n3 = $run($pdo, $root . '/database/hardening.sql');
    echo "Hardening diterapkan ({$n3} pernyataan).\n";
}
$users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($users === 0) {
    echo "Belum ada user. Buat Admin pertama: php bin/create-admin.php\n";
}
