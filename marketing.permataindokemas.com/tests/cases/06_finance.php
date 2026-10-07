<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Number;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PoFinancial;
use App\Models\Setting;

$finSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $c1 = Customer::create(['name' => 'PT Finance Uji', 'status' => 'Active']);
    $c2 = Customer::create(['name' => 'PT Customer Lain', 'status' => 'Active']);
    $net45 = Database::insert('purchase_orders', ['code' => 'PO-FIN0000001', 'po_number' => 'PO/FIN/045', 'customer_id' => $c1, 'po_date' => today(), 'status' => 'Open', 'payment_term' => 'NET 45']);
    $cbd = Database::insert('purchase_orders', ['code' => 'PO-FIN0000002', 'po_number' => 'PO/FIN/CBD', 'customer_id' => $c1, 'po_date' => today(), 'status' => 'Open', 'payment_term' => 'CBD']);
    $other = Database::insert('purchase_orders', ['code' => 'PO-FIN0000003', 'po_number' => 'PO/LAIN/001', 'customer_id' => $c2, 'po_date' => today(), 'status' => 'Open']);
    return $ids = ['c1' => $c1, 'c2' => $c2, 'net45' => $net45, 'cbd' => $cbd, 'other' => $other];
};

group('Phase 6 · Unit: status invoice & uang');

test('status invoice: Unpaid / Partial / Paid / Overdue', function () {
    $today = '2026-09-30';
    assert_same('Unpaid', Invoice::computeStatus('1000000.00', '0.00', '2026-10-30', $today));
    assert_same('Partial', Invoice::computeStatus('1000000.00', '400000.00', '2026-10-30', $today));
    assert_same('Paid', Invoice::computeStatus('1000000.00', '1000000.00', '2026-09-01', $today), 'lunas tidak pernah overdue');
    assert_same('Overdue', Invoice::computeStatus('1000000.00', '400000.00', '2026-09-29', $today));
    assert_same('Unpaid', Invoice::computeStatus('1000000.00', '0', '2026-09-30', $today), 'jatuh tempo hari ini belum overdue');
    assert_same('Unpaid', Invoice::computeStatus('1000000.00', '0', null, $today));
    assert_same('Paid', Invoice::computeStatus('0.30', '0.3', null, $today), 'perbandingan tepat sampai sen');
});

test('konversi uang ke sen tanpa error float', function () {
    assert_same(125000051, Number::toCents('1250000.505'));
    assert_same(10, Number::toCents('0.1'));
    assert_same(-550, Number::toCents('-5.5'));
    assert_same(30, Number::toCents(0.1 + 0.2));
    assert_same(null, Number::toCents('12abc'));
    assert_same('1250000.51', Number::fromCents(125000051));
    assert_same('-0.05', Number::fromCents(-5));
    $a = PoFinancial::computeAmounts(300000, '1846.85', 11.0);
    assert_same(['total_order_amount' => '554055000.00', 'ppn' => '60946050.00', 'total_incl_ppn' => '615001050.00'], $a);
    assert_same(null, PoFinancial::computeAmounts(100, null, 11.0));
});

group('Phase 6 · Invoice & Payment');

test('buat invoice: validasi, nomor unik, PO milik customer, jatuh tempo otomatis', function () use ($finSetup) {
    $ids = $finSetup();
    $c = client_as('Admin');
    assert_status(200, $c->get('/invoices/create', ['po_id' => $ids['net45']]));
    $bad = $c->post('/invoices', ['customer_id' => '', 'invoice_number' => '', 'invoice_date' => '', 'invoice_amount' => '0']);
    assert_status(422, $bad);
    assert_contains('Customer wajib diisi', $bad->body);
    assert_contains('Nomor invoice wajib diisi', $bad->body);
    assert_contains('Nilai invoice minimal 0.01', $bad->body);
    $wrongPo = $c->post('/invoices', ['customer_id' => (string) $ids['c1'], 'po_id' => (string) $ids['other'], 'invoice_number' => 'INV/FIN/001', 'invoice_date' => today(), 'invoice_amount' => '1000']);
    assert_contains('PO ini milik customer lain', $wrongPo->body);
    $badDue = $c->post('/invoices', ['customer_id' => (string) $ids['c1'], 'invoice_number' => 'INV/FIN/001', 'invoice_date' => today(), 'due_date' => date('Y-m-d', strtotime(today() . ' -1 day')), 'invoice_amount' => '1000']);
    assert_contains('Jatuh tempo tidak boleh sebelum Tanggal invoice', $badDue->body);
    // NET 45 dari PO
    $ok = $c->post('/invoices', ['customer_id' => (string) $ids['c1'], 'po_id' => (string) $ids['net45'], 'invoice_number' => 'INV/FIN/001', 'invoice_date' => today(), 'invoice_amount' => '12.500.000']);
    assert_redirect($ok, '/invoices/');
    $inv = Database::fetch("SELECT * FROM invoices_payments WHERE invoice_number = 'INV/FIN/001'");
    assert_same('12500000.00', $inv['invoice_amount']);
    assert_same(date('Y-m-d', strtotime(today() . ' +45 days')), $inv['due_date'], 'NET 45');
    assert_same('Unpaid', $inv['status']);
    // CBD → jatuh tempo hari yang sama; tanpa PO → default 30 hari
    $c->post('/invoices', ['customer_id' => (string) $ids['c1'], 'po_id' => (string) $ids['cbd'], 'invoice_number' => 'INV/FIN/002', 'invoice_date' => today(), 'invoice_amount' => '500000']);
    assert_same(today(), Database::fetchValue("SELECT due_date FROM invoices_payments WHERE invoice_number = 'INV/FIN/002'"));
    $c->post('/invoices', ['customer_id' => (string) $ids['c1'], 'invoice_number' => 'INV/FIN/003', 'invoice_date' => today(), 'invoice_amount' => '750000']);
    assert_same(date('Y-m-d', strtotime(today() . ' +30 days')), Database::fetchValue("SELECT due_date FROM invoices_payments WHERE invoice_number = 'INV/FIN/003'"));
    $dup = $c->post('/invoices', ['customer_id' => (string) $ids['c1'], 'invoice_number' => 'inv/fin/001', 'invoice_date' => today(), 'invoice_amount' => '1']);
    assert_status(422, $dup);
    assert_contains('Nomor invoice sudah dipakai', $dup->body);
});

