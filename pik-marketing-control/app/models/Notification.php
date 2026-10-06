<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use App\Helpers\Permission;
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

    /** @return list<int> user aktif yang role-nya punya permission tsb */
    public static function usersWith(string $permission): array
    {
        $rows = Database::fetchAll('SELECT id, role FROM users WHERE is_active = 1');
        return array_values(array_map(static fn ($r) => (int) $r['id'], array_filter($rows, static fn ($r) => Permission::allows((string) $r['role'], $permission))));
    }

    /**
     * Kirim notifikasi ke sekumpulan user (id duplikat/null diabaikan).
     * @param list<int|null> $userIds
     */
    public static function sendMany(array $userIds, string $type, string $title, ?string $message, ?string $link, ?string $entityType, ?int $entityId, ?string $dedupeKey = null): int
    {
        $sent = 0;
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
            $sent += (int) self::send($uid, $type, $title, $message, $link, $entityType, $entityId, $dedupeKey);
        }
        return $sent;
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
