<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Posisi stok per produk & tipe stok.
 * Nama produk diketik manual oleh Produksi/Gudang; nama yang sama (tidak peka huruf
 * besar/kecil & spasi) otomatis masuk ke produk yang sama sehingga stok terkelompok
 * per produk. Satu produk boleh memiliki beberapa entri (mis. beberapa batch / lokasi);
 * total per tipe = jumlah seluruh entri tipe tersebut.
 */
final class Stock extends Model
{
    public const TABLE = 'stock';
    public const ENTITY = 'stock';
    public const LABEL = 'code';

    public const TYPES = ['FG', 'WIP', 'Ready', 'Reserved'];
    public const TYPE_LABELS = [
        'FG'       => 'Finished Goods — barang jadi di gudang',
        'WIP'      => 'Work in Process — masih dalam produksi',
        'Ready'    => 'Ready — siap kirim',
        'Reserved' => 'Reserved — sudah dialokasikan untuk order',
    ];
    /** Issue migrasi yang selesai saat entri stok dihubungkan ke produk. */
    public const LINK_ISSUES = ['STOCK PRODUCT NOT MATCHED'];

    /**
     * Derived table total stok per produk & tipe.
     * Kolom: product_id, fg, wip, ready, reserved, entries
     */
    public static function byProductSql(): string
    {
        return "SELECT product_id,
                       SUM(CASE WHEN stock_type = 'FG' THEN COALESCE(quantity, 0) ELSE 0 END) AS fg,
                       SUM(CASE WHEN stock_type = 'WIP' THEN COALESCE(quantity, 0) ELSE 0 END) AS wip,
                       SUM(CASE WHEN stock_type = 'Ready' THEN COALESCE(quantity, 0) ELSE 0 END) AS ready,
                       SUM(CASE WHEN stock_type = 'Reserved' THEN COALESCE(quantity, 0) ELSE 0 END) AS reserved,
                       COUNT(*) AS entries
                FROM stock WHERE product_id IS NOT NULL GROUP BY product_id";
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(pr.name LIKE :q1 OR pr.product_code LIKE :q2 OR pr.variant LIKE :q3 OR s.product_legacy LIKE :q4 OR s.code LIKE :q5 OR s.status LIKE :q6 OR s.notes LIKE :q7)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) {
            $where[] = 's.stock_type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['product_id'])) {
            $where[] = 's.product_id = :pid';
            $params['pid'] = (int) $f['product_id'];
        }
        if (!empty($f['category'])) {
            $where[] = 'pr.category = :cat';
            $params['cat'] = (string) $f['category'];
        }
        if (($f['link'] ?? '') === 'unlinked') {
            $where[] = 's.product_id IS NULL';
        }
        return [implode(' AND ', $where), $params];
    }

    /** Daftar entri stok. */
    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(
            "SELECT s.*, pr.name AS product_name, pr.variant, pr.product_code, pr.unit, pr.category, u.name AS created_by_name
             FROM stock s LEFT JOIN products pr ON pr.id = s.product_id LEFT JOIN users u ON u.id = s.created_by
             WHERE " . $where,
            $params,
            "s.product_id IS NULL, COALESCE(pr.name, s.product_legacy), FIELD(s.stock_type, 'FG','WIP','Ready','Reserved'), s.id",
            $page
        );
    }

    /** Posisi stok per produk (pivot tipe stok). Hanya entri yang sudah terhubung ke produk. */
    public static function paginateByProduct(array $f, int $page): Paginator
    {
        $f['link'] = '';
        [$where, $params] = self::filters($f);
        return Paginator::query(
            "SELECT s.product_id, pr.code AS product_id_code, pr.name AS product_name, pr.variant, pr.product_code, pr.unit, pr.category,
                    SUM(CASE WHEN s.stock_type = 'FG' THEN COALESCE(s.quantity, 0) ELSE 0 END) AS fg,
                    SUM(CASE WHEN s.stock_type = 'WIP' THEN COALESCE(s.quantity, 0) ELSE 0 END) AS wip,
                    SUM(CASE WHEN s.stock_type = 'Ready' THEN COALESCE(s.quantity, 0) ELSE 0 END) AS ready,
                    SUM(CASE WHEN s.stock_type = 'Reserved' THEN COALESCE(s.quantity, 0) ELSE 0 END) AS reserved,
                    COUNT(*) AS entries, COALESCE(SUM(s.quantity IS NULL), 0) AS qty_missing,
                    MAX(COALESCE(s.updated_at, s.created_at)) AS last_update
             FROM stock s JOIN products pr ON pr.id = s.product_id
             WHERE " . $where . '
             GROUP BY s.product_id, pr.code, pr.name, pr.variant, pr.product_code, pr.unit, pr.category',
            $params,
            'product_name, s.product_id',
            $page
        );
    }

    /** @return array<string,int> total per tipe untuk filter aktif + jumlah entri */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        $row = Database::fetch(
            "SELECT COUNT(*) AS entries,
                    COALESCE(SUM(CASE WHEN s.stock_type = 'FG' THEN s.quantity END), 0) AS fg,
                    COALESCE(SUM(CASE WHEN s.stock_type = 'WIP' THEN s.quantity END), 0) AS wip,
                    COALESCE(SUM(CASE WHEN s.stock_type = 'Ready' THEN s.quantity END), 0) AS ready,
                    COALESCE(SUM(CASE WHEN s.stock_type = 'Reserved' THEN s.quantity END), 0) AS reserved,
                    COALESCE(SUM(s.product_id IS NULL), 0) AS unlinked
             FROM stock s LEFT JOIN products pr ON pr.id = s.product_id WHERE " . $where,
            $params
        ) ?? [];
        return array_map('intval', $row);
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(
            'SELECT s.*, pr.name AS product_name, pr.variant, pr.product_code, pr.unit
             FROM stock s LEFT JOIN products pr ON pr.id = s.product_id WHERE s.id = :id',
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> entri stok untuk satu produk */
    public static function forProduct(int $productId): array
    {
        return Database::fetchAll(
            "SELECT * FROM stock WHERE product_id = :id ORDER BY FIELD(stock_type, 'FG','WIP','Ready','Reserved'), id",
            ['id' => $productId]
        );
    }

    /** @return list<string> kategori produk yang punya stok (filter pengelompokan) */
    public static function categories(): array
    {
        return array_map('strval', Database::fetchColumn(
            "SELECT DISTINCT pr.category FROM stock s JOIN products pr ON pr.id = s.product_id WHERE pr.category IS NOT NULL AND pr.category <> '' ORDER BY pr.category"
        ));
    }

    /** @return list<string> status yang sudah pernah dipakai (untuk saran input) */
    public static function statuses(): array
    {
        return array_map('strval', Database::fetchColumn("SELECT DISTINCT status FROM stock WHERE status IS NOT NULL AND status <> '' ORDER BY status"));
    }

    /**
     * Qty dari box × isi per box (null bila salah satu kosong).
     */
    public static function boxQuantity(?int $box, ?int $qtyPerBox): ?int
    {
        return $box !== null && $qtyPerBox !== null ? $box * $qtyPerBox : null;
    }

    /**
     * Simpan entri stok. Nama produk yang diketik manual dicari / dicatat otomatis di
     * master produk (pengelompokan per produk). Bila produk baru dihubungkan ke entri
     * legacy, issue migrasi terkait otomatis ditandai selesai.
     * @param array<string,mixed> $data kolom stock (tanpa product_id bila $productName diisi)
     * @return array{id:int,product_id:int|null,product_created:bool}
     */
    public static function saveStock(?int $id, array $data, ?string $productName = null): array
    {
        return Database::transaction(function () use ($id, $data, $productName): array {
            $before = $id !== null ? self::find($id) : null;
            if ($id !== null && $before === null) {
                throw new DomainException('Data stok tidak ditemukan.');
            }
            $created = false;
            if ($productName !== null && trim($productName) !== '') {
                $product = Product::findOrCreateForStock($productName);
                $data['product_id'] = $product['id'];
                $created = $product['created'];
            } elseif (!array_key_exists('product_id', $data)) {
                $data['product_id'] = $before['product_id'] ?? null;
            }
            if ($id === null) {
                $id = self::create($data);
            } else {
                self::update($id, $data, $before);
            }
            if ($data['product_id'] !== null && ($before === null || $before['product_id'] === null)) {
                MigrationIssue::resolveForRecord('STOCK', $id, self::LINK_ISSUES, 'Entri stok dihubungkan ke master produk oleh user.');
            }
            return ['id' => $id, 'product_id' => $data['product_id'] !== null ? (int) $data['product_id'] : null, 'product_created' => $created];
        });
    }

    public static function remove(int $id): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new DomainException('Data stok tidak ditemukan.');
        }
        Database::transaction(function () use ($id, $row): void {
            self::delete($id, $row);
            MigrationIssue::closeForDeletedRecord('STOCK', $id);
        });
    }
}
