<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Code;
use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

final class User extends Model
{
    public const TABLE = 'users';
    public const ENTITY = 'user';
    public const LABEL = 'email';
    protected const TRACK_USER = false;

    /**
     * Buat user baru (password di-hash dengan password_hash).
     * @param array{name:string,email:string,role:string,password:string,is_active?:int,must_change_password?:int} $data
     */
    public static function createWithPassword(array $data): int
    {
        $email = mb_strtolower(trim($data['email']));
        if (Database::fetchValue('SELECT 1 FROM users WHERE email = :email', ['email' => $email])) {
            throw new DomainException('Email sudah terdaftar.');
        }
        $row = [
            'code'                 => Code::generate('users'),
            'name'                 => trim($data['name']),
            'email'                => $email,
            'password_hash'        => password_hash($data['password'], PASSWORD_DEFAULT),
            'role'                 => $data['role'],
            'is_active'            => (int) ($data['is_active'] ?? 1),
            'must_change_password' => (int) ($data['must_change_password'] ?? 0),
            'password_changed_at'  => date('Y-m-d H:i:s'),
        ];
        $id = Database::insert('users', $row);
        unset($row['password_hash']);
        Audit::log('create', self::ENTITY, $id, $email, Audit::snapshot($row));
        return $id;
    }

    public static function setPassword(int $id, string $password, bool $mustChange = false): void
    {
        Database::update('users', [
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => $mustChange ? 1 : 0,
            'password_changed_at'  => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        $user = self::find($id);
        Audit::log($mustChange ? 'password_reset' : 'password_change', self::ENTITY, $id, (string) ($user['email'] ?? ''));
    }

    public static function activeAdminCount(): int
    {
        return (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'Admin' AND is_active = 1");
    }

    /** @return array<int,string> user aktif untuk pilihan PIC */
    public static function picOptions(): array
    {
        return self::options('name', 'is_active = 1');
    }

    /** @return list<array<string,mixed>> */
    public static function activeByRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($roles) as $i => $role) {
            $placeholders[] = ':r' . $i;
            $params['r' . $i] = $role;
        }
        return Database::fetchAll('SELECT id, name, email, role FROM users WHERE is_active = 1 AND role IN (' . implode(',', $placeholders) . ')', $params);
    }

    public static function paginate(string $search, string $role, string $status, int $page): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(name LIKE :q1 OR email LIKE :q2)';
            $params['q1'] = Database::like($search);
            $params['q2'] = Database::like($search);
        }
        if ($role !== '') {
            $where[] = 'role = :role';
            $params['role'] = $role;
        }
        if ($status === 'active') {
            $where[] = 'is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'is_active = 0';
        }
        return Paginator::query(
            'SELECT id, code, name, email, role, is_active, must_change_password, last_login_at, created_at FROM users WHERE ' . implode(' AND ', $where),
            $params,
            'is_active DESC, name ASC',
            $page
        );
    }
}
