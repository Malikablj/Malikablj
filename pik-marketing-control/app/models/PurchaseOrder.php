<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Database;
use App\Helpers\Number;
use App\Helpers\Paginator;
use DomainException;

/**
 * Order Entry Form (OEF). Disimpan di tabel purchase_orders:
 *   order_number = No order (diisi manual, unik), po_number = No PO dari customer,
 *   review_status = hasil review PPIC (Pending → Approved "Bisa diproses" / Rejected "Tidak bisa diproses").
 * Data PO lama (sebelum OEF) tidak punya order_number dan berstatus review Approved.
 */
final class PurchaseOrder extends Model
{
    public const TABLE = 'purchase_orders';
    public const ENTITY = 'purchase_order';
    public const LABEL = 'order_number';

    public const STATUSES = ['Open', 'On Process', 'Partial', 'Closed', 'Cancelled'];
    public const OPEN_STATUSES = ['Open', 'On Process', 'Partial'];
    public const PAYMENT_TERMS = ['CBD', 'COD', 'DP 50%', 'Partial by SJ', 'NET 14', 'NET 30', 'NET 45', 'NET 60'];

    public const REVIEW_STATUSES = ['Pending', 'Approved', 'Rejected'];
    public const REVIEW_LABELS = ['Pending' => 'Menunggu review PPIC', 'Approved' => 'Bisa diproses', 'Rejected' => 'Tidak bisa diproses'];
    /** Field OEF yang bila diubah setelah direview membuat OEF kembali menunggu review PPIC. */
    public const REVIEWED_FIELDS = ['order_number', 'customer_id', 'sales_name', 'po_number', 'po_date', 'requested_delivery_date', 'delivery_address', 'remark'];

    private const SORTS = [
        'date' => 'p.po_date', 'number' => 'p.po_number', 'order' => 'p.order_number', 'customer' => 'c.name', 'status' => 'p.status',
        'qty' => 't.total_qty', 'outstanding' => 't.outstanding_qty', 'value' => 'p.grand_total', 'request' => 'p.requested_delivery_date',
    ];

