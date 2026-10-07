<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;

group('Phase 2 · Customers');

test('validasi form customer', function () {
    $c = client_as('Admin');
    assert_status(200, $c->get('/customers/create'));
    $res = $c->post('/customers', ['name' => '', 'email' => 'bukan-email', 'status' => 'Aktif', 'marketing_pic_id' => '999999', 'phone' => 'abc<>']);
    assert_status(422, $res);
    assert_contains('Nama customer wajib diisi', $res->body);
    assert_contains('Email harus berupa alamat email yang valid', $res->body);
    assert_contains('Status tidak valid', $res->body);
    assert_contains('PIC Marketing tidak ditemukan', $res->body);
    assert_contains('Telepon hanya boleh berisi', $res->body);
});

test('create customer + audit log + redirect ke detail', function () {
    $c = client_as('Admin');
    $pic = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'admin.qa@pik.test'");
    $res = $c->post('/customers', [
        'name' => 'PT. Maju Jaya Kosmetik', 'company' => 'PT Maju Jaya Kosmetik', 'pic' => 'Ibu Rina', 'phone' => '0812-3456-7890',
        'email' => 'Purchasing@MajuJaya.co.id', 'address' => 'Jl. Industri 1, Tangerang', 'industry' => 'Skincare', 'status' => 'Active',
        'source' => 'Referral', 'marketing_pic_id' => (string) $pic, 'notes' => 'Customer baru',
    ]);
    assert_redirect($res, '/customers/');
    $row = Database::fetch("SELECT * FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    assert_true($row !== null);
    assert_same('purchasing@majujaya.co.id', $row['email'], 'email dinormalisasi lowercase');
    assert_true((bool) preg_match('/^CUS-[0-9A-F]{10}$/', (string) $row['code']), 'kode format CUS-XXXXXXXXXX');
    assert_same($pic, (int) $row['created_by']);
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE entity_type = 'customer' AND action = 'create' AND entity_id = :id", ['id' => $row['id']]));
    $show = $c->get('/customers/' . $row['id']);
    assert_status(200, $show);
    assert_contains('PT. Maju Jaya Kosmetik', $show->body);
    assert_contains('Informasi customer', $show->body);
});

test('deteksi duplikat nama (PT. vs PT, huruf besar/kecil) dengan konfirmasi', function () {
    $c = client_as('Marketing');
    $res = $c->post('/customers', ['name' => 'pt maju jaya kosmetik', 'status' => 'Active']);
    assert_status(422, $res);
    assert_contains('Kemungkinan duplikat', $res->body);
    assert_contains('PT. Maju Jaya Kosmetik', $res->body);
    $ok = $c->post('/customers', ['name' => 'pt maju jaya kosmetik', 'status' => 'Potential', 'confirm_not_duplicate' => '1']);
    assert_redirect($ok, '/customers/');
    assert_same(2, (int) Database::fetchValue("SELECT COUNT(*) FROM customers WHERE name LIKE '%maju jaya kosmetik%'"));
});

test('output di-escape (XSS)', function () {
    $c = client_as('Sales');
    $res = $c->post('/customers', ['name' => '<script>alert(1)</script> Toko XSS', 'status' => 'Active', 'notes' => '"><img src=x onerror=alert(2)>']);
    assert_redirect($res, '/customers/');
    $id = (int) Database::fetchValue("SELECT id FROM customers WHERE name LIKE '%Toko XSS'");
    $show = $c->get('/customers/' . $id);
    assert_not_contains('<script>alert(1)</script>', $show->body);
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $show->body);
    assert_not_contains('<img src=x onerror', $show->body);
    $list = $c->get('/customers', ['q' => 'Toko XSS']);
    assert_not_contains('<script>alert(1)</script>', $list->body);
});

test('list: search, filter, sort, pagination', function () {
    for ($i = 1; $i <= 27; $i++) {
        Customer::create(['name' => sprintf('Batch Customer %02d', $i), 'status' => $i % 3 === 0 ? 'Dormant' : 'Active']);
    }
    $c = client_as('Viewer');
    $page1 = $c->get('/customers', ['q' => 'Batch Customer', 'sort' => 'name', 'dir' => 'asc']);
    assert_status(200, $page1);
    assert_contains('Batch Customer 01', $page1->body);
    assert_not_contains('Batch Customer 27', $page1->body);
    assert_contains('dari 27 customer', $page1->body);
    $page2 = $c->get('/customers', ['q' => 'Batch Customer', 'sort' => 'name', 'dir' => 'asc', 'page' => 2]);
    assert_contains('Batch Customer 27', $page2->body);
    $desc = $c->get('/customers', ['q' => 'Batch Customer', 'sort' => 'name', 'dir' => 'desc']);
    assert_true(strpos($desc->body, 'Batch Customer 27') < strpos($desc->body, 'Batch Customer 20'), 'urutan desc');
    $dormant = $c->get('/customers', ['q' => 'Batch Customer', 'status' => 'Dormant']);
    assert_contains('dari 9 customer', $dormant->body);
    // input sort yang tidak valid / injeksi tidak menyebabkan error
    assert_status(200, $c->get('/customers', ['sort' => 'name; DROP TABLE customers', 'dir' => 'sideways', 'q' => "' OR 1=1 --"]));
    assert_true((int) Database::fetchValue('SELECT COUNT(*) FROM customers') > 27);
});

