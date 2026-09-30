<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    private const SELECT = 'SELECT u.id, u.name, u.email, u.password_hash, u.role, u.department_id, u.job_title,
            u.is_active, u.last_login_at, u.created_at, u.updated_at,
            d.name AS department_name, d.code AS department_code
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id';

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE u.id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->one(self::SELECT . ' WHERE u.email = ?', [mb_strtolower(trim($email))]);
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return (bool) $this->scalar(
            'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?',
            [mb_strtolower(trim($email)), $exceptId ?? 0],
        );
    }

    /**
     * @param array{q?: string, role?: string, status?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage = 20): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            $params[] = self::like($filters['q']);
            $params[] = self::like($filters['q']);
        }
        if (($filters['role'] ?? '') !== '') {
            $where[] = 'u.role = ?';
            $params[] = $filters['role'];
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'u.is_active = ?';
            $params[] = $filters['status'] === 'active' ? 1 : 0;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        return [
            'rows' => $this->many(self::SELECT . $whereSql . ' ORDER BY u.is_active DESC, u.name' . self::limit($page, $perPage), $params),
            'total' => (int) $this->scalar('SELECT COUNT(*) FROM users u' . $whereSql, $params),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return $this->many(self::SELECT . ' WHERE u.is_active = 1 ORDER BY u.name');
    }

    /**
     * User aktif yang dapat menjadi approver (role approver/admin/super admin).
     *
     * @return list<array<string, mixed>>
     */
    public function activeApproverCandidates(): array
    {
        return $this->many(self::SELECT . " WHERE u.is_active = 1 AND u.role IN ('approver', 'admin', 'super_admin') ORDER BY u.name");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requesters(): array
    {
        return $this->many(self::SELECT . " WHERE u.role = 'requester' ORDER BY u.name");
    }

    /**
     * @param list<int> $excludeIds
     * @return list<array<string, mixed>>
     */
    public function activeByRole(string $role, ?int $departmentId, array $excludeIds = []): array
    {
        $sql = self::SELECT . ' WHERE u.is_active = 1 AND u.role = ?';
        $params = [$role];
        if ($departmentId !== null) {
            $sql .= ' AND u.department_id = ?';
            $params[] = $departmentId;
        }
        if ($excludeIds !== []) {
            $sql .= ' AND u.id NOT IN (' . implode(', ', array_fill(0, count($excludeIds), '?')) . ')';
            array_push($params, ...$excludeIds);
        }

        return $this->many($sql . ' ORDER BY u.name', $params);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow('users', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateRow('users', $id, $data);
    }

    public function touchLastLogin(int $id): void
    {
        $this->run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public function countByRole(string $role, bool $activeOnly = true): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM users WHERE role = ?' . ($activeOnly ? ' AND is_active = 1' : ''),
            [$role],
        );
    }
}
