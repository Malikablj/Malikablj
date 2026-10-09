<?php

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\ContactController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\DeliveryController;
use App\Controllers\FollowUpController;
use App\Controllers\ImportController;
use App\Controllers\InboundController;
use App\Controllers\InboundSupplierController;
use App\Controllers\LeadController;
use App\Controllers\LeadTimeController;
use App\Controllers\MigrationIssueController;
use App\Controllers\NotificationController;
use App\Controllers\ProductController;
use App\Controllers\ProfileController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\ReportController;
use App\Controllers\ReturnController;
use App\Controllers\SearchController;
use App\Controllers\SettingsController;
use App\Controllers\SetupController;
use App\Controllers\StockController;
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

    // Pencarian global (hasil difilter sesuai hak akses)
    $r->get('/search', [SearchController::class, 'index'], 'auth');

    // Customers & contacts
    $r->get('/customers', [CustomerController::class, 'index'], 'customers.view');
    $r->get('/customers/create', [CustomerController::class, 'create'], 'customers.create');
    $r->post('/customers', [CustomerController::class, 'store'], 'customers.create');
    $r->get('/customers/{id}', [CustomerController::class, 'show'], 'customers.view');
    $r->get('/customers/{id}/edit', [CustomerController::class, 'edit'], 'customers.edit');
    $r->post('/customers/{id}', [CustomerController::class, 'update'], 'customers.edit');
    $r->post('/customers/{id}/delete', [CustomerController::class, 'destroy'], 'customers.delete');
    $r->get('/contacts', [ContactController::class, 'index'], 'contacts.view');
    $r->get('/contacts/create', [ContactController::class, 'create'], 'contacts.create');
    $r->post('/contacts', [ContactController::class, 'store'], 'contacts.create');
    $r->get('/contacts/{id}/edit', [ContactController::class, 'edit'], 'contacts.edit');
    $r->post('/contacts/{id}', [ContactController::class, 'update'], 'contacts.edit');
    $r->post('/contacts/{id}/delete', [ContactController::class, 'destroy'], 'contacts.delete');

    // CRM: leads, activities, follow up
    $r->get('/leads', [LeadController::class, 'index'], 'leads.view');
    $r->get('/leads/list', [LeadController::class, 'list'], 'leads.view');
    $r->get('/leads/create', [LeadController::class, 'create'], 'leads.create');
    $r->post('/leads', [LeadController::class, 'store'], 'leads.create');
    $r->get('/leads/{id}', [LeadController::class, 'show'], 'leads.view');
    $r->get('/leads/{id}/edit', [LeadController::class, 'edit'], 'leads.edit');
    $r->post('/leads/{id}', [LeadController::class, 'update'], 'leads.edit');
    $r->post('/leads/{id}/status', [LeadController::class, 'status'], 'leads.edit');
    $r->post('/leads/{id}/convert', [LeadController::class, 'convert'], 'leads.edit');
    $r->post('/leads/{id}/delete', [LeadController::class, 'destroy'], 'leads.delete');
    $r->get('/activities', [ActivityController::class, 'index'], 'activities.view');
    $r->get('/activities/create', [ActivityController::class, 'create'], 'activities.create');
    $r->post('/activities', [ActivityController::class, 'store'], 'activities.create');
    $r->get('/activities/{id}/edit', [ActivityController::class, 'edit'], 'activities.edit');
    $r->post('/activities/{id}', [ActivityController::class, 'update'], 'activities.edit');
    $r->post('/activities/{id}/delete', [ActivityController::class, 'destroy'], 'activities.delete');
    $r->get('/follow-ups', [FollowUpController::class, 'index'], 'followups.view');
    $r->get('/follow-ups/create', [FollowUpController::class, 'create'], 'followups.create');
    $r->post('/follow-ups', [FollowUpController::class, 'store'], 'followups.create');
    $r->get('/follow-ups/{id}/edit', [FollowUpController::class, 'edit'], 'followups.view');
    $r->post('/follow-ups/{id}', [FollowUpController::class, 'update'], 'followups.edit');
    $r->post('/follow-ups/{id}/done', [FollowUpController::class, 'done'], 'followups.edit');
    $r->post('/follow-ups/{id}/reschedule', [FollowUpController::class, 'reschedule'], 'followups.edit');
    $r->post('/follow-ups/{id}/delete', [FollowUpController::class, 'destroy'], 'followups.delete');

    // Operations: Order Entry Form (URL /purchase-orders), PO lines, deliveries, complaint & return
    $r->get('/purchase-orders', [PurchaseOrderController::class, 'index'], 'purchase_orders.view');
    $r->get('/purchase-orders/create', [PurchaseOrderController::class, 'create'], 'purchase_orders.create');
    $r->post('/purchase-orders', [PurchaseOrderController::class, 'store'], 'purchase_orders.create');
    $r->post('/purchase-orders/ppic-bulk', [PurchaseOrderController::class, 'ppicBulk'], 'ppic.approve');
    $r->post('/purchase-orders/bulk-delete', [PurchaseOrderController::class, 'destroyBulk'], 'purchase_orders_bulk.delete');
    $r->get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'], 'purchase_orders.view');
    $r->get('/purchase-orders/{id}/edit', [PurchaseOrderController::class, 'edit'], 'purchase_orders.edit');
    $r->post('/purchase-orders/{id}', [PurchaseOrderController::class, 'update'], 'purchase_orders.edit');
    $r->post('/purchase-orders/{id}/delete', [PurchaseOrderController::class, 'destroy'], 'purchase_orders.delete');
    $r->post('/purchase-orders/{id}/ppic', [PurchaseOrderController::class, 'ppic'], 'ppic.approve');
    $r->post('/purchase-orders/{id}/schedule', [PurchaseOrderController::class, 'reschedule'], 'deliveries.edit');
    $r->post('/purchase-orders/{id}/lines', [PurchaseOrderController::class, 'addLine'], 'purchase_orders.edit');
    $r->get('/po-lines/{id}/edit', [PurchaseOrderController::class, 'editLine'], 'purchase_orders.edit');
    $r->post('/po-lines/{id}', [PurchaseOrderController::class, 'updateLine'], 'purchase_orders.edit');
    $r->post('/po-lines/{id}/delete', [PurchaseOrderController::class, 'destroyLine'], 'purchase_orders.edit');
    $r->get('/deliveries', [DeliveryController::class, 'index'], 'deliveries.view');
    $r->get('/deliveries/create', [DeliveryController::class, 'create'], 'deliveries.create');
    $r->post('/deliveries', [DeliveryController::class, 'store'], 'deliveries.create');
    $r->get('/deliveries/{id}', [DeliveryController::class, 'show'], 'deliveries.view');
    $r->get('/deliveries/{id}/edit', [DeliveryController::class, 'edit'], 'deliveries.edit');
    $r->post('/deliveries/{id}', [DeliveryController::class, 'update'], 'deliveries.edit');
    $r->post('/deliveries/{id}/link', [DeliveryController::class, 'link'], 'deliveries.edit');
    $r->post('/deliveries/{id}/delete', [DeliveryController::class, 'destroy'], 'deliveries.delete');
    $r->get('/returns', [ReturnController::class, 'index'], 'returns.view');
    $r->get('/returns/create', [ReturnController::class, 'create'], 'returns.create');
    $r->post('/returns', [ReturnController::class, 'store'], 'returns.create');
    $r->get('/returns/attachments/{id}', [ReturnController::class, 'attachment'], 'returns.view');
    $r->post('/returns/attachments/{id}/delete', [ReturnController::class, 'deleteAttachment'], 'returns.edit');
    $r->get('/returns/{id}', [ReturnController::class, 'show'], 'returns.view');
    $r->get('/returns/{id}/edit', [ReturnController::class, 'edit'], 'returns.edit');
    $r->post('/returns/{id}', [ReturnController::class, 'update'], 'returns.edit');
    $r->post('/returns/{id}/resolve', [ReturnController::class, 'resolve'], 'returns.resolve');
    $r->post('/returns/{id}/email', [ReturnController::class, 'email'], 'returns.edit');
    $r->post('/returns/{id}/delete', [ReturnController::class, 'destroy'], 'returns.delete');

    // Inventory: products, stock, lead time, inbound maklon, inbound supplier
    $r->get('/products', [ProductController::class, 'index'], 'products.view');
    $r->get('/products/create', [ProductController::class, 'create'], 'products.create');
    $r->post('/products', [ProductController::class, 'store'], 'products.create');
    $r->get('/products/{id}', [ProductController::class, 'show'], 'products.view');
    $r->get('/products/{id}/edit', [ProductController::class, 'edit'], 'products.edit');
    $r->post('/products/{id}', [ProductController::class, 'update'], 'products.edit');
    $r->post('/products/{id}/delete', [ProductController::class, 'destroy'], 'products.delete');
    $r->get('/stock', [StockController::class, 'index'], 'stock.view');
    $r->get('/stock/create', [StockController::class, 'create'], 'stock.create');
    $r->post('/stock', [StockController::class, 'store'], 'stock.create');
    $r->get('/stock/{id}/edit', [StockController::class, 'edit'], 'stock.edit');
    $r->post('/stock/{id}', [StockController::class, 'update'], 'stock.edit');
    $r->post('/stock/{id}/delete', [StockController::class, 'destroy'], 'stock.delete');
    $r->get('/lead-times', [LeadTimeController::class, 'index'], 'leadtime.view');
    $r->get('/lead-times/create', [LeadTimeController::class, 'create'], 'leadtime.create');
    $r->post('/lead-times', [LeadTimeController::class, 'store'], 'leadtime.create');
    $r->get('/lead-times/{id}/edit', [LeadTimeController::class, 'edit'], 'leadtime.edit');
    $r->post('/lead-times/{id}', [LeadTimeController::class, 'update'], 'leadtime.edit');
    $r->post('/lead-times/{id}/delete', [LeadTimeController::class, 'destroy'], 'leadtime.delete');
    $r->get('/inbound', [InboundController::class, 'index'], 'inbound.view');
    $r->get('/inbound/create', [InboundController::class, 'create'], 'inbound.create');
    $r->post('/inbound', [InboundController::class, 'store'], 'inbound.create');
    $r->get('/inbound/{id}', [InboundController::class, 'show'], 'inbound.view');
    $r->get('/inbound/{id}/edit', [InboundController::class, 'edit'], 'inbound.edit');
    $r->post('/inbound/{id}', [InboundController::class, 'update'], 'inbound.edit');
    $r->post('/inbound/{id}/delete', [InboundController::class, 'destroy'], 'inbound.delete');
    $r->get('/inbound-supplier', [InboundSupplierController::class, 'index'], 'inbound_supplier.view');
    $r->get('/inbound-supplier/create', [InboundSupplierController::class, 'create'], 'inbound_supplier.create');
    $r->post('/inbound-supplier', [InboundSupplierController::class, 'store'], 'inbound_supplier.create');
    $r->get('/inbound-supplier/{id}', [InboundSupplierController::class, 'show'], 'inbound_supplier.view');
    $r->get('/inbound-supplier/{id}/edit', [InboundSupplierController::class, 'edit'], 'inbound_supplier.edit');
    $r->post('/inbound-supplier/{id}', [InboundSupplierController::class, 'update'], 'inbound_supplier.edit');
    $r->post('/inbound-supplier/{id}/delete', [InboundSupplierController::class, 'destroy'], 'inbound_supplier.delete');

    // Reports (akses per jenis laporan dicek di controller: reports.customer, reports.po, ...)
    $r->get('/reports', [ReportController::class, 'index'], 'reports.view');
    $r->get('/reports/{type}', [ReportController::class, 'show'], 'reports.view');
    $r->get('/reports/{type}/export', [ReportController::class, 'export'], 'reports.export');

    // Settings (Admin)
    $r->get('/users', [UserController::class, 'index'], 'users.view');
    $r->get('/users/create', [UserController::class, 'create'], 'users.create');
    $r->post('/users', [UserController::class, 'store'], 'users.create');
    $r->get('/users/{id}/edit', [UserController::class, 'edit'], 'users.edit');
    $r->post('/users/{id}', [UserController::class, 'update'], 'users.edit');
    $r->post('/users/{id}/password', [UserController::class, 'resetPassword'], 'users.edit');
    $r->post('/users/{id}/unblock', [UserController::class, 'unblock'], 'users.edit');
    $r->post('/users/{id}/delete', [UserController::class, 'destroy'], 'users.delete');
    $r->get('/settings', [SettingsController::class, 'show'], 'settings.view');
    $r->post('/settings', [SettingsController::class, 'update'], 'settings.edit');
    $r->post('/settings/automation/run', [SettingsController::class, 'runAutomation'], 'settings.edit');
    $r->post('/settings/mail/test', [SettingsController::class, 'testMail'], 'settings.edit');
    $r->get('/import', [ImportController::class, 'show'], 'import.view');
    $r->post('/import', [ImportController::class, 'run'], 'import.create');
    $r->get('/migration-issues', [MigrationIssueController::class, 'index'], 'migration.view');
    $r->post('/migration-issues/bulk', [MigrationIssueController::class, 'bulk'], 'migration.edit');
    $r->get('/migration-issues/deliveries', [MigrationIssueController::class, 'deliveries'], 'migration.edit');
    $r->post('/migration-issues/deliveries', [MigrationIssueController::class, 'linkDeliveries'], 'migration.edit');
    $r->get('/migration-issues/{id}', [MigrationIssueController::class, 'show'], 'migration.view');
    $r->post('/migration-issues/{id}/status', [MigrationIssueController::class, 'status'], 'migration.edit');
    $r->post('/migration-issues/{id}/apply', [MigrationIssueController::class, 'apply'], 'migration.edit');
    $r->get('/audit-log', [AuditLogController::class, 'index'], 'audit.view');
    $r->get('/audit-log/{id}', [AuditLogController::class, 'show'], 'audit.view');
};
