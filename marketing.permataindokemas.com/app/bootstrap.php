<?php

declare(strict_types=1);

/*
 * Bootstrap aplikasi: autoload, konfigurasi, timezone, error handling.
 * Dipakai oleh public/index.php, script CLI (database/*.php, cron/*.php) dan test.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('PIK Marketing Control membutuhkan PHP 8.1 atau lebih baru.');
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $parts = explode('\\', substr($class, 4));
    $file = array_pop($parts);
    $dir = implode('/', array_map('strtolower', $parts));
    $path = APP_ROOT . '/app/' . ($dir !== '' ? $dir . '/' : '') . $file . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require APP_ROOT . '/app/helpers/functions.php';

// .env (atau file lain via variabel environment APP_ENV_FILE, dipakai test)
$envFile = getenv('APP_ENV_FILE');
App\Helpers\Env::load(is_string($envFile) && $envFile !== '' ? $envFile : APP_ROOT . '/.env');

mb_internal_encoding('UTF-8');
date_default_timezone_set((string) config('app.timezone', 'Asia/Jakarta'));

error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');

// Semua warning/notice dijadikan exception agar tidak ada error yang diam-diam lolos.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        App\Helpers\Logger::info("Deprecated: {$message} in {$file}:{$line}");
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
