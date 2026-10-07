<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Autentikasi email + password (PRD §2.4).
 * Password hanya diverifikasi dengan password_verify(); hash diperbarui otomatis bila
 * algoritma/biaya bawaan PHP berubah (password_needs_rehash).
 */
final class Auth
{
    private static ?User $current = null;
    private static bool $resolved = false;

    /**
     * Verifikasi kredensial. Mengembalikan User bila berhasil.
     * @throws TooManyAttemptsException|AuthenticationException
     */
    public static function attempt(string $email, string $password, string $ip): User
    {
        $email = mb_strtolower(trim($email));
        $locked = LoginThrottle::lockedSeconds($email, $ip);
        if ($locked > 0) {
            AuditLogger::log('auth.login_locked', 'user', null, null, ['email' => $email]);
            throw new TooManyAttemptsException(I18n::t('auth.locked', ['minutes' => (int) ceil($locked / 60)]), $locked);
        }

        $row = Db::fetch(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ?',
            [$email]
        );
        // Selalu jalankan password_verify agar waktu respons tidak membocorkan keberadaan email.
        $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $valid = password_verify($password, (string) $hash);

        if (!$row || !$valid) {
            LoginThrottle::hit($email, $ip, false);
            AuditLogger::log('auth.login_failed', 'user', $row['id'] ?? null, null, ['email' => $email]);
            throw new AuthenticationException(I18n::t('auth.invalid'));
        }
        if (!(bool) $row['is_active']) {
            LoginThrottle::hit($email, $ip, false);
            AuditLogger::log('auth.login_inactive', 'user', $row['id'], null, ['email' => $email]);
            throw new AuthenticationException(I18n::t('auth.inactive'));
        }

        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => (int) $row['id']]);
        }
        LoginThrottle::hit($email, $ip, true);
        Db::update('users', ['last_login_at' => Clock::nowString(), 'last_login_ip' => $ip], ['id' => (int) $row['id']]);
        $user = User::fromRow($row);
        AuditLogger::log('auth.login', 'user', $user->id, null, null, null, null, $user);
        return $user;
    }

    /** Simpan user ke sesi (setelah attempt berhasil). */
    public static function login(User $user): void
    {
        Session::regenerate();
        Csrf::rotate();
        $_SESSION['user_id'] = $user->id;
        $_SESSION['login_at'] = time();
        self::$current = $user;
        self::$resolved = true;
        RequestContext::set($user);
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            AuditLogger::log('auth.logout', 'user', $user->id, null, null, null, null, $user);
        }
        Session::destroy();
        self::$current = null;
        self::$resolved = true;
        RequestContext::set(null);
    }

    /** User saat ini dari sesi; akun yang dinonaktifkan langsung kehilangan akses. */
    public static function user(): ?User
    {
        if (self::$resolved) {
            return self::$current;
        }
        self::$resolved = true;
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $user = User::find((int) $id);
        if ($user === null || !$user->isActive) {
            unset($_SESSION['user_id']);
            return null;
        }
        self::$current = $user;
        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Untuk test / CLI. */
    public static function setUser(?User $user): void
    {
        self::$current = $user;
        self::$resolved = true;
        RequestContext::set($user);
    }

    public static function reset(): void
    {
        self::$current = null;
        self::$resolved = false;
    }
}
