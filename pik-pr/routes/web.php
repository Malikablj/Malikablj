<?php

declare(strict_types=1);

use App\Controllers\Api\PrApiController;
use App\Controllers\ApprovalController;
use App\Controllers\AttachmentController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DepartmentController;
use App\Controllers\ItemController;
use App\Controllers\NotificationController;
use App\Controllers\PrController;
use App\Controllers\ReportController;
use App\Controllers\SettingsController;
use App\Controllers\SupplierController;
use App\Controllers\UserController;
use App\Controllers\WorkflowController;
use App\Core\Router;

return static function (Router $r): void {
    $auth = ['auth'];
    $admin = ['auth', 'role:admin,super_admin'];

    // Autentikasi
    $r->get('/', [AuthController::class, 'home']);
    $r->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $r->post('/login', [AuthController::class, 'login'], ['guest']);
    $r->post('/logout', [AuthController::class, 'logout'], $auth);

    $r->get('/dashboard', [DashboardController::class, 'index'], $auth);

    // Purchase Requisition
    $r->get('/pr', [PrController::class, 'index'], $auth);
    $r->get('/pr/create', [PrController::class, 'create'], $auth);
    $r->post('/pr', [PrController::class, 'store'], $auth);
    $r->get('/pr/{id}', [PrController::class, 'show'], $auth);
    $r->get('/pr/{id}/edit', [PrController::class, 'edit'], $auth);
    $r->post('/pr/{id}', [PrController::class, 'update'], $auth);
    $r->post('/pr/{id}/submit', [PrController::class, 'submit'], $auth);
    $r->post('/pr/{id}/cancel', [PrController::class, 'cancel'], $auth);
    $r->post('/pr/{id}/complete', [PrController::class, 'complete'], $auth);
    $r->get('/pr/{id}/pdf', [PrController::class, 'pdf'], $auth);
    $r->post('/pr/{id}/attachments', [PrController::class, 'uploadAttachments'], $auth);
    $r->post('/pr/{id}/approve', [ApprovalController::class, 'approve'], $auth);
    $r->post('/pr/{id}/reject', [ApprovalController::class, 'reject'], $auth);
    $r->post('/pr/{id}/revision', [ApprovalController::class, 'revision'], $auth);

    $r->get('/attachments/{id}/download', [AttachmentController::class, 'download'], $auth);
    $r->post('/attachments/{id}/delete', [AttachmentController::class, 'delete'], $auth);

    $r->get('/approvals', [ApprovalController::class, 'index'], $auth);

    $r->get('/notifications', [NotificationController::class, 'index'], $auth);
    $r->post('/notifications/read-all', [NotificationController::class, 'readAll'], $auth);
    $r->post('/notifications/{id}/read', [NotificationController::class, 'read'], $auth);

    $r->get('/reports', [ReportController::class, 'index'], $admin);
    $r->get('/reports/export', [ReportController::class, 'export'], $admin);

    // Master data
    foreach ([
        '/users' => UserController::class,
        '/departments' => DepartmentController::class,
        '/suppliers' => SupplierController::class,
        '/items' => ItemController::class,
    ] as $prefix => $controller) {
        $r->get($prefix, [$controller, 'index'], $admin);
        $r->get($prefix . '/create', [$controller, 'create'], $admin);
        $r->post($prefix, [$controller, 'store'], $admin);
        $r->get($prefix . '/{id}/edit', [$controller, 'edit'], $admin);
        $r->post($prefix . '/{id}', [$controller, 'update'], $admin);
        $r->post($prefix . '/{id}/toggle', [$controller, 'toggle'], $admin);
    }
    $r->post('/departments/{id}/delete', [DepartmentController::class, 'delete'], $admin);
    $r->post('/suppliers/{id}/delete', [SupplierController::class, 'delete'], $admin);
    $r->post('/items/{id}/delete', [ItemController::class, 'delete'], $admin);

    $r->get('/approval-workflows', [WorkflowController::class, 'index'], $admin);
    $r->get('/approval-workflows/create', [WorkflowController::class, 'create'], $admin);
    $r->post('/approval-workflows', [WorkflowController::class, 'store'], $admin);
    $r->get('/approval-workflows/{id}/edit', [WorkflowController::class, 'edit'], $admin);
    $r->post('/approval-workflows/{id}', [WorkflowController::class, 'update'], $admin);
    $r->post('/approval-workflows/{id}/delete', [WorkflowController::class, 'delete'], $admin);

    $r->get('/audit-logs', [AuditLogController::class, 'index'], $admin);
    $r->get('/audit-logs/{id}', [AuditLogController::class, 'show'], $admin);

    $r->get('/settings', [SettingsController::class, 'index'], $auth);
    $r->post('/settings/password', [SettingsController::class, 'updatePassword'], $auth);
    $r->post('/settings/system', [SettingsController::class, 'updateSystem'], ['auth', 'role:super_admin']);

    // JSON API (session + header X-CSRF-Token untuk request yang mengubah data)
    $r->get('/api/pr', [PrApiController::class, 'index'], $auth);
    $r->post('/api/pr', [PrApiController::class, 'store'], $auth);
    $r->get('/api/pr/{id}', [PrApiController::class, 'show'], $auth);
    $r->put('/api/pr/{id}', [PrApiController::class, 'update'], $auth);
    $r->post('/api/pr/{id}/submit', [PrApiController::class, 'submit'], $auth);
    $r->post('/api/pr/{id}/approve', [PrApiController::class, 'approve'], $auth);
    $r->post('/api/pr/{id}/reject', [PrApiController::class, 'reject'], $auth);
    $r->post('/api/pr/{id}/revision', [PrApiController::class, 'revision'], $auth);
    $r->get('/api/pr/{id}/pdf', [PrApiController::class, 'pdf'], $auth);
    $r->get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount'], $auth);
};
