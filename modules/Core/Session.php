<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sesi PHP yang aman:
 *  - cookie HttpOnly, Secure (saat HTTPS atau SESSION_SECURE=1), SameSite=Lax
 *  - strict mode (tolak ID sesi buatan), hanya cookie (tanpa ID di URL)
 *  - timeout idle (setting security.session_timeout_minutes, bawaan 480 menit)
 *  - regenerasi ID saat login dan berkala
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli' && !defined('NPD_FORCE_SESSION')) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        $secureCfg = (string) Config::get('session.secure', 'auto');
        $secure = $secureCfg === '1' || ($secureCfg === 'auto' && Request::isHttps());

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        if (PHP_VERSION_ID < 80400) {
            // 48 karakter × 5 bit = 240 bit entropi; karakter [0-9a-v] aman untuk cookie.
            // (Pengaturan ini deprecated sejak PHP 8.4; bawaan 8.4 = 128 bit.)
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '5');
        }
        ini_set('session.gc_maxlifetime', (string) (self::idleMinutes() * 60));
        $savePath = (string) Config::get('session.save_path', '');
        if ($savePath !== '' && is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        session_name((string) Config::get('session.name', 'NPDSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => (string) Config::get('session.samesite', 'Lax'),
        ]);
        session_start();
        self::$started = true;
        self::enforceTimeouts();
    }

    public static function idleMinutes(): int
    {
        $fromSettings = null;
        try {
            $fromSettings = Settings::int('security.session_timeout_minutes', 0);
        } catch (\Throwable) {
            // DB belum siap — pakai konfigurasi
        }
        $m = $fromSettings ?: (int) Config::get('session.idle_minutes', 480);
        return max(5, min($m, 24 * 60));
    }

    private static function enforceTimeouts(): void
    {
        $now = time();
        $last = (int) ($_SESSION['_last_activity'] ?? 0);
        if (isset($_SESSION['user_id']) && $last > 0 && ($now - $last) > self::idleMinutes() * 60) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_last_activity'] = $now;

        $created = (int) ($_SESSION['_regenerated_at'] ?? 0);
        if ($created === 0) {
            $_SESSION['_regenerated_at'] = $now;
        } elseif (($now - $created) > (int) Config::get('session.regenerate_seconds', 1800)) {
            session_regenerate_id(true);
            $_SESSION['_regenerated_at'] = $now;
        }
    }

    /** Dipanggil setelah login berhasil: cegah session fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_regenerated_at'] = time();
        $_SESSION['_last_activity'] = time();
    }

    /** Logout: hapus data, cookie, dan file sesi. */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            if (!headers_sent()) {
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $p['path'],
                    'domain' => $p['domain'],
                    'secure' => $p['secure'],
                    'httponly' => $p['httponly'],
                    'samesite' => $p['samesite'] ?? 'Lax',
                ]);
            }
            session_destroy();
        }
        self::$started = false;
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

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $v;
    }

    /** Pesan sekali tampil (PRG). type: success | error | info | warning */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? $f : [];
    }
}
