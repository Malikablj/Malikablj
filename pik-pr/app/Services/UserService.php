<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Role;
use App\Repositories\MasterDataRepository;
use App\Repositories\UserRepository;

/**
 * Pengelolaan user oleh Admin / Super Admin.
 * - Hanya Super Admin yang boleh membuat/mengubah akun Super Admin.
 * - User tidak dapat menonaktifkan atau menurunkan role dirinya sendiri.
 * - User tidak pernah dihapus permanen (data PR merujuk ke user), cukup dinonaktifkan.
 */
final class UserService
{
    private UserRepository $users;
    private AuditService $audit;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->audit = new AuditService();
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function create(array $actor, array $input): int
    {
        $data = $this->validate($actor, $input, null);

        return Database::transaction(function () use ($actor, $data): int {
            $id = $this->users->create($data);
            $this->audit->log((int) $actor['id'], 'user.create', 'user', $id, null, $data);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function update(array $actor, int $id, array $input): void
    {
        $user = $this->findManageable($actor, $id);
        $data = $this->validate($actor, $input, $user);

        Database::transaction(function () use ($actor, $user, $data): void {
            $this->users->update((int) $user['id'], $data);
            [$old, $new] = AuditService::diff($user, $data);
            $this->audit->log((int) $actor['id'], 'user.update', 'user', (int) $user['id'], $old, $new);
        });
    }

    /**
     * Aktifkan / nonaktifkan user.
     *
     * @param array<string, mixed> $actor
     */
    public function toggleActive(array $actor, int $id): bool
    {
        $user = $this->findManageable($actor, $id);
        if ((int) $user['id'] === (int) $actor['id']) {
            throw new BusinessRuleException('Anda tidak dapat menonaktifkan akun sendiri.');
        }
        $active = !(bool) $user['is_active'];
        if (!$active && $user['role'] === Role::SuperAdmin->value && $this->users->countByRole(Role::SuperAdmin->value) <= 1) {
            throw new BusinessRuleException('Minimal harus ada satu Super Admin aktif.');
        }

        Database::transaction(function () use ($actor, $user, $active): void {
            $this->users->update((int) $user['id'], ['is_active' => $active ? 1 : 0]);
            $this->audit->log((int) $actor['id'], $active ? 'user.activate' : 'user.deactivate', 'user', (int) $user['id'], ['is_active' => (int) $user['is_active']], ['is_active' => $active ? 1 : 0]);
        });

        return $active;
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function findManageable(array $actor, int $id): array
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw new HttpException(404);
        }
        if ($user['role'] === Role::SuperAdmin->value && $actor['role'] !== Role::SuperAdmin->value) {
            throw new HttpException(403, 'Hanya Super Admin yang dapat mengelola akun Super Admin.');
        }

        return $user;
    }

    /**
     * Role yang boleh diberikan oleh actor.
     *
     * @param array<string, mixed> $actor
     * @return array<string, string>
     */
    public static function assignableRoles(array $actor): array
    {
        $roles = Role::labels();
        if ($actor['role'] !== Role::SuperAdmin->value) {
            unset($roles[Role::SuperAdmin->value]);
        }

        return $roles;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validate(array $actor, array $input, ?array $existing): array
    {
        $v = new Validator($input);
        $v->required('name', 'Nama')->maxLength('name', 100, 'Nama')
            ->required('email', 'Email')->email('email', 'Email')->maxLength('email', 190, 'Email')
            ->required('role', 'Role')->in('role', array_keys(self::assignableRoles($actor)), 'Role')
            ->maxLength('job_title', 100, 'Jabatan');

        $email = mb_strtolower($v->value('email'));
        if ($email !== '' && $this->users->emailExists($email, $existing !== null ? (int) $existing['id'] : null)) {
            $v->add('email', 'Email sudah digunakan user lain.');
        }

        $departmentId = (int) $v->value('department_id');
        if ($departmentId > 0 && MasterDataRepository::departments()->find($departmentId) === null) {
            $v->add('department_id', 'Department tidak valid.');
        }
        $role = $v->value('role');
        if ($departmentId <= 0 && in_array($role, [Role::Requester->value, Role::Approver->value], true)) {
            $v->add('department_id', 'Department wajib diisi untuk Requester dan Approver.');
        }

        $password = is_scalar($input['password'] ?? null) ? (string) $input['password'] : '';
        if ($existing === null || $password !== '') {
            $problem = AuthService::passwordProblem($password);
            if ($problem !== null) {
                $v->add('password', $existing === null && $password === '' ? 'Password wajib diisi.' : $problem);
            }
        }

        if ($existing !== null && (int) $existing['id'] === (int) $actor['id'] && $role !== $existing['role']) {
            $v->add('role', 'Anda tidak dapat mengubah role akun sendiri.');
        }
        $v->throwIfFailed();

        $data = [
            'name' => $v->value('name'),
            'email' => $email,
            'role' => $role,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'job_title' => $v->value('job_title') !== '' ? $v->value('job_title') : null,
        ];
        if ($password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($existing === null) {
            $data['is_active'] = 1;
        }

        return $data;
    }

    /**
     * Dipakai seeder: lempar error validasi dalam bentuk teks.
     */
    public static function describe(ValidationException $e): string
    {
        return implode(' ', $e->errors);
    }
}
