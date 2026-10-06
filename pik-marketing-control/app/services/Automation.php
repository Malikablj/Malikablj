<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Logger;
use App\Helpers\Number;
use App\Helpers\Permission;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Setting;

/**
 * Otomasi berkala:
 *   1. status Overdue follow up disinkronkan;
 *   2. notifikasi pengingat (tidak dobel — setiap notifikasi punya dedupe key):
 *      - follow up jatuh hari ini (PIC)
 *      - follow up overdue (PIC)
 *      - delivery terjadwal dalam N hari (PIC marketing customer / pembuat delivery
 *        + semua user PPIC; semua user Marketing bila tidak ada penerima)
 *      - lead mendekati / melewati target closing (PIC lead)
 *   Penerima hanya user aktif yang punya akses ke modul terkait.
 *
 * Dijalankan: otomatis saat aplikasi dipakai (maks. 1x per interval, lihat
 * pengaturan automation_interval_minutes) dan/atau lewat cron: php cron/automation.php
 */
final class Automation
{
    private const LOCK = 'pik_automation';

    /** @return array<string,mixed>|null hasil run, null bila belum waktunya atau sedang berjalan */
    public static function runIfDue(): ?array
    {
        $interval = max(5, Setting::int('automation_interval_minutes', 60));
        $last = Setting::get('automation_last_run');
        if ($last !== null && strtotime($last) !== false && strtotime($last) > time() - $interval * 60) {
            return null;
        }
        return self::run();
    }

    /** @return array<string,mixed>|null */
    public static function run(?string $today = null): ?array
    {
        if ((int) Database::fetchValue('SELECT GET_LOCK(:k, 0)', ['k' => self::LOCK]) !== 1) {
            return null;
        }
        try {
            $today ??= today();
            $follow = FollowUp::refreshOverdue($today);
            $result = [
                'ran_at'             => date('Y-m-d H:i:s'),
                'followups_overdue'  => $follow['overdue'],
                'notifications'      => [
                    'followup_due'      => self::followUpsDue($today),
                    'followup_overdue'  => self::followUpsOverdue($today),
                    'delivery_upcoming' => self::upcomingDeliveries($today),
                    'lead_closing'      => self::leadsClosing($today),
                ],
            ];
            Setting::set('automation_last_run', $result['ran_at'], false);
            Setting::set('automation_last_result', json_encode($result, JSON_UNESCAPED_UNICODE), false);
            return $result;
        } catch (\Throwable $e) {
            Logger::error('Automation gagal: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
            throw $e;
        } finally {
            Database::fetchValue('SELECT RELEASE_LOCK(:k)', ['k' => self::LOCK]);
        }
    }

    /**
     * User aktif (dari daftar id) yang role-nya punya permission.
     * @param list<int|string|null> $ids
     * @return list<int>
     */
    private static function allowed(array $ids, string $perm): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = Database::fetchAll('SELECT id, role FROM users WHERE is_active = 1 AND id IN (' . implode(',', $ids) . ')');
        return array_values(array_map(static fn ($r) => (int) $r['id'], array_filter($rows, static fn ($r) => Permission::allows((string) $r['role'], $perm))));
    }

    /** @return list<int> semua user aktif dengan permission tsb (opsional: hanya role tertentu) */
    private static function usersWith(string $perm, ?string $role = null): array
    {
        $rows = Database::fetchAll('SELECT id, role FROM users WHERE is_active = 1' . ($role !== null ? ' AND role = :r' : ''), $role !== null ? ['r' => $role] : []);
        return array_values(array_map(static fn ($r) => (int) $r['id'], array_filter($rows, static fn ($r) => Permission::allows((string) $r['role'], $perm))));
    }

    private static function followUpsDue(string $today): int
    {
        $sent = 0;
        $rows = Database::fetchAll(
            "SELECT f.id, f.purpose, f.follow_up_time, f.follow_up_type, COALESCE(f.pic_user_id, f.created_by) AS uid, c.name AS customer_name, l.lead_name
             FROM follow_up f LEFT JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id
             WHERE f.follow_up_date = :d AND f.status NOT IN ('Done','Cancelled') AND f.reminder = 1",
            ['d' => $today]
        );
        foreach ($rows as $r) {
            foreach (self::allowed([$r['uid']], 'followups.view') as $uid) {
                $sent += (int) Notification::send($uid, 'followup_due', 'Follow up hari ini: ' . $r['purpose'],
                    trim(($r['customer_name'] ?? $r['lead_name'] ?? '') . ' · ' . $r['follow_up_type'] . ($r['follow_up_time'] ? ' · ' . substr((string) $r['follow_up_time'], 0, 5) : ''), ' ·'),
                    '/follow-ups/' . $r['id'] . '/edit', 'follow_up', (int) $r['id'], 'followup_due:' . $r['id'] . ':' . $today);
            }
        }
        return $sent;
    }