test('pembayaran: sebagian → Partial, lebih bayar ditolak, lunas → Paid, riwayat tercatat', function () {
    $inv = Database::fetch("SELECT * FROM invoices_payments WHERE invoice_number = 'INV/FIN/001'");
    $c = client_as('Admin');
    $base = '/invoices/' . $inv['id'];
    $page = $c->get($base);
    assert_status(200, $page);
    assert_contains('Catat pembayaran', $page->body);
    $future = $c->post($base . '/payments', ['amount' => '1000', 'payment_date' => date('Y-m-d', strtotime(today() . ' +1 day'))]);
    assert_status(422, $future);
    assert_contains('tidak boleh di masa depan', $future->body);
    assert_redirect($c->post($base . '/payments', ['amount' => '5.000.000', 'payment_date' => today(), 'payment_receipt_number' => 'TRF-001', 'note' => 'DP']), $base);
    $after = Database::fetch('SELECT * FROM invoices_payments WHERE id = :id', ['id' => $inv['id']]);
    assert_same('5000000.00', $after['paid_amount']);
    assert_same('Partial', $after['status']);
    assert_same('TRF-001', $after['payment_receipt_number']);
    $over = $c->post($base . '/payments', ['amount' => '7500000.01', 'payment_date' => today()]);
    assert_redirect($over, $base);
    assert_contains('melebihi sisa tagihan', $c->get($base)->body);
    assert_same('5000000.00', Database::fetchValue('SELECT paid_amount FROM invoices_payments WHERE id = :id', ['id' => $inv['id']]), 'lebih bayar tidak tersimpan');
    assert_redirect($c->post($base . '/payments', ['amount' => '7500000', 'payment_date' => today(), 'payment_receipt_number' => 'TRF-002']), $base);
    $paid = Database::fetch('SELECT * FROM invoices_payments WHERE id = :id', ['id' => $inv['id']]);
    assert_same('Paid', $paid['status']);
    assert_same('12500000.00', $paid['paid_amount']);
    $final = $c->get($base);
    assert_contains('sudah lunas', $final->body);
    assert_not_contains('Catat pembayaran', $final->body);
    assert_contains('TRF-002', $final->body);
    assert_same(2, count(Invoice::paymentHistory((int) $inv['id'])));
    $again = $c->post($base . '/payments', ['amount' => '1', 'payment_date' => today()]);
    assert_redirect($again, $base);
    assert_contains('sudah lunas', $c->get($base)->body);
});

test('jatuh tempo lewat → Overdue otomatis; filter & ringkasan', function () use ($finSetup) {
    $ids = $finSetup();
    $past = date('Y-m-d', strtotime(today() . ' -10 days'));
    $id = Database::insert('invoices_payments', ['code' => 'PAY-FIN0000001', 'customer_id' => $ids['c1'], 'invoice_number' => 'INV/FIN/LAMA', 'invoice_date' => date('Y-m-d', strtotime(today() . ' -40 days')),
        'due_date' => $past, 'invoice_amount' => '2000000.00', 'paid_amount' => '500000.00', 'status' => 'Partial']);
    $c = client_as('Admin');
    $list = $c->get('/invoices', ['status' => 'Overdue']);
    assert_status(200, $list);
    assert_contains('INV/FIN/LAMA', $list->body);
    assert_same('Overdue', Database::fetchValue('SELECT status FROM invoices_payments WHERE id = :id', ['id' => $id]));
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'auto_status' AND entity_type = 'invoice' AND entity_id = :id", ['id' => $id]));
    assert_contains('Rp 1.500.000', $list->body, 'sisa overdue');
    $open = $c->get('/invoices', ['status' => 'open']);
    assert_contains('INV/FIN/002', $open->body);
    assert_not_contains('INV/FIN/001', $open->body, 'yang lunas tidak tampil');
});

