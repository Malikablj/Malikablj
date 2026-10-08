<?php
declare(strict_types=1);

/**
 * Cetak perintah GRANT hak minimal untuk user MySQL aplikasi (runtime web & cron), per tabel:
 * SELECT/INSERT/UPDATE/DELETE, kecuali audit_logs hanya SELECT/INSERT (append-only, PRD §9.4).
 * Instalasi, migrasi & pemulihan memakai user admin terpisah; backup memakai user --role=backup (docs/DEPLOYMENT.md).
 *
 *   php bin/db-grants.php --user=npd_app --host=localhost > grants.sql
 *   mysql -u root -p < grants.sql
 *   php bin/db-grants.php --role=backup --user=npd_backup   # user backup: SELECT, SHOW VIEW, TRIGGER
 * Jalankan ulang (role app) setelah migrasi yang menambah tabel.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Config;
use App\Core\Db;

$opts = getopt('', ['user:', 'host:', 'database:', 'role:']);
$role = (string) ($opts['role'] ?? 'app');
$user = (string) ($opts['user'] ?? '');
$host = (string) ($opts['host'] ?? 'localhost');
$db = (string) ($opts['database'] ?? Config::get('db.name'));
if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) || !preg_match('/^[A-Za-z0-9_.%:-]{1,255}$/', $host) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)
    || !in_array($role, ['app', 'backup'], true)) {
    fwrite(STDERR, "Pemakaian: php bin/db-grants.php --user=NAMA_USER [--host=localhost] [--database=NAMA_DB] [--role=app|backup]\n");
    exit(1);
}
$tables = Db::column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$db]);
if (!$tables) {
    fwrite(STDERR, "Database {$db} tidak memiliki tabel (jalankan bin/install.php dahulu)\n");
    exit(1);
}
$who = "'{$user}'@'{$host}'";
if ($role === 'backup') {
    // mysqldump --single-transaction --triggers --routines --no-tablespaces: baca data + definisi trigger
    echo "-- User backup {$who} untuk database `{$db}` (baca saja + definisi trigger)\n";
    echo "-- Buat user bila belum ada: CREATE USER {$who} IDENTIFIED BY '<password kuat>';\n";
    echo "GRANT SELECT, SHOW VIEW, TRIGGER ON `{$db}`.* TO {$who};\n";
    exit(0);
}
echo "-- Hak minimal user aplikasi {$who} untuk database `{$db}` (" . count($tables) . " tabel)\n";
echo "-- Buat user bila belum ada: CREATE USER {$who} IDENTIFIED BY '<password kuat>';\n";
echo "-- Cabut hak lama yang lebih luas bila ada: REVOKE ALL PRIVILEGES ON `{$db}`.* FROM {$who};\n";
foreach ($tables as $t) {
    $priv = $t === 'audit_logs' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
    echo "GRANT {$priv} ON `{$db}`.`{$t}` TO {$who};\n";
}
