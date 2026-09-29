<?php

declare(strict_types=1);

namespace App\Helpers;

/** Pembungkus sederhana untuk $_GET, $_POST dan $_SERVER. */
final class Request
{
    private static ?string $basePath = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** Path relatif terhadap base path aplikasi, selalu diawali "/". */
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $base = self::basePath();
        if ($script !== '' && str_starts_with($path, $script)) {
            // Mode tanpa mod_rewrite: /base/index.php/customers
            $path = substr($path, strlen($script));
        } elseif ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');
        return $path;
    }

    /** Base path aplikasi, mis. "" (root) atau "/pik". */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }
        // APP_BASE_PATH kosong = deteksi otomatis; "/" = root domain; "/pik" = subfolder
        $configured = trim((string) (config('app.base_path') ?? ''));
        if ($configured !== '') {
            $configured = trim($configured, '/');
            return self::$basePath = $configured === '' ? '' : '/' . $configured;
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));
        $dir = rtrim($dir, '/');
        if ($dir === '.' || $dir === '/') {
            $dir = '';
        }
        return self::$basePath = $dir;
    }

    public static function setBasePath(?string $basePath): void
    {
        self::$basePath = $basePath;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        return $_GET[$key] ?? $default;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Nilai string (trim) dari query string — aman untuk filter. */
    public static function queryString(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public static function queryInt(string $key, int $default = 0): int
    {
        $value = $_GET[$key] ?? null;
        if (is_string($value) && preg_match('/^\d{1,9}$/', $value)) {
            return (int) $value;
        }
        return $default;
    }

    /** @return array<string,mixed> */
    public static function post(): array
    {
        return $_POST;
    }

    /** @param list<string> $keys @return array<string,mixed> */
    public static function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $_POST[$key] ?? null;
        }
        return $out;
    }

    public static function isAjax(): bool
    {
        $xrw = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return $xrw === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /** URL lengkap (path + query) request saat ini, relatif terhadap aplikasi. */
    public static function fullPath(): string
    {
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        return self::path() . ($qs !== '' ? '?' . $qs : '');
    }
}