    /** Label OEF: No order → No PO customer → kode. */
    public static function label(array $row): string
    {
        foreach (['order_number', 'po_number', 'code'] as $k) {
            if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                return (string) $row[$k];
            }
        }
        return '#' . ($row['id'] ?? '');
    }

    /** Badge review PPIC (hijau / merah / kuning). */
    public static function reviewBadge(?string $status): string
    {
        $tone = match ((string) $status) {
            'Approved' => 'success', 'Rejected' => 'danger', default => 'warning',
        };
        return '<span class="badge-soft badge-soft-' . $tone . '">' . e(self::REVIEW_LABELS[(string) $status] ?? (string) $status) . '</span>';
    }

    /** Kolom nilai dokumen PO (diisi import database PO atau form PO). */
    public const VALUE_FIELDS = ['currency', 'price_includes_tax', 'subtotal', 'discount_amount', 'tax_amount', 'shipping_cost', 'grand_total'];

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(p.po_number LIKE :q1 OR p.code LIKE :q2 OR c.name LIKE :q3 OR p.order_number LIKE :q7 OR p.sales_name LIKE :q8
                OR EXISTS (SELECT 1 FROM po_lines pl2 JOIN products pr2 ON pr2.id = pl2.product_id WHERE pl2.po_id = p.id
                    AND (pr2.name LIKE :q4 OR pl2.product_name_legacy LIKE :q5 OR pl2.item_code LIKE :q6 OR pl2.item_description LIKE :q9 OR pl2.subcont_supplier LIKE :q10)))';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['ppic']) && in_array($f['ppic'], self::REVIEW_STATUSES, true)) {
            $where[] = 'p.review_status = :ppic';
            $params['ppic'] = $f['ppic'];
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'open') {
            $where[] = "p.status IN ('Open','On Process','Partial')";
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'p.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'p.po_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'p.po_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['issue'] ?? '') === 'outstanding') {
            $where[] = 'COALESCE(t.open_outstanding_qty, 0) > 0';
        }
        // Bulan PO (YYYY-MM)
        if (!empty($f['month']) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $f['month'], $m)) {
            $where[] = 'p.po_date BETWEEN :m_from AND :m_to';
            $params['m_from'] = $m[1] . '-' . $m[2] . '-01';
            $params['m_to'] = date('Y-m-t', (int) strtotime($params['m_from']));
        }
        if (($f['review'] ?? '') === 'needs_review') {
            $where[] = "p.import_status = 'NEEDS_REVIEW'";
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, string $sort, string $dir, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        $order = (self::SORTS[$sort] ?? 'p.po_date') . ($dir === 'asc' ? ' ASC' : ' DESC') . ', p.id DESC';
        return Paginator::query(
            'SELECT p.*, c.name AS customer_name,
                    COALESCE(t.line_count, 0) AS line_count, COALESCE(t.total_qty, 0) AS total_qty,
                    COALESCE(t.delivered_qty, 0) AS delivered_qty, COALESCE(t.return_qty, 0) AS return_qty,
                    COALESCE(t.outstanding_qty, 0) AS outstanding_qty, COALESCE(t.open_outstanding_qty, 0) AS open_outstanding_qty
             FROM purchase_orders p
             LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
             WHERE ' . $where,
            $params,
            $order,
            $page
        );
    }

    /** @return array<string,mixed> ringkasan untuk filter aktif */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        return Database::fetch(
            "SELECT COUNT(*) AS po_count, COALESCE(SUM(t.total_qty), 0) AS total_qty, COALESCE(SUM(t.delivered_qty), 0) AS delivered_qty,
                    COALESCE(SUM(t.open_outstanding_qty), 0) AS outstanding_qty,
                    COALESCE(SUM(p.review_status = 'Pending'), 0) AS pending_review, COALESCE(SUM(p.review_status = 'Rejected'), 0) AS rejected,
                    COALESCE(SUM(CASE WHEN p.status <> 'Cancelled' THEN p.grand_total END), 0) AS total_value,
                    SUM(CASE WHEN p.status <> 'Cancelled' AND p.grand_total IS NULL THEN 1 ELSE 0 END) AS without_value,
                    SUM(CASE WHEN p.import_status = 'NEEDS_REVIEW' THEN 1 ELSE 0 END) AS needs_review
             FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN (" . PoLine::poTotalsSql() . ') t ON t.po_id = p.id WHERE ' . $where,
            $params
        ) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(
            'SELECT p.*, c.name AS customer_name, cu.name AS created_by_name, ru.name AS reviewed_by_name,
                    COALESCE(t.line_count, 0) AS line_count, COALESCE(t.total_qty, 0) AS total_qty,
                    COALESCE(t.delivered_qty, 0) AS delivered_qty, COALESCE(t.return_qty, 0) AS return_qty,
                    COALESCE(t.outstanding_qty, 0) AS outstanding_qty, COALESCE(t.open_outstanding_qty, 0) AS open_outstanding_qty
             FROM purchase_orders p
             LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN users cu ON cu.id = p.created_by
             LEFT JOIN users ru ON ru.id = p.reviewed_by
             LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
             WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function lines(int $poId): array
    {
        return Database::fetchAll(
            'SELECT pl.*, pr.name AS product_name, pr.code AS product_code_id, pr.product_code, pr.variant, pr.unit AS product_unit,
                    t.delivered_qty, t.return_qty, t.outstanding_qty,
                    (SELECT COUNT(*) FROM deliveries d WHERE d.po_line_id = pl.id) AS delivery_count
             FROM po_lines pl
             JOIN products pr ON pr.id = pl.product_id
             JOIN (' . PoLine::totalsSql() . ') t ON t.line_id = pl.id
             WHERE pl.po_id = :id ORDER BY pl.id',
            ['id' => $poId]
        );
    }

    /** No order OEF sudah dipakai OEF lain? (tidak peka huruf besar/kecil & spasi di awal/akhir) */
    public static function orderNumberTaken(string $number, ?int $exceptId = null): ?array
    {
        return Database::fetch(
            'SELECT p.id, p.code, p.order_number, c.name AS customer_name FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             WHERE LOWER(TRIM(p.order_number)) = LOWER(TRIM(:n)) AND p.id <> :ex LIMIT 1',
            ['n' => $number, 'ex' => $exceptId ?? 0]
        );
    }

    /** Nomor PO sudah dipakai PO lain? (tidak peka huruf besar/kecil) */
    public static function numberTaken(string $number, ?int $exceptId = null): ?array
    {
        return Database::fetch(
            'SELECT p.id, p.code, c.name AS customer_name FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             WHERE LOWER(TRIM(p.po_number)) = LOWER(TRIM(:n)) AND p.id <> :ex LIMIT 1',
            ['n' => $number, 'ex' => $exceptId ?? 0]
        );
    }

    /**
     * Buat PO beserta baris-barisnya dalam satu transaksi.
     * @param array<string,mixed> $header
     * @param list<array{product_id:int,order_qty:int,remark:?string,unit?:?string,unit_price?:?string}> $lines
     */
    public static function createWithLines(array $header, array $lines): int
    {
        if ($lines === []) {
            throw new DomainException('OEF minimal memiliki satu produk.');
        }
        return Database::transaction(function () use ($header, $lines): int {
            $id = self::create($header);
            $includesTax = !empty($header['price_includes_tax']);
            foreach ($lines as $line) {
                PoLine::create([
                    'po_id' => $id, 'product_id' => $line['product_id'], 'order_qty' => $line['order_qty'], 'remark' => $line['remark'] ?? null,
                    'unit' => $line['unit'] ?? null, 'unit_price' => $line['unit_price'] ?? null,
                    'item_description' => $line['item_description'] ?? null, 'subcont_supplier' => $line['subcont_supplier'] ?? null,
                    'line_subtotal' => PoLine::subtotalFor((int) $line['order_qty'], $line['unit_price'] ?? null, $includesTax, null),
                ]);
            }
            self::recalcTotals($id);
            return $id;
        });
    }

    /**
     * Hitung ulang nilai PO setelah baris/header berubah:
     *   subtotal    = jumlah subtotal (DPP) baris — hanya bila SEMUA baris punya harga;
     *                 selain itu subtotal yang tersimpan (mis. dari dokumen PO) dipertahankan
     *   grand total = subtotal − diskon + PPN + ongkir (nilai kosong dihitung 0)
     */
    public static function recalcTotals(int $poId): void
    {
        $po = Database::fetch('SELECT id, code, po_number, subtotal, discount_amount, tax_amount, shipping_cost, grand_total FROM purchase_orders WHERE id = :id', ['id' => $poId]);
        if ($po === null) {
            return;
        }
        $agg = Database::fetch('SELECT COUNT(*) AS n, SUM(CASE WHEN line_subtotal IS NULL THEN 1 ELSE 0 END) AS missing, SUM(line_subtotal) AS total FROM po_lines WHERE po_id = :id', ['id' => $poId]);
        $subtotal = $po['subtotal'];
        if ((int) $agg['n'] > 0 && (int) $agg['missing'] === 0) {
            $subtotal = Number::fromCents(Number::toCents((string) $agg['total']));
        }
        $grand = $po['grand_total'];
        if ($subtotal !== null) {
            $grand = Number::fromCents(Number::toCents((string) $subtotal) - Number::toCents((string) ($po['discount_amount'] ?? '0'))
                + Number::toCents((string) ($po['tax_amount'] ?? '0')) + Number::toCents((string) ($po['shipping_cost'] ?? '0')));
        }
        $changes = [];
        foreach (['subtotal' => $subtotal, 'grand_total' => $grand] as $col => $value) {
            $old = $po[$col] !== null ? Number::fromCents(Number::toCents((string) $po[$col])) : null;
            if ($old !== $value) {
                $changes[$col] = ['old' => $po[$col], 'new' => $value];
            }
        }
        if ($changes !== []) {
            Database::update('purchase_orders', array_map(static fn (array $c) => $c['new'], $changes), 'id = :id', ['id' => $poId]);
            Audit::log('auto_total', self::ENTITY, $poId, (string) ($po['po_number'] ?? $po['code']), $changes);
        }
    }

    /** @return array<string,int> data yang masih merujuk PO */
    public static function dependents(int $id): array
    {
        $row = Database::fetch(
            'SELECT (SELECT COUNT(*) FROM deliveries WHERE po_id = :a) AS deliveries,
                    (SELECT COUNT(*) FROM returns WHERE po_id = :b) AS returns,
                    (SELECT COUNT(*) FROM invoices_payments WHERE po_id = :c) AS invoices,
                    (SELECT COUNT(*) FROM po_financials WHERE po_id = :d) AS financials,
                    (SELECT COUNT(*) FROM leadtime WHERE po_id = :e) AS leadtimes,
                    (SELECT COUNT(*) FROM inbound_maklon WHERE po_id = :f) AS inbound',
            ['a' => $id, 'b' => $id, 'c' => $id, 'd' => $id, 'e' => $id, 'f' => $id]
        ) ?? [];
        return array_filter(array_map('intval', $row));
    }

    public static function deleteSafely(int $id): void
    {
        $po = self::find($id);
        if ($po === null) {
            throw new DomainException('OEF tidak ditemukan.');
        }
        $deps = self::dependents($id);
        if ($deps !== []) {
            $labels = ['deliveries' => 'delivery', 'returns' => 'retur/komplain', 'invoices' => 'invoice (data keuangan lama)', 'financials' => 'ringkasan finansial (data keuangan lama)', 'leadtimes' => 'lead time', 'inbound' => 'inbound maklon'];
            $parts = [];
            foreach ($deps as $k => $n) {
                $parts[] = $n . ' ' . $labels[$k];
            }
            throw new DomainException('OEF tidak dapat dihapus karena masih memiliki ' . implode(', ', $parts) . '. Gunakan status Cancelled.');
        }
        Database::transaction(function () use ($id, $po): void {
            foreach (Database::fetchAll('SELECT * FROM po_lines WHERE po_id = :id', ['id' => $id]) as $line) {
                PoLine::delete((int) $line['id'], $line);
            }
            self::delete($id, $po);
        });
    }

    /**
     * Sesuaikan status PO otomatis dari data delivery & retur:
     *  - Open / On Process → Partial   bila sudah ada kiriman tetapi masih outstanding
     *  - Open / On Process / Partial → Closed  bila seluruh line outstanding ≤ 0
     *  - Closed dan Cancelled tidak pernah diubah otomatis (keputusan manual).
     * @return string|null status baru bila berubah
     */
    public static function syncStatus(?int $poId): ?string
    {
        if ($poId === null) {
            return null;
        }
        $po = self::findFull($poId);
        if ($po === null || !in_array($po['status'], self::OPEN_STATUSES, true)) {
            return null;
        }
        $total = (int) $po['total_qty'];
        $delivered = (int) $po['delivered_qty'];
        $maxOutstanding = (int) Database::fetchValue('SELECT COALESCE(MAX(t.outstanding_qty), 0) FROM (' . PoLine::totalsSql() . ') t WHERE t.po_id = :id', ['id' => $poId]);
        $new = $po['status'];
        if ($total > 0 && $delivered > 0 && $maxOutstanding <= 0) {
            $new = 'Closed';
        } elseif ($delivered > 0 && $maxOutstanding > 0 && in_array($po['status'], ['Open', 'On Process'], true)) {
            $new = 'Partial';
        }
        if ($new === $po['status']) {
            return null;
        }
        Database::update('purchase_orders', ['status' => $new], 'id = :id', ['id' => $poId]);
        Audit::log('auto_status', self::ENTITY, $poId, (string) ($po['po_number'] ?? $po['code']), ['status' => ['old' => $po['status'], 'new' => $new]]);
        return $new;
    }

    /**
     * Pilihan PO line untuk form delivery/retur, dikelompokkan per PO.
     * @return array<string,array<int,string>>
     */
    public static function lineOptions(bool $openOnly = true, ?int $includeLineId = null, ?int $poId = null): array
    {
        $poFilter = $poId !== null ? ' AND pl.po_id = :po' : '';
        $params = ['inc' => $includeLineId ?? 0];
        if ($poId !== null) {
            $params['po'] = $poId;
        }
        $rows = Database::fetchAll(
            'SELECT pl.id, p.order_number, p.po_number, p.code AS po_code, p.status, c.name AS customer_name, pr.name AS product_name, pl.order_qty, t.outstanding_qty
             FROM po_lines pl
             JOIN purchase_orders p ON p.id = pl.po_id
             LEFT JOIN customers c ON c.id = p.customer_id
             JOIN products pr ON pr.id = pl.product_id
             JOIN (' . PoLine::totalsSql() . ') t ON t.line_id = pl.id
             WHERE ' . ($openOnly ? "((p.status IN ('Open','On Process','Partial') AND p.review_status = 'Approved') OR pl.id = :inc)" : '(1=1 OR pl.id = :inc)') . $poFilter . '
             ORDER BY p.po_date DESC, p.id DESC, pl.id',
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $ref = $r['order_number'] !== null && $r['order_number'] !== '' ? $r['order_number'] . ($r['po_number'] ? ' / PO ' . $r['po_number'] : '') : ($r['po_number'] ?? $r['po_code']);
            $group = $ref . ' — ' . ($r['customer_name'] ?? 'customer ?') . ' (' . $r['status'] . ')';
            $out[$group][(int) $r['id']] = $r['product_name'] . ' · order ' . number_format((int) $r['order_qty'], 0, ',', '.') . ' · outstanding ' . number_format((int) $r['outstanding_qty'], 0, ',', '.');
        }
        return $out;
    }
}
