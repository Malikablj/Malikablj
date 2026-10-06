<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Number;
use App\Helpers\Paginator;
use App\Helpers\Validator;
use DomainException;

/** Catatan data migrasi yang perlu ditinjau (lihat Settings › Migration Issues). */
final class MigrationIssue
{
    public const STATUSES = ['Needs Review', 'Auto-Corrected', 'Resolved', 'Ignored'];

    /** Halaman record asal per sheet: [path, permission, label]. */
    public const RECORD_LINKS = [
        'CUSTOMERS'         => ['/customers/{id}', 'customers.view', 'Customer'],
        'PRODUCTS'          => ['/products/{id}', 'products.view', 'Produk'],
        'PURCHASE_ORDERS'   => ['/purchase-orders/{id}', 'purchase_orders.view', 'Purchase order'],
        'PO_LINES'          => ['/po-lines/{id}/edit', 'purchase_orders.edit', 'Baris PO'],
        'DELIVERIES'        => ['/deliveries/{id}', 'deliveries.view', 'Delivery'],
        'RETURNS'           => ['/returns/{id}/edit', 'returns.edit', 'Retur'],
        'STOCK'             => ['/stock/{id}/edit', 'stock.edit', 'Stok'],
        'LEADTIME'          => ['/lead-times/{id}/edit', 'leadtime.edit', 'Lead time'],
        'INBOUND_MAKLON'    => ['/inbound/{id}', 'inbound.view', 'Inbound maklon'],
        'INVOICES_PAYMENTS' => ['/invoices/{id}', 'finance.view', 'Invoice'],
        'PO_FINANCIALS'     => ['/po-financials/{id}', 'finance.view', 'PO financial'],
    ];

    /**
     * Kolom yang boleh diisi ulang dari issue (pilih nilai master atau nilai
     * spreadsheet legacy): sheet => [field => [tabel, tipe, entity audit]].
     */
    public const VALUE_FIELDS = [
        'DELIVERIES'        => ['delivery_date' => ['deliveries', 'date', 'delivery']],
        'INVOICES_PAYMENTS' => ['invoice_date' => ['invoices_payments', 'date', 'invoice'], 'payment_date' => ['invoices_payments', 'date', 'invoice']],
        'PURCHASE_ORDERS'   => ['po_date' => ['purchase_orders', 'date', 'purchase_order']],
        'PO_FINANCIALS'     => [
            'ppn'                => ['po_financials', 'decimal', 'po_financial'],
            'total_order_amount' => ['po_financials', 'decimal', 'po_financial'],
            'unit_price'         => ['po_financials', 'decimal', 'po_financial'],
        ],
    ];

    /** Jenis issue yang otomatis selesai saat record berhasil dihubungkan ke PO line. */
    public const LINK_TYPES = ['PRODUCT/LINE NOT MATCHED', 'PO NOT FOUND', 'LINE NOT LINKED', 'PO LINE MISMATCH', 'RETURN NOT LINKED TO PO LINE'];

