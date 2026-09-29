<?php

declare(strict_types=1);

namespace App\Helpers;

/** Struktur menu sidebar. Item hanya tampil bila user punya permission-nya. */
final class Navigation
{
    /** @return list<array{label:string,items:list<array{label:string,icon:string,path:string,permission:string}>}> */
    public static function groups(): array
    {
        return [
            ['label' => '', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'path' => '/', 'permission' => 'dashboard.view'],
            ]],
            ['label' => 'Customer & CRM', 'items' => [
                ['label' => 'Customers', 'icon' => 'bi-buildings', 'path' => '/customers', 'permission' => 'customers.view'],
                ['label' => 'Contacts', 'icon' => 'bi-person-lines-fill', 'path' => '/contacts', 'permission' => 'contacts.view'],
                ['label' => 'Leads', 'icon' => 'bi-kanban', 'path' => '/leads', 'permission' => 'leads.view'],
                ['label' => 'Activities', 'icon' => 'bi-chat-square-text', 'path' => '/activities', 'permission' => 'activities.view'],
                ['label' => 'Follow Up', 'icon' => 'bi-calendar2-check', 'path' => '/follow-ups', 'permission' => 'followups.view'],
            ]],
            ['label' => 'Operations', 'items' => [
                ['label' => 'Purchase Orders', 'icon' => 'bi-receipt', 'path' => '/purchase-orders', 'permission' => 'purchase_orders.view'],
                ['label' => 'Deliveries', 'icon' => 'bi-truck', 'path' => '/deliveries', 'permission' => 'deliveries.view'],
                ['label' => 'Returns', 'icon' => 'bi-arrow-return-left', 'path' => '/returns', 'permission' => 'returns.view'],
            ]],
            ['label' => 'Inventory', 'items' => [
                ['label' => 'Products', 'icon' => 'bi-box-seam', 'path' => '/products', 'permission' => 'products.view'],
                ['label' => 'Stock', 'icon' => 'bi-boxes', 'path' => '/stock', 'permission' => 'stock.view'],
                ['label' => 'Lead Time', 'icon' => 'bi-hourglass-split', 'path' => '/lead-times', 'permission' => 'leadtime.view'],
                ['label' => 'Inbound Maklon', 'icon' => 'bi-box-arrow-in-down', 'path' => '/inbound', 'permission' => 'inbound.view'],
            ]],
            ['label' => 'Finance', 'items' => [
                ['label' => 'Invoice & Payment', 'icon' => 'bi-cash-coin', 'path' => '/invoices', 'permission' => 'finance.view'],
                ['label' => 'PO Financials', 'icon' => 'bi-graph-up-arrow', 'path' => '/po-financials', 'permission' => 'finance.view'],
            ]],
            ['label' => 'Insight', 'items' => [
                ['label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'path' => '/reports', 'permission' => 'reports.view'],
                ['label' => 'Notifications', 'icon' => 'bi-bell', 'path' => '/notifications', 'permission' => 'auth'],
            ]],
            ['label' => 'Settings', 'items' => [
                ['label' => 'Users', 'icon' => 'bi-people', 'path' => '/users', 'permission' => 'users.view'],
                ['label' => 'Migration Issues', 'icon' => 'bi-exclamation-diamond', 'path' => '/migration-issues', 'permission' => 'migration.view'],
                ['label' => 'Import Data', 'icon' => 'bi-cloud-arrow-up', 'path' => '/import', 'permission' => 'import.view'],
                ['label' => 'Audit Log', 'icon' => 'bi-shield-check', 'path' => '/audit-log', 'permission' => 'audit.view'],
            ]],
        ];
    }

    /** Grup menu yang sudah difilter sesuai hak akses user saat ini. */
    public static function visibleGroups(): array
    {
        $out = [];
        foreach (self::groups() as $group) {
            $items = array_values(array_filter($group['items'], static function (array $item): bool {
                return $item['permission'] === 'auth' ? Auth::check() : Auth::can($item['permission']);
            }));
            if ($items !== []) {
                $out[] = ['label' => $group['label'], 'items' => $items];
            }
        }
        return $out;
    }

    public static function isActive(string $path): bool
    {
        $current = Request::path();
        if ($path === '/') {
            return $current === '/';
        }
        return $current === $path || str_starts_with($current, $path . '/');
    }

    /** Item navigasi bawah (mobile). */
    public static function bottomItems(): array
    {
        $candidates = [
            ['label' => 'Home', 'icon' => 'bi-grid-1x2', 'path' => '/', 'permission' => 'dashboard.view'],
            ['label' => 'Customers', 'icon' => 'bi-buildings', 'path' => '/customers', 'permission' => 'customers.view'],
            ['label' => 'Leads', 'icon' => 'bi-kanban', 'path' => '/leads', 'permission' => 'leads.view'],
            ['label' => 'Follow Up', 'icon' => 'bi-calendar2-check', 'path' => '/follow-ups', 'permission' => 'followups.view'],
            ['label' => 'PO', 'icon' => 'bi-receipt', 'path' => '/purchase-orders', 'permission' => 'purchase_orders.view'],
            ['label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'path' => '/reports', 'permission' => 'reports.view'],
        ];
        $items = array_values(array_filter($candidates, static fn ($i) => Auth::can($i['permission'])));
        return array_slice($items, 0, 4);
    }
}
