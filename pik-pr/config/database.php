<?php

declare(strict_types=1);

use App\Core\Env;

// Aplikasi hanya mendukung MySQL 8.0+ melalui PDO (pdo_mysql).
return [
    'driver' => (string) Env::get('DB_CONNECTION', 'mysql'),
    'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => (string) Env::get('DB_DATABASE', 'pik_pr'),
    'username' => (string) Env::get('DB_USERNAME', 'root'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
