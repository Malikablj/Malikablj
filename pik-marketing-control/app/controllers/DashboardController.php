<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Database;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $today = today();
        $this->view('dashboard/index', [
            'title'       => 'Dashboard',
            'kpi'         => DashboardService::kpis($today),
            'today'       => $today,
            'hasBusinessData' => (int) Database::fetchValue('SELECT COUNT(*) FROM customers') > 0,
        ]);
    }
}
