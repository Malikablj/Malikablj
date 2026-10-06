<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Number;
use App\Models\Lead;
use App\Models\PoLine;
use App\Models\ProductReturn;

/**
 * Laporan: Customer, Lead, Activity, OEF, Delivery, Retur & Komplain.
 * Filter umum: periode (from/to), customer, PIC. Satu definisi kolom dipakai
 * untuk tampilan layar, export CSV, dan export Excel sehingga angkanya selalu sama.
 *
 * Kolom: key, label, type (text|date|datetime|qty|money|pct|status),
 *        total (jumlahkan di baris total), hide (sm|md|lg|xl = sembunyi di layar kecil),
 *        link (path dengan {field}) + perm (permission untuk menampilkan link).
 */
final class ReportService
{
    public const MAX_ROWS = 20000;
    public const SCREEN_ROWS = 500;

    public const REPORTS = [
        'customer'  => ['title' => 'Customer', 'perm' => 'reports.customer', 'icon' => 'bi-buildings',
                        'desc' => 'Aktivitas, OEF, dan outstanding per customer.', 'date' => 'Periode aktivitas & OEF', 'pic' => 'PIC marketing customer'],
        'lead'      => ['title' => 'Lead', 'perm' => 'reports.lead', 'icon' => 'bi-kanban',
                        'desc' => 'Pipeline lead per status, sumber, dan PIC; win rate.', 'date' => 'Tanggal lead dibuat', 'pic' => 'PIC lead'],
        'activity'  => ['title' => 'Activity', 'perm' => 'reports.activity', 'icon' => 'bi-chat-square-text',
                        'desc' => 'Aktivitas marketing & sales per tipe dan PIC.', 'date' => 'Tanggal aktivitas', 'pic' => 'PIC aktivitas'],
        'po'        => ['title' => 'Order Entry Form (OEF)', 'perm' => 'reports.po', 'icon' => 'bi-receipt',
                        'desc' => 'OEF per status & review PPIC: qty order, terkirim, retur, dan outstanding.', 'date' => 'Tanggal OEF', 'pic' => 'PIC marketing customer'],
        'delivery'  => ['title' => 'Delivery', 'perm' => 'reports.delivery', 'icon' => 'bi-truck',
                        'desc' => 'Pengiriman per periode, customer, dan status.', 'date' => 'Tanggal delivery', 'pic' => 'PIC marketing customer'],
        'complaint' => ['title' => 'Retur & Komplain', 'perm' => 'reports.complaint', 'icon' => 'bi-chat-left-dots',
                        'desc' => 'Seluruh retur & komplain: alasan, bukti, hasil penyelesaian, dan email QC.', 'date' => 'Tanggal retur / komplain', 'pic' => 'PIC marketing customer'],
    ];

    /** @return array<string,array<string,string>> laporan yang boleh dibuka user saat ini */
    public static function available(): array
    {
        return array_filter(self::REPORTS, static fn (array $r): bool => Auth::can($r['perm']));
    }

    /** @return array<string,array{label:string,from:string,to:string}> */
    public static function presets(string $today): array
    {
        $ts = strtotime($today);
        $lastMonth = strtotime(date('Y-m-01', $ts) . ' -1 month');
        return [
            'all'        => ['label' => 'Semua waktu', 'from' => '', 'to' => ''],
            'this_month' => ['label' => 'Bulan ini', 'from' => date('Y-m-01', $ts), 'to' => date('Y-m-t', $ts)],
            'last_month' => ['label' => 'Bulan lalu', 'from' => date('Y-m-01', $lastMonth), 'to' => date('Y-m-t', $lastMonth)],
            'last_90'    => ['label' => '90 hari', 'from' => date('Y-m-d', strtotime($today . ' -89 days')), 'to' => $today],
            'this_year'  => ['label' => 'Tahun ini', 'from' => date('Y-01-01', $ts), 'to' => date('Y-12-31', $ts)],
        ];
    }

