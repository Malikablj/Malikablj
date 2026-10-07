<?php
declare(strict_types=1);

namespace App\Notification;

use App\Core\Clock;
use App\Core\Db;

/** Pusat notifikasi web milik pengguna (PRD §7.3): daftar, tandai dibaca, buka tautan. */
final class NotificationCenter
{
    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function list(int $userId, bool $unreadOnly, ?string $type, int $page = 1, int $perPage = 30): array
    {
        $w = ['user_id = ?'];
        $p = [$userId];
        if ($unreadOnly) {
            $w[] = 'is_read = 0';
        }
        if ($type !== null && $type !== '' && preg_match('/^[a-z_]{1,40}$/', $type)) {
            $w[] = 'type = ?';
            $p[] = $type;
        }
        $where = implode(' AND ', $w);
        $total = (int) Db::value("SELECT COUNT(*) FROM notifications WHERE $where", $p);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Db::fetchAll("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(100, $perPage)) . " OFFSET $offset", $p);
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<string> jenis notifikasi milik pengguna */
    public function types(int $userId): array
    {
        return Db::column('SELECT DISTINCT type FROM notifications WHERE user_id = ? ORDER BY type', [$userId]);
    }

    public function markRead(int $userId, int $id): ?array
    {
        $n = Db::fetch('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($n && (int) $n['is_read'] === 0) {
            Db::execute('UPDATE notifications SET is_read = 1, read_at = ? WHERE id = ? AND user_id = ?', [Clock::nowString(), $id, $userId]);
        }
        return $n;
    }

    public function markAllRead(int $userId): int
    {
        return Db::execute('UPDATE notifications SET is_read = 1, read_at = ? WHERE user_id = ? AND is_read = 0', [Clock::nowString(), $userId]);
    }

    /** Tautan internal yang aman (relatif terhadap aplikasi) atau null. */
    public static function safeLink(?string $link): ?string
    {
        if ($link === null || $link === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $link) || str_starts_with($link, '//') || str_contains($link, '\\') || preg_match('/[\r\n]/', $link)) {
            return null;
        }
        return ltrim($link, '/');
    }
}
