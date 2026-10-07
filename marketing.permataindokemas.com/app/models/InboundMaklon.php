<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Penerimaan barang/komponen maklon dari vendor.
 * Total masuk = Qty diterima − Qty reject (dihitung otomatis saat disimpan).
 */
final class InboundMaklon extends Model
{
    public const TABLE = 'inbound_maklon';
    public const ENTITY = 'inbound_maklon';
    public const LABEL = 'sj_number';

    private const SELECT = 'SELECT ib.*, p.po_number, p.code AS po_code, p.customer_id, c.name AS customer_name,
            pr.name AS product_name, pr.variant
        FROM inbound_maklon ib
        LEFT JOIN purchase_orders p ON p.id = ib.po_id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN products pr ON pr.id = ib.product_id';

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(ib.sj_number LIKE :q1 OR ib.vendor LIKE :q2 OR ib.receiver LIKE :q3 OR ib.component_name LIKE :q4 OR ib.internal_component_code LIKE :q5
                        OR ib.factory_component_code LIKE :q6 OR p.po_number LIKE :q7 OR ib.po_number_legacy LIKE :q8 OR pr.name LIKE :q9 OR ib.code LIKE :q10)';
            for ($i = 1; $i <= 10; $i++) {
                $params['q' . $i] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['vendor'])) {
            $where[] = 'ib.vendor = :vendor';
            $params['vendor'] = (string) $f['vendor'];
        }
        if (!empty($f['receiver'])) {
            $where[] = 'ib.receiver = :receiver';
            $params['receiver'] = (string) $f['receiver'];
        }
        if (!empty($f['from'])) {
            $where[] = 'ib.actual_inbound_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'ib.actual_inbound_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['link'] ?? '') === 'no_po') {
            $where[] = 'ib.po_id IS NULL';
        } elseif (($f['link'] ?? '') === 'no_product') {
            $where[] = 'ib.product_id IS NULL';
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, 'ib.actual_inbound_date IS NULL, ib.actual_inbound_date DESC, ib.id DESC', $page);
    }

    /** @return array<string,int> */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        $row = Database::fetch(
            'SELECT COUNT(*) AS n, COALESCE(SUM(ib.quantity), 0) AS qty, COALESCE(SUM(ib.reject_qty), 0) AS reject,
                    COALESCE(SUM(COALESCE(ib.total_in, ib.quantity - COALESCE(ib.reject_qty, 0))), 0) AS total_in,
                    COALESCE(SUM(ib.po_id IS NULL), 0) AS no_po
             FROM inbound_maklon ib LEFT JOIN purchase_orders p ON p.id = ib.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = ib.product_id WHERE ' . $where,
            $params
        ) ?? [];
        return array_map('intval', $row);
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE ib.id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function forProduct(int $productId, int $limit = 20): array
    {
        return Database::fetchAll(self::SELECT . ' WHERE ib.product_id = :id ORDER BY ib.actual_inbound_date DESC, ib.id DESC LIMIT ' . max(1, $limit), ['id' => $productId]);
    }

    /** @return list<array<string,mixed>> */
    public static function forPo(int $poId): array
    {
        return Database::fetchAll(self::SELECT . ' WHERE ib.po_id = :id ORDER BY ib.actual_inbound_date DESC, ib.id DESC', ['id' => $poId]);
    }

    /** Total masuk = qty − reject. */
    public static function totalIn(?int $quantity, ?int $reject): ?int
    {
        return $quantity === null ? null : $quantity - ($reject ?? 0);
    }

    /** @return list<string> */
    public static function distinct(string $column): array
    {
        if (!in_array($column, ['vendor', 'receiver', 'type'], true)) {
            throw new DomainException('Kolom tidak dikenal.');
        }
        return array_map('strval', Database::fetchColumn("SELECT DISTINCT `{$column}` FROM inbound_maklon WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}`"));
    }

    /** @return array<int,string> PO untuk pilihan form (terbaru dulu) */
    public static function poOptions(?int $includeId = null): array
    {
        $rows = Database::fetchAll(
            "SELECT p.id, p.po_number, p.code, p.status, c.name AS customer_name FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             WHERE p.status IN ('Open','On Process','Partial') OR p.id = :inc ORDER BY p.po_date DESC, p.id DESC",
            ['inc' => $includeId ?? 0]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ($r['po_number'] ?? $r['code']) . ' — ' . ($r['customer_name'] ?? 'customer ?') . ' (' . $r['status'] . ')';
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function saveInbound(?int $id, array $data): int
    {
        $data['total_in'] = self::totalIn($data['quantity'] ?? null, $data['reject_qty'] ?? null);
        return Database::transaction(function () use ($id, $data): int {
            if ($id === null) {
                return self::create($data);
            }
            $before = self::find($id);
            if ($before === null) {
                throw new DomainException('Data inbound tidak ditemukan.');
            }
            self::update($id, $data, $before);
            return $id;
        });
    }

    public static function remove(int $id): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new DomainException('Data inbound tidak ditemukan.');
        }
        Database::transaction(function () use ($id, $row): void {
            self::delete($id, $row);
            MigrationIssue::closeForDeletedRecord('INBOUND_MAKLON', $id);
        });
    }
}
