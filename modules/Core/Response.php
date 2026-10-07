<?php
declare(strict_types=1);

namespace App\Core;

/** Keluaran HTTP: JSON, redirect, halaman error, header keamanan. */
final class Response
{
    private static ?string $nonce = null;

    /** Nonce CSP per request untuk <script> inline yang diizinkan. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        $nonce = self::nonce();
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (Request::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    public static function redirect(string $path, int $status = 303): never
    {
        if (!headers_sent()) {
            header('Location: ' . self::url($path), true, $status);
        }
        exit;
    }

    /**
     * URL internal relatif terhadap root aplikasi (mendukung instalasi di subfolder).
     * Path yang diawali "/" dianggap sudah absolut (mis. hasil url() atau REQUEST_URI).
     */
    public static function url(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path) || (str_starts_with($path, '/') && !str_starts_with($path, '//'))) {
            return $path;
        }
        return self::basePath() . '/' . ltrim($path, '/');
    }

    public static function basePath(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        // SCRIPT_NAME mis. /npd/public/settings/users.php → base /npd/public
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $publicDir = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 2) . '/public'));
        $file = str_replace('\\', '/', (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
        if ($script !== '' && $publicDir !== '' && $file !== '' && str_starts_with($file, $publicDir)) {
            $relative = substr($file, strlen($publicDir)); // /settings/users.php
            $base = rtrim(substr($script, 0, strlen($script) - strlen($relative)), '/');
        } else {
            $base = '';
        }
        return $base;
    }

    /** Tampilkan error aman (HTML atau JSON) lalu berhenti. */
    public static function error(int $status, string $message, array $extra = []): never
    {
        if (Request::wantsJson()) {
            self::json(['error' => $message] + $extra, $status);
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        $errorStatus = $status;
        $errorMessage = $message;
        require dirname(__DIR__, 2) . '/includes/layout/error.php';
        exit;
    }
}