    /**
     * @param array{from:string,to:string,customer_id:int,pic:int} $f
     * @return array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,summary:list<array<string,string>>,breakdowns:list<array<string,mixed>>,truncated:bool}
     */
    public static function run(string $type, array $f, string $today): array
    {
        $result = match ($type) {
            'customer'  => self::customerReport($f),
            'lead'      => self::leadReport($f),
            'activity'  => self::activityReport($f),
            'po'        => self::poReport($f),
            'delivery'  => self::deliveryReport($f),
            'complaint' => self::complaintReport($f),
            default     => throw new \InvalidArgumentException('Laporan tidak dikenal.'),
        };
        $result['truncated'] = count($result['rows']) >= self::MAX_ROWS;
        return $result;
    }

    // ------------------------------------------------------------------ helpers

    /** Kondisi periode untuk kolom DATE (atau DATETIME bila $datetime). */
    private static function period(string $expr, string $prefix, array $f, array &$params, bool $datetime = false): string
    {
        $sql = '';
        if (!empty($f['from'])) {
            $sql .= " AND {$expr} >= :{$prefix}_from";
            $params[$prefix . '_from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $sql .= $datetime ? " AND {$expr} < DATE_ADD(:{$prefix}_to, INTERVAL 1 DAY)" : " AND {$expr} <= :{$prefix}_to";
            $params[$prefix . '_to'] = $f['to'];
        }
        return $sql;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function sumMoney(array $rows, string $key): string
    {
        $cents = 0;
        foreach ($rows as $r) {
            $cents += Number::toCents($r[$key] ?? null) ?? 0;
        }
        return Number::fromCents($cents);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function sumInt(array $rows, string $key): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $n += (int) ($r[$key] ?? 0);
        }
        return $n;
    }

    /**
     * Rincian (jumlah per kategori) untuk bar list.
     * @param array<string,int|float> $counts label => nilai
     * @return array{title:string,unit:string,items:list<array{label:string,value:float,display:string}>}
     */
    private static function breakdown(string $title, array $counts, string $unit = 'qty', ?int $top = null, bool $sort = true): array
    {
        if ($sort) {
            arsort($counts);
        }
        if ($top !== null && count($counts) > $top) {
            $rest = array_sum(array_slice($counts, $top, null, true));
            $counts = array_slice($counts, 0, $top, true);
            $counts['Lainnya'] = $rest;
        }
        $items = [];
        foreach ($counts as $label => $value) {
            if ((float) $value <= 0) {
                continue;
            }
            $items[] = [
                'label'   => (string) $label,
                'value'   => (float) $value,
                'display' => $unit === 'money' ? Number::money($value) : Number::qty($value),
            ];
        }
        return ['title' => $title, 'unit' => $unit, 'items' => $items];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private static function countBy(array $rows, string $key, string $empty = '(kosong)'): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (string) ($r[$key] ?? '');
            $k = $k === '' ? $empty : $k;
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private static function sumBy(array $rows, string $key, string $valueKey, string $empty = '(kosong)'): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (string) ($r[$key] ?? '');
            $k = $k === '' ? $empty : $k;
            $out[$k] = ($out[$k] ?? 0) + (int) ($r[$valueKey] ?? 0);
        }
        return $out;
    }

    private static function pct(float $part, float $whole): string
    {
        return $whole > 0 ? number_format($part * 100 / $whole, 1, ',', '.') . '%' : '—';
    }

    // ------------------------------------------------------------------ reports

