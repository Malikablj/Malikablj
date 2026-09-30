<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Basis repository: semua query melalui prepared statement.
 * Nama tabel/kolom hanya berasal dari kode (tidak pernah dari input user).
 */
abstract class Repository
{
    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute(self::normalize($params));
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function many(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute(self::normalize($params));

        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute(self::normalize($params));
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    protected function run(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute(self::normalize($params));

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function insertRow(string $table, array $data): int
    {
        self::assertIdentifiers([$table, ...array_keys($data)]);
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $this->run($sql, array_values($data));

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function updateRow(string $table, int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        self::assertIdentifiers([$table, ...array_keys($data)]);
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($data)));
        $this->run("UPDATE {$table} SET {$sets} WHERE id = ?", [...array_values($data), $id]);
    }

    protected static function limit(int $page, int $perPage): string
    {
        $perPage = max(1, min(200, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;

        return sprintf(' LIMIT %d OFFSET %d', $perPage, $offset);
    }

    /**
     * Escape karakter wildcard LIKE agar pencarian tidak bisa dimanipulasi.
     */
    protected static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int|string, mixed>
     */
    private static function normalize(array $params): array
    {
        return array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, $params);
    }

    /**
     * @param list<string> $identifiers
     */
    private static function assertIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
                throw new InvalidArgumentException('Identifier SQL tidak valid: ' . $identifier);
            }
        }
    }
}
