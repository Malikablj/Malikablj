<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Manajemen session yang aman: cookie HttpOnly + SameSite, strict mode,
 * batas waktu idle & absolut, serta flash message.
 */
final class Session
{
    private static bool $started = false;
    private static bool $expired = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        $cfg = config('app.session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        session_name($cfg['name']);
        $path = Request::basePath() === '' ? '/' : Request::basePath() . '/';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $path,
            'secure'   => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        $now = time();
        $idle = max(5, (int) $cfg['idle_minutes']) * 60;
        $absolute = max(1, (int) $cfg['absolute_hours']) * 3600;
        $last = (int) ($_SESSION['_last_activity'] ?? $now);
        $created = (int) ($_SESSION['_created'] ?? $now);
        if (isset($_SESSION['user_id']) && (($now - $last) > $idle || ($now - $created) > $absolute)) {
            self::$expired = true;
            self::destroy();
            self::start();
            return;
        }
        $_SESSION['_created'] = $created;
        $_SESSION['_last_activity'] = $now;
    }

    public static function wasExpired(): bool
    {
        return self::$expired;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Ganti session id (mencegah session fixation) — dipanggil saat login. */
    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = time();
        $_SESSION['_last_activity'] = time();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
            session_destroy();
        }
        self::$started = false;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function pullFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($messages) ? $messages : [];
    }
}