test('edit customer mencatat perubahan di audit log', function () {
    $c = client_as('Management');
    $id = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    assert_status(200, $c->get('/customers/' . $id . '/edit'));
    $res = $c->post('/customers/' . $id, ['name' => 'PT. Maju Jaya Kosmetik', 'status' => 'Inactive', 'industry' => 'Kosmetik', 'phone' => '0812-3456-7890']);
    assert_redirect($res, '/customers/' . $id);
    assert_same('Inactive', Database::fetchValue('SELECT status FROM customers WHERE id = :id', ['id' => $id]));
    $changes = (string) Database::fetchValue("SELECT changes FROM audit_logs WHERE entity_type = 'customer' AND action = 'update' AND entity_id = :id ORDER BY id DESC LIMIT 1", ['id' => $id]);
    assert_contains('"status":{"old":"Active","new":"Inactive"}', $changes);
});

test('otorisasi backend customer per role', function () {
    $viewer = client_as('Viewer');
    assert_status(200, $viewer->get('/customers'));
    assert_status(403, $viewer->get('/customers/create'));
    assert_status(403, $viewer->post('/customers', ['name' => 'Tidak boleh', 'status' => 'Active']));
    $id = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    assert_status(403, $viewer->post('/customers/' . $id, ['name' => 'Hack', 'status' => 'Active']));
    assert_status(403, $viewer->post('/customers/' . $id . '/delete'));
    assert_false((bool) Database::fetchValue("SELECT 1 FROM customers WHERE name IN ('Tidak boleh','Hack')"));
    $show = $viewer->get('/customers/' . $id);
    assert_not_contains('/customers/' . $id . '/edit', $show->body, 'tombol edit disembunyikan untuk Viewer');
});

test('tab detail customer mengikuti hak akses role', function () {
    $id = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    $admin = client_as('Admin');
    foreach (['overview', 'contacts', 'leads', 'activities', 'followups', 'pos', 'deliveries', 'returns'] as $tab) {
        assert_status(200, $admin->get('/customers/' . $id, ['tab' => $tab]), "tab {$tab}");
    }
    assert_not_contains('tab=invoices', $admin->get('/customers/' . $id)->body, 'tab Invoice dihapus (menu Finance)');
    $sales = client_as('Sales')->get('/customers/' . $id);
    assert_contains('tab=leads', $sales->body);
    assert_contains('tab=pos', $sales->body, 'Sales menginput OEF');
    assert_not_contains('tab=invoices', $sales->body);
    $mgmt = client_as('Management')->get('/customers/' . $id);
    assert_contains('tab=pos', $mgmt->body);
    assert_not_contains('tab=leads', $mgmt->body, 'Management tidak punya akses Leads');
    // tab yang tidak diizinkan jatuh ke overview (tidak membocorkan data)
    $forced = client_as('Viewer')->get('/customers/' . $id, ['tab' => 'invoices']);
    assert_status(200, $forced);
    assert_not_contains('Invoice &amp; pembayaran', $forced->body);
    assert_status(403, client_as('PPIC')->get('/customers/' . $id), 'PPIC tidak membuka menu Customers');
});

test('ringkasan customer menghitung outstanding dari data PO aktual', function () {
    $cid = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    $prd = Database::insert('products', ['code' => 'PRD-TEST000001', 'name' => 'Botol Uji 100ml', 'unit' => 'pcs']);
    $po = Database::insert('purchase_orders', ['code' => 'PO-TEST000001', 'po_number' => 'PO/UJI/001', 'customer_id' => $cid, 'po_date' => today(), 'status' => 'On Process']);
    $line = Database::insert('po_lines', ['code' => 'POL-TEST00001', 'po_id' => $po, 'product_id' => $prd, 'order_qty' => 1000]);
    Database::insert('deliveries', ['code' => 'DEL-TEST00001', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prd, 'delivery_date' => today(), 'delivered_qty' => 600, 'status' => 'Delivered']);
    Database::insert('deliveries', ['code' => 'DEL-TEST00002', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prd, 'delivery_date' => today(), 'delivered_qty' => 100, 'status' => 'Scheduled']);
    Database::insert('returns', ['code' => 'RET-TEST00001', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prd, 'return_date' => today(), 'return_qty' => 50, 'reason' => 'Damage']);
    $summary = Customer::summary($cid, today());
    assert_same(1000, $summary['order_qty']);
    assert_same(600, $summary['delivered_qty'], 'hanya status Delivered/Partial dihitung');
    assert_same(450, $summary['outstanding_qty'], '1000 - 600 + 50');
    assert_same(1, $summary['po_open']);
    $page = client_as('Admin')->get('/customers/' . $cid, ['tab' => 'pos']);
    assert_contains('PO/UJI/001', $page->body);
    assert_contains('>450<', $page->body);
});

