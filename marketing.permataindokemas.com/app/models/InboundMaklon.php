<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Penerimaan barang/komponen maklon dari vendor — diinput manual oleh Gudang.
 * Total masuk = Qty diterima − Qty reject (dihitung otomatis saat disimpan).
 * No. order/PO dan nama barang diketik manual; bila cocok persis dengan order /
 * produk yang ada, record otomatis terhubung (tanpa membuat data baru).
 */
final class InboundMaklon extends Model
{
    public const TABLE = 'inbound_maklon';
    public const ENTITY = 'inbound_maklon';
    public const LABEL = 'sj_number';

    private const SELECT = 'SELECT ib.*, COALESCE(p.order_number, p.po_number) AS po_number, p.code AS po_code, p.customer_id, c.name AS customer_name,
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
                        OR ib.factory_component_code LIKE :q6 OR p.po_number LIKE :q7 OR ib.po_number_legacy LIKE :q8 OR pr.name LIKE :q9 OR ib.code LIKE :q10
                        OR p.order_number LIKE :q11)';
            for ($i = 1; $i <= 11; $i++) {
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
        if (!in_array($column, ['vendor', 'receiver', 'type', 'component_name'], true)) {
            throw new DomainException('Kolom tidak dikenal.');
        }
        return array_map('strval', Database::fetchColumn("SELECT DISTINCT `{$column}` FROM inbound_maklon WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}`"));
    }

    /**
     * Order (OEF / PO) yang nomornya sama persis dengan isian manual
     * (No. order OEF atau No. PO customer, tidak peka huruf besar/kecil).
     */
    public static function matchOrder(?string $reference): ?int
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }
        $id = Database::fetchValue(
            'SELECT id FROM purchase_orders WHERE LOWER(TRIM(order_number)) = LOWER(:a) OR LOWER(TRIM(po_number)) = LOWER(:b)
             ORDER BY (LOWER(TRIM(order_number)) = LOWER(:c)) DESC, id DESC LIMIT 1',
            ['a' => $reference, 'b' => $reference, 'c' => $reference]
        );
        return $id !== null ? (int) $id : null;
    }

    /**
     * Simpan inbound. Relasi ke order & produk ditentukan otomatis dari isian manual:
     *  - po_number_legacy (No. order / PO yang diketik) → po_id bila cocok persis
     *  - component_name → product_id bila sama persis dengan nama produk
     * @param array<string,mixed> $data
     */
    public static function saveInbound(?int $id, array $data): int
    {
        $data['total_in'] = self::totalIn($data['quantity'] ?? null, $data['reject_qty'] ?? null);
        return Database::transaction(function () use ($id, $data): int {
            $before = $id !== null ? self::find($id) : null;
            if ($id !== null && $before === null) {
                throw new DomainException('Data inbound tidak ditemukan.');
            }
            if (array_key_exists('po_number_legacy', $data)) {
                $data['po_id'] = self::matchOrder($data['po_number_legacy']);
            }
            if (array_key_exists('component_name', $data)) {
                $product = Product::findByName((string) $data['component_name']);
                $sameName = $before !== null && mb_strtolower(trim((string) $before['component_name'])) === mb_strtolower(trim((string) $data['component_name']));
                $data['product_id'] = $product !== null ? (int) $product['id'] : ($sameName ? $before['product_id'] : null);
            }
            if ($id === null) {
                return self::create($data);
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
