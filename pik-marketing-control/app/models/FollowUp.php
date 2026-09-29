<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Follow up.
 *
 * ATURAN BISNIS: follow up menjadi OVERDUE bila tanggalnya < hari ini dan
 * status bukan Done. Follow up berstatus Cancelled juga tidak dianggap
 * overdue (sudah ditutup), sehingga tidak memunculkan peringatan palsu.
 */
final class FollowUp extends Model
{
    public const TABLE = 'follow_up';
    public const ENTITY = 'follow_up';
    public const LABEL = 'purpose';

    public const STATUSES = ['Planned', 'Done', 'Reschedule', 'Cancelled', 'Overdue'];
    /** Status yang bisa dipilih manual (Overdue diberikan otomatis oleh sistem). */
    public const EDITABLE_STATUSES = ['Planned', 'Reschedule', 'Done', 'Cancelled'];
    public const CLOSED_STATUSES = ['Done', 'Cancelled'];
    public const TYPES = Activity::TYPES;
    public const TABS = ['today' => 'Hari ini', 'upcoming' => 'Mendatang', 'overdue' => 'Overdue', 'completed' => 'Selesai', 'all' => 'Semua'];

    public static function isOverdue(string $followUpDate, string $status, string $today): bool
    {
        return $followUpDate < $today && !in_array($status, self::CLOSED_STATUSES, true);
    }

    /** Status yang ditampilkan (Overdue dihitung otomatis). */
    public static function displayStatus(array $row, string $today): string
    {
        return self::isOverdue((string) $row['follow_up_date'], (string) $row['status'], $today) ? 'Overdue' : (string) $row['status'];
    }

