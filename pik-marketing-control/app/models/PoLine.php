<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;

/**
 * Baris PO (produk + qty order).
 *
 * ATURAN BISNIS (satu-satunya sumber perhitungan di aplikasi):
 *   Delivered Quantity   = SUM(deliveries.delivered_qty) untuk PO line tsb
 *                          dengan status Delivered / Partial
 *   Return Quantity      = SUM(returns.return_qty) untuk PO line tsb
 *   Outstanding Quantity = Order Quantity − Delivered Quantity + Return Quantity
 *
 * Nilai negatif berarti kelebihan kirim (over delivery).
 */
final class PoLine extends Model
{
    public const TABLE = 'po_lines';
    public const ENTITY = 'po_line';
    public const LABEL = 'code';

    /** Status delivery yang dihitung sebagai barang terkirim */
    public const DELIVERED_STATUSES = ['Delivered', 'Partial'];

    /**
     * Derived table berisi delivered/return/outstanding per PO line.
     * Kolom: line_id, po_id, product_id, order_qty, delivered_qty, return_qty, outstanding_qty
     */
    public static function totalsSql(): string
    {
        return "SELECT pl.id AS line_id, pl.po_id, pl.product_id, pl.order_qty,
                       COALESCE(d.qty, 0) AS delivered_qty,
                       COALESCE(r.qty, 0) AS return_qty,
                       pl.order_qty - COALESCE(d.qty, 0) + COALESCE(r.qty, 0) AS outstanding_qty
                FROM po_lines pl
                LEFT JOIN (SELECT po_line_id, SUM(delivered_qty) AS qty FROM deliveries
                           WHERE po_line_id IS NOT NULL AND status IN ('Delivered','Partial') GROUP BY po_line_id) d ON d.po_line_id = pl.id
                LEFT JOIN (SELECT po_line_id, SUM(return_qty) AS qty FROM returns
                           WHERE po_line_id IS NOT NULL GROUP BY po_line_id) r ON r.po_line_id = pl.id";
    }

    /**
     * Derived table total per PO.
     * Kolom: po_id, line_count, total_qty, delivered_qty, return_qty, outstanding_qty, open_outstanding_qty
     *   open_outstanding_qty = jumlah outstanding positif saja (kelebihan kirim tidak mengurangi)
     */
    public static function poTotalsSql(): string
    {
        return 'SELECT t.po_id, COUNT(*) AS line_count, SUM(t.order_qty) AS total_qty,
                       SUM(t.delivered_qty) AS delivered_qty, SUM(t.return_qty) AS return_qty,
                       SUM(t.outstanding_qty) AS outstanding_qty,
                       SUM(GREATEST(t.outstanding_qty, 0)) AS open_outstanding_qty
                FROM (' . self::totalsSql() . ') t GROUP BY t.po_id';
    }

    /** Hitung outstanding (fungsi murni, dipakai juga oleh test). */
    public static function outstanding(int $orderQty, int $deliveredQty, int $returnQty): int
    {
        return $orderQty - $deliveredQty + $returnQty;
    }

    /** @return array{order_qty:int,delivered_qty:int,return_qty:int,outstanding_qty:int}|null */
    public static function totals(int $lineId): ?array
    {
        $row = Database::fetch('SELECT * FROM (' . self::totalsSql() . ') t WHERE t.line_id = :id', ['id' => $lineId]);
        if ($row === null) {
            return null;
        }
        return [
            'order_qty'       => (int) $row['order_qty'],
            'delivered_qty'   => (int) $row['delivered_qty'],
            'return_qty'      => (int) $row['return_qty'],
            'outstanding_qty' => (int) $row['outstanding_qty'],
        ];
    }
}
