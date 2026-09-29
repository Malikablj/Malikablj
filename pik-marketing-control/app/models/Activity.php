<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

final class Activity extends Model
{
    public const TABLE = 'activities';
    public const ENTITY = 'activity';
    public const LABEL = 'subject';

    public const TYPES = ['WhatsApp', 'Phone Call', 'Email', 'Meeting', 'Visit', 'Quotation', 'Sample', 'Presentation', 'Follow Up', 'Complaint', 'Other'];

    /** @param array{q?:string,type?:string,pic?:int,customer_id?:int,lead_id?:int,from?:string,to?:string} $f */
    public static function paginate(array $f, int $page): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(a.subject LIKE :q1 OR a.description LIKE :q2 OR c.name LIKE :q3 OR l.lead_name LIKE :q4)';
            foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) {
            $where[] = 'a.activity_type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['pic'])) {
            $where[] = 'a.pic_user_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'a.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['lead_id'])) {
            $where[] = 'a.lead_id = :lid';
            $params['lid'] = (int) $f['lead_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'a.activity_date >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'a.activity_date <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }
        return Paginator::query(
            'SELECT a.*, c.name AS customer_name, l.lead_name, u.name AS pic_name
             FROM activities a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN leads l ON l.id = a.lead_id
             LEFT JOIN users u ON u.id = a.pic_user_id WHERE ' . implode(' AND ', $where),
            $params,
            'a.activity_date DESC, a.id DESC',
            $page
        );
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(
            'SELECT a.*, c.name AS customer_name, l.lead_name, u.name AS pic_name
             FROM activities a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN leads l ON l.id = a.lead_id
             LEFT JOIN users u ON u.id = a.pic_user_id WHERE a.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Simpan aktivitas. Bila diminta, sekaligus membuat follow up untuk
     * tanggal "Next follow up" (otomatis terhubung ke activity ini).
     * @param array<string,mixed> $data
     * @return array{id:int,follow_up_id:?int}
     */
    public static function saveActivity(?int $id, array $data, bool $createFollowUp): array
    {
        return Database::transaction(function () use ($id, $data, $createFollowUp): array {
            $before = $id !== null ? self::find($id) : null;
            if ($id === null) {
                $id = self::create($data);
            } else {
                self::update($id, $data, $before);
            }
            $followUpId = null;
            if ($createFollowUp && !empty($data['next_follow_up'])) {
                $exists = Database::fetchValue(
                    "SELECT id FROM follow_up WHERE activity_id = :a AND follow_up_date = :d AND status NOT IN ('Done','Cancelled')",
                    ['a' => $id, 'd' => $data['next_follow_up']]
                );
                if (!$exists) {
                    $followUpId = FollowUp::saveFollowUp(null, [
                        'customer_id'    => $data['customer_id'],
                        'lead_id'        => $data['lead_id'],
                        'activity_id'    => $id,
                        'pic_user_id'    => $data['pic_user_id'],
                        'follow_up_date' => $data['next_follow_up'],
                        'follow_up_type' => in_array($data['activity_type'], FollowUp::TYPES, true) ? $data['activity_type'] : 'Follow Up',
                        'purpose'        => mb_substr((string) ($data['next_action'] ?: 'Tindak lanjut: ' . $data['subject']), 0, 255),
                        'status'         => 'Planned',
                        'reminder'       => 1,
                    ]);
                }
            }
            Lead::refreshDerived(isset($data['lead_id']) ? (int) $data['lead_id'] : null);
            if ($before !== null && $before['lead_id'] !== null && (int) $before['lead_id'] !== (int) ($data['lead_id'] ?? 0)) {
                Lead::refreshDerived((int) $before['lead_id']);
            }
            return ['id' => $id, 'follow_up_id' => $followUpId];
        });
    }

    public static function remove(int $id): void
    {
        $activity = self::find($id);
        if ($activity === null) {
            throw new DomainException('Aktivitas tidak ditemukan.');
        }
        self::delete($id, $activity);
        Lead::refreshDerived($activity['lead_id'] !== null ? (int) $activity['lead_id'] : null);
    }
}
