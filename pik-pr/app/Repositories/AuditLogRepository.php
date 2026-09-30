<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Audit log bersifat append-only: repository ini sengaja hanya menyediakan INSERT dan SELECT.
 */
final class AuditLogRepository extends Repository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow('audit_logs', $data);
    }

    /**
     * @param array<string, string> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage = 30): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($filters['action'] ?? '') !== '') {
            $where[] = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (($filters['entity_type'] ?? '') !== '') {
            $where[] = 'a.entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (($filters['user_id'] ?? '') !== '') {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'a.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'a.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        return [
            'rows' => $this->many(
                'SELECT a.*, u.name AS user_name, u.email AS user_email
                FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id'
                . $whereSql . ' ORDER BY a.id DESC' . self::limit($page, $perPage),
                $params,
            ),
            'total' => (int) $this->scalar('SELECT COUNT(*) FROM audit_logs a' . $whereSql, $params),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT a.*, u.name AS user_name, u.email AS user_email
            FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?',
            [$id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEntity(string $entityType, int $entityId): array
    {
        return $this->many(
            'SELECT a.*, u.name AS user_name
            FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.entity_type = ? AND a.entity_id = ?
            ORDER BY a.id',
            [$entityType, $entityId],
        );
    }

    /**
     * @return list<string>
     */
    public function distinctActions(): array
    {
        return array_column($this->many('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
    }

    /**
     * @return list<string>
     */
    public function distinctEntityTypes(): array
    {
        return array_column($this->many('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type'), 'entity_type');
    }
}