test('edit invoice: nilai < dibayar ditolak, koreksi dibayar, hapus hanya bila belum dibayar', function () use ($finSetup) {
    $ids = $finSetup();
    $c = client_as('Admin');
    $inv = Database::fetch("SELECT * FROM invoices_payments WHERE invoice_number = 'INV/FIN/LAMA'");
    $base = '/invoices/' . $inv['id'];
    assert_status(200, $c->get($base . '/edit'));
    $form = ['customer_id' => (string) $ids['c1'], 'invoice_number' => 'INV/FIN/LAMA', 'invoice_date' => $inv['invoice_date'], 'due_date' => $inv['due_date'], 'invoice_amount' => '400000', 'paid_amount' => '500.000'];
    $bad = $c->post($base, $form);
    assert_status(422, $bad);
    assert_contains('Total dibayar tidak boleh melebihi nilai invoice', $bad->body);
    $form['invoice_amount'] = '2.000.000';
    $form['paid_amount'] = '2.000.000';
    assert_redirect($c->post($base, $form), $base);
    assert_same('Paid', Database::fetchValue('SELECT status FROM invoices_payments WHERE id = :id', ['id' => $inv['id']]), 'koreksi → lunas');
    assert_redirect($c->post($base . '/delete'), $base);
    assert_contains('sudah memiliki pembayaran', $c->get($base)->body);
    $unpaid = (int) Database::fetchValue("SELECT id FROM invoices_payments WHERE invoice_number = 'INV/FIN/003'");
    Database::insert('migration_issues', ['code' => 'ISS-FIN0000001', 'table_name' => 'INVOICES_PAYMENTS', 'record_id' => $unpaid, 'issue_type' => 'DUE DATE MISSING', 'description' => 'uji']);
    assert_redirect($c->post('/invoices/' . $unpaid . '/delete'), '/invoices');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM invoices_payments WHERE id = :id', ['id' => $unpaid]));
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-FIN0000001'"));
});

test('invoice legacy: mengisi jatuh tempo & PO menyelesaikan issue', function () use ($finSetup) {
    $ids = $finSetup();
    $id = Database::insert('invoices_payments', ['code' => 'PAY-FIN0000002', 'customer_id' => $ids['c1'], 'invoice_number' => 'INV/LEGACY/9', 'invoice_date' => today(), 'invoice_amount' => '100.00', 'paid_amount' => '0.00', 'status' => 'Unpaid', 'po_number_legacy' => 'PO/FIN/045']);
    Database::insert('migration_issues', ['code' => 'ISS-FIN0000002', 'table_name' => 'INVOICES_PAYMENTS', 'record_id' => $id, 'issue_type' => 'DUE DATE MISSING', 'description' => 'uji']);
    Database::insert('migration_issues', ['code' => 'ISS-FIN0000003', 'table_name' => 'INVOICES_PAYMENTS', 'record_id' => $id, 'issue_type' => 'PO NOT FOUND', 'description' => 'uji']);
    $c = client_as('Admin');
    assert_redirect($c->post('/invoices/' . $id, ['customer_id' => (string) $ids['c1'], 'po_id' => (string) $ids['net45'], 'invoice_number' => 'INV/LEGACY/9', 'invoice_date' => today(), 'due_date' => '', 'invoice_amount' => '100', 'paid_amount' => '0']), '/invoices/' . $id);
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-FIN0000002'"));
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-FIN0000003'"));
    assert_same(date('Y-m-d', strtotime(today() . ' +45 days')), Database::fetchValue('SELECT due_date FROM invoices_payments WHERE id = :id', ['id' => $id]));
});

test('otorisasi finance: hanya Admin (PRD), halaman customer menyembunyikan piutang', function () use ($finSetup) {
    $ids = $finSetup();
    $inv = (int) Database::fetchValue("SELECT id FROM invoices_payments WHERE invoice_number = 'INV/FIN/002'");
    foreach (['Marketing', 'Sales', 'Management', 'Viewer'] as $role) {
        $c = client_as($role);
        assert_status(403, $c->get('/invoices'), $role . ' /invoices');
        assert_status(403, $c->get('/po-financials'), $role . ' /po-financials');
        assert_status(403, $c->post('/invoices/' . $inv . '/payments', ['amount' => '1', 'payment_date' => today()]), $role . ' bayar');
    }
    $page = client_as('Marketing')->get('/customers/' . $ids['c1']);
    assert_status(200, $page);
    assert_not_contains('Piutang', $page->body);
    assert_contains('Piutang', client_as('Admin')->get('/customers/' . $ids['c1'])->body);
});

