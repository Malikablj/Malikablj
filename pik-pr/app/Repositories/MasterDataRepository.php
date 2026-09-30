<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

/**
 * Repository generik untuk master data sederhana (departments, suppliers, items).
 */
final class MasterDataRepository extends Repository
{
    /**
     * @param list<string> $searchColumns
     */
    public function __construct(
        private readonly string $table,
        private readonly array $searchColumns,
        private readonly string $orderBy = 'name',
        ?PDO $db = null,
    ) {
        parent::__construct($db);
    }

    public static function departments(): self
    {
        return new self('departments', ['name', 'code']);
    }

    public static function suppliers(): self
    {
        return new self('suppliers', ['name', 'code', 'contact']);
    }

    public static function items(): self
    {
        return new self('items', ['name', 'code', 'description']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allActive(): array
    {
        return $this->many("SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY {$this->orderBy}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->many("SELECT * FROM {$this->table} ORDER BY {$this->orderBy}");
    }

    /**
     * @param array{q?: string, status?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage = 20): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $parts = [];
            foreach ($this->searchColumns as $column) {
                $parts[] = "{$column} LIKE ?";
                $params[] = self::like($filters['q']);
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'is_active = ?';
            $params[] = $filters['status'] === 'active' ? 1 : 0;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        return [
            'rows' => $this->many(
                "SELECT * FROM {$this->table}{$whereSql} ORDER BY is_active DESC, {$this->orderBy}" . self::limit($page, $perPage),
                $params,
            ),
            'total' => (int) $this->scalar("SELECT COUNT(*) FROM {$this->table}{$whereSql}", $params),
        ];
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return (bool) $this->scalar(
            "SELECT COUNT(*) FROM {$this->table} WHERE code = ? AND id <> ?",
            [$code, $exceptId ?? 0],
        );
    }

    public function valueExists(string $column, string $value, ?int $exceptId = null): bool
    {
        return (bool) $this->scalar(
            "SELECT COUNT(*) FROM {$this->table} WHERE {$column} = ? AND id <> ?",
            [$value, $exceptId ?? 0],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow($this->table, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateRow($this->table, $id, $data);
    }

    /**
     * Menghapus permanen. Mengembalikan false bila data masih dipakai (foreign key).
     */
    public function delete(int $id): bool
    {
        try {
            $this->run("DELETE FROM {$this->table} WHERE id = ?", [$id]);

            return true;
        } catch (PDOException $e) {
            // 1451: Cannot delete or update a parent row: a foreign key constraint fails
            if (($e->errorInfo[1] ?? null) === 1451) {
                return false;
            }
            throw $e;
        }
    }
}
