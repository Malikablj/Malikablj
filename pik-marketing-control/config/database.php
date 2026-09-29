<?php

declare(strict_types=1);

use App\Helpers\Env;

/*
 * Koneksi database MySQL/MariaDB (PDO).
 * Kredensial dibaca dari .env — file .env TIDAK di-commit ke git.
 */
return [
    'host'      => Env::get('DB_HOST', '127.0.0.1'),
    'port'      => (int) Env::get('DB_PORT', '3306'),
    'database'  => Env::get('DB_DATABASE', 'pik_marketing'),
    'username'  => Env::get('DB_USERNAME', 'root'),
    'password'  => Env::get('DB_PASSWORD', ''),
    'socket'    => Env::get('DB_SOCKET', ''),
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    // Zona waktu sesi database (harus sama dengan APP_TIMEZONE). Asia/Jakarta = +07:00
    'timezone'  => Env::get('DB_TIMEZONE', '+07:00'),
];