    private static function customerReport(array $f): array
    {
        $params = [];
        $actPeriod = self::period('a.activity_date', 'ac', $f, $params, true);
        $poPeriod = self::period('p.po_date', 'po', $f, $params);
        $where = ['1=1'];
        if (!empty($f['customer_id'])) {
            $where[] = 'c.id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['pic'])) {
            $where[] = 'c.marketing_pic_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        $open = "'Open','On Process','Partial'";
        $rows = Database::fetchAll(
            "SELECT c.id, c.code, c.name, c.industry, c.status, u.name AS pic_name,
                    COALESCE(ld.open_leads, 0) AS open_leads, COALESCE(ld.pipeline, 0) AS pipeline,
                    COALESCE(ac.n, 0) AS activities, ac.last_activity,
                    COALESCE(po.n, 0) AS po_count, COALESCE(po.qty, 0) AS order_qty, COALESCE(po.delivered, 0) AS delivered_qty,
                    COALESCE(op.outstanding, 0) AS outstanding_qty
             FROM customers c
             LEFT JOIN users u ON u.id = c.marketing_pic_id
             LEFT JOIN (SELECT customer_id, SUM(status NOT IN ('Won','Lost','Dormant')) AS open_leads,
                               SUM(CASE WHEN status NOT IN ('Won','Lost','Dormant') THEN COALESCE(potential_value, 0) ELSE 0 END) AS pipeline
                        FROM leads WHERE customer_id IS NOT NULL GROUP BY customer_id) ld ON ld.customer_id = c.id
             LEFT JOIN (SELECT a.customer_id, COUNT(*) AS n, MAX(a.activity_date) AS last_activity FROM activities a
                        WHERE a.customer_id IS NOT NULL{$actPeriod} GROUP BY a.customer_id) ac ON ac.customer_id = c.id
             LEFT JOIN (SELECT p.customer_id, COUNT(*) AS n, SUM(COALESCE(t.total_qty, 0)) AS qty, SUM(COALESCE(t.delivered_qty, 0)) AS delivered
                        FROM purchase_orders p LEFT JOIN (" . PoLine::poTotalsSql() . ") t ON t.po_id = p.id
                        WHERE p.customer_id IS NOT NULL{$poPeriod} GROUP BY p.customer_id) po ON po.customer_id = c.id
             LEFT JOIN (SELECT p.customer_id, SUM(COALESCE(t.open_outstanding_qty, 0)) AS outstanding
                        FROM purchase_orders p JOIN (" . PoLine::poTotalsSql() . ") t ON t.po_id = p.id
                        WHERE p.customer_id IS NOT NULL AND p.status IN ({$open}) GROUP BY p.customer_id) op ON op.customer_id = c.id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY COALESCE(po.qty, 0) DESC, COALESCE(ac.n, 0) DESC, c.name
             LIMIT ' . self::MAX_ROWS,
            $params
        );
        $columns = [
            ['key' => 'name', 'label' => 'Customer', 'type' => 'text', 'link' => '/customers/{id}', 'perm' => 'customers.view', 'sub' => ['industry', 'pic_name']],
            ['key' => 'status', 'label' => 'Status', 'type' => 'status', 'hide' => 'md'],
            ['key' => 'pic_name', 'label' => 'PIC', 'type' => 'text', 'hide' => 'xxl'],
            ['key' => 'open_leads', 'label' => 'Lead aktif', 'type' => 'qty', 'total' => true, 'hide' => 'xl'],
            ['key' => 'activities', 'label' => 'Aktivitas', 'type' => 'qty', 'total' => true, 'hide' => 'lg'],
            ['key' => 'last_activity', 'label' => 'Aktivitas terakhir', 'type' => 'datetime', 'hide' => 'xxl'],
            ['key' => 'po_count', 'label' => 'OEF', 'type' => 'qty', 'total' => true, 'hide' => 'xl'],
            ['key' => 'order_qty', 'label' => 'Qty order', 'type' => 'qty', 'total' => true],
            ['key' => 'delivered_qty', 'label' => 'Terkirim', 'type' => 'qty', 'total' => true, 'hide' => 'xl'],
            ['key' => 'outstanding_qty', 'label' => 'Outstanding (OEF berjalan)', 'type' => 'qty', 'total' => true, 'hide' => 'sm'],
        ];
        $active = count(array_filter($rows, static fn ($r) => $r['status'] === 'Active'));
        $withPo = count(array_filter($rows, static fn ($r) => (int) $r['po_count'] > 0));
        $summary = [
            ['label' => 'Customer', 'value' => Number::qty(count($rows)), 'meta' => Number::qty($active) . ' aktif'],
            ['label' => 'Customer dengan OEF', 'value' => Number::qty($withPo), 'meta' => 'dalam periode'],
            ['label' => 'Aktivitas', 'value' => Number::qty(self::sumInt($rows, 'activities')), 'meta' => 'dalam periode'],
            ['label' => 'Qty order', 'value' => Number::qty(self::sumInt($rows, 'order_qty')), 'meta' => 'terkirim ' . Number::qty(self::sumInt($rows, 'delivered_qty'))],
            ['label' => 'Outstanding OEF berjalan', 'value' => Number::qty(self::sumInt($rows, 'outstanding_qty')), 'meta' => 'pcs saat ini'],
        ];
        $byQty = [];
        foreach ($rows as $r) {
            $byQty[(string) $r['name']] = (int) $r['order_qty'];
        }
        return [
            'columns'    => $columns,
            'rows'       => $rows,
            'summary'    => $summary,
            'breakdowns' => [
                self::breakdown('Qty order per customer', $byQty, 'qty', 8),
                self::breakdown('Customer per status', self::countBy($rows, 'status')),
            ],
        ];
    }

    private static function leadReport(array $f): array
    {
        $params = [];
        $where = '1=1' . self::period('l.created_at', 'ld', $f, $params, true);
        if (!empty($f['customer_id'])) {
            $where .= ' AND l.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['pic'])) {
            $where .= ' AND l.pic_user_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        $rows = Database::fetchAll(
            'SELECT l.id, l.code, l.lead_name, COALESCE(c.name, l.company_name) AS company, l.customer_id, l.source, u.name AS pic_name,
                    l.status, l.priority, l.potential_value, l.expected_close_date, l.created_at, l.last_contact, l.product_interest
             FROM leads l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN users u ON u.id = l.pic_user_id
             WHERE ' . $where . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . self::MAX_ROWS,
            $params
        );
        $open = array_filter($rows, static fn ($r) => in_array($r['status'], Lead::OPEN_STATUSES, true));
        $won = array_filter($rows, static fn ($r) => $r['status'] === 'Won');
        $lost = array_filter($rows, static fn ($r) => $r['status'] === 'Lost');
        $byStatus = [];
        foreach (Lead::STATUSES as $s) {
            $byStatus[$s] = 0;
        }
        foreach ($rows as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
        }
        return [
            'columns' => [
                ['key' => 'lead_name', 'label' => 'Lead', 'type' => 'text', 'link' => '/leads/{id}', 'perm' => 'leads.view', 'sub' => ['company', 'pic_name']],
                ['key' => 'company', 'label' => 'Customer / perusahaan', 'type' => 'text', 'hide' => 'lg'],
                ['key' => 'source', 'label' => 'Sumber', 'type' => 'text', 'hide' => 'xxl'],
                ['key' => 'pic_name', 'label' => 'PIC', 'type' => 'text', 'hide' => 'lg'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ['key' => 'priority', 'label' => 'Prioritas', 'type' => 'text', 'hide' => 'xxl'],
                ['key' => 'potential_value', 'label' => 'Potential value', 'type' => 'money', 'total' => true, 'hide' => 'sm'],
                ['key' => 'expected_close_date', 'label' => 'Target closing', 'type' => 'date', 'hide' => 'xl'],
                ['key' => 'created_at', 'label' => 'Dibuat', 'type' => 'datetime', 'hide' => 'xxl'],
            ],
            'rows'    => $rows,
            'summary' => [
                ['label' => 'Lead', 'value' => Number::qty(count($rows)), 'meta' => Number::qty(count($open)) . ' masih berjalan'],
                ['label' => 'Pipeline (lead berjalan)', 'value' => Number::money(self::sumMoney(array_values($open), 'potential_value')), 'meta' => 'potential value'],
                ['label' => 'Won', 'value' => Number::qty(count($won)), 'meta' => Number::money(self::sumMoney(array_values($won), 'potential_value'))],
                ['label' => 'Win rate', 'value' => self::pct(count($won), count($won) + count($lost)), 'meta' => 'Won ÷ (Won + Lost)'],
            ],
            'breakdowns' => [
                self::breakdown('Lead per status (urutan pipeline)', $byStatus, 'qty', null, false),
                self::breakdown('Lead per sumber', self::countBy($rows, 'source', 'Tanpa sumber'), 'qty', 6),
            ],
        ];
    }

    private static function activityReport(array $f): array
    {
        $params = [];
        $where = '1=1' . self::period('a.activity_date', 'at', $f, $params, true);
        if (!empty($f['customer_id'])) {
            $where .= ' AND a.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['pic'])) {
            $where .= ' AND a.pic_user_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        $rows = Database::fetchAll(
            'SELECT a.id, a.code, a.activity_date, a.activity_type, a.subject, a.next_action, a.customer_id, a.lead_id,
                    COALESCE(c.name, l.company_name, l.lead_name) AS related, l.lead_name, u.name AS pic_name
             FROM activities a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN leads l ON l.id = a.lead_id
             LEFT JOIN users u ON u.id = a.pic_user_id
             WHERE ' . $where . ' ORDER BY a.activity_date DESC, a.id DESC LIMIT ' . self::MAX_ROWS,
            $params
        );
        $customers = count(array_unique(array_filter(array_column($rows, 'customer_id'))));
        return [
            'columns' => [
                ['key' => 'subject', 'label' => 'Aktivitas', 'type' => 'text', 'link' => '/activities/{id}/edit', 'perm' => 'activities.edit', 'sub' => ['related', 'activity_type']],
                ['key' => 'activity_date', 'label' => 'Tanggal', 'type' => 'datetime'],
                ['key' => 'activity_type', 'label' => 'Tipe', 'type' => 'text', 'hide' => 'md'],
                ['key' => 'related', 'label' => 'Customer / lead', 'type' => 'text', 'hide' => 'lg'],
                ['key' => 'pic_name', 'label' => 'PIC', 'type' => 'text', 'hide' => 'md'],
                ['key' => 'next_action', 'label' => 'Tindak lanjut', 'type' => 'text', 'hide' => 'xxl'],
            ],
            'rows'    => $rows,
            'summary' => [
                ['label' => 'Aktivitas', 'value' => Number::qty(count($rows)), 'meta' => 'dalam periode'],
                ['label' => 'Customer dihubungi', 'value' => Number::qty($customers), 'meta' => 'customer berbeda'],
                ['label' => 'PIC aktif', 'value' => Number::qty(count(array_unique(array_filter(array_column($rows, 'pic_name'))))), 'meta' => 'user dengan aktivitas'],
            ],
            'breakdowns' => [
                self::breakdown('Aktivitas per tipe', self::countBy($rows, 'activity_type')),
                self::breakdown('Aktivitas per PIC', self::countBy($rows, 'pic_name', 'Tanpa PIC'), 'qty', 8),
            ],
        ];
    }

    private static function poReport(array $f): array
    {
        $params = [];
        $where = '1=1' . self::period('p.po_date', 'po', $f, $params);
        if (!empty($f['customer_id'])) {
            $where .= ' AND p.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['pic'])) {
            $where .= ' AND c.marketing_pic_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        $rows = Database::fetchAll(
            'SELECT p.id, p.code, COALESCE(p.order_number, p.po_number, p.code) AS order_ref, p.order_number, p.po_number, c.name AS customer_name,
                    u.name AS pic_name, p.sales_name, p.po_date, p.status, p.review_status, p.requested_delivery_date,
                    COALESCE(t.line_count, 0) AS line_count, COALESCE(t.total_qty, 0) AS total_qty, COALESCE(t.delivered_qty, 0) AS delivered_qty,
                    COALESCE(t.return_qty, 0) AS return_qty, COALESCE(t.outstanding_qty, 0) AS outstanding_qty,
                    COALESCE(t.open_outstanding_qty, 0) AS open_outstanding_qty, p.grand_total
             FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN users u ON u.id = c.marketing_pic_id
             LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
             WHERE ' . $where . ' ORDER BY p.po_date IS NULL, p.po_date DESC, p.id DESC LIMIT ' . self::MAX_ROWS,
            $params
        );
        foreach ($rows as &$r) {
            $r['review_label'] = \App\Models\PurchaseOrder::REVIEW_LABELS[$r['review_status']] ?? $r['review_status'];
        }
        unset($r);
        $open = array_values(array_filter($rows, static fn ($r) => in_array($r['status'], ['Open', 'On Process', 'Partial'], true)));
        $total = self::sumInt($rows, 'total_qty');
        $delivered = self::sumInt($rows, 'delivered_qty');
        $pending = count(array_filter($rows, static fn ($r) => $r['review_status'] === 'Pending'));
        $columns = [
            ['key' => 'order_ref', 'label' => 'No. order', 'type' => 'text', 'link' => '/purchase-orders/{id}', 'perm' => 'purchase_orders.view', 'sub' => ['customer_name', 'po_number']],
            ['key' => 'po_number', 'label' => 'No. PO customer', 'type' => 'text', 'hide' => 'xxl'],
            ['key' => 'customer_name', 'label' => 'Customer', 'type' => 'text', 'hide' => 'md'],
            ['key' => 'sales_name', 'label' => 'Sales', 'type' => 'text', 'hide' => 'xxl'],
            ['key' => 'po_date', 'label' => 'Tanggal', 'type' => 'date', 'hide' => 'lg'],
            ['key' => 'requested_delivery_date', 'label' => 'Permintaan kirim', 'type' => 'date', 'hide' => 'xxl'],
            ['key' => 'review_label', 'label' => 'Review PPIC', 'type' => 'text', 'hide' => 'xl'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'status', 'hide' => 'sm'],
            ['key' => 'total_qty', 'label' => 'Qty order', 'type' => 'qty', 'total' => true, 'hide' => 'sm'],
            ['key' => 'delivered_qty', 'label' => 'Terkirim', 'type' => 'qty', 'total' => true, 'hide' => 'xl'],
            ['key' => 'return_qty', 'label' => 'Retur', 'type' => 'qty', 'total' => true, 'hide' => 'xxl'],
            ['key' => 'outstanding_qty', 'label' => 'Outstanding', 'type' => 'qty', 'total' => true],
        ];
        $summary = [
            ['label' => 'OEF', 'value' => Number::qty(count($rows)), 'meta' => Number::qty(count($open)) . ' masih berjalan · ' . Number::qty($pending) . ' menunggu review'],
            ['label' => 'Qty order', 'value' => Number::qty($total), 'meta' => 'retur ' . Number::qty(self::sumInt($rows, 'return_qty'))],
            ['label' => 'Terkirim', 'value' => Number::qty($delivered), 'meta' => self::pct($delivered, $total) . ' dari order'],
            ['label' => 'Outstanding OEF berjalan', 'value' => Number::qty(self::sumInt($open, 'open_outstanding_qty')), 'meta' => 'pcs belum terkirim'],
        ];
        // Nilai PO (hasil import dokumen PO) adalah data keuangan: hanya untuk role dengan po_values.view
        if (Auth::can('po_values.view')) {
            $columns[] = ['key' => 'grand_total', 'label' => 'Nilai PO (arsip)', 'type' => 'money', 'total' => true, 'hide' => 'xxl', 'empty' => '—'];
            $summary[] = ['label' => 'Nilai PO (arsip import)', 'value' => Number::money(self::sumMoney(array_values(array_filter($rows, static fn ($r) => $r['status'] !== 'Cancelled')), 'grand_total')),
                'meta' => 'grand total, tanpa Cancelled · ' . Number::qty(count(array_filter($rows, static fn ($r) => $r['grand_total'] === null))) . ' tanpa nilai'];
        }
        return [
            'columns'    => $columns,
            'rows'       => $rows,
            'summary'    => $summary,
            'breakdowns' => [
                self::breakdown('OEF per status', self::countBy($rows, 'status')),
                self::breakdown('OEF per review PPIC', self::countBy($rows, 'review_label')),
                self::breakdown('Qty order per customer', self::sumBy($rows, 'customer_name', 'total_qty', 'Customer belum terhubung'), 'qty', 8),
            ],
        ];
    }

    private static function deliveryReport(array $f): array
    {
        $params = [];
        $where = '1=1' . self::period('d.delivery_date', 'dl', $f, $params);
        if (!empty($f['customer_id'])) {
            $where .= ' AND p.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['pic'])) {
            $where .= ' AND c.marketing_pic_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        $rows = Database::fetchAll(
            "SELECT d.id, d.code, COALESCE(d.sj_number, d.code) AS sj_number, d.delivery_date, d.status, d.delivered_qty, d.destination,
                    COALESCE(p.order_number, p.po_number, p.code) AS po_number, d.po_id, c.name AS customer_name, pr.name AS product_name,
                    CASE WHEN d.po_line_id IS NULL THEN 'Belum terhubung' ELSE 'Terhubung' END AS link_status,
                    CASE WHEN d.status IN ('Delivered','Partial') THEN d.delivered_qty ELSE 0 END AS counted_qty
             FROM deliveries d LEFT JOIN purchase_orders p ON p.id = d.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = d.product_id
             WHERE " . $where . ' ORDER BY d.delivery_date IS NULL, d.delivery_date DESC, d.id DESC LIMIT ' . self::MAX_ROWS,
            $params
        );
        $rParams = [];
        $rWhere = '1=1' . self::period('r.return_date', 'rt', $f, $rParams);
        if (!empty($f['customer_id'])) {
            $rWhere .= ' AND p.customer_id = :cid';
            $rParams['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['pic'])) {
            $rWhere .= ' AND c.marketing_pic_id = :pic';
            $rParams['pic'] = (int) $f['pic'];
        }
        $returned = (int) Database::fetchValue(
            'SELECT COALESCE(SUM(r.return_qty), 0) FROM returns r LEFT JOIN purchase_orders p ON p.id = r.po_id LEFT JOIN customers c ON c.id = p.customer_id WHERE ' . $rWhere,
            $rParams
        );
        $upcoming = count(array_filter($rows, static fn ($r) => in_array($r['status'], ['Scheduled', 'On Delivery'], true)));
        $unlinked = count(array_filter($rows, static fn ($r) => $r['link_status'] === 'Belum terhubung'));
        return [
            'columns' => [
                ['key' => 'sj_number', 'label' => 'Surat jalan', 'type' => 'text', 'link' => '/deliveries/{id}', 'perm' => 'deliveries.view', 'sub' => ['customer_name', 'product_name']],
                ['key' => 'delivery_date', 'label' => 'Tanggal', 'type' => 'date'],
                ['key' => 'po_number', 'label' => 'OEF', 'type' => 'text', 'hide' => 'lg'],
                ['key' => 'customer_name', 'label' => 'Customer', 'type' => 'text', 'hide' => 'md'],
                ['key' => 'product_name', 'label' => 'Produk', 'type' => 'text', 'hide' => 'xxl'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status', 'hide' => 'sm'],
                ['key' => 'link_status', 'label' => 'Relasi baris OEF', 'type' => 'text', 'hide' => 'xxl'],
                ['key' => 'delivered_qty', 'label' => 'Qty', 'type' => 'qty', 'total' => true],
            ],
            'rows'    => $rows,
            'summary' => [
                ['label' => 'Delivery', 'value' => Number::qty(count($rows)), 'meta' => Number::qty($upcoming) . ' terjadwal / dalam perjalanan'],
                ['label' => 'Qty diterima customer', 'value' => Number::qty(self::sumInt($rows, 'counted_qty')), 'meta' => 'status Delivered / Partial'],
                ['label' => 'Qty retur', 'value' => Number::qty($returned), 'meta' => 'retur dalam periode'],
                ['label' => 'Belum terhubung ke baris OEF', 'value' => Number::qty($unlinked), 'meta' => 'data legacy'],
            ],
            'breakdowns' => [
                self::breakdown('Delivery per status', self::countBy($rows, 'status')),
                self::breakdown('Qty diterima per customer', self::sumBy($rows, 'customer_name', 'counted_qty', 'PO tidak diketahui'), 'qty', 8),
            ],
        ];
    }

    private static function complaintReport(array $f): array
    {
        $params = [];
        $where = '1=1' . self::period('r.return_date', 'rt', $f, $params);
        if (!empty($f['customer_id'])) {
            $where .= ' AND p.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['pic'])) {
            $where .= ' AND c.marketing_pic_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        $rows = Database::fetchAll(
            "SELECT r.id, r.code, r.case_type, r.return_date, r.reason, r.return_qty, r.affected_qty,
                    COALESCE(r.return_qty, r.affected_qty) AS qty, r.resolution_status, r.resolution_note, r.resolved_at, r.qc_email, r.note,
                    COALESCE(p.order_number, p.po_number, p.code, r.po_number_legacy) AS order_ref, c.name AS customer_name,
                    COALESCE(pr.name, r.product_legacy) AS product_name, ru.name AS resolved_by_name,
                    (SELECT COUNT(*) FROM return_attachments ra WHERE ra.return_id = r.id) AS evidence_count,
                    (SELECT el.status FROM email_logs el WHERE el.entity_type = 'return' AND el.entity_id = r.id ORDER BY el.id DESC LIMIT 1) AS email_status
             FROM returns r LEFT JOIN purchase_orders p ON p.id = r.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = r.product_id LEFT JOIN users ru ON ru.id = r.resolved_by
             WHERE " . $where . ' ORDER BY r.return_date IS NULL, r.return_date DESC, r.id DESC LIMIT ' . self::MAX_ROWS,
            $params
        );
        foreach ($rows as &$r) {
            $r['reason_label'] = $r['reason'] !== null ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : null;
            $r['email_label'] = ProductReturn::EMAIL_LABELS[$r['email_status'] ?? ''] ?? 'Belum dikirim';
        }
        unset($r);
        $count = static fn (string $status): int => count(array_filter($rows, static fn ($r) => $r['resolution_status'] === $status));
        $returns = array_values(array_filter($rows, static fn ($r) => $r['case_type'] === 'Retur'));
        return [
            'columns' => [
                ['key' => 'code', 'label' => 'Kasus', 'type' => 'text', 'link' => '/returns/{id}', 'perm' => 'returns.view', 'sub' => ['customer_name', 'product_name']],
                ['key' => 'return_date', 'label' => 'Tanggal', 'type' => 'date', 'hide' => 'sm'],
                ['key' => 'case_type', 'label' => 'Jenis', 'type' => 'text', 'hide' => 'md'],
                ['key' => 'reason_label', 'label' => 'Alasan', 'type' => 'text', 'hide' => 'lg'],
                ['key' => 'customer_name', 'label' => 'Customer', 'type' => 'text', 'hide' => 'xl'],
                ['key' => 'order_ref', 'label' => 'OEF', 'type' => 'text', 'hide' => 'xxl'],
                ['key' => 'product_name', 'label' => 'Produk', 'type' => 'text', 'hide' => 'xxl'],
                ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty', 'total' => true, 'hide' => 'md'],
                ['key' => 'resolution_status', 'label' => 'Hasil', 'type' => 'status'],
                ['key' => 'resolution_note', 'label' => 'Catatan / alasan', 'type' => 'text', 'hide' => 'xxl'],
                ['key' => 'evidence_count', 'label' => 'Bukti', 'type' => 'qty', 'hide' => 'xl'],
                ['key' => 'email_label', 'label' => 'Email QC', 'type' => 'text', 'hide' => 'xxl'],
            ],
            'rows'    => $rows,
            'summary' => [
                ['label' => 'Retur & komplain', 'value' => Number::qty(count($rows)), 'meta' => Number::qty(count($returns)) . ' retur · ' . Number::qty(count($rows) - count($returns)) . ' komplain'],
                ['label' => 'Selesai', 'value' => Number::qty($count('Selesai')), 'meta' => self::pct($count('Selesai'), count($rows)) . ' dari semua kasus'],
                ['label' => 'Tidak selesai', 'value' => Number::qty($count('Tidak selesai')), 'meta' => 'dengan alasan'],
                ['label' => 'Masih open', 'value' => Number::qty($count('Open')), 'meta' => 'belum ada hasil'],
                ['label' => 'Qty retur', 'value' => Number::qty(self::sumInt($returns, 'return_qty')), 'meta' => 'menambah outstanding OEF'],
            ],
            'breakdowns' => [
                self::breakdown('Kasus per hasil penyelesaian', self::countBy($rows, 'resolution_status')),
                self::breakdown('Kasus per alasan', self::countBy($rows, 'reason_label', 'Tanpa alasan')),
                self::breakdown('Kasus per customer', self::countBy($rows, 'customer_name', 'Customer tidak diketahui'), 'qty', 8),
            ],
        ];
    }
}
