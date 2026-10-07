<?php
declare(strict_types=1);

namespace App\Core;

/** Pembaca request HTTP. Semua nilai dari pengguna diperlakukan sebagai tidak tepercaya. */
final class Request
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isMutating(): bool
    {
        return in_array(self::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** Nilai string dari POST lalu GET (trim). */
    public static function input(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
        if ($v === null || is_array($v)) {
            return $default;
        }
        return trim((string) $v);
    }

    public static function post(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? null;
        if ($v === null || is_array($v)) {
            return $default;
        }
        return trim((string) $v);
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        if ($v === null || is_array($v)) {
            return $default;
        }
        return trim((string) $v);
    }

    public static function int(string $key, ?int $default = null): ?int
    {
        $v = self::input($key);
        if ($v === null || $v === '' || !preg_match('/^-?\d{1,10}$/', $v)) {
            return $default;
        }
        return (int) $v;
    }

    /** @return array<mixed> */
    public static function array(string $key): array
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    /** Body JSON (fetch API). @return array<string,mixed> */
    public static function json(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 2_000_000) {
            throw new BusinessRuleException('Payload terlalu besar');
        }
        $data = json_decode($raw, true);
        return $cache = is_array($data) ? $data : [];
    }

    public static function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        return str_contains($accept, 'application/json')
            || str_contains($script, '/api/')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** Klien secara eksplisit meminta JSON (header Accept / X-Requested-With), terlepas dari path. */
    public static function acceptsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (self::fromTrustedProxy()) {
            return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    public static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (self::fromTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    private static function fromTrustedProxy(): bool
    {
        $trusted = (array) Config::get('security.trusted_proxies', []);
        return $trusted !== [] && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted, true);
    }

    /** Path relatif aman untuk redirect kembali (hanya path internal). */
    public static function safeReturnPath(?string $path, string $fallback): string
    {
        if ($path === null || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\\")
            || preg_match('/[\r\n]/', $path)) {
            return $fallback;
        }
        return $path;
    }
}
