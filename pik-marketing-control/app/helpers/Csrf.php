<?php

declare(strict_types=1);

namespace App\Helpers;

/** Proteksi CSRF: satu token acak per session, diverifikasi di setiap POST. */
final class Csrf
{
    public const FIELD = '_token';

    public static function token(): string
    {
        $token = Session::get('_csrf_token');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf_token', $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . self::token() . '">';
    }

    public static function verify(?string $submitted): bool
    {
        $token = Session::get('_csrf_token');
        if (!is_string($token) || $token === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }
        return hash_equals($token, $submitted);
    }

    public static function verifyRequest(): void
    {
        $submitted = $_POST[self::FIELD] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify(is_string($submitted) ? $submitted : null)) {
            throw new HttpException(419);
        }
    }

    /** Buat token baru (mis. setelah login/logout). */
    public static function rotate(): void
    {
        Session::set('_csrf_token', bin2hex(random_bytes(32)));
    }
}
