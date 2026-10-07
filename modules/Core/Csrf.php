<?php
declare(strict_types=1);

namespace App\Core;

/** Proteksi CSRF: token per sesi, dicek dengan hash_equals pada setiap request yang mengubah data. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function isValid(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    /** @throws CsrfException */
    public static function verify(): void
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!is_string($token) || !self::isValid($token)) {
            throw new CsrfException(I18n::t('error.csrf'));
        }
    }

    /** Ganti token (setelah login/logout). */
    public static function rotate(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
}
