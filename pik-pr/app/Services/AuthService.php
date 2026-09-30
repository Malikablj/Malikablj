<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BusinessRuleException;
use App\Core\Config;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;

final class AuthService
{
    private UserRepository $users;
    private LoginAttemptRepository $attempts;
    private AuditService $audit;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->attempts = new LoginAttemptRepository();
        $this->audit = new AuditService();
    }

    /**
     * Memverifikasi kredensial. Pesan gagal sengaja dibuat umum agar tidak
     * membocorkan apakah email terdaftar.
     *
     * @return array<string, mixed>
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->required('email', 'Email')->email('email', 'Email')->maxLength('email', 190, 'Email')->required('password', 'Password');
        $v->throwIfFailed();

        $maxAttempts = (int) Config::get('app.login.max_attempts', 5);
        $decay = (int) Config::get('app.login.decay_minutes', 15);
        if ($this->attempts->recentFailures($email, $ip, $decay) >= $maxAttempts) {
            $this->audit->log(null, 'auth.login_locked', 'user', null, null, ['email' => $email]);
            throw new BusinessRuleException("Terlalu banyak percobaan login gagal. Coba lagi dalam {$decay} menit.");
        }

        $user = $this->users->findByEmail($email);
        // Tetap jalankan password_verify walau user tidak ada untuk menyamakan waktu respons.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $valid = password_verify($password, (string) $hash) && $user !== null;

        if (!$valid) {
            $this->attempts->record($email, $ip);
            $this->audit->log($user !== null ? (int) $user['id'] : null, 'auth.login_failed', 'user', $user !== null ? (int) $user['id'] : null, null, ['email' => $email]);
            throw new BusinessRuleException('Email atau password salah.');
        }
        if (!(bool) $user['is_active']) {
            $this->audit->log((int) $user['id'], 'auth.login_inactive', 'user', (int) $user['id'], null, ['email' => $email]);
            throw new BusinessRuleException('Akun Anda dinonaktifkan. Hubungi admin.');
        }

        $this->attempts->clear($email, $ip);
        $this->attempts->prune(24 * 60);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        $this->users->touchLastLogin((int) $user['id']);
        $this->audit->log((int) $user['id'], 'auth.login', 'user', (int) $user['id']);

        return $this->users->find((int) $user['id']) ?? $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function logout(array $user): void
    {
        $this->audit->log((int) $user['id'], 'auth.logout', 'user', (int) $user['id']);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function changePassword(array $user, string $current, string $new, string $confirmation): void
    {
        $fresh = $this->users->find((int) $user['id']);
        $errors = [];
        if ($fresh === null || !password_verify($current, (string) $fresh['password_hash'])) {
            $errors['current_password'] = 'Password saat ini salah.';
        }
        $policy = self::passwordProblem($new);
        if ($policy !== null) {
            $errors['password'] = $policy;
        } elseif ($new !== $confirmation) {
            $errors['password_confirmation'] = 'Konfirmasi password tidak sama.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->users->update((int) $user['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        $this->audit->log((int) $user['id'], 'user.password_change', 'user', (int) $user['id']);
    }

    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 8) {
            return 'Password minimal 8 karakter.';
        }
        if (mb_strlen($password) > 72) {
            return 'Password maksimal 72 karakter.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password harus mengandung huruf dan angka.';
        }

        return null;
    }
}
