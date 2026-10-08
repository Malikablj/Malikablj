<?php
declare(strict_types=1);

/**
 * Konfigurasi aplikasi. Nilai dibaca dari .env (lihat .env.example) dengan nilai bawaan aman.
 * File ini mengembalikan array; akses lewat App\Core\Config::get('key.sub').
 */

use App\Core\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$storage = Env::get('STORAGE_PATH') ?: $root . '/storage';

return [
    'app' => [
        'name'     => 'NPD Project Control',
        'version'  => '3.0.0',
        'env'      => Env::get('APP_ENV', 'production'),
        'debug'    => Env::bool('APP_DEBUG', false),
        'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
        'key'      => Env::get('APP_KEY', ''),
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Jakarta'),
        'root'     => $root,
        'default_language' => 'id',
        'languages' => ['id', 'en'],
    ],
    'db' => require __DIR__ . '/database.php',
    'session' => [
        'name'      => Env::get('SESSION_NAME', 'NPDSESSID'),
        'secure'    => Env::get('SESSION_SECURE', 'auto'),
        'save_path' => Env::get('SESSION_SAVE_PATH', ''),
        'samesite'  => 'Lax',
        // timeout idle bawaan (menit) — dapat ditimpa setting security.session_timeout_minutes
        'idle_minutes' => 480,
        // regenerasi ID sesi berkala (detik)
        'regenerate_seconds' => 1800,
    ],
    'security' => [
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    ],
    'storage' => [
        'root'      => $storage,
        'documents' => $storage . '/documents',
        'exports'   => $storage . '/exports',
        'logs'      => $storage . '/logs',
        'cache'     => $storage . '/cache',
    ],
    'mail' => require __DIR__ . '/mail.php',
    // backup harian (bin/backup.php) — simpan di disk lain / salin ke luar server bila memungkinkan
    'backup' => [
        'path'           => Env::get('BACKUP_PATH') ?: $storage . '/backups',
        'retention_days' => max(1, (int) Env::get('BACKUP_RETENTION_DAYS', '30')),
        'mysqldump'      => Env::get('MYSQLDUMP_BIN', 'mysqldump'),
        'mysql'          => Env::get('MYSQL_BIN', 'mysql'),
        'tar'            => Env::get('TAR_BIN', 'tar'),
        // user MySQL khusus backup (SELECT, SHOW VIEW, TRIGGER); kosong = user aplikasi (trigger tidak ikut ter-dump)
        'db_user'        => Env::get('BACKUP_DB_USER', ''),
        'db_pass'        => Env::get('BACKUP_DB_PASS', ''),
    ],
];
