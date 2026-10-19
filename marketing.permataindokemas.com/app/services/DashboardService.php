<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Models\PoLine;

/**
 * Query dashboard — seluruh angka dibaca langsung dari database aktual.
 * Aman untuk database kosong (semua agregat memakai COALESCE).
 */
final class DashboardService
{
    /** @return array<string,int|float> */
    public static function kpis(string $today): array
    {
        $customers = Database::fetch("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Active'), 0) AS active FROM customers") ?? [];
        $leads = Database::fetch(
            "SELECT COUNT(*) AS open_count, COALESCE(SUM(potential_value), 0) AS pipeline
             FROM leads WHERE status NOT IN ('Won','Lost','Dormant')"
        ) ?? [];
        $followToday = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM follow_up WHERE follow_up_date = :d AND status NOT IN ('Done','Cancelled')",
            ['d' => $today]
        );
        $followOverdue = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM follow_up WHERE follow_up_date < :d AND status NOT IN ('Done','Cancelled')",
            ['d' => $today]
        );
        $po = Database::fetch(
            "SELECT COUNT(*) AS open_count, COALESCE(SUM(t.open_outstanding_qty), 0) AS outstanding
             FROM purchase_orders po
             LEFT JOIN (" . PoLine::poTotalsSql() . ") t ON t.po_id = po.id
             WHERE po.status IN ('Open','On Process','Partial')"
        ) ?? [];

        $ppicPending = (int) Database::fetchValue("SELECT COUNT(*) FROM purchase_orders WHERE ppic_status = 'Pending'");
        $complaintsOpen = (int) Database::fetchValue("SELECT COUNT(*) FROM returns WHERE complaint_status = 'Open'");
        $deliveriesUpcoming = (int) Database::fetchValue("SELECT COUNT(*) FROM deliveries WHERE status IN ('Scheduled','On Delivery')");
        $stock = Database::fetch(
            "SELECT COUNT(DISTINCT product_id) AS products,
                    COALESCE(SUM(CASE WHEN stock_type = 'FG' THEN quantity END), 0) AS fg,
                    COALESCE(SUM(CASE WHEN stock_type = 'Ready' THEN quantity END), 0) AS ready
             FROM stock"
        ) ?? [];
        $monthStart = date('Y-m-01', strtotime($today) ?: time());
        $inboundMaklon = (int) Database::fetchValue('SELECT COUNT(*) FROM inbound_maklon WHERE actual_inbound_date BETWEEN :a AND :b', ['a' => $monthStart, 'b' => $today]);
        $inboundSupplier = (int) Database::fetchValue('SELECT COUNT(*) FROM inbound_supplier WHERE inbound_date BETWEEN :a AND :b', ['a' => $monthStart, 'b' => $today]);

        return [
            'ppic_pending'       => $ppicPending,
            'complaints_open'    => $complaintsOpen,
            'deliveries_upcoming' => $deliveriesUpcoming,
            'stock_products'     => (int) ($stock['products'] ?? 0),
            'stock_fg'           => (int) ($stock['fg'] ?? 0),
            'stock_ready'        => (int) ($stock['ready'] ?? 0),
            'inbound_maklon_month'   => $inboundMaklon,
            'inbound_supplier_month' => $inboundSupplier,
            'customers_total'    => (int) ($customers['total'] ?? 0),
            'customers_active'   => (int) ($customers['active'] ?? 0),
            'leads_open'         => (int) ($leads['open_count'] ?? 0),
            'leads_pipeline'     => (float) ($leads['pipeline'] ?? 0),
            'followups_today'    => $followToday,
            'followups_overdue'  => $followOverdue,
            'po_open'            => (int) ($po['open_count'] ?? 0),
            'outstanding_qty'    => (int) ($po['outstanding'] ?? 0),
        ];
    }
    /**
     * Data visual mini di kartu KPI dashboard (komposisi, tren 6 bulan, jadwal 7 hari).
     * @return array<string,mixed>
     */
    public static function insights(string $today): array
    {
        $statusCounts = static function (string $sql, array $params = []): array {
            $out = [];
            foreach (Database::fetchAll($sql, $params) as $r) {
                $out[(string) $r['k']] = (int) $r['n'];
            }
            return $out;
        };
        $running = Database::fetch(
            "SELECT COALESCE(SUM(t.total_qty), 0) AS total, COALESCE(SUM(t.open_outstanding_qty), 0) AS open_qty
             FROM purchase_orders po LEFT JOIN (" . PoLine::poTotalsSql() . ") t ON t.po_id = po.id
             WHERE po.status IN ('Open','On Process','Partial')"
        ) ?? [];
        $week = [];
        $perDay = $statusCounts(
            "SELECT delivery_date AS k, COUNT(*) AS n FROM deliveries
             WHERE status IN ('Scheduled','On Delivery') AND delivery_date BETWEEN :a AND :b GROUP BY delivery_date",
            ['a' => $today, 'b' => date('Y-m-d', strtotime($today . ' +6 days'))]
        );
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($today . " +{$i} days"));
            $week[$d] = $perDay[$d] ?? 0;
        }
        $stock = Database::fetch(
            "SELECT COALESCE(SUM(CASE WHEN stock_type = 'FG' THEN quantity END), 0) AS FG,
                    COALESCE(SUM(CASE WHEN stock_type = 'WIP' THEN quantity END), 0) AS WIP,
                    COALESCE(SUM(CASE WHEN stock_type = 'Ready' THEN quantity END), 0) AS Ready,
                    COALESCE(SUM(CASE WHEN stock_type = 'Reserved' THEN quantity END), 0) AS Reserved
             FROM stock"
        ) ?? [];

        return [
            'leads_by_stage'   => $statusCounts("SELECT status AS k, COUNT(*) AS n FROM leads WHERE status IN ('New','Contacted','Qualified','Quotation','Negotiation') GROUP BY status"),
            'followups_done_today' => (int) Database::fetchValue("SELECT COUNT(*) FROM follow_up WHERE follow_up_date = :d AND status = 'Done'", ['d' => $today]),
            'followups_open'   => (int) Database::fetchValue("SELECT COUNT(*) FROM follow_up WHERE status NOT IN ('Done','Cancelled')"),
            'overdue_oldest'   => Database::fetchValue("SELECT MIN(follow_up_date) FROM follow_up WHERE follow_up_date < :d AND status NOT IN ('Done','Cancelled')", ['d' => $today]),
            'orders_by_status' => $statusCounts("SELECT status AS k, COUNT(*) AS n FROM purchase_orders WHERE status IN ('Open','On Process','Partial') GROUP BY status"),
            'ppic_oldest'      => Database::fetchValue("SELECT MIN(created_at) FROM purchase_orders WHERE ppic_status = 'Pending'"),
            'running_total'    => (int) ($running['total'] ?? 0),
            'running_fulfilled' => max(0, (int) ($running['total'] ?? 0) - (int) ($running['open_qty'] ?? 0)),
            'complaints_months' => self::monthly('returns', 'return_date', $today),
            'deliveries_week'  => $week,
            'deliveries_on_way' => (int) Database::fetchValue("SELECT COUNT(*) FROM deliveries WHERE status = 'On Delivery'"),
            'deliveries_late'  => (int) Database::fetchValue("SELECT COUNT(*) FROM deliveries WHERE status = 'Scheduled' AND delivery_date < :d", ['d' => $today]),
            'stock_types'      => array_map('intval', $stock),
            'inbound_maklon_months'   => self::monthly('inbound_maklon', 'actual_inbound_date', $today),
            'inbound_supplier_months' => self::monthly('inbound_supplier', 'inbound_date', $today),
        ];
    }

    /** Jumlah record per bulan untuk 6 bulan terakhir (termasuk bulan ini), mis. ['2026-05' => 3, ...]. */
    private static function monthly(string $table, string $dateColumn, string $today): array
    {
        $first = date('Y-m-01', strtotime(date('Y-m-01', strtotime($today)) . ' -5 months'));
        $rows = Database::fetchAll(
            "SELECT DATE_FORMAT({$dateColumn}, '%Y-%m') AS ym, COUNT(*) AS n FROM {$table}
             WHERE {$dateColumn} BETWEEN :a AND :b GROUP BY ym",
            ['a' => $first, 'b' => $today]
        );
        $counts = array_column($rows, 'n', 'ym');
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $ym = date('Y-m', strtotime($first . " +{$i} months"));
            $out[$ym] = (int) ($counts[$ym] ?? 0);
        }
        return $out;
    }

    /**
     * Posisi stok per produk (FG terbanyak) untuk panel dashboard.
     * @return list<array<string,mixed>>
     */
    public static function stockTop(int $limit = 6): array
    {
        return Database::fetchAll(
            "SELECT s.product_id, pr.name AS product_name, pr.variant, pr.unit,
                    SUM(CASE WHEN s.stock_type = 'FG' THEN COALESCE(s.quantity, 0) ELSE 0 END) AS fg,
                    SUM(CASE WHEN s.stock_type = 'Ready' THEN COALESCE(s.quantity, 0) ELSE 0 END) AS ready,
                    SUM(CASE WHEN s.stock_type = 'WIP' THEN COALESCE(s.quantity, 0) ELSE 0 END) AS wip
             FROM stock s JOIN products pr ON pr.id = s.product_id
             GROUP BY s.product_id, pr.name, pr.variant, pr.unit
             ORDER BY fg DESC, ready DESC, pr.name LIMIT " . max(1, $limit)
        );
    }

    /**
     * Follow up terbuka hari ini ("today") atau yang terlewat ("overdue").
     * Milik user yang login ditampilkan lebih dulu.
     * @return list<array<string,mixed>>
     */
    public static function followUps(string $bucket, string $today, ?int $meId, int $limit = 6): array
    {
        $cond = $bucket === 'today' ? 'f.follow_up_date = :d' : 'f.follow_up_date < :d';
        return Database::fetchAll(
            "SELECT f.id, f.follow_up_date, f.follow_up_time, f.follow_up_type, f.purpose, f.status, f.customer_id, f.lead_id, f.pic_user_id,
                    c.name AS customer_name, l.lead_name, u.name AS pic_name
             FROM follow_up f LEFT JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id
             LEFT JOIN users u ON u.id = f.pic_user_id
             WHERE {$cond} AND f.status NOT IN ('Done','Cancelled')
             ORDER BY (f.pic_user_id = :me) DESC, f.follow_up_date ASC, f.follow_up_time IS NULL, f.follow_up_time ASC, f.id ASC
             LIMIT " . max(1, $limit),
            ['d' => $today, 'me' => $meId ?? 0]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recentActivities(int $limit = 6): array
    {
        return Database::fetchAll(
            'SELECT a.id, a.activity_date, a.activity_type, a.subject, a.customer_id, a.lead_id,
                    COALESCE(c.name, l.company_name, l.lead_name) AS related, u.name AS pic_name
             FROM activities a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN leads l ON l.id = a.lead_id
             LEFT JOIN users u ON u.id = a.pic_user_id
             ORDER BY a.activity_date DESC, a.id DESC LIMIT ' . max(1, $limit)
        );
    }

    /** @return list<array<string,mixed>> PO terbaru */
    public static function recentOrders(int $limit = 6): array
    {
        return Database::fetchAll(
            'SELECT p.id, p.code, COALESCE(p.order_number, p.po_number) AS po_number, p.po_date, p.status, p.ppic_status, c.name AS customer_name,
                    COALESCE(t.total_qty, 0) AS total_qty, COALESCE(t.outstanding_qty, 0) AS outstanding_qty
             FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
             ORDER BY p.po_date IS NULL, p.po_date DESC, p.id DESC LIMIT ' . max(1, $limit)
        );
    }

    /**
     * Penerimaan barang terbaru (inbound maklon + inbound supplier) sesuai hak akses.
     * @return list<array<string,mixed>>
     */
    public static function recentInbound(int $limit = 6): array
    {
        $parts = [];
        if (Auth::can('inbound.view')) {
            $parts[] = "SELECT 'maklon' AS kind, id, code, sj_number, actual_inbound_date AS inbound_date, vendor AS partner,
                               COALESCE(component_name, '') AS item_name, COALESCE(total_in, quantity) AS qty, 'pcs' AS unit
                        FROM inbound_maklon";
        }
        if (Auth::can('inbound_supplier.view')) {
            $parts[] = "SELECT 'supplier' AS kind, id, code, sj_number, inbound_date, supplier AS partner,
                               item_name, COALESCE(total_in, quantity) AS qty, unit
                        FROM inbound_supplier";
        }
        if ($parts === []) {
            return [];
        }
        return Database::fetchAll(
            'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') x
             ORDER BY x.inbound_date IS NULL, x.inbound_date DESC, x.id DESC LIMIT ' . max(1, $limit)
        );
    }
}
