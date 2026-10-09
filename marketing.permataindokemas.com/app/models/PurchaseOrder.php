<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

final class PurchaseOrder extends Model
{
    public const TABLE = 'purchase_orders';
    public const ENTITY = 'purchase_order';
    public const LABEL = 'po_number';

    public const STATUSES = ['Open', 'On Process', 'Partial', 'Closed', 'Cancelled'];
    public const OPEN_STATUSES = ['Open', 'On Process', 'Partial'];
    public const PAYMENT_TERMS = ['CBD', 'COD', 'DP 50%', 'Partial by SJ', 'NET 14', 'NET 30', 'NET 45', 'NET 60'];

    /** Konfirmasi PPIC atas Order Entry Form. NULL = data PO lama (tanpa konfirmasi). */
    public const PPIC_STATUSES = ['Pending', 'Approved', 'Rejected'];
    public const PPIC_LABELS = [
        'Pending'  => 'Menunggu PPIC',
        'Approved' => 'Bisa diproses',
        'Rejected' => 'Tidak bisa diproses',
    ];

    private const SORTS = [
        'date' => 'p.po_date', 'number' => 'COALESCE(p.order_number, p.po_number)', 'customer' => 'c.name', 'status' => 'p.status',
        'qty' => 't.total_qty', 'outstanding' => 't.outstanding_qty', 'requested' => 'p.requested_date', 'ppic' => 'p.ppic_status',
    ];

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(p.po_number LIKE :q1 OR p.code LIKE :q2 OR c.name LIKE :q3 OR EXISTS (SELECT 1 FROM po_lines pl2 JOIN products pr2 ON pr2.id = pl2.product_id WHERE pl2.po_id = p.id AND (pr2.name LIKE :q4 OR pl2.product_name_legacy LIKE :q5))
                         OR p.order_number LIKE :q6 OR p.sales_name LIKE :q7 OR p.supplier LIKE :q8)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
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
        $ppic = (string) ($f['ppic'] ?? '');
        if (in_array($ppic, self::PPIC_STATUSES, true)) {
            $where[] = 'p.ppic_status = :ppic';
            $params['ppic'] = $ppic;
        } elseif ($ppic === 'legacy') {
            $where[] = 'p.ppic_status IS NULL';
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
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, string $sort, string $dir, int $page, ?int $perPage = null): Paginator
    {
        [$where, $params] = self::filters($f);
        $order = (self::SORTS[$sort] ?? 'p.po_date') . ($dir === 'asc' ? ' ASC' : ' DESC') . ', p.id DESC';
        return Paginator::query(
            'SELECT p.*, c.name AS customer_name,
                    COALESCE(t.line_count, 0) AS line_count, COALESCE(t.total_qty, 0) AS total_qty,
                    COALESCE(t.delivered_qty, 0) AS delivered_qty, COALESCE(t.return_qty, 0) AS return_qty,
                    COALESCE(t.outstanding_qty, 0) AS outstanding_qty, COALESCE(t.open_outstanding_qty, 0) AS open_outstanding_qty,
                    (SELECT pr1.name FROM po_lines pl1 JOIN products pr1 ON pr1.id = pl1.product_id WHERE pl1.po_id = p.id ORDER BY pl1.id LIMIT 1) AS first_product,
                    sd.delivery_date AS schedule_date, sd.status AS schedule_status
             FROM purchase_orders p
             LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
             LEFT JOIN deliveries sd ON sd.id = p.schedule_delivery_id
             WHERE ' . $where,
            $params,
            $order,
            $page,
            $perPage
        );
    }

