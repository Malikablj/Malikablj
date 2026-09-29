<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\User;

final class AuditLogController extends Controller
{
    public function index(): void
    {
        $filters = [
            'q'       => Request::queryString('q'),
            'user_id' => Request::queryInt('user_id'),
            'action'  => Request::queryString('action'),
            'entity'  => Request::queryString('entity'),
            'from'    => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'      => Validator::parseDate(Request::queryString('to')) ?? '',
        ];
        $this->view('audit/index', [
            'title'    => 'Audit Log',
            'logs'     => AuditLog::paginate($filters, $this->page()),
            'filters'  => $filters,
            'users'    => User::options('name'),
            'actions'  => AuditLog::actions(),
            'entities' => AuditLog::entities(),
        ]);
    }

    public function show(int $id): void
    {
        $log = $this->found(AuditLog::find($id));
        $changes = $log['changes'] ? json_decode((string) $log['changes'], true) : null;
        $this->view('audit/show', ['title' => 'Detail Audit Log', 'log' => $log, 'changes' => is_array($changes) ? $changes : null]);
    }
}
