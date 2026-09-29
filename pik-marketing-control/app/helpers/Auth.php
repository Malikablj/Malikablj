<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Autentikasi berbasis session.
 * - password_hash / password_verify (tidak ada password plaintext)
 * - pembatasan percobaan login (brute force) per email dan per IP
 * - session id diganti saat login (anti session fixation)
 * - user dimuat ulang dari database di setiap request, sehingga
 *   penonaktifan user / perubahan role langsung berlaku.
 */
final class Auth
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $id = Session::get('user_id');
        if (!is_int($id) || $id <= 0) {
            return self::$user = null;
        }
        $user = Database::fetch(
            'SELECT id, code, name, email, role, is_active, must_change_password, last_login_at FROM users WHERE id = :id',
            ['id' => $id]
        );
        if ($user === null || (int) $user['is_active'] !== 1) {
            Session::forget('user_id');
            return self::$user = null;
        }
        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function role(): ?string
    {
        $user = self::user();
        return $user ? (string) $user['role'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function can(string $permission): bool
    {
        return Permission::allows(self::role(), $permission);
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'Admin';
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = Request::ip();
        $security = config('app.security');
        $max = (int) $security['login_max_attempts'];
        $window = (int) $security['login_lockout_minutes'];

        if (self::isThrottled($email, $ip, $max, $window)) {
            Audit::log('login_blocked', 'user', null, $email);
            return ['ok' => false, 'error' => "Terlalu banyak percobaan login gagal. Coba lagi dalam {$window} menit."];
        }

        $user = Database::fetch('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        // password_verify tetap dijalankan walau user tidak ada, untuk menyamakan waktu respons
        $hash = $user['password_hash'] ?? '$2y$12$5IWX4yp/Z4n4seSZ0bJeQOeGL.vpek5NaYFGLyANWMJdrLAAVg442';
        $valid = password_verify($password, (string) $hash);

        if ($user === null || !$valid) {
            self::recordAttempt($email, $ip, false);
            Audit::log('login_failed', 'user', $user ? (int) $user['id'] : null, $email);
            return ['ok' => false, 'error' => 'Email atau password salah.'];
        }
        if ((int) $user['is_active'] !== 1) {
            self::recordAttempt($email, $ip, false);
            Audit::log('login_failed', 'user', (int) $user['id'], $email . ' (nonaktif)');
            return ['ok' => false, 'error' => 'Akun Anda dinonaktifkan. Hubungi Admin.'];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        }

        self::recordAttempt($email, $ip, true);
        self::loginUsingId((int) $user['id']);
        Database::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $user['id']]);
        Audit::log('login', 'user', (int) $user['id'], (string) $user['email']);
        return ['ok' => true];
    }

    public static function loginUsingId(int $userId): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set('user_id', $userId);
        self::$resolved = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('logout', 'user', self::id(), (string) (self::user()['email'] ?? ''));
        }
        Session::destroy();
        self::$resolved = false;
        self::$user = null;
    }

    /** Reset cache user (dipakai test & setelah update profil). */
    public static function refresh(): void
    {
        self::$resolved = false;
        self::$user = null;
    }

    private static function isThrottled(string $email, string $ip, int $max, int $windowMinutes): bool
    {
        $since = date('Y-m-d H:i:s', time() - $windowMinutes * 60);
        $byEmail = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE email = :email AND success = 0 AND attempted_at >= :since',
            ['email' => $email, 'since' => $since]
        );
        // batas per IP lebih longgar (beberapa user bisa berbagi IP kantor)
        $byIp = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND success = 0 AND attempted_at >= :since',
            ['ip' => $ip, 'since' => $since]
        );
        return $byEmail >= $max || $byIp >= $max * 4;
    }

    private static function recordAttempt(string $email, string $ip, bool $success): void
    {
        Database::insert('login_attempts', [
            'email'        => mb_substr($email, 0, 190),
            'ip_address'   => $ip,
            'success'      => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
        if ($success) {
            // login berhasil menghapus hitungan gagal untuk email tsb.
            Database::delete('login_attempts', 'email = :email AND success = 0', ['email' => $email]);
        }
    }

    /** @return string|null pesan error bila password tidak memenuhi kebijakan */
    public static function passwordPolicyError(string $password): ?string
    {
        $min = (int) config('app.security.password_min_length');
        if (mb_strlen($password) < $min) {
            return "Password minimal {$min} karakter.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password harus mengandung huruf dan angka.';
        }
        if (mb_strlen($password) > 200) {
            return 'Password terlalu panjang.';
        }
        return null;
    }
}
