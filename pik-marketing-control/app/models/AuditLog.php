<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;

final class AuditLog
{
    /** @param array{q?:string,user_id?:int,action?:string,entity?:string,from?:string,to?:string} $f */
    public static function paginate(array $f, int $page): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(a.entity_label LIKE :q1 OR a.user_name LIKE :q2 OR a.ip_address LIKE :q3)';
            $params['q1'] = Database::like($f['q']);
            $params['q2'] = Database::like($f['q']);
            $params['q3'] = Database::like($f['q']);
        }
        if (!empty($f['user_id'])) {
            $where[] = 'a.user_id = :uid';
            $params['uid'] = $f['user_id'];
        }
        if (!empty($f['action'])) {
            $where[] = 'a.action = :action';
            $params['action'] = $f['action'];
        }
        if (!empty($f['entity'])) {
            $where[] = 'a.entity_type = :entity';
            $params['entity'] = $f['entity'];
        }
        if (!empty($f['from'])) {
            $where[] = 'a.created_at >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'a.created_at <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }
        return Paginator::query(
            'SELECT a.* FROM audit_logs a WHERE ' . implode(' AND ', $where),
            $params,
            'a.created_at DESC, a.id DESC',
            $page,
            50
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM audit_logs WHERE id = :id', ['id' => $id]);
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_map('strval', Database::fetchColumn('SELECT DISTINCT action FROM audit_logs ORDER BY action'));
    }

    /** @return list<string> */
    public static function entities(): array
    {
        return array_map('strval', Database::fetchColumn('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'));
    }

    /** @return list<array<string,mixed>> riwayat perubahan satu record */
    public static function forEntity(string $entity, int $id, int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT * FROM audit_logs WHERE entity_type = :e AND entity_id = :id ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['e' => $entity, 'id' => $id]
        );
    }
}
