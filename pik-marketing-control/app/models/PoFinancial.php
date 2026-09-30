<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Ringkasan nilai PO (harga, PPN, status bayar) — sheet PO_FINANCIALS.
 *
 * Total sebelum PPN = Qty × Harga satuan
 * PPN               = Total × tarif PPN (pengaturan ppn_rate, default 11%)
 * Total + PPN       = Total + PPN
 * Nilai dihitung ulang hanya bila Qty dan Harga satuan terisi; kolom
 * Terkirim / Belum terkirim / Outstanding amount adalah snapshot spreadsheet
 * lama dan tidak diubah aplikasi (angka terkirim terkini dibaca dari PO).
 */
final class PoFinancial extends Model
{
    public const TABLE = 'po_financials';
    public const ENTITY = 'po_financial';
    public const LABEL = 'code';

    public const PAYMENT_STATUSES = ['Unpaid', 'Partial', 'Paid'];
    public const LINK_ISSUES = ['PO NOT FOUND'];

    private const SELECT = 'SELECT f.*, p.po_number, p.code AS po_code, p.customer_id, c.name AS customer_name
        FROM po_financials f
        LEFT JOIN purchase_orders p ON p.id = f.po_id
        LEFT JOIN customers c ON c.id = p.customer_id';

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(p.po_number LIKE :q1 OR f.po_number_legacy LIKE :q2 OR f.brand LIKE :q3 OR f.product_legacy LIKE :q4 OR f.product_code_legacy LIKE :q5 OR c.name LIKE :q6 OR f.code LIKE :q7)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['status']) && in_array($f['status'], self::PAYMENT_STATUSES, true)) {
            $where[] = 'f.payment_status = :st';
            $params['st'] = $f['status'];
        } elseif (($f['status'] ?? '') === 'none') {
            $where[] = 'f.payment_status IS NULL';
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'p.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['brand'])) {
            $where[] = 'f.brand = :brand';
            $params['brand'] = (string) $f['brand'];
        }
        if (($f['link'] ?? '') === 'no_po') {
            $where[] = 'f.po_id IS NULL';
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, 'f.po_date IS NULL, f.po_date DESC, f.id DESC', $page);
    }

    /** @return array<string,mixed> */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        return Database::fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(f.total_order_amount), 0) AS total_order, COALESCE(SUM(f.total_incl_ppn), 0) AS total_incl,
                    COALESCE(SUM(f.payment_status IS NULL OR f.payment_status <> 'Paid'), 0) AS unpaid_count,
                    COALESCE(SUM(CASE WHEN f.payment_status IS NULL OR f.payment_status <> 'Paid' THEN f.total_incl_ppn ELSE 0 END), 0) AS unpaid_value,
                    COALESCE(SUM(f.po_id IS NULL), 0) AS no_po
             FROM po_financials f LEFT JOIN purchase_orders p ON p.id = f.po_id LEFT JOIN customers c ON c.id = p.customer_id WHERE " . $where,
            $params
        ) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE f.id = :id', ['id' => $id]);
    }

    /** @return list<string> */
    public static function brands(): array
    {
        return array_map('strval', Database::fetchColumn("SELECT DISTINCT brand FROM po_financials WHERE brand IS NOT NULL AND brand <> '' ORDER BY brand"));
    }

    /**
     * Hitung nilai dari qty & harga satuan (null bila salah satu kosong).
     * @return array{total_order_amount:string,ppn:string,total_incl_ppn:string}|null
     */
    public static function computeAmounts(?int $qty, mixed $unitPrice, float $ppnRate): ?array
    {
        if ($qty === null || $unitPrice === null || $unitPrice === '') {
            return null;
        }
        $total = round($qty * (float) $unitPrice, 2);
        $ppn = round($total * $ppnRate / 100, 2);
        return [
            'total_order_amount' => number_format($total, 2, '.', ''),
            'ppn'                => number_format($ppn, 2, '.', ''),
            'total_incl_ppn'     => number_format($total + $ppn, 2, '.', ''),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function savePoFinancial(?int $id, array $data, float $ppnRate): int
    {
        $amounts = self::computeAmounts($data['order_qty'] ?? null, $data['unit_price'] ?? null, $ppnRate);
        if ($amounts !== null) {
            $data += $amounts;
        }
        if (!empty($data['po_id']) && empty($data['po_date'])) {
            $data['po_date'] = Database::fetchValue('SELECT po_date FROM purchase_orders WHERE id = :id', ['id' => $data['po_id']]);
        }
        return Database::transaction(function () use ($id, $data): int {
            $before = $id !== null ? self::find($id) : null;
            if ($id !== null && $before === null) {
                throw new DomainException('Data tidak ditemukan.');
            }
            if ($id === null) {
                $id = self::create($data);
            } else {
                self::update($id, $data, $before);
            }
            if (!empty($data['po_id']) && ($before === null || $before['po_id'] === null)) {
                MigrationIssue::resolveForRecord('PO_FINANCIALS', $id, self::LINK_ISSUES, 'Ringkasan finansial dihubungkan ke PO oleh user.');
            }
            return $id;
        });
    }

    public static function remove(int $id): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new DomainException('Data tidak ditemukan.');
        }
        Database::transaction(function () use ($id, $row): void {
            self::delete($id, $row);
            MigrationIssue::closeForDeletedRecord('PO_FINANCIALS', $id);
        });
    }
}
