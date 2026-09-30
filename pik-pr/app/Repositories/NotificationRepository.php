<?php

declare(strict_types=1);

namespace App\Repositories;

final class NotificationRepository extends Repository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow('notifications', $data);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function forUser(int $userId, bool $unreadOnly, int $page, int $perPage = 20): array
    {
        $where = ' WHERE n.user_id = ?' . ($unreadOnly ? ' AND n.read_at IS NULL' : '');

        return [
            'rows' => $this->many(
                'SELECT n.*, pr.pr_number, pr.status AS pr_status
                FROM notifications n LEFT JOIN purchase_requisitions pr ON pr.id = n.pr_id'
                . $where . ' ORDER BY n.created_at DESC, n.id DESC' . self::limit($page, $perPage),
                [$userId],
            ),
            'total' => (int) $this->scalar('SELECT COUNT(*) FROM notifications n' . $where, [$userId]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public function markRead(int $id, int $userId): void
    {
        $this->run('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$id, $userId]);
    }

    public function markAllRead(int $userId): int
    {
        return $this->run('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPr(int $prId): array
    {
        return $this->many('SELECT * FROM notifications WHERE pr_id = ? ORDER BY id', [$prId]);
    }
}
