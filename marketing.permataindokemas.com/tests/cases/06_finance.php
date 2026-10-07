<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Number;
use App\Models\Customer;
use App\Models\PurchaseOrder;

/*
 * Menu Finance (Invoice & Payment, PO Financials) dihapus karena ranah divisi Keuangan.
 * Test ini memastikan menu, halaman, laporan, dan notifikasi keuangan sudah tidak ada,
 * sementara data lama (hasil migrasi) tetap aman di database.
 */
$finSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $c1 = Customer::create(['name' => 'PT Finance Uji', 'status' => 'Active']);
    $po = Database::insert('purchase_orders', ['code' => 'PO-FIN0000001', 'po_number' => 'PO/FIN/045', 'customer_id' => $c1, 'po_date' => today(), 'status' => 'Open', 'payment_term' => 'NET 45']);
    $inv = Database::insert('invoices_payments', ['code' => 'PAY-FIN0000001', 'customer_id' => $c1, 'po_id' => $po, 'invoice_number' => 'INV/FIN/LAMA', 'invoice_date' => today(),
        'due_date' => date('Y-m-d', strtotime(today() . ' -5 days')), 'invoice_amount' => '1500000.00', 'paid_amount' => '0.00', 'status' => 'Unpaid']);
    $pof = Database::insert('po_financials', ['code' => 'POF-FIN0000001', 'po_id' => $po, 'po_number_legacy' => 'PO/FIN/045', 'order_qty' => 100, 'unit_price' => '1500.000000',
        'total_order_amount' => '150000.00', 'payment_status' => 'Unpaid']);
    return $ids = ['c1' => $c1, 'po' => $po, 'inv' => $inv, 'pof' => $pof];
};

group('Phase 6 · Unit: uang');

test('konversi uang ke sen tanpa error float', function () {
    assert_same(125000051, Number::toCents('1250000.505'));
    assert_same('1250000.51', Number::fromCents(125000051));
    assert_same(30, Number::toCents(0.1 + 0.2));
    assert_same('-0.05', Number::fromCents(-5));
});

group('Phase 6 · Menu Finance dihapus');

test('halaman Invoice & Payment dan PO Financials tidak ada lagi (404), termasuk untuk Admin', function () use ($finSetup) {
    $ids = $finSetup();
    $admin = client_as('Admin');
    foreach (['/invoices', '/invoices/create', '/invoices/' . $ids['inv'], '/invoices/' . $ids['inv'] . '/edit',
              '/po-financials', '/po-financials/create', '/po-financials/' . $ids['pof'], '/reports/financial', '/reports/financial/export'] as $path) {
        assert_status(404, $admin->get($path), 'GET ' . $path);
    }
    assert_status(404, $admin->post('/invoices/' . $ids['inv'] . '/payments', ['amount' => '1', 'payment_date' => today()]));
    assert_status(404, $admin->post('/invoices', ['customer_id' => (string) $ids['c1'], 'invoice_number' => 'INV/BARU', 'invoice_date' => today(), 'invoice_amount' => '1']));
    assert_false((bool) Database::fetchValue("SELECT 1 FROM invoices_payments WHERE invoice_number = 'INV/BARU'"));
    foreach (['App\\Controllers\\InvoiceController', 'App\\Controllers\\PoFinancialController', 'App\\Models\\Invoice', 'App\\Models\\PoFinancial'] as $class) {
        assert_false(class_exists($class), $class . ' dihapus');
    }
});

test('menu, dashboard, customer, order, laporan & pengaturan tanpa unsur keuangan', function () use ($finSetup) {
    $ids = $finSetup();
    $admin = client_as('Admin');
    $home = $admin->get('/');
    assert_status(200, $home);
    foreach (['Invoice &amp; Payment', 'PO Financials', 'href="/invoices"', 'href="/po-financials"', 'Piutang belum dibayar'] as $text) {
        assert_not_contains($text, $home->body, 'dashboard/menu: ' . $text);
    }
    $customer = $admin->get('/customers/' . $ids['c1']);
    assert_not_contains('Piutang', $customer->body);
    assert_not_contains('tab=invoices', $customer->body);
    $order = $admin->get('/purchase-orders/' . $ids['po']);
    assert_status(200, $order);
    assert_not_contains('INV/FIN/LAMA', $order->body, 'bagian Finance di halaman order dihapus');
    assert_not_contains('/invoices/create', $order->body);
    $reports = $admin->get('/reports');
    assert_not_contains('/reports/financial', $reports->body);
    $custReport = $admin->get('/reports/customer');
    assert_not_contains('Piutang', $custReport->body);
    assert_not_contains('Nilai invoice', $custReport->body);
    $settings = $admin->get('/settings');
    assert_not_contains('Jatuh tempo default invoice', $settings->body);
    assert_not_contains('Tarif PPN', $settings->body);
    $search = $admin->get('/search', ['q' => 'INV/FIN']);
    assert_not_contains('INV/FIN/LAMA', $search->body, 'invoice tidak muncul di pencarian');
});

test('data keuangan lama tetap tersimpan & tetap melindungi order dari penghapusan', function () use ($finSetup) {
    $ids = $finSetup();
    assert_same('1500000.00', Database::fetchValue('SELECT invoice_amount FROM invoices_payments WHERE id = :id', ['id' => $ids['inv']]));
    assert_true((bool) Database::fetchValue('SELECT 1 FROM po_financials WHERE id = :id', ['id' => $ids['pof']]));
    $deps = PurchaseOrder::dependents($ids['po']);
    assert_same(1, $deps['invoices'] ?? 0);
    $res = client_as('Admin')->post('/purchase-orders/' . $ids['po'] . '/delete');
    assert_redirect($res, '/purchase-orders/' . $ids['po']);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM purchase_orders WHERE id = :id', ['id' => $ids['po']]), 'order dengan invoice lama tidak terhapus');
    // otomasi tidak lagi mengubah status invoice / mengirim notifikasi invoice
    $result = App\Services\Automation::run();
    assert_true(is_array($result));
    assert_false(array_key_exists('invoices_updated', $result));
    assert_false(array_key_exists('invoice_overdue', $result['notifications']));
    assert_same('Unpaid', Database::fetchValue('SELECT status FROM invoices_payments WHERE id = :id', ['id' => $ids['inv']]));
    assert_false((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE type = 'invoice_overdue'"));
});
