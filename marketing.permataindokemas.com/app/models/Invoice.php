<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Database;
use App\Helpers\Number;
use App\Helpers\Paginator;
use DomainException;

/**
 * Invoice & pembayaran (tabel invoices_payments, satu baris per invoice).
 *
 * ATURAN:
 *   Sisa tagihan = Nilai invoice − Total dibayar (tidak boleh bayar melebihi tagihan)
 *   Status:
 *     Paid     → sisa ≤ 0
 *     Overdue  → belum lunas dan jatuh tempo < hari ini
 *     Partial  → sudah ada pembayaran, belum lunas, belum jatuh tempo
 *     Unpaid   → belum ada pembayaran, belum jatuh tempo
 *   Setiap pembayaran menambah "Total dibayar" dan dicatat di audit log
 *   (action "payment") sebagai riwayat pembayaran.
 */
final class Invoice extends Model
{
    public const TABLE = 'invoices_payments';
    public const ENTITY = 'invoice';
    public const LABEL = 'invoice_number';

    public const STATUSES = ['Unpaid', 'Partial', 'Paid', 'Overdue'];
    public const OPEN_STATUSES = ['Unpaid', 'Partial', 'Overdue'];
    /** Issue migrasi yang selesai otomatis bila datanya dilengkapi user. */
    public const PO_ISSUES = ['PO NOT FOUND'];
    public const DUE_ISSUES = ['DUE DATE MISSING'];

    private const SELECT = 'SELECT i.*, (i.invoice_amount - i.paid_amount) AS outstanding_amount,
            c.name AS customer_name, p.po_number, p.code AS po_code, p.payment_term
        FROM invoices_payments i
        LEFT JOIN customers c ON c.id = i.customer_id
        LEFT JOIN purchase_orders p ON p.id = i.po_id';

    private const SORTS = [
        'date' => 'i.invoice_date', 'due' => 'i.due_date', 'number' => 'i.invoice_number', 'customer' => 'c.name',
        'amount' => 'i.invoice_amount', 'outstanding' => '(i.invoice_amount - i.paid_amount)',
    ];

    /** Status dari nilai tagihan, pembayaran, dan jatuh tempo (fungsi murni). */
    public static function computeStatus(mixed $amount, mixed $paid, ?string $dueDate, string $today): string
    {
        $amountC = Number::toCents($amount) ?? 0;
        $paidC = Number::toCents($paid) ?? 0;
        if ($amountC > 0 && $amountC - $paidC <= 0) {
            return 'Paid';
        }
        if ($dueDate !== null && $dueDate !== '' && $dueDate < $today) {
            return 'Overdue';
        }
        return $paidC > 0 ? 'Partial' : 'Unpaid';
    }

    /** Ekspresi SQL yang sama dengan computeStatus(). */
    private static function statusCaseSql(string $todayParam): string
    {
        return "CASE WHEN invoice_amount > 0 AND invoice_amount - paid_amount <= 0 THEN 'Paid'
                     WHEN due_date IS NOT NULL AND due_date < :{$todayParam} THEN 'Overdue'
                     WHEN paid_amount > 0 THEN 'Partial'
                     ELSE 'Unpaid' END";
    }

