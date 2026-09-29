<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use PDOException;

/** Notifikasi in-app per user. */
final class Notification
{
    public static function unreadCount(int $userId): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0', ['u' => $userId]);
    }

    /**
     * Kirim notifikasi. Bila dedupe_key sudah pernah dikirim ke user tsb,
     * notifikasi tidak diduplikasi (return false).
     */
    public static function send(int $userId, string $type, string $title, ?string $message = null, ?string $link = null, ?string $entityType = null, ?int $entityId = null, ?string $dedupeKey = null): bool
    {
        try {
            Database::insert('notifications', [
                'user_id'     => $userId,
                'type'        => mb_substr($type, 0, 40),
                'title'       => mb_substr($title, 0, 190),
                'message'     => $message !== null ? mb_substr($message, 0, 500) : null,
                'link'        => $link !== null ? mb_substr($link, 0, 255) : null,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'dedupe_key'  => $dedupeKey !== null ? mb_substr($dedupeKey, 0, 120) : null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false; // duplikat dedupe_key
            }
            throw $e;
        }
    }

    public static function paginateForUser(int $userId, string $filter, int $page): Paginator
    {
        $where = 'user_id = :u';
        if ($filter === 'unread') {
            $where .= ' AND is_read = 0';
        }
        return Paginator::query('SELECT * FROM notifications WHERE ' . $where, ['u' => $userId], 'created_at DESC, id DESC', $page, 30);
    }

    /** @return array<string,mixed>|null */
    public static function findForUser(int $id, int $userId): ?array
    {
        return Database::fetch('SELECT * FROM notifications WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
    }

    public static function markRead(int $id, int $userId): void
    {
        Database::update('notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'id = :id AND user_id = :u AND is_read = 0', ['id' => $id, 'u' => $userId]);
    }

    public static function markAllRead(int $userId): int
    {
        return Database::update('notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'user_id = :u AND is_read = 0', ['u' => $userId]);
    }
}
