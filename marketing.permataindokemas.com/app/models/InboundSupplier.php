<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Number;
use App\Helpers\Paginator;
use DomainException;

/**
 * Penerimaan barang dari supplier (bahan baku, kemasan, label, dll.) — diinput manual oleh Gudang.
 * Qty boleh desimal (mis. kg / liter). Total masuk = Qty diterima − Qty reject (dihitung otomatis).
 */
final class InboundSupplier extends Model
{
    public const TABLE = 'inbound_supplier';
    public const ENTITY = 'inbound_supplier';
    public const LABEL = 'item_name';

    /** Saran isian (tetap bisa diketik bebas). */
    public const CATEGORIES = ['Bahan baku', 'Kemasan', 'Label / stiker', 'Karton / box', 'Tinta / pewarna', 'Sparepart', 'Lainnya'];
    public const UNITS = ['pcs', 'kg', 'gram', 'liter', 'roll', 'lembar', 'box', 'sak', 'set', 'meter'];

    private const SELECT = 'SELECT s.*, cu.name AS created_by_name, uu.name AS updated_by_name
        FROM inbound_supplier s
        LEFT JOIN users cu ON cu.id = s.created_by
        LEFT JOIN users uu ON uu.id = s.updated_by';

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(s.item_name LIKE :q1 OR s.item_code LIKE :q2 OR s.supplier LIKE :q3 OR s.sj_number LIKE :q4 OR s.po_reference LIKE :q5
                        OR s.code LIKE :q6 OR s.receiver LIKE :q7 OR s.location LIKE :q8)';
            for ($i = 1; $i <= 8; $i++) {
                $params['q' . $i] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['supplier'])) {
            $where[] = 's.supplier = :supplier';
            $params['supplier'] = (string) $f['supplier'];
        }
        if (!empty($f['category'])) {
            $where[] = 's.category = :category';
            $params['category'] = (string) $f['category'];
        }
        if (!empty($f['from'])) {
            $where[] = 's.inbound_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 's.inbound_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['reject'] ?? '') === '1') {
            $where[] = 'COALESCE(s.reject_qty, 0) > 0';
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, 's.inbound_date DESC, s.id DESC', $page);
    }

    /** Rekap per barang (nama + satuan): total diterima, reject, masuk, jumlah penerimaan. */
    public static function paginateByItem(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(
            'SELECT MIN(s.item_name) AS item_name, s.unit, COUNT(*) AS receipts, COUNT(DISTINCT s.supplier) AS suppliers,
                    SUM(s.quantity) AS quantity, COALESCE(SUM(s.reject_qty), 0) AS reject_qty,
                    SUM(COALESCE(s.total_in, s.quantity - COALESCE(s.reject_qty, 0))) AS total_in,
                    MAX(s.inbound_date) AS last_date, MIN(s.category) AS category
             FROM inbound_supplier s
             WHERE ' . $where . '
             GROUP BY LOWER(TRIM(s.item_name)), s.unit',
            $params,
            'item_name ASC, unit ASC',
            $page
        );
    }

    /** @return array<string,int> */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        $row = Database::fetch(
            'SELECT COUNT(*) AS n, COUNT(DISTINCT s.supplier) AS suppliers, COUNT(DISTINCT LOWER(TRIM(s.item_name))) AS items,
                    COALESCE(SUM(COALESCE(s.reject_qty, 0) > 0), 0) AS with_reject
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

    /** Total masuk = qty − reject (dihitung dalam sen agar desimal tepat). */
    public static function totalIn(?string $quantity, ?string $reject): ?string
    {
        $qty = Number::toCents($quantity);
        if ($qty === null) {
            return null;
        }
        return Number::fromCents($qty - (Number::toCents($reject) ?? 0));
    }

    /** Format qty: desimal hanya bila ada (1.250 / 12,5). */
    public static function formatQty(mixed $value, string $empty = '—'): string
    {
        return Number::decimal($value, 2, $empty);
    }

    /** @return list<string> */
    public static function distinct(string $column): array
    {
        if (!in_array($column, ['supplier', 'receiver', 'category', 'unit', 'item_name', 'location'], true)) {
            throw new DomainException('Kolom tidak dikenal.');
        }
        return array_map('strval', Database::fetchColumn("SELECT DISTINCT `{$column}` FROM inbound_supplier WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}`"));
    }

    /** @param array<string,mixed> $data */
    public static function saveInbound(?int $id, array $data): int
    {
        // Simpan dengan format DECIMAL(15,2) yang sama dengan database (audit log hanya mencatat perubahan nyata)
        foreach (['quantity', 'reject_qty'] as $key) {
            $cents = Number::toCents($data[$key] ?? null);
            $data[$key] = $cents !== null ? Number::fromCents($cents) : null;
        }
        $data['total_in'] = self::totalIn($data['quantity'], $data['reject_qty']);
        return Database::transaction(function () use ($id, $data): int {
            if ($id === null) {
                return self::create($data);
            }
            $before = self::find($id);
            if ($before === null) {
                throw new DomainException('Data inbound supplier tidak ditemukan.');
            }
            self::update($id, $data, $before);
            return $id;
        });
    }

    public static function remove(int $id): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new DomainException('Data inbound supplier tidak ditemukan.');
        }
        self::delete($id, $row);
    }
}
