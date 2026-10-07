<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Paginator;
use App\Models\PoLine;

/**
 * Tab pada halaman detail customer: satu tempat untuk melihat konteks
 * contacts, leads, activities, follow up, order (OEF), delivery, dan complaint/return.
 * Tab hanya tampil bila role user punya akses ke modul tersebut.
 */
final class CustomerTabs
{
    public const TABS = [
        'overview'   => ['label' => 'Overview', 'perm' => 'customers.view', 'count' => null],
        'contacts'   => ['label' => 'Contacts', 'perm' => 'contacts.view', 'count' => 'contacts'],
        'leads'      => ['label' => 'Leads', 'perm' => 'leads.view', 'count' => 'leads'],
        'activities' => ['label' => 'Activities', 'perm' => 'activities.view', 'count' => 'activities'],
        'followups'  => ['label' => 'Follow Up', 'perm' => 'followups.view', 'count' => 'followups'],
        'pos'        => ['label' => 'Order (OEF)', 'perm' => 'purchase_orders.view', 'count' => 'pos'],
        'deliveries' => ['label' => 'Deliveries', 'perm' => 'deliveries.view', 'count' => 'deliveries'],
        'returns'    => ['label' => 'Complaint & Return', 'perm' => 'returns.view', 'count' => 'returns'],
    ];