    /**
     * Sinkronkan kolom status dengan aturan overdue (dipanggil otomatis).
     * @return array{overdue:int,reverted:int}
     */
    public static function refreshOverdue(string $today): array
    {
        $overdue = Database::query(
            "UPDATE follow_up SET status = 'Overdue' WHERE follow_up_date < :d AND status IN ('Planned','Reschedule')",
            ['d' => $today]
        )->rowCount();
        // bila tanggal diundur ke hari ini/masa depan, status kembali aktif
        $reverted = Database::query(
            "UPDATE follow_up SET status = 'Reschedule' WHERE follow_up_date >= :d AND status = 'Overdue'",
            ['d' => $today]
        )->rowCount();
        return ['overdue' => $overdue, 'reverted' => $reverted];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function tabWhere(string $tab, string $today): array
    {
        return match ($tab) {
            'today'     => ["f.follow_up_date = :today AND f.status NOT IN ('Done','Cancelled')", ['today' => $today]],
            'upcoming'  => ["f.follow_up_date > :today AND f.status NOT IN ('Done','Cancelled')", ['today' => $today]],
            'overdue'   => ["f.follow_up_date < :today AND f.status NOT IN ('Done','Cancelled')", ['today' => $today]],
            'completed' => ["f.status IN ('Done','Cancelled')", []],
            default     => ['1=1', []],
        };
    }

    /** @return array<string,int> */
    public static function tabCounts(string $today, array $f = []): array
    {
        [$where, $params] = self::filters($f);
        $row = Database::fetch(
            "SELECT
                COALESCE(SUM(f.follow_up_date = :t1 AND f.status NOT IN ('Done','Cancelled')), 0) AS today,
                COALESCE(SUM(f.follow_up_date > :t2 AND f.status NOT IN ('Done','Cancelled')), 0) AS upcoming,
                COALESCE(SUM(f.follow_up_date < :t3 AND f.status NOT IN ('Done','Cancelled')), 0) AS overdue,
                COALESCE(SUM(f.status IN ('Done','Cancelled')), 0) AS completed,
                COUNT(*) AS total
             FROM follow_up f LEFT JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id WHERE " . $where,
            array_merge(['t1' => $today, 't2' => $today, 't3' => $today], $params)
        ) ?? [];
        return [
            'today' => (int) ($row['today'] ?? 0), 'upcoming' => (int) ($row['upcoming'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0), 'all' => (int) ($row['total'] ?? 0),
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(f.purpose LIKE :q1 OR c.name LIKE :q2 OR l.lead_name LIKE :q3 OR f.code LIKE :q4)';
            foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['pic'])) {
            $where[] = 'f.pic_user_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'f.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) {
            $where[] = 'f.follow_up_type = :type';
            $params['type'] = $f['type'];
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(string $tab, string $today, array $f, int $page): Paginator
    {
        [$tabWhere, $tabParams] = self::tabWhere($tab, $today);
        [$where, $params] = self::filters($f);
        $order = match ($tab) {
            'overdue' => 'f.follow_up_date ASC, f.follow_up_time IS NULL, f.follow_up_time ASC',
            'completed' => 'COALESCE(f.completed_at, f.updated_at, f.created_at) DESC',
            'all' => 'f.follow_up_date DESC',
            default => 'f.follow_up_date ASC, f.follow_up_time IS NULL, f.follow_up_time ASC',
        };
        return Paginator::query(
            "SELECT f.*, c.name AS customer_name, l.lead_name, u.name AS pic_name,
                    (f.follow_up_date < :ov_today AND f.status NOT IN ('Done','Cancelled')) AS is_overdue
             FROM follow_up f LEFT JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id
             LEFT JOIN users u ON u.id = f.pic_user_id
             WHERE {$tabWhere} AND {$where}",
            array_merge(['ov_today' => $today], $tabParams, $params),
            $order . ', f.id ASC',
            $page
        );
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(
            'SELECT f.*, c.name AS customer_name, l.lead_name, u.name AS pic_name, a.subject AS activity_subject
             FROM follow_up f LEFT JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id
             LEFT JOIN users u ON u.id = f.pic_user_id LEFT JOIN activities a ON a.id = f.activity_id WHERE f.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Simpan follow up. Status "Overdue" tidak dipilih manual: bila tanggal
     * sudah lewat dan status masih terbuka, sistem yang menandainya.
     * @param array<string,mixed> $data
     */
    public static function saveFollowUp(?int $id, array $data): int
    {
        $today = date('Y-m-d');
        $status = (string) ($data['status'] ?? 'Planned');
        if (!in_array($status, self::CLOSED_STATUSES, true)) {
            $data['status'] = $data['follow_up_date'] < $today ? 'Overdue' : ($status === 'Overdue' ? 'Reschedule' : $status);
        }
        if ($data['status'] === 'Done') {
            $data['completed_at'] ??= date('Y-m-d H:i:s');
        } else {
            $data['completed_at'] = null;
        }
        $before = $id !== null ? self::find($id) : null;
        if ($id === null) {
            $id = self::create($data);
        } else {
            self::update($id, $data, $before);
        }
        self::notifyAssignment($id, $before, $data);
        Lead::refreshDerived(isset($data['lead_id']) ? (int) $data['lead_id'] : null);
        if ($before !== null && $before['lead_id'] !== null && (int) $before['lead_id'] !== (int) ($data['lead_id'] ?? 0)) {
            Lead::refreshDerived((int) $before['lead_id']);
        }
        return $id;
    }

    /** Tandai selesai; opsional langsung jadwalkan follow up lanjutan. */
    public static function markDone(int $id, ?string $result, ?string $nextDate, ?string $nextPurpose = null): ?int
    {
        return Database::transaction(function () use ($id, $result, $nextDate, $nextPurpose): ?int {
            $fu = self::find($id);
            if ($fu === null) {
                throw new DomainException('Follow up tidak ditemukan.');
            }
            self::update($id, [
                'status'         => 'Done',
                'result'         => $result !== null && $result !== '' ? $result : $fu['result'],
                'next_follow_up' => $nextDate,
                'completed_at'   => date('Y-m-d H:i:s'),
            ], $fu);
            $newId = null;
            if ($nextDate !== null) {
                $newId = self::create([
                    'customer_id'    => $fu['customer_id'],
                    'lead_id'        => $fu['lead_id'],
                    'pic_user_id'    => $fu['pic_user_id'],
                    'follow_up_date' => $nextDate,
                    'follow_up_type' => $fu['follow_up_type'],
                    'purpose'        => mb_substr($nextPurpose !== null && $nextPurpose !== '' ? $nextPurpose : 'Lanjutan: ' . $fu['purpose'], 0, 255),
                    'status'         => 'Planned',
                    'reminder'       => $fu['reminder'],
                ]);
            }
            Lead::refreshDerived($fu['lead_id'] !== null ? (int) $fu['lead_id'] : null);
            return $newId;
        });
    }

    public static function reschedule(int $id, string $newDate, ?string $note): void
    {
        $fu = self::find($id);
        if ($fu === null) {
            throw new DomainException('Follow up tidak ditemukan.');
        }
        if (in_array($fu['status'], self::CLOSED_STATUSES, true)) {
            throw new DomainException('Follow up yang sudah selesai/dibatalkan tidak dapat dijadwalkan ulang.');
        }
        $notes = trim(((string) $fu['notes']) . "\n" . 'Dijadwalkan ulang ' . fmt_date((string) $fu['follow_up_date']) . ' → ' . fmt_date($newDate) . ($note ? ': ' . $note : ''));
        self::update($id, [
            'follow_up_date' => $newDate,
            'status'         => $newDate < date('Y-m-d') ? 'Overdue' : 'Reschedule',
            'notes'          => $notes,
        ], $fu);
        Lead::refreshDerived($fu['lead_id'] !== null ? (int) $fu['lead_id'] : null);
    }

    public static function remove(int $id): void
    {
        $fu = self::find($id);
        if ($fu === null) {
            throw new DomainException('Follow up tidak ditemukan.');
        }
        self::delete($id, $fu);
        Lead::refreshDerived($fu['lead_id'] !== null ? (int) $fu['lead_id'] : null);
    }

    private static function notifyAssignment(int $id, ?array $before, array $data): void
    {
        $pic = $data['pic_user_id'] ?? null;
        if ($pic === null || (int) $pic === (int) Auth::id() || ($before !== null && (int) $before['pic_user_id'] === (int) $pic)) {
            return;
        }
        Notification::send((int) $pic, 'followup_assigned', 'Follow up untuk Anda: ' . $data['purpose'],
            'Jadwal ' . fmt_date((string) $data['follow_up_date']) . ' · dari ' . (Auth::user()['name'] ?? 'sistem') . '.',
            '/follow-ups/' . $id . '/edit', 'follow_up', $id, 'fu-assigned-' . $id . '-' . $pic);
    }
}