    private static function followUpsOverdue(string $today): int
    {
        $sent = 0;
        $rows = Database::fetchAll(
            "SELECT f.id, f.purpose, f.follow_up_date, COALESCE(f.pic_user_id, f.created_by) AS uid, c.name AS customer_name, l.lead_name
             FROM follow_up f LEFT JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id
             WHERE f.follow_up_date < :d AND f.status NOT IN ('Done','Cancelled') AND f.reminder = 1",
            ['d' => $today]
        );
        foreach ($rows as $r) {
            foreach (self::allowed([$r['uid']], 'followups.view') as $uid) {
                $sent += (int) Notification::send($uid, 'followup_overdue', 'Follow up terlewat: ' . $r['purpose'],
                    trim(($r['customer_name'] ?? $r['lead_name'] ?? '') . ' · jadwal ' . fmt_date($r['follow_up_date']), ' ·'),
                    '/follow-ups/' . $r['id'] . '/edit', 'follow_up', (int) $r['id'], 'followup_overdue:' . $r['id'] . ':' . $r['follow_up_date']);
            }
        }
        return $sent;
    }

    private static function upcomingDeliveries(string $today): int
    {
        $days = max(0, Setting::int('delivery_reminder_days', 2));
        $until = date('Y-m-d', strtotime($today . ' +' . $days . ' days'));
        $rows = Database::fetchAll(
            "SELECT d.id, d.code, d.sj_number, d.delivery_date, d.delivered_qty, d.created_by, c.name AS customer_name, c.marketing_pic_id, pr.name AS product_name
             FROM deliveries d LEFT JOIN purchase_orders p ON p.id = d.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = d.product_id
             WHERE d.status IN ('Scheduled','On Delivery') AND d.delivery_date BETWEEN :d1 AND :d2",
            ['d1' => $today, 'd2' => $until]
        );
        $fallback = null;
        $ppic = $rows !== [] ? self::usersWith('deliveries.sj') : [];
        $sent = 0;
        foreach ($rows as $r) {
            $users = array_values(array_unique(array_merge(self::allowed([$r['marketing_pic_id'], $r['created_by']], 'deliveries.view'), $ppic)));
            if ($users === []) {
                $users = $fallback ??= self::usersWith('deliveries.view', 'Marketing');
            }
            $when = $r['delivery_date'] === $today ? 'hari ini' : fmt_date($r['delivery_date']);
            foreach ($users as $uid) {
                $sent += (int) Notification::send($uid, 'delivery_upcoming', 'Delivery ' . ($r['sj_number'] ?? $r['code']) . ' dijadwalkan ' . $when,
                    trim(($r['customer_name'] ?? '') . ' · ' . ($r['product_name'] ?? '') . ' · ' . Number::qty($r['delivered_qty']) . ' pcs', ' ·'),
                    '/deliveries/' . $r['id'], 'delivery', (int) $r['id'], 'delivery_upcoming:' . $r['id'] . ':' . $r['delivery_date']);
            }
        }
        return $sent;
    }

    private static function leadsClosing(string $today): int
    {
        $until = date('Y-m-d', strtotime($today . ' +3 days'));
        $open = "'" . implode("','", Lead::OPEN_STATUSES) . "'";
        $rows = Database::fetchAll(
            "SELECT l.id, l.lead_name, l.status, l.expected_close_date, COALESCE(l.pic_user_id, l.created_by) AS uid
             FROM leads l WHERE l.status IN ({$open}) AND l.expected_close_date IS NOT NULL AND l.expected_close_date <= :d",
            ['d' => $until]
        );
        $sent = 0;
        foreach ($rows as $r) {
            $passed = (string) $r['expected_close_date'] < $today;
            foreach (self::allowed([$r['uid']], 'leads.view') as $uid) {
                $sent += (int) Notification::send($uid, 'lead_closing', ($passed ? 'Target closing terlewat: ' : 'Target closing segera: ') . $r['lead_name'],
                    'Target ' . fmt_date($r['expected_close_date']) . ' · status ' . $r['status'],
                    '/leads/' . $r['id'], 'lead', (int) $r['id'], 'lead_closing:' . $r['id'] . ':' . $r['expected_close_date']);
            }
        }
        return $sent;
    }
}