    /**
     * Sinkronkan status tersimpan (mis. invoice yang baru lewat jatuh tempo → Overdue).
     * Setiap perubahan dicatat di audit log sebagai "auto_status".
     */
    public static function refreshStatuses(string $today): int
    {
        $rows = Database::fetchAll(
            'SELECT * FROM (SELECT id, invoice_number, code, status, ' . self::statusCaseSql('t1') . ' AS new_status FROM invoices_payments) x
             WHERE x.new_status <> x.status',
            ['t1' => $today]
        );
        foreach ($rows as $r) {
            Database::update(self::TABLE, ['status' => $r['new_status']], 'id = :id', ['id' => $r['id']]);
            Audit::log('auto_status', self::ENTITY, (int) $r['id'], (string) ($r['invoice_number'] ?? $r['code']), ['status' => ['old' => $r['status'], 'new' => $r['new_status']]]);
        }
        return count($rows);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f, string $today): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(i.invoice_number LIKE :q1 OR i.code LIKE :q2 OR p.po_number LIKE :q3 OR i.po_number_legacy LIKE :q4 OR c.name LIKE :q5 OR i.payment_receipt_number LIKE :q6)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'open') {
            $where[] = "i.status <> 'Paid'";
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'i.status = :status';
            $params['status'] = $status;
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'i.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'i.invoice_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'i.invoice_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['due'] ?? '') === 'week') {
            $where[] = "i.status <> 'Paid' AND i.due_date BETWEEN :d1 AND :d2";
            $params['d1'] = $today;
            $params['d2'] = date('Y-m-d', strtotime($today . ' +7 days'));
        }
        if (($f['link'] ?? '') === 'no_po') {
            $where[] = 'i.po_id IS NULL';
        } elseif (($f['link'] ?? '') === 'no_due') {
            $where[] = "i.due_date IS NULL AND i.status <> 'Paid'";
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, string $sort, string $dir, string $today, int $page): Paginator
    {
        [$where, $params] = self::filters($f, $today);
        $order = (self::SORTS[$sort] ?? 'i.invoice_date') . ($dir === 'asc' ? ' ASC' : ' DESC') . ', i.id DESC';
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, $order, $page);
    }

    /** @return array<string,mixed> */
    public static function summary(array $f, string $today): array
    {
        [$where, $params] = self::filters($f, $today);
        return Database::fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(i.invoice_amount), 0) AS invoiced, COALESCE(SUM(i.paid_amount), 0) AS paid,
                    COALESCE(SUM(GREATEST(i.invoice_amount - i.paid_amount, 0)), 0) AS outstanding,
                    COALESCE(SUM(i.status = 'Overdue'), 0) AS overdue_count,
                    COALESCE(SUM(CASE WHEN i.status = 'Overdue' THEN GREATEST(i.invoice_amount - i.paid_amount, 0) ELSE 0 END), 0) AS overdue_amount
             FROM invoices_payments i LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN purchase_orders p ON p.id = i.po_id
             WHERE " . $where,
            $params
        ) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE i.id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function forPo(int $poId): array
    {
        return Database::fetchAll(self::SELECT . ' WHERE i.po_id = :id ORDER BY i.invoice_date DESC, i.id DESC', ['id' => $poId]);
    }

    /** Nomor invoice sudah dipakai invoice lain? (tidak peka huruf besar/kecil) */
    public static function numberTaken(string $number, ?int $exceptId = null): ?array
    {
        return Database::fetch(
            'SELECT id, code FROM invoices_payments WHERE LOWER(TRIM(invoice_number)) = LOWER(TRIM(:n)) AND id <> :ex LIMIT 1',
            ['n' => $number, 'ex' => $exceptId ?? 0]
        );
    }

    /**
     * Jatuh tempo default bila dikosongkan:
     *   termin PO "NET n" → n hari; CBD / COD → hari yang sama;
     *   selain itu → pengaturan invoice_default_due_days (default 30 hari).
     */
    public static function defaultDueDate(string $invoiceDate, ?int $poId): string
    {
        $days = Setting::int('invoice_default_due_days', 30);
        if ($poId !== null) {
            $term = strtoupper(trim((string) Database::fetchValue('SELECT payment_term FROM purchase_orders WHERE id = :id', ['id' => $poId])));
            if (preg_match('/\bNET\s*(\d{1,3})\b/', $term, $m)) {
                $days = (int) $m[1];
            } elseif (in_array($term, ['CBD', 'COD'], true)) {
                $days = 0;
            }
        }
        return date('Y-m-d', strtotime($invoiceDate . ' +' . $days . ' days'));
    }

    /**
     * Simpan invoice (baru / edit). Status dihitung ulang.
     * @param array<string,mixed> $data
     */
    public static function saveInvoice(?int $id, array $data, string $today): int
    {
        return Database::transaction(function () use ($id, $data, $today): int {
            $before = null;
            if ($id !== null) {
                $before = Database::fetch('SELECT * FROM invoices_payments WHERE id = :id FOR UPDATE', ['id' => $id]);
                if ($before === null) {
                    throw new DomainException('Invoice tidak ditemukan.');
                }
            }
            $paid = $data['paid_amount'] ?? ($before['paid_amount'] ?? '0');
            if ((Number::toCents($paid) ?? 0) > (Number::toCents($data['invoice_amount']) ?? 0)) {
                throw new DomainException('Nilai invoice tidak boleh lebih kecil dari total yang sudah dibayar (' . Number::money($paid) . ').');
            }
            $data['status'] = self::computeStatus($data['invoice_amount'], $paid, $data['due_date'] ?? null, $today);
            if ($id === null) {
                $data['paid_amount'] = $data['paid_amount'] ?? '0.00';
                $id = self::create($data);
            } else {
                self::update($id, $data, $before);
            }
            if (!empty($data['po_id']) && ($before === null || $before['po_id'] === null)) {
                MigrationIssue::resolveForRecord('INVOICES_PAYMENTS', $id, self::PO_ISSUES, 'Invoice dihubungkan ke PO oleh user.');
            }
            if (!empty($data['due_date']) && ($before === null || $before['due_date'] === null)) {
                MigrationIssue::resolveForRecord('INVOICES_PAYMENTS', $id, self::DUE_ISSUES, 'Jatuh tempo diisi oleh user.');
            }
            return $id;
        });
    }

    /**
     * Catat pembayaran. Baris invoice dikunci (FOR UPDATE) agar dua pembayaran
     * bersamaan tidak melebihi tagihan.
     * @param array{amount:string,payment_date:string,payment_receipt_number:?string,payment_attachment:?string,note:?string} $p
     * @return array{status:string,paid_amount:string,outstanding:string}
     */
    public static function recordPayment(int $id, array $p, string $today): array
    {
        return Database::transaction(function () use ($id, $p, $today): array {
            $inv = Database::fetch('SELECT * FROM invoices_payments WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($inv === null) {
                throw new DomainException('Invoice tidak ditemukan.');
            }
            $amountC = Number::toCents($p['amount']) ?? 0;
            $invoiceC = Number::toCents($inv['invoice_amount']) ?? 0;
            $paidC = Number::toCents($inv['paid_amount']) ?? 0;
            $remainingC = $invoiceC - $paidC;
            if ($amountC <= 0) {
                throw new DomainException('Jumlah pembayaran harus lebih dari 0.');
            }
            if ($remainingC <= 0) {
                throw new DomainException('Invoice ini sudah lunas.');
            }
            if ($amountC > $remainingC) {
                throw new DomainException('Pembayaran melebihi sisa tagihan (' . Number::money(Number::fromCents($remainingC)) . ').');
            }
            $newPaid = Number::fromCents($paidC + $amountC);
            $lastDate = $inv['payment_date'] !== null && (string) $inv['payment_date'] > $p['payment_date'] ? (string) $inv['payment_date'] : $p['payment_date'];
            $update = [
                'paid_amount'  => $newPaid,
                'payment_date' => $lastDate,
                'status'       => self::computeStatus($inv['invoice_amount'], $newPaid, $inv['due_date'], $today),
            ];
            if (!empty($p['payment_receipt_number'])) {
                $update['payment_receipt_number'] = $p['payment_receipt_number'];
            }
            if (!empty($p['payment_attachment'])) {
                $update['payment_attachment'] = $p['payment_attachment'];
            }
            $update['updated_by'] = \App\Helpers\Auth::id();
            Database::update(self::TABLE, $update, 'id = :id', ['id' => $id]);
            Audit::log('payment', self::ENTITY, $id, (string) ($inv['invoice_number'] ?? $inv['code']), [
                'amount'       => ['old' => null, 'new' => Number::fromCents($amountC)],
                'payment_date' => ['old' => null, 'new' => $p['payment_date']],
                'receipt'      => ['old' => null, 'new' => $p['payment_receipt_number'] ?? null],
                'note'         => ['old' => null, 'new' => $p['note'] ?? null],
                'paid_amount'  => ['old' => $inv['paid_amount'], 'new' => $newPaid],
                'status'       => ['old' => $inv['status'], 'new' => $update['status']],
            ]);
            return ['status' => $update['status'], 'paid_amount' => $newPaid, 'outstanding' => Number::fromCents($invoiceC - $paidC - $amountC)];
        });
    }

    /** @return list<array<string,mixed>> riwayat pembayaran dari audit log (terbaru dulu) */
    public static function paymentHistory(int $id): array
    {
        $rows = Database::fetchAll(
            "SELECT created_at, user_name, changes FROM audit_logs WHERE entity_type = 'invoice' AND entity_id = :id AND action = 'payment' ORDER BY created_at DESC, id DESC",
            ['id' => $id]
        );
        $out = [];
        foreach ($rows as $r) {
            $c = json_decode((string) $r['changes'], true) ?: [];
            $out[] = [
                'recorded_at'  => $r['created_at'],
                'user_name'    => $r['user_name'],
                'amount'       => $c['amount']['new'] ?? null,
                'payment_date' => $c['payment_date']['new'] ?? null,
                'receipt'      => $c['receipt']['new'] ?? null,
                'note'         => $c['note']['new'] ?? null,
            ];
        }
        return $out;
    }

    public static function remove(int $id): void
    {
        $inv = self::find($id);
        if ($inv === null) {
            throw new DomainException('Invoice tidak ditemukan.');
        }
        if ((Number::toCents($inv['paid_amount']) ?? 0) > 0) {
            throw new DomainException('Invoice yang sudah memiliki pembayaran tidak dapat dihapus. Koreksi pembayaran lebih dulu bila memang salah input.');
        }
        Database::transaction(function () use ($id, $inv): void {
            self::delete($id, $inv);
            MigrationIssue::closeForDeletedRecord('INVOICES_PAYMENTS', $id);
        });
    }

    /** @return array<int,string> PO milik customer (untuk form), terbaru dulu */
    public static function poOptions(?int $customerId, ?int $includeId = null): array
    {
        $rows = Database::fetchAll(
            'SELECT p.id, p.po_number, p.code, p.status, p.payment_term, c.name AS customer_name
             FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             WHERE ' . ($customerId !== null ? '(p.customer_id = :cid OR p.id = :inc)' : '(1=1 OR p.id = :inc)') . '
             ORDER BY p.po_date DESC, p.id DESC',
            $customerId !== null ? ['cid' => $customerId, 'inc' => $includeId ?? 0] : ['inc' => $includeId ?? 0]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ($r['po_number'] ?? $r['code']) . ($customerId === null ? ' — ' . ($r['customer_name'] ?? '?') : '')
                . ' (' . $r['status'] . ($r['payment_term'] ? ', ' . $r['payment_term'] : '') . ')';
        }
        return $out;
    }
}