    /**
     * @param array<string,int> $counts
     * @return array<string,array{label:string,count:int|null}>
     */
    public static function available(array $counts): array
    {
        $out = [];
        foreach (self::TABS as $key => $tab) {
            if (Auth::can($tab['perm'])) {
                $out[$key] = ['label' => $tab['label'], 'count' => $tab['count'] ? ($counts[$tab['count']] ?? 0) : null];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function load(string $tab, int $customerId, int $page): array
    {
        $today = today();
        return match ($tab) {
            'overview'   => self::overview($customerId, $today),
            'contacts'   => ['contacts' => Database::fetchAll('SELECT * FROM contacts WHERE customer_id = :c ORDER BY is_primary DESC, status ASC, name ASC', ['c' => $customerId])],
            'leads'      => ['leads' => Database::fetchAll(
                'SELECT l.*, u.name AS pic_name FROM leads l LEFT JOIN users u ON u.id = l.pic_user_id
                 WHERE l.customer_id = :c ORDER BY FIELD(l.status, \'New\',\'Contacted\',\'Qualified\',\'Quotation\',\'Negotiation\',\'Won\',\'Lost\',\'Dormant\'), l.created_at DESC',
                ['c' => $customerId]
            )],
            'activities' => ['activities' => Paginator::query(
                'SELECT a.*, u.name AS pic_name, l.lead_name FROM activities a
                 LEFT JOIN users u ON u.id = a.pic_user_id LEFT JOIN leads l ON l.id = a.lead_id
                 WHERE a.customer_id = :c',
                ['c' => $customerId],
                'a.activity_date DESC, a.id DESC',
                $page,
                20
            )],
            'followups'  => ['followups' => Paginator::query(
                "SELECT f.*, u.name AS pic_name, l.lead_name,
                        (f.follow_up_date < :today AND f.status NOT IN ('Done','Cancelled')) AS is_overdue
                 FROM follow_up f LEFT JOIN users u ON u.id = f.pic_user_id LEFT JOIN leads l ON l.id = f.lead_id
                 WHERE f.customer_id = :c",
                ['c' => $customerId, 'today' => $today],
                "(f.status IN ('Done','Cancelled')) ASC, f.follow_up_date ASC, f.id ASC",
                $page,
                20
            )],
            'pos'        => ['pos' => Paginator::query(
                'SELECT p.*, COALESCE(t.line_count, 0) AS line_count, COALESCE(t.total_qty, 0) AS total_qty,
                        COALESCE(t.delivered_qty, 0) AS delivered_qty, COALESCE(t.return_qty, 0) AS return_qty,
                        COALESCE(t.outstanding_qty, 0) AS outstanding_qty
                 FROM purchase_orders p LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
                 WHERE p.customer_id = :c',
                ['c' => $customerId],
                "(p.status IN ('Closed','Cancelled')) ASC, p.po_date DESC, p.id DESC",
                $page,
                20
            )],
            'deliveries' => ['deliveries' => Paginator::query(
                'SELECT d.*, COALESCE(p.order_number, p.po_number) AS po_number, p.code AS po_code, pr.name AS product_name
                 FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id LEFT JOIN products pr ON pr.id = d.product_id
                 WHERE p.customer_id = :c',
                ['c' => $customerId],
                'd.delivery_date DESC, d.id DESC',
                $page,
                25
            )],
            'returns'    => ['returns' => Paginator::query(
                'SELECT r.*, COALESCE(p.order_number, p.po_number) AS po_number, p.code AS po_code, pr.name AS product_name
                 FROM returns r LEFT JOIN purchase_orders p ON p.id = r.po_id LEFT JOIN products pr ON pr.id = r.product_id
                 WHERE COALESCE(r.customer_id, p.customer_id) = :c',
                ['c' => $customerId],
                'r.return_date DESC, r.id DESC',
                $page,
                25
            )],
            default      => [],
        };
    }

    /** @return array<string,mixed> */
    private static function overview(int $customerId, string $today): array
    {
        $data = [
            'contacts'   => Database::fetchAll("SELECT * FROM contacts WHERE customer_id = :c AND status = 'Active' ORDER BY is_primary DESC, name ASC LIMIT 4", ['c' => $customerId]),
            'activities' => [],
            'followups'  => [],
            'openPos'    => [],
            'deliveries' => [],
            'leads'      => [],
        ];
        if (Auth::can('activities.view')) {
            $data['activities'] = Database::fetchAll(
                'SELECT a.*, u.name AS pic_name FROM activities a LEFT JOIN users u ON u.id = a.pic_user_id
                 WHERE a.customer_id = :c ORDER BY a.activity_date DESC, a.id DESC LIMIT 6',
                ['c' => $customerId]
            );
        }
        if (Auth::can('followups.view')) {
            $data['followups'] = Database::fetchAll(
                "SELECT f.*, u.name AS pic_name, (f.follow_up_date < :today) AS is_overdue
                 FROM follow_up f LEFT JOIN users u ON u.id = f.pic_user_id
                 WHERE f.customer_id = :c AND f.status NOT IN ('Done','Cancelled')
                 ORDER BY f.follow_up_date ASC, f.id ASC LIMIT 6",
                ['c' => $customerId, 'today' => $today]
            );
        }
        if (Auth::can('leads.view')) {
            $data['leads'] = Database::fetchAll(
                "SELECT l.* FROM leads l WHERE l.customer_id = :c AND l.status NOT IN ('Won','Lost','Dormant')
                 ORDER BY l.expected_close_date IS NULL, l.expected_close_date ASC LIMIT 5",
                ['c' => $customerId]
            );
        }
        if (Auth::can('purchase_orders.view')) {
            $data['openPos'] = Database::fetchAll(
                "SELECT p.*, COALESCE(t.total_qty, 0) AS total_qty, COALESCE(t.delivered_qty, 0) AS delivered_qty,
                        COALESCE(t.outstanding_qty, 0) AS outstanding_qty
                 FROM purchase_orders p LEFT JOIN (" . PoLine::poTotalsSql() . ") t ON t.po_id = p.id
                 WHERE p.customer_id = :c AND p.status IN ('Open','On Process','Partial')
                 ORDER BY p.po_date DESC, p.id DESC LIMIT 6",
                ['c' => $customerId]
            );
        }
        if (Auth::can('deliveries.view')) {
            $data['deliveries'] = Database::fetchAll(
                'SELECT d.*, COALESCE(p.order_number, p.po_number) AS po_number, pr.name AS product_name
                 FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id LEFT JOIN products pr ON pr.id = d.product_id
                 WHERE p.customer_id = :c ORDER BY d.delivery_date DESC, d.id DESC LIMIT 5',
                ['c' => $customerId]
            );
        }
        return $data;
    }
}
