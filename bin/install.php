<?php
declare(strict_types=1);

/**
 * Instalasi / pembaruan database:
 *   php bin/install.php                 # pakai DB dari .env: jalankan schema.sql + seed.sql
 *   php bin/install.php --database=npd_test --fresh   # (test) hapus & buat ulang database
 *
 * schema.sql dan seed.sql idempoten, sehingga aman dijalankan ulang di server berisi data.
 * Database BARU langsung berskema terbaru, maka seluruh file database/migrations/ dicatat sebagai sudah
 * dijalankan. Database LAMA yang sudah berisi tabel diperbarui dengan: php bin/migrate.php
 * Opsi --fresh MENGHAPUS database; ditolak bila APP_ENV=production.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Config;
use App\Core\SqlScript;

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
// Hosting (cPanel/phpMyAdmin) biasanya sudah membuat database dan user tidak berhak CREATE DATABASE:
// buat hanya bila belum ada.
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$dbName]);
if ((int) $exists->fetchColumn() === 0) {
    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} else {
    // database buatan panel bisa memakai charset bawaan server; tabel tetap utf8mb4 eksplisit, ini hanya merapikan bawaan
    try {
        $pdo->exec("ALTER DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException) {
    }
}
$pdo->exec("USE `{$dbName}`");

$existingTables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();

$root = dirname(__DIR__);
$n1 = SqlScript::runFile($pdo, $root . '/database/schema.sql');
$n2 = SqlScript::runFile($pdo, $root . '/database/seed.sql');
echo "Database `{$dbName}` siap: {$n1} pernyataan skema, {$n2} pernyataan seed.\n";
$migrations = glob($root . '/database/migrations/*.sql') ?: [];
if ($existingTables === 0) {
    // skema baru sudah memuat seluruh perubahan migrasi → catat sebagai sudah dijalankan
    $mark = $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (?)');
    foreach ($migrations as $m) {
        $mark->execute([basename($m)]);
    }
} elseif ($migrations) {
    echo "Database sudah berisi data: jalankan php bin/migrate.php untuk menerapkan perubahan skema.\n";
}
if (isset($opts['with-hardening'])) {
    $n3 = SqlScript::runFile($pdo, $root . '/database/hardening.sql');
    echo "Hardening diterapkan ({$n3} pernyataan).\n";
}
$users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($users === 0 && !getenv('NPD_SETUP')) { // setup.sh membuat Admin pada langkah berikutnya
    echo "Belum ada user. Buat Admin pertama: php bin/create-admin.php\n";
}