    /**
     * Tandai issue terbuka milik sebuah record sebagai Resolved.
     * @param list<string> $types
     */
    public static function resolveForRecord(string $table, int $recordId, array $types, string $note): int
    {
        if ($types === []) {
            return 0;
        }
        $placeholders = [];
        $params = ['t' => $table, 'r' => $recordId, 'note' => $note, 'by' => Auth::id(), 'at' => date('Y-m-d H:i:s')];
        foreach (array_values($types) as $i => $type) {
            $placeholders[] = ':ty' . $i;
            $params['ty' . $i] = $type;
        }
        $count = Database::query(
            "UPDATE migration_issues SET resolution_status = 'Resolved', resolution_note = :note, resolved_by = :by, resolved_at = :at
             WHERE table_name = :t AND record_id = :r AND resolution_status = 'Needs Review' AND issue_type IN (" . implode(',', $placeholders) . ')',
            $params
        )->rowCount();
        if ($count > 0) {
            Audit::log('resolve_issue', 'migration_issue', null, "{$table} #{$recordId}", ['resolved' => ['old' => null, 'new' => $count . ' issue: ' . $note]]);
        }
        return $count;
    }

    /**
     * Record legacy dihapus user (mis. baris judul/ringkasan spreadsheet):
     * issue yang masih "Needs Review" untuk record tsb ikut ditutup.
     */
    public static function closeForDeletedRecord(string $table, int $recordId): int
    {
        $count = Database::query(
            "UPDATE migration_issues SET resolution_status = 'Resolved', resolution_note = :note, resolved_by = :by, resolved_at = :at
             WHERE table_name = :t AND record_id = :r AND resolution_status = 'Needs Review'",
            ['t' => $table, 'r' => $recordId, 'note' => 'Record dihapus oleh user.', 'by' => Auth::id(), 'at' => date('Y-m-d H:i:s')]
        )->rowCount();
        if ($count > 0) {
            Audit::log('resolve_issue', 'migration_issue', null, "{$table} #{$recordId}", ['resolved' => ['old' => null, 'new' => $count . ' issue: record dihapus']]);
        }
        return $count;
    }

    /** @return list<array<string,mixed>> issue terbuka untuk sebuah record */
    public static function openForRecord(string $table, int $recordId): array
    {
        return Database::fetchAll(
            "SELECT * FROM migration_issues WHERE table_name = :t AND record_id = :r AND resolution_status IN ('Needs Review','Auto-Corrected') ORDER BY id",
            ['t' => $table, 'r' => $recordId]
        );
    }

    public static function openCount(): int
    {
        return (int) Database::fetchValue("SELECT COUNT(*) FROM migration_issues WHERE resolution_status = 'Needs Review'");
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(m.record_code LIKE :q1 OR m.legacy_po LIKE :q2 OR m.legacy_product LIKE :q3 OR m.description LIKE :q4 OR m.code LIKE :q5 OR m.master_value LIKE :q6)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['table'])) {
            $where[] = 'm.table_name = :t';
            $params['t'] = (string) $f['table'];
        }
        if (!empty($f['type'])) {
            $where[] = 'm.issue_type = :ty';
            $params['ty'] = (string) $f['type'];
        }
        $status = (string) ($f['status'] ?? 'Needs Review');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'm.resolution_status = :st';
            $params['st'] = $status;
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(
            'SELECT m.*, u.name AS resolved_by_name FROM migration_issues m LEFT JOIN users u ON u.id = m.resolved_by WHERE ' . $where,
            $params,
            'm.table_name, m.issue_type, m.id',
            $page,
            50
        );
    }

    /** @return array<string,int> jumlah per status */
    public static function statusCounts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach (Database::fetchAll('SELECT resolution_status, COUNT(*) AS n FROM migration_issues GROUP BY resolution_status') as $r) {
            $out[(string) $r['resolution_status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array{table_name:string,issue_type:string,n:int}> ringkasan per sheet & jenis untuk status tertentu */
    public static function typeSummary(string $status): array
    {
        $where = in_array($status, self::STATUSES, true) ? 'WHERE resolution_status = :s' : '';
        return array_map(static fn ($r) => ['table_name' => (string) $r['table_name'], 'issue_type' => (string) $r['issue_type'], 'n' => (int) $r['n']], Database::fetchAll(
            "SELECT table_name, issue_type, COUNT(*) AS n FROM migration_issues {$where} GROUP BY table_name, issue_type ORDER BY n DESC",
            $where !== '' ? ['s' => $status] : []
        ));
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_map('strval', Database::fetchColumn('SELECT DISTINCT table_name FROM migration_issues ORDER BY table_name'));
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map('strval', Database::fetchColumn('SELECT DISTINCT issue_type FROM migration_issues ORDER BY issue_type'));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT m.*, u.name AS resolved_by_name FROM migration_issues m LEFT JOIN users u ON u.id = m.resolved_by WHERE m.id = :id', ['id' => $id]);
    }

    /** Link ke record asal (null bila tidak ada halaman / tidak berhak). */
    public static function recordLink(array $issue): ?string
    {
        $map = self::RECORD_LINKS[$issue['table_name']] ?? null;
        if ($map === null || empty($issue['record_id']) || !Auth::can($map[1])) {
            return null;
        }
        return str_replace('{id}', (string) (int) $issue['record_id'], $map[0]);
    }

    /** Apakah issue ini bisa diselesaikan dengan memilih nilai master / legacy. */
    public static function valueTarget(array $issue): ?array
    {
        $target = self::VALUE_FIELDS[$issue['table_name']][$issue['field_name'] ?? ''] ?? null;
        if ($target === null || empty($issue['record_id'])) {
            return null;
        }
        return ['table' => $target[0], 'type' => $target[1], 'entity' => $target[2], 'column' => (string) $issue['field_name']];
    }

    /**
     * Label pasangan nilai: issue dari import database PO membandingkan nilai di
     * aplikasi (master_value) dengan dokumen PO (suggested_value); issue workbook
     * AppSheet membandingkan master workbook dengan spreadsheet legacy.
     * @return array{master:string,suggested:string,use_master:string,use_suggested:string}
     */
    public static function valueLabels(array $issue): array
    {
        return !empty($issue['import_log_id'])
            ? ['master' => 'Nilai di aplikasi', 'suggested' => 'Nilai di dokumen PO', 'use_master' => 'Pakai nilai di aplikasi', 'use_suggested' => 'Pakai nilai dokumen PO']
            : ['master' => 'Nilai di master workbook', 'suggested' => 'Nilai di spreadsheet legacy', 'use_master' => 'Pakai nilai master', 'use_suggested' => 'Pakai nilai spreadsheet legacy'];
    }

    /**
     * Issue "POSSIBLE EXISTING PO": admin memastikan PO di file sama dengan PO di
     * aplikasi → PO aplikasi diberi import_ref, sehingga import berikutnya
     * melengkapinya (bukan membuat PO baru).
     */
    public const LINK_PO_TYPES = ['POSSIBLE EXISTING PO', 'PO MATCH AMBIGUOUS'];

    /** @return list<string> kode PO kandidat yang boleh dipilih untuk issue "Hubungkan" */
    public static function linkCandidates(array $issue): array
    {
        if (!in_array($issue['issue_type'], self::LINK_PO_TYPES, true)) {
            return [];
        }
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) ($issue['master_value'] ?? '')))));
        if ($codes === [] && !empty($issue['record_code'])) {
            $codes = [(string) $issue['record_code']];
        }
        return $codes;
    }

    public static function linkPo(int $id, ?string $note, ?string $poCode = null): void
    {
        $issue = self::find($id);
        if ($issue === null || !in_array($issue['issue_type'], self::LINK_PO_TYPES, true) || $issue['resolution_status'] !== 'Needs Review') {
            throw new DomainException('Issue ini tidak dapat dihubungkan ke PO.');
        }
        $ref = trim((string) $issue['suggested_value']);
        $candidates = self::linkCandidates($issue);
        $poCode ??= $candidates[0] ?? null;
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ref) || $poCode === null || !in_array($poCode, $candidates, true)) {
            throw new DomainException('Pilih salah satu PO kandidat.');
        }
        Database::transaction(function () use ($ref, $note, $id, $poCode): void {
            $po = Database::fetch('SELECT id, code, po_number, import_ref FROM purchase_orders WHERE code = :c FOR UPDATE', ['c' => $poCode]);
            if ($po === null) {
                throw new DomainException('PO tujuan sudah tidak ada.');
            }
            if ($po['import_ref'] !== null && $po['import_ref'] !== $ref) {
                throw new DomainException('PO ' . $po['code'] . ' sudah terhubung ke data lain dari file (' . $po['import_ref'] . ').');
            }
            if (Database::fetchValue('SELECT 1 FROM purchase_orders WHERE import_ref = :r AND id <> :id', ['r' => $ref, 'id' => (int) $po['id']])) {
                throw new DomainException('Data ' . $ref . ' dari file sudah terhubung ke PO lain.');
            }
            Database::update('purchase_orders', ['import_ref' => $ref, 'updated_by' => Auth::id()], 'id = :id', ['id' => (int) $po['id']]);
            Audit::log('update', 'purchase_order', (int) $po['id'], (string) ($po['po_number'] ?? $po['code']), ['import_ref' => ['old' => $po['import_ref'], 'new' => $ref]]);
            self::setStatus($id, 'Resolved', trim('Dihubungkan ke ' . $po['code'] . '; jalankan import database PO lagi untuk melengkapi data.' . ($note ? ' ' . $note : '')));
        });
    }

    /** Ubah status satu issue (Resolved / Ignored / Needs Review) + catatan, tercatat di audit log. */
    public static function setStatus(int $id, string $status, ?string $note): void
    {
        if (!in_array($status, ['Resolved', 'Ignored', 'Needs Review'], true)) {
            throw new DomainException('Status tidak valid.');
        }
        $issue = self::find($id);
        if ($issue === null) {
            throw new DomainException('Issue tidak ditemukan.');
        }
        $closing = $status !== 'Needs Review';
        Database::update('migration_issues', [
            'resolution_status' => $status,
            'resolution_note'   => $note,
            'resolved_by'       => $closing ? Auth::id() : null,
            'resolved_at'       => $closing ? date('Y-m-d H:i:s') : null,
        ], 'id = :id', ['id' => $id]);
        Audit::log(match ($status) { 'Resolved' => 'resolve_issue', 'Ignored' => 'ignore_issue', default => 'reopen_issue' },
            'migration_issue', $id, (string) $issue['code'], ['resolution_status' => ['old' => $issue['resolution_status'], 'new' => $status], 'note' => ['old' => null, 'new' => $note]]);
        if ($issue['table_name'] === 'PURCHASE_ORDERS' && !empty($issue['record_id'])) {
            self::refreshPoReviewStatus((int) $issue['record_id']);
        }
    }

    /** PO hasil import: NEEDS_REVIEW selama masih ada issue terbuka, selain itu OK. */
    public static function refreshPoReviewStatus(int $poId): void
    {
        $open = (bool) Database::fetchValue(
            "SELECT 1 FROM migration_issues WHERE table_name = 'PURCHASE_ORDERS' AND record_id = :id AND resolution_status = 'Needs Review' LIMIT 1",
            ['id' => $poId]
        );
        Database::query(
            'UPDATE purchase_orders SET import_status = :s WHERE id = :id AND import_status IS NOT NULL AND import_status <> :s2',
            ['s' => $open ? 'NEEDS_REVIEW' : 'OK', 's2' => $open ? 'NEEDS_REVIEW' : 'OK', 'id' => $poId]
        );
    }

    /**
     * Terapkan nilai master atau nilai legacy (suggested) ke record asal, lalu tandai issue Resolved.
     * Hanya untuk kolom di VALUE_FIELDS, dengan validasi format nilai.
     */
    public static function applyValue(int $id, string $which, ?string $note): void
    {
        $issue = self::find($id);
        if ($issue === null) {
            throw new DomainException('Issue tidak ditemukan.');
        }
        $target = self::valueTarget($issue);
        if ($target === null || !in_array($which, ['master', 'suggested'], true)) {
            throw new DomainException('Issue ini tidak dapat diselesaikan dengan memilih nilai.');
        }
        $raw = trim((string) ($which === 'master' ? $issue['master_value'] : $issue['suggested_value']));
        $value = $target['type'] === 'date' ? Validator::parseDate($raw) : (Number::parseDecimal($raw));
        if ($value === null) {
            throw new DomainException('Nilai "' . $raw . '" bukan ' . ($target['type'] === 'date' ? 'tanggal' : 'angka') . ' yang valid.');
        }
        Database::transaction(function () use ($issue, $target, $value, $which, $note, $id): void {
            Database::assertIdentifier($target['table']);
            Database::assertIdentifier($target['column']);
            $record = Database::fetch("SELECT id, `{$target['column']}` AS v FROM `{$target['table']}` WHERE id = :id FOR UPDATE", ['id' => (int) $issue['record_id']]);
            if ($record === null) {
                throw new DomainException('Record asal sudah tidak ada.');
            }
            Database::update($target['table'], [$target['column'] => $value, 'updated_by' => Auth::id()], 'id = :id', ['id' => (int) $issue['record_id']]);
            Audit::log('update', $target['entity'], (int) $issue['record_id'], (string) ($issue['record_code'] ?? ''), [$target['column'] => ['old' => $record['v'], 'new' => $value]]);
            $labels = self::valueLabels($issue);
            self::setStatus($id, 'Resolved', trim($labels[$which] . ' diterapkan' . ($note ? ': ' . $note : '')));
        });
    }

    /**
     * Delivery legacy tanpa PO line yang PO-nya hanya punya SATU baris.
     * Ditampilkan berdampingan (produk di spreadsheet vs produk baris PO) untuk
     * dikonfirmasi manual — tidak ada penautan otomatis.
     * @return list<array<string,mixed>>
     */
    public static function singleLineDeliveryCandidates(int $limit = 50, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT d.id, d.code, d.sj_number, d.delivery_date, d.delivered_qty, d.status, d.po_id, p.po_number, p.code AS po_code, c.name AS customer_name,
                    one.line_id, pr.name AS line_product, one.order_qty,
                    (SELECT mi.legacy_product FROM migration_issues mi WHERE mi.table_name = 'DELIVERIES' AND mi.record_id = d.id AND mi.legacy_product IS NOT NULL ORDER BY mi.id LIMIT 1) AS legacy_product
             FROM deliveries d
             JOIN (SELECT po_id, MIN(id) AS line_id, MIN(product_id) AS product_id, MIN(order_qty) AS order_qty, COUNT(*) AS n FROM po_lines GROUP BY po_id HAVING COUNT(*) = 1) one ON one.po_id = d.po_id
             JOIN purchase_orders p ON p.id = d.po_id
             LEFT JOIN customers c ON c.id = p.customer_id
             JOIN products pr ON pr.id = one.product_id
             WHERE d.po_line_id IS NULL
             ORDER BY p.po_date DESC, d.po_id, d.delivery_date, d.id
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
    }

    public static function singleLineDeliveryCount(): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM deliveries d JOIN (SELECT po_id FROM po_lines GROUP BY po_id HAVING COUNT(*) = 1) one ON one.po_id = d.po_id WHERE d.po_line_id IS NULL'
        );
    }
}
