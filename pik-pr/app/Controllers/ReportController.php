<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\MasterDataRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\ReportService;

final class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = ReportService::filters($request->query);
        $reports = new ReportService();

        return $this->view('reports/index', [
            'title' => 'Laporan',
            'filters' => $filters,
            'summary' => $reports->summary($filters),
            'byStatus' => $reports->breakdown($filters, 'status'),
            'byDepartment' => $reports->breakdown($filters, 'department'),
            'bySupplier' => $reports->breakdown($filters, 'supplier'),
            'byRequester' => $reports->breakdown($filters, 'requester'),
            'byMonth' => $reports->breakdown($filters, 'month'),
            'rows' => $reports->rows($filters, 200),
            'departments' => MasterDataRepository::departments()->all(),
            'suppliers' => MasterDataRepository::suppliers()->all(),
            'requesters' => (new UserRepository())->requesters(),
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = ReportService::filters($request->query);
        $csv = (new ReportService())->csv($filters);
        (new AuditService())->log((int) $this->user()['id'], 'report.export', 'report', null, null, $filters);

        return Response::download($csv, 'laporan-pr-' . date('Ymd-His') . '.csv', 'text/csv; charset=utf-8');
    }
}
