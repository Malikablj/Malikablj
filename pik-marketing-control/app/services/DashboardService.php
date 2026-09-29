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

        return [
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
}
