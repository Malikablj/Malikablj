<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;

/**
 * Penerimaan barang dari supplier (diinput manual oleh Purchasing).
 * Qty diterima bersih = Qty datang − Qty reject.
 */
final class InboundSupplier extends Model
{
    public const TABLE = 'inbound_supplier';
    public const ENTITY = 'inbound_supplier';
    public const LABEL = 'item_name';

    private const SELECT = 'SELECT s.*, (s.quantity - s.reject_qty) AS accepted_qty, cu.name AS created_by_name
        FROM inbound_supplier s LEFT JOIN users cu ON cu.id = s.created_by';

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(s.code LIKE :q1 OR s.supplier LIKE :q2 OR s.item_name LIKE :q3 OR s.purchase_number LIKE :q4 OR s.sj_number LIKE :q5 OR s.specification LIKE :q6 OR s.receiver LIKE :q7)';
            for ($i = 1; $i <= 7; $i++) {
                $params['q' . $i] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['supplier'])) {
            $where[] = 's.supplier = :supplier';
            $params['supplier'] = (string) $f['supplier'];
        }
        if (!empty($f['from'])) {
            $where[] = 's.receive_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 's.receive_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['reject'] ?? '') === '1') {
            $where[] = 's.reject_qty > 0';
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, 's.receive_date DESC, s.id DESC', $page);
    }

    /** @return array<string,int> */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        $row = Database::fetch(
            'SELECT COUNT(*) AS n, COUNT(DISTINCT s.supplier) AS suppliers, COALESCE(SUM(s.quantity), 0) AS qty,
                    COALESCE(SUM(s.reject_qty), 0) AS reject, COALESCE(SUM(s.quantity - s.reject_qty), 0) AS accepted
             FROM inbound_supplier s WHERE ' . $where,
            $params
        ) ?? [];
        return array_map('intval', $row);
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);
    }

    /** @return list<string> nilai yang pernah dipakai (saran input) */
    public static function distinct(string $column): array
    {
        if (!in_array($column, ['supplier', 'receiver', 'unit', 'item_name'], true)) {
            return [];
        }
        return array_map('strval', Database::fetchColumn("SELECT DISTINCT {$column} FROM inbound_supplier WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} LIMIT 300"));
    }

    /** @return list<array<string,mixed>> penerimaan terakhir (dashboard) */
    public static function recent(int $limit = 5): array
    {
        return Database::fetchAll(self::SELECT . ' ORDER BY s.receive_date DESC, s.id DESC LIMIT ' . max(1, $limit));
    }
}
