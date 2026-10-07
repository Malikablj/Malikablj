<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\LeadTime;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $today = today();
        $canFollow = Auth::can('followups.view');
        if ($canFollow) {
            FollowUp::refreshOverdue($today);
        }
        // Setiap daftar hanya diambil bila role boleh melihat modulnya (otorisasi di backend)
        $this->view('dashboard/index', [
            'title'           => 'Dashboard',
            'kpi'             => DashboardService::kpis($today),
            'today'           => $today,
            'hasBusinessData' => (int) Database::fetchValue('SELECT COUNT(*) FROM customers') > 0,
            'followToday'     => $canFollow ? DashboardService::followUps('today', $today, Auth::id()) : null,
            'followOverdue'   => $canFollow ? DashboardService::followUps('overdue', $today, Auth::id()) : null,
            'activities'      => Auth::can('activities.view') ? DashboardService::recentActivities() : null,
            'orders'          => Auth::can('purchase_orders.view') ? DashboardService::recentOrders() : null,
            'deliveries'      => Auth::can('deliveries.view') ? Delivery::upcoming(6) : null,
            'leadtimes'       => Auth::can('leadtime.view') ? LeadTime::upcoming($today, 4) : null,
            'inbound'         => Auth::can('inbound.view') || Auth::can('inbound_supplier.view') ? DashboardService::recentInbound(6) : null,
        ]);
    }
}
