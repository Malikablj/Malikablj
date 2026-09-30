<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Pembungkus $_SESSION. Pada CLI (test & seeder) session PHP tidak dijalankan,
 * cukup array $_SESSION biasa sehingga alur request dapat diuji tanpa browser.
 */
final class Session
{
    private static bool $started = false;

    /**
     * @param array{name: string, lifetime: int, secure: bool} $config
     */
    public static function start(array $config, bool $secureRequest = false): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION ??= [];

            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) max(60, $config['lifetime'] * 60));

        session_name($config['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Request::basePath() ?: '') . '/',
            'secure' => $config['secure'] || $secureRequest,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Memindahkan flash data: data flash request sebelumnya tersedia di request ini lalu dihapus.
     */
    public static function ageFlash(): void
    {
        $_SESSION['_flash_old'] = $_SESSION['_flash_new'] ?? [];
        $_SESSION['_flash_new'] = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_new'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_new'][$key] ?? $_SESSION['_flash_old'][$key] ?? $default;
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function invalidate(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'PHPSESSID', '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
            session_start();
            session_regenerate_id(true);
        }
    }

    /**
     * Hanya untuk test: mengosongkan state session di antara skenario.
     */
    public static function reset(): void
    {
        $_SESSION = [];
        self::$started = false;
    }
}
