<?php
declare(strict_types=1);

namespace App\User;

use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\Settings;
use App\Core\User;
use App\Core\ValidationException;

/**
 * Manajemen user (PRD §2.4): Admin membuat, mengubah, menonaktifkan, mereset password.
 * User mengganti password sendiri dan preferensi (bahasa, tema).
 * Tidak ada penghapusan user — akun nonaktif tetap tampil di riwayat & audit.
 */
final class UserService
{
    public const THEMES = ['system', 'light', 'dark'];

    /** @return list<array<string,mixed>> */
    public function list(?string $search = null, ?string $role = null, ?string $status = null): array
    {
        $sql = 'SELECT u.id, u.name, u.email, u.job_title, u.phone, u.is_active, u.last_login_at, u.created_at,
                       u.must_change_password, r.code AS role_code
                FROM users u JOIN roles r ON r.id = u.role_id WHERE 1 = 1';
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($role !== null && $role !== '') {
            $sql .= ' AND r.code = ?';
            $params[] = $role;
        }
        if ($status === 'active') {
            $sql .= ' AND u.is_active = 1';
        } elseif ($status === 'inactive') {
            $sql .= ' AND u.is_active = 0';
        }
        $sql .= ' ORDER BY u.is_active DESC, r.sort_order, u.name';
        return Db::fetchAll($sql, $params);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = Db::fetch(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );
        if (!$row) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        unset($row['password_hash']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function roles(): array
    {
        return Db::fetchAll('SELECT id, code, name_id, name_en FROM roles ORDER BY sort_order');
    }

    /** @return list<array{id:int,name:string}> pengguna aktif per role (untuk pilihan PIC) */
    public function activeByRole(string ...$roleCodes): array
    {
        if ($roleCodes === []) {
            return Db::fetchAll('SELECT u.id, u.name, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.name');
        }
        return Db::fetchAll(
            'SELECT u.id, u.name, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.code IN ' . Db::in($roleCodes) . ' ORDER BY u.name',
            $roleCodes
        );
    }

    /**
     * @param array<string,mixed> $data name, email, role, job_title, phone, password
     */
    public function create(User $actor, array $data): int
    {
        Gate::authorize($actor, 'user.manage');
        $clean = $this->validate($data, null, true);
        $id = Db::transaction(function () use ($actor, $clean, $data): int {
            $id = Db::insert('users', [
                'role_id' => $clean['role_id'],
                'name' => $clean['name'],
                'email' => $clean['email'],
                'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
                'job_title' => $clean['job_title'],
                'phone' => $clean['phone'],
                'is_active' => 1,
                'language' => $clean['language'],
                'must_change_password' => 1,
                'password_changed_at' => Clock::nowString(),
                'created_by' => $actor->id,
            ]);
            AuditLogger::log('user.create', 'user', $id, null, $this->auditView($clean), null, null, $actor);
            return $id;
        });
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, int $id, array $data): void
    {
        Gate::authorize($actor, 'user.manage');
        $before = $this->get($id);
        $clean = $this->validate($data, $id, false);
        if ($id === $actor->id && (int) $clean['role_id'] !== (int) $before['role_id']) {
            throw new BusinessRuleException(I18n::t('user.cannot_change_own_role'));
        }
        if ((int) $clean['role_id'] !== (int) $before['role_id'] && $before['role_code'] === 'admin') {
            $this->assertNotLastAdmin($id);
        }
        Db::transaction(function () use ($actor, $id, $clean, $before): void {
            Db::update('users', [
                'role_id' => $clean['role_id'],
                'name' => $clean['name'],
                'email' => $clean['email'],
                'job_title' => $clean['job_title'],
                'phone' => $clean['phone'],
                'language' => $clean['language'],
            ], ['id' => $id]);
            [$old, $new] = AuditLogger::diff($this->auditView($before), $this->auditView($clean));
            if ($new !== []) {
                AuditLogger::log('user.update', 'user', $id, $old, $new, null, null, $actor);
            }
        });
        Gate::flush();
    }

    public function setActive(User $actor, int $id, bool $active, ?string $reason = null): void
    {
        Gate::authorize($actor, 'user.manage');
        $before = $this->get($id);
        if (!$active && $id === $actor->id) {
            throw new BusinessRuleException(I18n::t('user.cannot_deactivate_self'));
        }
        if (!$active && $before['role_code'] === 'admin') {
            $this->assertNotLastAdmin($id);
        }
        if ((bool) $before['is_active'] === $active) {
            return;
        }
        Db::transaction(function () use ($actor, $id, $active, $reason): void {
            Db::update('users', ['is_active' => $active ? 1 : 0, 'deactivated_at' => $active ? null : Clock::nowString()], ['id' => $id]);
            AuditLogger::log($active ? 'user.activate' : 'user.deactivate', 'user', $id, ['is_active' => !$active], ['is_active' => $active], $reason, null, $actor);
        });
    }

    public function resetPassword(User $actor, int $id, string $newPassword): void
    {
        Gate::authorize($actor, 'user.manage');
        $this->get($id);
        $this->assertPasswordStrength($newPassword, 'password');
        Db::transaction(function () use ($actor, $id, $newPassword): void {
            Db::update('users', [
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'must_change_password' => 1,
                'password_changed_at' => Clock::nowString(),
            ], ['id' => $id]);
            AuditLogger::log('user.reset_password', 'user', $id, null, null, null, null, $actor);
        });
    }

    public function changeOwnPassword(User $user, string $current, string $new, string $confirm): void
    {
        $hash = (string) Db::value('SELECT password_hash FROM users WHERE id = ?', [$user->id]);
        if (!password_verify($current, $hash)) {
            throw new ValidationException(['current_password' => I18n::t('user.current_password_wrong')]);
        }
        if ($new !== $confirm) {
            throw new ValidationException(['password_confirmation' => I18n::t('user.password_mismatch')]);
        }
        if (password_verify($new, $hash)) {
            throw new ValidationException(['new_password' => I18n::t('user.password_same')]);
        }
        $this->assertPasswordStrength($new, 'new_password');
        Db::transaction(function () use ($user, $new): void {
            Db::update('users', [
                'password_hash' => password_hash($new, PASSWORD_DEFAULT),
                'must_change_password' => 0,
                'password_changed_at' => Clock::nowString(),
            ], ['id' => $user->id]);
            AuditLogger::log('user.change_password', 'user', $user->id, null, null, null, null, $user);
        });
    }

    public function updatePreferences(User $user, ?string $language, ?string $theme): void
    {
        $data = [];
        if ($language !== null) {
            if (!in_array($language, I18n::SUPPORTED, true)) {
                throw new ValidationException(['language' => I18n::t('validation.invalid')]);
            }
            $data['language'] = $language;
        }
        if ($theme !== null) {
            if (!in_array($theme, self::THEMES, true)) {
                throw new ValidationException(['theme' => I18n::t('validation.invalid')]);
            }
            $data['theme'] = $theme;
        }
        if ($data !== []) {
            Db::update('users', $data, ['id' => $user->id]);
        }
    }

    /** Membuat Admin pertama (CLI bin/create-admin.php) — tanpa aktor. */
    public function createInitialAdmin(string $name, string $email, string $password, ?string $jobTitle = null): int
    {
        $roleId = (int) Db::value("SELECT id FROM roles WHERE code = 'admin'");
        if ($roleId === 0) {
            throw new BusinessRuleException('Role admin belum ada. Jalankan database/seed.sql terlebih dahulu.');
        }
        $clean = $this->validate(['name' => $name, 'email' => $email, 'role' => 'admin', 'job_title' => $jobTitle, 'password' => $password], null, true);
        $id = Db::insert('users', [
            'role_id' => $roleId,
            'name' => $clean['name'],
            'email' => $clean['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'job_title' => $clean['job_title'],
            'is_active' => 1,
            'language' => 'id',
            'password_changed_at' => Clock::nowString(),
        ]);
        AuditLogger::log('user.create_initial_admin', 'user', $id, null, $this->auditView($clean));
        return $id;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{name:string,email:string,role_id:int,role_code:string,job_title:?string,phone:?string,language:string}
     */
    private function validate(array $data, ?int $id, bool $requirePassword): array
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $roleCode = (string) ($data['role'] ?? '');
        $jobTitle = trim((string) ($data['job_title'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $language = (string) ($data['language'] ?? 'id');

        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = I18n::t('validation.required_max', ['max' => 120]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = I18n::t('validation.email');
        } else {
            $exists = Db::value('SELECT id FROM users WHERE email = ? AND (? IS NULL OR id <> ?)', [$email, $id, $id]);
            if ($exists) {
                $errors['email'] = I18n::t('user.email_taken');
            }
        }
        $roleId = (int) Db::value('SELECT id FROM roles WHERE code = ?', [$roleCode]);
        if ($roleId === 0) {
            $errors['role'] = I18n::t('validation.invalid');
        }
        if (mb_strlen($jobTitle) > 120) {
            $errors['job_title'] = I18n::t('validation.max', ['max' => 120]);
        }
        if (mb_strlen($phone) > 40) {
            $errors['phone'] = I18n::t('validation.max', ['max' => 40]);
        }
        if (!in_array($language, I18n::SUPPORTED, true)) {
            $language = 'id';
        }
        if ($requirePassword) {
            try {
                $this->assertPasswordStrength((string) ($data['password'] ?? ''), 'password');
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'name' => $name,
            'email' => $email,
            'role_id' => $roleId,
            'role_code' => $roleCode,
            'job_title' => $jobTitle !== '' ? $jobTitle : null,
            'phone' => $phone !== '' ? $phone : null,
            'language' => $language,
        ];
    }

    public function assertPasswordStrength(string $password, string $field): void
    {
        $min = max(8, Settings::int('security.password_min_length', 8));
        if (mb_strlen($password) < $min || mb_strlen($password) > 128) {
            throw new ValidationException([$field => I18n::t('user.password_length', ['min' => $min])]);
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException([$field => I18n::t('user.password_complexity')]);
        }
    }

    private function assertNotLastAdmin(int $id): void
    {
        $others = (int) Db::value(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1 AND u.id <> ?",
            [$id]
        );
        if ($others === 0) {
            throw new BusinessRuleException(I18n::t('user.last_admin'));
        }
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    private function auditView(array $u): array
    {
        return [
            'name' => $u['name'] ?? null,
            'email' => $u['email'] ?? null,
            'role' => $u['role_code'] ?? null,
            'job_title' => $u['job_title'] ?? null,
            'phone' => $u['phone'] ?? null,
            'language' => $u['language'] ?? null,
        ];
    }
}
