<?php
declare(strict_types=1);

namespace App\Notification;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Settings;

/**
 * Notifikasi web + antrean email (PRD §7.3, Lampiran C).
 *  - Teks dibuat dalam bahasa masing-masing penerima.
 *  - dedupe_key mencegah notifikasi yang sama dibuat berulang (UNIQUE per user).
 *  - Email HANYA dimasukkan antrean (notification_deliveries); pengiriman SMTP dilakukan
 *    cron/notifications.php sehingga kegagalan email tidak pernah menggagalkan transaksi bisnis.
 */
final class Notifier
{
    /** Jenis yang boleh dikirim lewat email (kolom Email = Ya pada PRD §7.3). */
    public const EMAIL_TYPES = ['project_assigned', 'project_overdue', 'npr_submitted', 'npr_returned', 'hold_reminder'];

    /**
     * @param list<int> $userIds
     * @param array<string,string|int|float> $params parameter teks (tidak diterjemahkan: nama project, part, dll.)
     * @param array<string,mixed> $data data tambahan
     * @return int jumlah notifikasi baru
     */
    public static function send(
        array $userIds,
        string $type,
        string $titleKey,
        string $bodyKey,
        array $params = [],
        ?string $link = null,
        ?int $projectId = null,
        ?int $processId = null,
        ?string $dedupeKey = null,
        ?int $excludeUserId = null,
        array $data = [],
    ): int {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0 && $id !== $excludeUserId)));
        if ($userIds === []) {
            return 0;
        }
        $users = Db::fetchAll('SELECT id, name, email, language FROM users WHERE is_active = 1 AND id IN ' . Db::in($userIds), $userIds);
        $created = 0;
        $emailOn = self::emailEnabledFor($type);
        foreach ($users as $u) {
            $lang = (string) $u['language'];
            $title = I18n::t($titleKey, $params, $lang);
            $body = I18n::t($bodyKey, $params, $lang);
            $inserted = Db::execute(
                'INSERT IGNORE INTO notifications (user_id, type, title, body, link, project_id, process_id, data_json, dedupe_key, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $u['id'], $type, mb_substr($title, 0, 255), $body, $link, $projectId, $processId,
                 $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $dedupeKey, Clock::nowString()]
            );
            if ($inserted === 0) {
                continue; // duplikat (dedupe)
            }
            $created++;
            $notificationId = (int) Db::pdo()->lastInsertId();
            if ($emailOn && filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) {
                self::queueEmail($notificationId, (int) $u['id'], (string) $u['email'], $title, $body, $link, $lang,
                    $dedupeKey !== null ? 'n:' . $u['id'] . ':' . $dedupeKey : null);
            }
        }
        return $created;
    }

    public static function emailEnabledFor(string $type): bool
    {
        if (!in_array($type, self::EMAIL_TYPES, true) || !Settings::bool('mail.enabled', false)) {
            return false;
        }
        $enabled = Settings::json('mail.types_enabled');
        return !array_key_exists($type, $enabled) || (bool) $enabled[$type];
    }

    /** Masukkan email ke antrean (dipakai juga untuk ringkasan harian). */
    public static function queueEmail(?int $notificationId, int $userId, string $to, string $subject, string $body, ?string $link, string $lang, ?string $dedupeKey = null): void
    {
        $html = EmailTemplate::render($subject, $body, $link, $lang);
        Db::execute(
            'INSERT IGNORE INTO notification_deliveries (notification_id, user_id, channel, to_email, subject, body_html, body_text, status, attempts, max_attempts, next_attempt_at, dedupe_key, created_at)
             VALUES (?, ?, \'email\', ?, ?, ?, ?, \'pending\', 0, ?, ?, ?, ?)',
            [$notificationId, $userId, $to, mb_substr($subject, 0, 255), $html, EmailTemplate::text($body, $link, $lang),
             (int) Config::get('mail.max_attempts', 5), Clock::nowString(), $dedupeKey, Clock::nowString()]
        );
    }

    /** @return list<int> user aktif dengan role tertentu */
    public static function usersWithRole(string ...$roles): array
    {
        return array_map('intval', Db::column(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ' . Db::in($roles),
            $roles
        ));
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
    }
}
