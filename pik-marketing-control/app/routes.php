<?php

declare(strict_types=1);

use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\NotificationController;
use App\Controllers\ProfileController;
use App\Controllers\SetupController;
use App\Controllers\UserController;
use App\Helpers\Router;

/*
 * Daftar route. Argumen ketiga = permission yang WAJIB dimiliki user
 * (dicek di backend oleh Router sebelum controller dijalankan).
 *   'guest' = tanpa login · 'auth' = cukup login
 */
return static function (Router $r): void {
    // Autentikasi & setup
    $r->get('/login', [AuthController::class, 'showLogin'], 'guest');
    $r->post('/login', [AuthController::class, 'login'], 'guest');
    $r->post('/logout', [AuthController::class, 'logout'], 'auth');
    $r->get('/setup', [SetupController::class, 'show'], 'guest');
    $r->post('/setup', [SetupController::class, 'store'], 'guest');

    // Dashboard
    $r->get('/', [DashboardController::class, 'index'], 'dashboard.view');

    // Profil & notifikasi (milik user sendiri)
    $r->get('/profile', [ProfileController::class, 'show'], 'auth');
    $r->post('/profile', [ProfileController::class, 'update'], 'auth');
    $r->get('/profile/password', [ProfileController::class, 'showPassword'], 'auth');
    $r->post('/profile/password', [ProfileController::class, 'updatePassword'], 'auth');
    $r->get('/notifications', [NotificationController::class, 'index'], 'auth');
    $r->get('/notifications/{id}/open', [NotificationController::class, 'open'], 'auth');
    $r->post('/notifications/read-all', [NotificationController::class, 'markAll'], 'auth');

    // Settings (Admin)
    $r->get('/users', [UserController::class, 'index'], 'users.view');
    $r->get('/users/create', [UserController::class, 'create'], 'users.create');
    $r->post('/users', [UserController::class, 'store'], 'users.create');
    $r->get('/users/{id}/edit', [UserController::class, 'edit'], 'users.edit');
    $r->post('/users/{id}', [UserController::class, 'update'], 'users.edit');
    $r->post('/users/{id}/password', [UserController::class, 'resetPassword'], 'users.edit');
    $r->get('/audit-log', [AuditLogController::class, 'index'], 'audit.view');
    $r->get('/audit-log/{id}', [AuditLogController::class, 'show'], 'audit.view');
};
