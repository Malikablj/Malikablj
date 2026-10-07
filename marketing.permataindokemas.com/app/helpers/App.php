<?php

declare(strict_types=1);

namespace App\Helpers;

use PDOException;
use Throwable;

/** Siklus hidup request HTTP. */
final class App
{
    public static function handleHttp(): void
    {
        self::sendSecurityHeaders();

        if (config('app.force_https') && !Request::isHttps()) {
            $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            return;
        }

        try {
            Session::start();
            Migrator::ensure();
            self::runAutomation();
            $router = new Router();
            (require APP_ROOT . '/app/routes.php')($router);
            $router->dispatch(Request::method(), Request::path());
        } catch (HttpException $e) {
            self::renderError($e->status, $e->getMessage());
        } catch (PDOException $e) {
            Logger::error('Database error: ' . $e->getMessage(), ['path' => Request::path()]);
            self::renderError(503, 'Tidak dapat terhubung ke database atau query gagal. Periksa konfigurasi database (.env) dan pastikan schema.sql sudah diimpor.', $e);
        } catch (Throwable $e) {
            Logger::error(get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine(), 'path' => Request::path()]);
            self::renderError(500, 'Terjadi kesalahan pada server. Kejadian ini sudah dicatat di log.', $e);
        }
    }

    /**
     * Otomasi (notifikasi & status overdue) ikut berjalan saat aplikasi dipakai,
     * maksimal sekali per interval. Kegagalan otomasi tidak boleh mengganggu halaman.
     */
    private static function runAutomation(): void
    {
        if (!Auth::check()) {
            return;
        }
        try {
            \App\Services\Automation::runIfDue();
        } catch (Throwable $e) {
            Logger::error('Automation: ' . $e->getMessage());
        }
    }

    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        header('Cross-Origin-Opener-Policy: same-origin');
        if (Request::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function renderError(int $status, string $message, ?Throwable $e = null): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
        }
        if (Request::isAjax()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }
        $debug = (bool) config('app.debug') && $e !== null;
        try {
            $loggedIn = $status !== 503 && Auth::check();
        } catch (Throwable) {
            $loggedIn = false;
        }
        try {
            echo View::render('errors/error', [
                'title'   => $status . ' — ' . self::statusTitle($status),
                'status'  => $status,
                'heading' => self::statusTitle($status),
                'message' => $message,
                'trace'   => $debug ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString() : null,
                'loggedIn' => $loggedIn,
            ], 'layouts/auth');
        } catch (Throwable $inner) {
            echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }

    private static function statusTitle(int $status): string
    {
        return match ($status) {
            401 => 'Perlu login',
            403 => 'Akses ditolak',
            404 => 'Tidak ditemukan',
            405 => 'Metode tidak diizinkan',
            419 => 'Sesi kedaluwarsa',
            429 => 'Terlalu banyak percobaan',
            503 => 'Layanan tidak tersedia',
            default => 'Terjadi kesalahan',
        };
    }
}
