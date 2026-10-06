<?php

declare(strict_types=1);

namespace App\Services;

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

        $oef = Database::fetch("SELECT COALESCE(SUM(review_status = 'Pending' AND status <> 'Cancelled'), 0) AS pending FROM purchase_orders") ?? [];
        $week = date('Y-m-d', strtotime($today . ' +7 days'));
        $deliveries = Database::fetch(
            "SELECT COALESCE(SUM(status IN ('Scheduled','On Delivery') AND delivery_date BETWEEN :d1 AND :d2), 0) AS week,
                    COALESCE(SUM(status IN ('Scheduled','On Delivery') AND (sj_number IS NULL OR sj_number = '')), 0) AS without_sj
             FROM deliveries",
            ['d1' => $today, 'd2' => $week]
        ) ?? [];
        $complaints = (int) Database::fetchValue("SELECT COUNT(*) FROM returns WHERE resolution_status = 'Open'");
        $stock = Database::fetch(
            "SELECT COALESCE(SUM(CASE WHEN stock_type = 'Ready' THEN quantity END), 0) AS ready, COALESCE(SUM(CASE WHEN stock_type = 'FG' THEN quantity END), 0) AS fg,
                    COALESCE(SUM(CASE WHEN stock_type = 'WIP' THEN quantity END), 0) AS wip
             FROM stock WHERE product_id IS NOT NULL"
        ) ?? [];
        $monthFrom = date('Y-m-01', strtotime($today));
        $inbound = Database::fetch(
            'SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(total_in, quantity - COALESCE(reject_qty, 0))), 0) AS qty FROM inbound_maklon WHERE actual_inbound_date >= :m',
            ['m' => $monthFrom]
        ) ?? [];
        $supplier = Database::fetch(
            'SELECT COUNT(*) AS n, COALESCE(SUM(quantity - reject_qty), 0) AS qty FROM inbound_supplier WHERE receive_date >= :m',
            ['m' => $monthFrom]
        ) ?? [];

        return [
            'oef_pending'        => (int) ($oef['pending'] ?? 0),
            'deliveries_week'    => (int) ($deliveries['week'] ?? 0),
            'deliveries_no_sj'   => (int) ($deliveries['without_sj'] ?? 0),
            'complaints_open'    => $complaints,
            'stock_ready'        => (int) ($stock['ready'] ?? 0),
            'stock_fg'           => (int) ($stock['fg'] ?? 0),
            'stock_wip'          => (int) ($stock['wip'] ?? 0),
            'inbound_month'      => (int) ($inbound['n'] ?? 0),
            'inbound_month_qty'  => (int) ($inbound['qty'] ?? 0),
            'supplier_month'     => (int) ($supplier['n'] ?? 0),
            'supplier_month_qty' => (int) ($supplier['qty'] ?? 0),
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
            'SELECT p.id, p.code, p.order_number, p.po_number, p.po_date, p.status, p.review_status, p.requested_delivery_date, c.name AS customer_name,
                    COALESCE(t.total_qty, 0) AS total_qty, COALESCE(t.outstanding_qty, 0) AS outstanding_qty
             FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
             ORDER BY p.po_date IS NULL, p.po_date DESC, p.id DESC LIMIT ' . max(1, $limit)
        );
    }

    /** @return list<array<string,mixed>> OEF menunggu review PPIC (paling lama dulu) */
    public static function pendingOef(int $limit = 8): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.code, p.order_number, p.po_number, p.po_date, p.requested_delivery_date, p.sales_name, p.created_at, c.name AS customer_name,
                    COALESCE(t.total_qty, 0) AS total_qty, COALESCE(t.line_count, 0) AS line_count
             FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN (" . PoLine::poTotalsSql() . ") t ON t.po_id = p.id
             WHERE p.review_status = 'Pending' AND p.status <> 'Cancelled'
             ORDER BY p.created_at ASC, p.id ASC LIMIT " . max(1, $limit)
        );
    }

    /** @return list<array<string,mixed>> retur & komplain yang belum ada hasilnya */
    public static function openComplaints(int $limit = 5): array
    {
        return Database::fetchAll(
            "SELECT r.id, r.code, r.case_type, r.return_date, r.reason, c.name AS customer_name, pr.name AS product_name
             FROM returns r LEFT JOIN purchase_orders p ON p.id = r.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = r.product_id
             WHERE r.resolution_status = 'Open' ORDER BY r.return_date IS NULL, r.return_date DESC, r.id DESC LIMIT " . max(1, $limit)
        );
    }

    /** @return list<array<string,mixed>> penerimaan inbound maklon terakhir */
    public static function recentInbound(int $limit = 5): array
    {
        return Database::fetchAll(
            'SELECT id, code, sj_number, vendor, component_name, actual_inbound_date, COALESCE(total_in, quantity - COALESCE(reject_qty, 0)) AS total_in
             FROM inbound_maklon ORDER BY actual_inbound_date IS NULL, actual_inbound_date DESC, id DESC LIMIT ' . max(1, $limit)
        );
    }
}