group('Phase 6 · PO Financials');

test('nilai PO: total & PPN dihitung dari qty × harga (tarif dari pengaturan)', function () use ($finSetup) {
    $ids = $finSetup();
    $c = client_as('Admin');
    assert_status(200, $c->get('/po-financials/create', ['po_id' => $ids['net45']]));
    $bad = $c->post('/po-financials', ['po_id' => '', 'po_date' => '', 'product_legacy' => '', 'payment_status' => 'Lunas']);
    assert_status(422, $bad);
    assert_contains('Status pembayaran tidak valid', $bad->body);
    $bad2 = $c->post('/po-financials', ['po_id' => '', 'po_date' => '', 'product_legacy' => '', 'payment_status' => 'Unpaid']);
    assert_contains('Isi tanggal PO atau pilih PO', $bad2->body);
    assert_contains('Isi produk / deskripsi atau pilih PO', $bad2->body);
    $ok = $c->post('/po-financials', ['po_id' => (string) $ids['net45'], 'product_legacy' => 'Botol 100ml', 'brand' => 'Brand Uji', 'order_qty' => '300.000', 'unit_price' => '1.846,85', 'payment_status' => 'Unpaid']);
    assert_redirect($ok, '/po-financials/');
    $row = Database::fetch("SELECT * FROM po_financials WHERE brand = 'Brand Uji'");
    assert_same('554055000.00', $row['total_order_amount']);
    assert_same('60946050.00', $row['ppn']);
    assert_same('615001050.00', $row['total_incl_ppn']);
    assert_same(today(), $row['po_date'], 'tanggal PO diambil dari PO');
    Setting::set('ppn_rate', '12', false);
    $c->post('/po-financials/' . $row['id'], ['po_id' => (string) $ids['net45'], 'product_legacy' => 'Botol 100ml', 'brand' => 'Brand Uji', 'order_qty' => '100', 'unit_price' => '1000', 'payment_status' => 'Paid']);
    $row2 = Database::fetch('SELECT * FROM po_financials WHERE id = :id', ['id' => $row['id']]);
    assert_same('100000.00', $row2['total_order_amount']);
    assert_same('12000.00', $row2['ppn'], 'tarif PPN mengikuti pengaturan');
    assert_same('Paid', $row2['payment_status']);
    Setting::set('ppn_rate', '11', false);
    $show = $c->get('/po-financials/' . $row['id']);
    assert_status(200, $show);
    assert_contains('Rp 112.000', $show->body);
});

test('PO financial legacy: harga kosong tidak menghapus total; hubungkan PO menyelesaikan issue; total beda ditandai', function () use ($finSetup) {
    $ids = $finSetup();
    $id = Database::insert('po_financials', ['code' => 'POF-FIN0000001', 'po_number_legacy' => 'PO/FIN/045', 'po_date' => today(), 'product_legacy' => 'Jar legacy', 'order_qty' => 1000,
        'unit_price' => null, 'total_order_amount' => '5000000.00', 'ppn' => '550000.00', 'total_incl_ppn' => '5550000.00', 'payment_status' => 'Unpaid']);
    Database::insert('migration_issues', ['code' => 'ISS-FIN0000004', 'table_name' => 'PO_FINANCIALS', 'record_id' => $id, 'issue_type' => 'PO NOT FOUND', 'description' => 'uji']);
    $c = client_as('Admin');
    assert_redirect($c->post('/po-financials/' . $id, ['po_id' => (string) $ids['net45'], 'po_date' => today(), 'product_legacy' => 'Jar legacy', 'order_qty' => '1000', 'unit_price' => '', 'payment_status' => 'Unpaid']), '/po-financials/' . $id);
    $row = Database::fetch('SELECT * FROM po_financials WHERE id = :id', ['id' => $id]);
    assert_same('5550000.00', $row['total_incl_ppn'], 'total legacy dipertahankan');
    assert_same($ids['net45'], (int) $row['po_id']);
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-FIN0000004'"));
    Database::update('po_financials', ['unit_price' => '5.000000'], 'id = :id', ['id' => $id]);
    assert_contains('berbeda dengan Qty × Harga satuan', $c->get('/po-financials/' . $id)->body);
    $list = $c->get('/po-financials', ['q' => 'Jar legacy']);
    assert_contains('Jar legacy', $list->body);
    assert_status(302, $c->post('/po-financials/' . $id . '/delete'));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM po_financials WHERE id = :id', ['id' => $id]));
});
