<?php
declare(strict_types=1);

use App\Core\Env;

/** Koneksi MySQL 8 (PDO). */
return [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (int) Env::get('DB_PORT', '3306'),
    'socket'   => Env::get('DB_SOCKET', ''),
    'name'     => Env::get('DB_NAME', 'npd_project_control'),
    'user'     => Env::get('DB_USER', 'root'),
    'pass'     => Env::get('DB_PASS', ''),
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'timezone' => '+07:00',
];
