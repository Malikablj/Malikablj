<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;

final class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $repo = new AuditLogRepository();
        $filters = [
            'action' => $request->queryString('action'),
            'entity_type' => $request->queryString('entity_type'),
            'user_id' => (string) (int) $request->queryString('user_id'),
            'date_from' => $request->queryString('date_from'),
            'date_to' => $request->queryString('date_to'),
        ];
        if ($filters['user_id'] === '0') {
            $filters['user_id'] = '';
        }
        foreach (['date_from', 'date_to'] as $key) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
                $filters[$key] = '';
            }
        }
        $page = $this->page($request);
        $result = $repo->paginate($filters, $page, 30);

        return $this->view('audit/index', [
            'title' => 'Audit Log',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 30,
            'filters' => $filters,
            'actions' => $repo->distinctActions(),
            'entityTypes' => $repo->distinctEntityTypes(),
            'users' => (new UserRepository())->paginate([], 1, 200)['rows'],
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $log = $this->findOr404((new AuditLogRepository())->find($id));

        return $this->view('audit/show', [
            'title' => 'Audit Log #' . $id,
            'log' => $log,
            'old' => json_decode((string) ($log['old_values'] ?? ''), true),
            'new' => json_decode((string) ($log['new_values'] ?? ''), true),
        ]);
    }
}