    /** @return array<string,mixed> ringkasan untuk filter aktif */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        return Database::fetch(
            "SELECT COUNT(*) AS po_count, COALESCE(SUM(t.total_qty), 0) AS total_qty, COALESCE(SUM(t.delivered_qty), 0) AS delivered_qty,
                    COALESCE(SUM(t.open_outstanding_qty), 0) AS outstanding_qty, COALESCE(SUM(p.ppic_status = 'Pending'), 0) AS ppic_pending
             FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN (" . PoLine::poTotalsSql() . ') t ON t.po_id = p.id WHERE ' . $where,
            $params
        ) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(
            'SELECT p.*, c.name AS customer_name, c.address AS customer_address, cu.name AS created_by_name, pu.name AS ppic_by_name,
                    COALESCE(t.line_count, 0) AS line_count, COALESCE(t.total_qty, 0) AS total_qty,
                    COALESCE(t.delivered_qty, 0) AS delivered_qty, COALESCE(t.return_qty, 0) AS return_qty,
                    COALESCE(t.outstanding_qty, 0) AS outstanding_qty, COALESCE(t.open_outstanding_qty, 0) AS open_outstanding_qty,
                    sd.delivery_date AS schedule_date, sd.status AS schedule_status, sd.code AS schedule_code, sd.sj_number AS schedule_sj
             FROM purchase_orders p
             LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN users cu ON cu.id = p.created_by
             LEFT JOIN users pu ON pu.id = p.ppic_by
             LEFT JOIN deliveries sd ON sd.id = p.schedule_delivery_id
             LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
             WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function lines(int $poId): array
    {
        return Database::fetchAll(
            'SELECT pl.*, pr.name AS product_name, pr.code AS product_code_id, pr.product_code, pr.variant, pr.unit,
                    t.delivered_qty, t.return_qty, t.outstanding_qty,
                    (SELECT COUNT(*) FROM deliveries d WHERE d.po_line_id = pl.id) AS delivery_count
             FROM po_lines pl
             JOIN products pr ON pr.id = pl.product_id
             JOIN (' . PoLine::totalsSql() . ') t ON t.line_id = pl.id
             WHERE pl.po_id = :id ORDER BY pl.id',
            ['id' => $poId]
        );
    }

    /** Label order untuk tampilan: No. OEF, lalu No. PO customer, lalu kode. */
    public static function displayNumber(array $po): string
    {
        foreach (['order_number', 'po_number', 'code'] as $k) {
            if (!empty($po[$k])) {
                return (string) $po[$k];
            }
        }
        return '#' . ($po['id'] ?? '');
    }

    /** Ekspresi SQL label order (alias tabel purchase_orders = $alias). */
    public static function numberSql(string $alias = 'p'): string
    {
        return "COALESCE({$alias}.order_number, {$alias}.po_number, {$alias}.code)";
    }

    public static function ppicPendingCount(): int
    {
        return (int) Database::fetchValue("SELECT COUNT(*) FROM purchase_orders WHERE ppic_status = 'Pending'");
    }

    /** No. order (diisi manual) sudah dipakai order lain? Tidak peka huruf besar/kecil & spasi. */
    public static function orderNumberTaken(string $number, ?int $exceptId = null): ?array
    {
        return Database::fetch(
            'SELECT p.id, p.code, p.order_number, c.name AS customer_name FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             WHERE LOWER(TRIM(p.order_number)) = LOWER(TRIM(:n)) AND p.id <> :ex LIMIT 1',
            ['n' => $number, 'ex' => $exceptId ?? 0]
        );
    }

    /** No. order terakhir yang diinput (bantuan saat mengisi nomor manual). */
    public static function lastOrderNumber(): ?string
    {
        $value = Database::fetchValue('SELECT order_number FROM purchase_orders WHERE order_number IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 1');
        return is_string($value) && $value !== '' ? $value : null;
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
     * @param list<array{product_id:int,order_qty:int,remark:?string}> $lines
     */
    public static function createWithLines(array $header, array $lines): int
    {
        if ($lines === []) {
            throw new DomainException('PO minimal memiliki satu baris produk.');
        }
        return Database::transaction(function () use ($header, $lines): int {
            $id = self::create($header);
            foreach ($lines as $line) {
                PoLine::create(['po_id' => $id, 'product_id' => $line['product_id'], 'order_qty' => $line['order_qty'], 'remark' => $line['remark'] ?? null]);
            }
            return $id;
        });
    }

    /**
     * Data yang masih merujuk PO. Jadwal delivery otomatis dari OEF yang belum
     * berjalan (Scheduled/Cancelled) tidak dihitung — ikut terhapus bersama OEF.
     * @return array<string,int>
     */
    public static function dependents(int $id): array
    {
        $row = Database::fetch(
            "SELECT (SELECT COUNT(*) FROM deliveries d WHERE d.po_id = :a
                        AND NOT (d.id <=> (SELECT schedule_delivery_id FROM purchase_orders WHERE id = :g) AND d.status IN ('Scheduled','Cancelled')
                                 AND NOT EXISTS (SELECT 1 FROM returns rr WHERE rr.delivery_id = d.id))) AS deliveries,
                    (SELECT COUNT(*) FROM returns WHERE po_id = :b) AS returns,
                    (SELECT COUNT(*) FROM invoices_payments WHERE po_id = :c) AS invoices,
                    (SELECT COUNT(*) FROM po_financials WHERE po_id = :d) AS financials,
                    (SELECT COUNT(*) FROM leadtime WHERE po_id = :e) AS leadtimes,
                    (SELECT COUNT(*) FROM inbound_maklon WHERE po_id = :f) AS inbound",
            ['a' => $id, 'b' => $id, 'c' => $id, 'd' => $id, 'e' => $id, 'f' => $id, 'g' => $id]
        ) ?? [];
        return array_filter(array_map('intval', $row));
    }

    public static function deleteSafely(int $id): void
    {
        $po = self::find($id);
        if ($po === null) {
            throw new DomainException('PO tidak ditemukan.');
        }
        $deps = self::dependents($id);
        if ($deps !== []) {
            $labels = ['deliveries' => 'delivery', 'returns' => 'retur', 'invoices' => 'invoice', 'financials' => 'ringkasan finansial', 'leadtimes' => 'lead time', 'inbound' => 'inbound maklon'];
            $parts = [];
            foreach ($deps as $k => $n) {
                $parts[] = $n . ' ' . $labels[$k];
            }
            throw new DomainException('PO tidak dapat dihapus karena masih memiliki ' . implode(', ', $parts) . '. Gunakan status Cancelled.');
        }
        Database::transaction(function () use ($id, $po): void {
            if (!empty($po['schedule_delivery_id'])) {
                $schedule = Delivery::find((int) $po['schedule_delivery_id']);
                Database::update('purchase_orders', ['schedule_delivery_id' => null], 'id = :id', ['id' => $id]);
                if ($schedule !== null) {
                    Delivery::delete((int) $schedule['id'], $schedule);
                }
            }
            foreach (Database::fetchAll('SELECT * FROM po_lines WHERE po_id = :id', ['id' => $id]) as $line) {
                PoLine::delete((int) $line['id'], $line);
            }
            self::delete($id, $po);
            // notifikasi yang menunjuk ke order ini tidak lagi bisa dibuka
            Database::delete('notifications', 'entity_type = :t AND entity_id = :id', ['t' => self::ENTITY, 'id' => $id]);
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
            'SELECT pl.id, COALESCE(p.order_number, p.po_number) AS po_number, p.code AS po_code, p.status, c.name AS customer_name, pr.name AS product_name, pl.order_qty, t.outstanding_qty
             FROM po_lines pl
             JOIN purchase_orders p ON p.id = pl.po_id
             LEFT JOIN customers c ON c.id = p.customer_id
             JOIN products pr ON pr.id = pl.product_id
             JOIN (' . PoLine::totalsSql() . ') t ON t.line_id = pl.id
             WHERE ' . ($openOnly ? "(p.status IN ('Open','On Process','Partial') OR pl.id = :inc)" : '(1=1 OR pl.id = :inc)') . $poFilter . '
             ORDER BY p.po_date DESC, p.id DESC, pl.id',
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $group = ($r['po_number'] ?? $r['po_code']) . ' — ' . ($r['customer_name'] ?? 'customer ?') . ' (' . $r['status'] . ')';
            $out[$group][(int) $r['id']] = $r['product_name'] . ' · order ' . number_format((int) $r['order_qty'], 0, ',', '.') . ' · outstanding ' . number_format((int) $r['outstanding_qty'], 0, ',', '.');
        }
        return $out;
    }
}
