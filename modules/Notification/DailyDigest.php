<?php
declare(strict_types=1);

namespace App\Notification;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Scheduling\WorkingCalendar;

/**
 * Ringkasan overdue harian (PRD §7.3): satu notifikasi + satu email per penerima (PIC proses dan NPD PIC),
 * hanya hari kerja, berisi proses yang sudah overdue sebelum hari ini (hari pertama dikirim terpisah).
 */
final class DailyDigest
{
    public function __construct(private OverdueService $overdue = new OverdueService(), private ?WorkingCalendar $cal = null)
    {
    }

    /** @return array{recipients:int,items:int,skipped:bool} */
    public function run(?string $today = null): array
    {
        $today ??= Clock::todayString();
        $cal = $this->cal ?? WorkingCalendar::fromDb();
        if (!$cal->isWorkingDay($today)) {
            return ['recipients' => 0, 'items' => 0, 'skipped' => true];
        }
        $by = [];
        $items = 0;
        foreach ($this->overdue->overdueProcesses([], $today) as $r) {
            if ($r['overdue_since'] >= $today) {
                continue; // hari pertama → email "overdue hari pertama"
            }
            $items++;
            foreach (array_unique(array_filter([(int) $r['pic_user_id'], (int) $r['npd_pic_id']])) as $uid) {
                $by[$uid][] = $r;
            }
        }
        $sent = 0;
        foreach ($by as $uid => $list) {
            $user = Db::fetch('SELECT id, name, email, language, is_active FROM users WHERE id = ?', [$uid]);
            if (!$user || (int) $user['is_active'] !== 1) {
                continue;
            }
            $lang = (string) $user['language'];
            $lines = array_map(static fn ($r) => '• ' . $r['project_code'] . ' › ' . ($r['part_name'] ? $r['part_name'] . ' › ' : '') . $r['process_label']
                . ' · PIC: ' . ($r['pic_name'] ?? '–') . ' · ' . I18n::t('notif.days_late', ['days' => $r['overdue_days']], $lang), $list);
            $count = count($list);
            $title = I18n::t('notif.overdue_daily.title', ['count' => $count, 'date' => I18n::date($today, $lang)], $lang);
            $body = I18n::t('notif.overdue_daily.body', ['count' => $count], $lang) . "\n" . implode("\n", $lines);
            $dedupe = 'overdue_daily:' . $uid . ':' . $today;
            $inserted = Db::execute(
                'INSERT IGNORE INTO notifications (user_id, type, title, body, link, data_json, dedupe_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, 'project_overdue', mb_substr($title, 0, 255), $body, 'dashboard.php#overdue', json_encode(['processes' => array_column($list, 'id')]), $dedupe, Clock::nowString()]
            );
            if ($inserted === 0) {
                continue;
            }
            $sent++;
            if (Notifier::emailEnabledFor('project_overdue') && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
                Notifier::queueEmail((int) Db::pdo()->lastInsertId(), $uid, (string) $user['email'], $title, $body, 'dashboard.php', $lang, 'mail:' . $dedupe);
            }
        }
        return ['recipients' => $sent, 'items' => $items, 'skipped' => false];
    }
}
