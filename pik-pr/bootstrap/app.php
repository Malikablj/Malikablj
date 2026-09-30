<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $message = "Dependency belum terpasang. Jalankan `composer install` di folder aplikasi.\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit(1);
}
require $autoload;

Env::load(BASE_PATH . '/.env');

// Test suite selalu memakai database terpisah agar data development tidak tersentuh.
if (Env::get('APP_ENV') === 'testing') {
    Env::set('DB_DATABASE', (string) Env::get('DB_TEST_DATABASE', 'pik_pr_test'));
    Env::set('UPLOAD_PATH', 'storage/testing/uploads');
}

Config::load(BASE_PATH . '/config');

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));
mb_internal_encoding('UTF-8');