test('hapus customer: diblokir bila ada PO, boleh bila hanya kontak (kontak ikut terhapus)', function () {
    $c = client_as('Admin');
    $withPo = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    $res = $c->post('/customers/' . $withPo . '/delete');
    assert_redirect($res, '/customers/' . $withPo);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM customers WHERE id = :id', ['id' => $withPo]), 'tidak terhapus');
    $flash = $c->get('/customers/' . $withPo);
    assert_contains('tidak dapat dihapus karena masih memiliki 1 PO', $flash->body);

    $simple = Customer::create(['name' => 'Customer Sementara', 'status' => 'Potential']);
    Database::insert('contacts', ['code' => 'CON-TEST00001', 'customer_id' => $simple, 'name' => 'Kontak Sementara']);
    assert_redirect($c->post('/customers/' . $simple . '/delete'), '/customers');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM customers WHERE id = :id', ['id' => $simple]));
    assert_false((bool) Database::fetchValue("SELECT 1 FROM contacts WHERE code = 'CON-TEST00001'"), 'kontak ikut terhapus');
});

group('Phase 2 · Contacts');

test('CRUD kontak + validasi + satu kontak utama per customer', function () {
    $c = client_as('Sales');
    $cid = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    assert_status(200, $c->get('/contacts/create', ['customer_id' => $cid]));
    $bad = $c->post('/contacts', ['customer_id' => '999999', 'name' => '', 'email' => 'x@', 'status' => 'Active']);
    assert_status(422, $bad);
    assert_contains('Customer tidak ditemukan', $bad->body);
    assert_contains('Nama wajib diisi', $bad->body);

    $r1 = $c->post('/contacts', ['customer_id' => (string) $cid, 'name' => 'Ibu Rina', 'position' => 'Purchasing', 'phone' => '0812 1111 2222', 'status' => 'Active', 'is_primary' => '1', 'return' => '/customers/' . $cid . '?tab=contacts']);
    assert_redirect($r1, '/customers/' . $cid . '?tab=contacts');
    $r2 = $c->post('/contacts', ['customer_id' => (string) $cid, 'name' => 'Pak Budi', 'whatsapp' => '0813-3333-4444', 'status' => 'Active', 'is_primary' => '1']);
    assert_redirect($r2, '/customers/' . $cid);
    $primaries = Database::fetchColumn('SELECT name FROM contacts WHERE customer_id = :c AND is_primary = 1', ['c' => $cid]);
    assert_same(['Pak Budi'], $primaries, 'hanya satu kontak utama');

    $tab = $c->get('/customers/' . $cid, ['tab' => 'contacts']);
    assert_contains('Ibu Rina', $tab->body);
    assert_contains('https://wa.me/6281333334444', $tab->body, 'link WhatsApp diformat 62…');

    $rinaId = (int) Database::fetchValue("SELECT id FROM contacts WHERE name = 'Ibu Rina'");
    assert_status(200, $c->get('/contacts/' . $rinaId . '/edit'));
    assert_redirect($c->post('/contacts/' . $rinaId, ['customer_id' => (string) $cid, 'name' => 'Ibu Rina Wati', 'status' => 'Inactive', 'is_primary' => '0']), '/customers/' . $cid);
    assert_same('Inactive', Database::fetchValue('SELECT status FROM contacts WHERE id = :id', ['id' => $rinaId]));

    $list = $c->get('/contacts', ['q' => 'Rina']);
    assert_contains('Ibu Rina Wati', $list->body);
    assert_redirect($c->post('/contacts/' . $rinaId . '/delete'), '/customers/' . $cid);
    assert_false((bool) Database::fetchValue('SELECT 1 FROM contacts WHERE id = :id', ['id' => $rinaId]));
});

test('Viewer hanya bisa melihat kontak', function () {
    $v = client_as('Viewer');
    assert_status(200, $v->get('/contacts'));
    assert_status(403, $v->get('/contacts/create'));
    $cid = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT. Maju Jaya Kosmetik'");
    assert_status(403, $v->post('/contacts', ['customer_id' => (string) $cid, 'name' => 'X', 'status' => 'Active']));
});

group('Phase 2 · Pencarian global');

test('search menemukan customer, kontak, dan PO sesuai hak akses', function () {
    $admin = client_as('Admin');
    $res = $admin->get('/search', ['q' => 'Maju Jaya']);
    assert_status(200, $res);
    assert_contains('Customers', $res->body);
    assert_contains('PT. Maju Jaya Kosmetik', $res->body);
    $po = $admin->get('/search', ['q' => 'PO/UJI']);
    assert_contains('Order Entry Form', $po->body);
    assert_contains('PO/UJI/001', $po->body);
    $gudang = client_as('Gudang')->get('/search', ['q' => 'PO/UJI']);
    assert_status(200, $gudang);
    assert_not_contains('PO/UJI/001', $gudang->body, 'Gudang tidak melihat order');
    assert_status(200, $admin->get('/search', ['q' => "%' UNION SELECT password_hash FROM users -- "]));
    assert_status(200, $admin->get('/search', ['q' => 'x']));
});
