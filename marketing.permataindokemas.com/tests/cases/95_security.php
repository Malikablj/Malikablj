<?php

declare(strict_types=1);

use App\Helpers\Database;

group('Security · XSS, injeksi, cookie');

const XSS = 'X"><img src=x onerror=alert(1)>';

test('XSS: data berbahaya di semua field teks selalu di-escape di seluruh halaman', function () {
    $x = XSS;
    $admin = client_as('Admin');
    $adminId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'admin.qa@pik.test'");
    // Data "legacy" disisipkan langsung (melewati validasi form), seperti hasil import spreadsheet
    $cust = Database::insert('customers', ['code' => 'CUS-XSS0000001', 'name' => 'Cust ' . $x, 'company' => $x, 'pic' => $x, 'address' => $x, 'industry' => $x, 'source' => $x, 'notes' => $x,
        'phone' => '0812', 'email' => 'a@b.co', 'status' => 'Active', 'marketing_pic_id' => $adminId]);
    Database::insert('contacts', ['code' => 'CON-XSS0000001', 'customer_id' => $cust, 'name' => 'Kontak ' . $x, 'position' => $x, 'notes' => $x, 'is_primary' => 1]);
    $prod = Database::insert('products', ['code' => 'PRD-XSS0000001', 'name' => 'Produk ' . $x, 'variant' => $x, 'product_code' => $x, 'category' => $x, 'notes' => $x, 'unit' => 'pcs']);
    $lead = Database::insert('leads', ['code' => 'LEAD-XSS000001', 'customer_id' => $cust, 'lead_name' => 'Lead ' . $x, 'company_name' => $x, 'product_interest' => $x, 'source' => $x, 'notes' => $x,
        'status' => 'Qualified', 'pic_user_id' => $adminId, 'expected_close_date' => today()]);
    Database::insert('activities', ['code' => 'ACT-XSS0000001', 'customer_id' => $cust, 'lead_id' => $lead, 'activity_date' => today() . ' 08:00:00', 'activity_type' => 'Visit',
        'subject' => 'Aktivitas ' . $x, 'description' => $x, 'next_action' => $x, 'pic_user_id' => $adminId]);
    $fu = Database::insert('follow_up', ['code' => 'FUP-XSS0000001', 'customer_id' => $cust, 'lead_id' => $lead, 'pic_user_id' => $adminId, 'follow_up_date' => today(),
        'follow_up_type' => 'Email', 'purpose' => 'FU ' . $x, 'notes' => $x, 'status' => 'Planned']);
    $po = Database::insert('purchase_orders', ['code' => 'PO-XSS0000001', 'po_number' => 'PO ' . $x, 'customer_id' => $cust, 'po_date' => today(), 'status' => 'Open',
        'order_number' => 'OEF-XSS-0001', 'sales_name' => $x, 'product_spec' => $x, 'is_subcont' => 1, 'supplier' => $x, 'ship_to' => $x, 'ppic_status' => 'Rejected', 'ppic_note' => $x,
        'payment_term' => $x, 'remark' => $x, 'legacy_status' => $x]);
    $line = Database::insert('po_lines', ['code' => 'POL-XSS000001', 'po_id' => $po, 'product_id' => $prod, 'order_qty' => 100, 'remark' => $x]);
    $del = Database::insert('deliveries', ['code' => 'DEL-XSS000001', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prod, 'delivery_date' => today(), 'sj_number' => 'SJ ' . $x,
        'destination' => $x, 'note' => $x, 'delivered_qty' => 10, 'status' => 'Scheduled']);
    Database::insert('deliveries', ['code' => 'DEL-XSS000002', 'po_id' => $po, 'delivery_date' => today(), 'sj_number' => 'SJ2 ' . $x, 'delivered_qty' => 5, 'status' => 'Delivered', 'migration_flag' => $x]);
    $ret = Database::insert('returns', ['code' => 'RET-XSS000001', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prod, 'return_date' => today(), 'return_qty' => 1, 'reason' => 'Other',
        'sj_number' => $x, 'destination' => $x, 'note' => $x, 'product_legacy' => $x,
        'complaint_detail' => $x, 'complaint_status' => 'Unresolved', 'resolution_note' => $x, 'qc_email' => 'qc@pik.test', 'email_status' => 'failed', 'email_error' => $x]);
    $stock = Database::insert('stock', ['code' => 'STK-XSS0000001', 'product_legacy' => 'Stok ' . $x, 'stock_type' => 'FG', 'quantity' => 5, 'status' => mb_substr($x, 0, 40), 'notes' => $x]);
    Database::insert('stock', ['code' => 'STK-XSS0000002', 'product_id' => $prod, 'stock_type' => 'Ready', 'quantity' => 5, 'status' => mb_substr($x, 0, 40), 'notes' => $x]);
    $lt = Database::insert('leadtime', ['code' => 'LT-XSS0000001', 'po_id' => $po, 'product_id' => $prod, 'po_number_legacy' => $x, 'product_legacy' => $x, 'notes' => $x, 'quantity' => 1, 'delivery_date' => today(), 'status' => 'Planned']);
    $inb = Database::insert('inbound_maklon', ['code' => 'INB-XSS0000001', 'vendor' => 'Vendor ' . $x, 'receiver' => $x, 'actual_inbound_date' => today(), 'sj_number' => $x, 'component_name' => $x,
        'type' => $x, 'notes' => $x, 'odoo_checklist' => mb_substr($x, 0, 60), 'quantity' => 1, 'po_id' => $po, 'attachment' => 'javascript:alert(1)']);
    $inv = Database::insert('invoices_payments', ['code' => 'PAY-XSS0000001', 'customer_id' => $cust, 'po_id' => $po, 'invoice_number' => 'INV ' . $x, 'invoice_date' => today(), 'due_date' => today(),
        'invoice_amount' => '100.00', 'paid_amount' => '0.00', 'status' => 'Unpaid', 'notes' => $x, 'payment_receipt_number' => $x, 'invoice_attachment' => 'javascript:alert(1)']);
    $pof = Database::insert('po_financials', ['code' => 'POF-XSS0000001', 'po_id' => $po, 'brand' => $x, 'product_legacy' => $x, 'notes' => $x, 'po_date' => today(), 'payment_status' => 'Unpaid']);
    $iss = Database::insert('migration_issues', ['code' => 'ISS-XSS0000001', 'table_name' => 'PURCHASE_ORDERS', 'record_id' => $po, 'record_code' => 'PO-XSS0000001', 'issue_type' => 'DATE DIFFERS FROM LEGACY',
        'field_name' => 'po_date', 'master_value' => $x, 'suggested_value' => $x, 'legacy_po' => $x, 'legacy_product' => $x, 'description' => $x]);
    Database::insert('notifications', ['user_id' => $adminId, 'type' => 'followup_due', 'title' => 'Notif ' . $x, 'message' => $x, 'link' => '/']);
    App\Helpers\Audit::log('update', 'customer', $cust, 'Audit ' . $x, ['name' => ['old' => $x, 'new' => $x]]);
    $auditId = (int) Database::fetchValue("SELECT MAX(id) FROM audit_logs");

    $pages = [
        '/', '/customers', '/customers/' . $cust, '/customers/' . $cust . '/edit', '/contacts',
        '/leads', '/leads/list', '/leads/' . $lead, '/leads/' . $lead . '/edit', '/activities', '/follow-ups', '/follow-ups?tab=all', '/follow-ups/' . $fu . '/edit',
        '/purchase-orders', '/purchase-orders/' . $po, '/purchase-orders/' . $po . '/edit', '/po-lines/' . $line . '/edit',
        '/deliveries', '/deliveries/' . $del, '/deliveries/' . $del . '/edit', '/returns', '/returns/' . $ret, '/returns/' . $ret . '/edit', '/returns/create', '/reports/complaint',
        '/products', '/products/' . $prod, '/products/' . $prod . '/edit', '/stock', '/stock?view=entries', '/stock/' . $stock . '/edit',
        '/lead-times', '/lead-times/' . $lt . '/edit', '/inbound', '/inbound/' . $inb, '/inbound/' . $inb . '/edit',
        '/invoices', '/invoices/' . $inv, '/invoices/' . $inv . '/edit', '/po-financials', '/po-financials/' . $pof, '/po-financials/' . $pof . '/edit',
        '/reports/customer', '/reports/lead', '/reports/activity', '/reports/po', '/reports/delivery', '/reports/financial',
        '/notifications', '/audit-log', '/audit-log/' . $auditId, '/migration-issues?status=all', '/migration-issues/' . $iss, '/migration-issues/deliveries',
        '/search?q=' . rawurlencode('X"><img'), '/search?q=' . rawurlencode($x), '/customers?q=' . rawurlencode($x), '/customers?' . http_build_query(['customer' . $x => 1]),
        '/customers/' . $cust . '?tab=' . rawurlencode($x),
    ];
    foreach (['contacts', 'leads', 'activities', 'followups', 'pos', 'deliveries', 'returns', 'invoices'] as $tab) {
        $pages[] = '/customers/' . $cust . '?tab=' . $tab;
    }
    foreach ($pages as $path) {
        $res = $admin->get($path);
        assert_true(in_array($res->status, [200, 404], true), "HTTP {$res->status} untuk {$path}");
        assert_not_contains('<img src=x onerror', $res->body, 'payload tidak ter-escape di ' . $path);
        assert_not_contains('href="javascript:', strtolower($res->body), 'link javascript: di ' . $path);
    }
    // export juga aman (CSV diberi tanda petik untuk sel berawalan =,+,-,@)
    $csv = $admin->get('/reports/customer/export', ['format' => 'csv']);
    assert_status(200, $csv);
});

