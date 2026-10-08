<?php
declare(strict_types=1);

/**
 * Bootstrap aplikasi — di-require oleh setiap halaman di public/, endpoint api/, dan skrip cron/bin.
 */

use App\Core\AppException;
use App\Core\Auth;
use App\Core\Config;
use App\Core\I18n;
use App\Core\Request;
use App\Core\RequestContext;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;

if (defined('APP_ROOT')) {
    return;
}
define('APP_ROOT', dirname(__DIR__));

// 1. Autoload: App\ → modules/ (tanpa bergantung pada Composer), lalu vendor bila ada.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/modules/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}

// 2. Konfigurasi & zona waktu
Config::init(require APP_ROOT . '/config/config.php');
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));
mb_internal_encoding('UTF-8');

// 3. Logging & penanganan error
$logDir = (string) Config::get('storage.logs');
if ($logDir !== '' && is_dir($logDir) && is_writable($logDir)) {
    ini_set('log_errors', '1');
    ini_set('error_log', $logDir . '/app.log');
}
// CLI (cron/bin/test): error tampil di stderr agar terlihat di log cron; web: hanya di development.
ini_set('display_errors', PHP_SAPI === 'cli' ? 'stderr' : (Config::get('app.debug') && Config::isDevelopment() ? '1' : '0'));
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof AppException) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, '[' . get_class($e) . '] ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        $extra = $e instanceof ValidationException ? ['errors' => $e->errors()] : [];
        Response::error($e->httpStatus(), $e->getMessage(), $extra);
    }
    error_log(sprintf('[%s] %s in %s:%d%s%s', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL, $e->getTraceAsString()));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    $message = Config::get('app.debug') && Config::isDevelopment()
        ? get_class($e) . ': ' . $e->getMessage()
        : I18n::t('error.server');
    Response::error(500, $message);
});

// 4. Helper global
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/permissions.php';

// 5. Konteks web: sesi, pengguna, bahasa, header keamanan
if (PHP_SAPI !== 'cli') {
    Response::sendSecurityHeaders();
    Session::start();
    $user = Auth::user();
    RequestContext::set($user, Request::ip(), Request::userAgent());
    I18n::setLocale($user?->language ?? (string) ($_SESSION['guest_language'] ?? Config::get('app.default_language', 'id')));
    if (Request::isMutating()) {
        // kiriman di atas post_max_size dibuang PHP (termasuk token CSRF) → beri tahu batas ukurannya (413)
        if (Request::exceedsPostLimit()) {
            $mb = (int) floor(min(Request::iniBytes((string) ini_get('post_max_size')), \App\Document\UploadValidator::maxBytes()) / 1048576);
            Response::error(413, I18n::t('upload.request_too_large', ['mb' => $mb]));
        }
        // Pertahanan berlapis: setiap request yang mengubah data wajib membawa token CSRF yang sah,
        // walaupun endpoint lupa memanggil require_post() (gagal → 419 lewat exception handler).
        \App\Core\Csrf::verify();
    }
} else {
    I18n::setLocale((string) Config::get('app.default_language', 'id'));
}