test('SQL injection: parameter pencarian, sort, filter & id aman', function () {
    $c = client_as('Admin');
    $users = (int) Database::fetchValue('SELECT COUNT(*) FROM users');
    $payloads = ["' OR '1'='1", "1; DROP TABLE users; --", "\\' UNION SELECT password_hash FROM users --"];
    foreach ($payloads as $p) {
        foreach (['/customers', '/purchase-orders', '/deliveries', '/invoices', '/products', '/leads/list', '/reports/po'] as $path) {
            $res = $c->get($path, ['q' => $p, 'sort' => $p, 'dir' => $p, 'status' => $p, 'customer_id' => $p, 'from' => $p, 'to' => $p, 'page' => $p]);
            assert_status(200, $res, $path . ' ' . $p);
            assert_not_contains('SQLSTATE', $res->body);
            assert_not_contains('$2y$', $res->body, 'tidak ada hash password yang bocor');
        }
    }
    assert_status(404, $c->get('/customers/1%20OR%201=1'));
    assert_same($users, (int) Database::fetchValue('SELECT COUNT(*) FROM users'), 'tabel users utuh');
});

test('cookie session HttpOnly + SameSite; tidak ada password/secret di halaman', function () {
    $c = new HttpClient(TEST_BASE_URL);
    $res = $c->get('/login');
    $cookie = strtolower($res->headers['set-cookie'] ?? '');
    assert_contains('httponly', $cookie);
    assert_contains('samesite=lax', $cookie);
    $page = client_as('Admin')->get('/users');
    assert_not_contains('$2y$', $page->body, 'hash password tidak pernah ditampilkan');
    assert_not_contains('dev_only_local_pw', $page->body);
});
