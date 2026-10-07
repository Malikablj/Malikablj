<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;

$repSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $pic = create_user('Marketing', 'pic.report@pik.test');
    $cust = Customer::create(['name' => 'PT Laporan Uji', 'status' => 'Active', 'marketing_pic_id' => $pic]);
    $evil = Customer::create(['name' => '=HYPERLINK("http://x")', 'status' => 'Active']);
    $prod = Database::insert('products', ['code' => 'PRD-REP0000001', 'name' => 'Botol Laporan', 'unit' => 'pcs']);
    // PO dalam periode Maret 2026 (dua baris) dan satu PO di luar periode
    $po1 = Database::insert('purchase_orders', ['code' => 'PO-REP0000001', 'po_number' => 'PO/REP/001', 'customer_id' => $cust, 'po_date' => '2026-03-05', 'status' => 'Open']);
    $l1 = Database::insert('po_lines', ['code' => 'POL-REP000001', 'po_id' => $po1, 'product_id' => $prod, 'order_qty' => 1000]);
    $l2 = Database::insert('po_lines', ['code' => 'POL-REP000002', 'po_id' => $po1, 'product_id' => $prod, 'order_qty' => 500]);
    Database::insert('deliveries', ['code' => 'DEL-REP000001', 'po_id' => $po1, 'po_line_id' => $l1, 'product_id' => $prod, 'delivery_date' => '2026-03-20', 'delivered_qty' => 600, 'status' => 'Delivered']);
    Database::insert('deliveries', ['code' => 'DEL-REP000002', 'po_id' => $po1, 'po_line_id' => $l2, 'product_id' => $prod, 'delivery_date' => '2026-03-25', 'delivered_qty' => 200, 'status' => 'Scheduled']);
    $po2 = Database::insert('purchase_orders', ['code' => 'PO-REP0000002', 'po_number' => 'PO/REP/LUAR', 'customer_id' => $cust, 'po_date' => '2026-01-10', 'status' => 'Closed']);
    Database::insert('po_lines', ['code' => 'POL-REP000003', 'po_id' => $po2, 'product_id' => $prod, 'order_qty' => 300]);
    $po3 = Database::insert('purchase_orders', ['code' => 'PO-REP0000003', 'po_number' => 'PO/REP/EVIL', 'customer_id' => $evil, 'po_date' => '2026-03-07', 'status' => 'Open']);
    Database::insert('po_lines', ['code' => 'POL-REP000004', 'po_id' => $po3, 'product_id' => $prod, 'order_qty' => 50]);
    // Lead: 1 won, 1 lost, 1 berjalan (untuk win rate)
    foreach ([['Won', 1000000], ['Lost', 500000], ['Qualified', 2500000]] as $i => [$status, $value]) {
        Database::insert('leads', ['code' => 'LEAD-REP00000' . $i, 'customer_id' => $cust, 'lead_name' => 'Lead Laporan ' . $status, 'status' => $status,
            'potential_value' => $value, 'pic_user_id' => $pic, 'source' => 'Exhibition', 'created_at' => '2026-03-10 09:00:00']);
    }
    // Invoice: 1 lunas, 1 sebagian lewat jatuh tempo 45 hari
    $past = date('Y-m-d', strtotime(today() . ' -45 days'));
    Database::insert('invoices_payments', ['code' => 'PAY-REP0000001', 'customer_id' => $cust, 'po_id' => $po1, 'invoice_number' => 'INV/REP/001', 'invoice_date' => '2026-03-21',
        'due_date' => '2026-04-20', 'invoice_amount' => '1000000.00', 'paid_amount' => '1000000.00', 'status' => 'Paid']);
    Database::insert('invoices_payments', ['code' => 'PAY-REP0000002', 'customer_id' => $cust, 'po_id' => $po1, 'invoice_number' => 'INV/REP/002', 'invoice_date' => '2026-03-22',
        'due_date' => $past, 'invoice_amount' => '2500000.50', 'paid_amount' => '500000.00', 'status' => 'Partial']);
    return $ids = ['pic' => $pic, 'customer' => $cust, 'evil' => $evil, 'po1' => $po1];
};

group('Phase 7 · Dashboard');

test('dashboard menampilkan daftar sesuai role (data aktual, otorisasi backend)', function () use ($repSetup) {
    $ids = $repSetup();
    $fu = Database::insert('follow_up', ['code' => 'FUP-REP0000001', 'customer_id' => $ids['customer'], 'follow_up_date' => today(), 'follow_up_type' => 'Phone Call',
        'purpose' => 'Telepon konfirmasi laporan', 'status' => 'Planned']);
    Database::insert('follow_up', ['code' => 'FUP-REP0000002', 'customer_id' => $ids['customer'], 'follow_up_date' => date('Y-m-d', strtotime(today() . ' -3 days')),
        'follow_up_type' => 'Email', 'purpose' => 'Kirim katalog terlewat', 'status' => 'Planned']);
    Database::insert('activities', ['code' => 'ACT-REP0000001', 'customer_id' => $ids['customer'], 'activity_date' => today() . ' 09:00:00', 'activity_type' => 'Visit', 'subject' => 'Kunjungan laporan uji']);
    Database::insert('deliveries', ['code' => 'DEL-REP000003', 'po_id' => $ids['po1'], 'delivery_date' => date('Y-m-d', strtotime(today() . ' +2 days')), 'sj_number' => 'SJ-REP-NEXT', 'delivered_qty' => 10, 'status' => 'Scheduled']);

    // "Order terbaru" = 6 PO dengan tanggal terbaru (bergantung data test lain, jadi dibaca dari database)
    $latestPo = (string) Database::fetchValue('SELECT COALESCE(po_number, code) FROM purchase_orders ORDER BY po_date IS NULL, po_date DESC, id DESC LIMIT 1');
    $admin = client_as('Admin')->get('/');
    assert_status(200, $admin);
    foreach (['Telepon konfirmasi laporan', 'Kirim katalog terlewat', 'Kunjungan laporan uji', $latestPo, 'SJ-REP-NEXT', 'Piutang belum dibayar'] as $text) {
        assert_contains($text, $admin->body, 'Admin: ' . $text);
    }
    $sales = client_as('Sales')->get('/');
    assert_contains('Telepon konfirmasi laporan', $sales->body);
    assert_contains('Kunjungan laporan uji', $sales->body);
    assert_not_contains('Order terbaru', $sales->body, 'Sales tidak punya akses PO');
    assert_not_contains('SJ-REP-NEXT', $sales->body);
    assert_not_contains('Piutang', $sales->body);
    $mgmt = client_as('Management')->get('/');
    assert_contains('Order terbaru', $mgmt->body);
    assert_contains('SJ-REP-NEXT', $mgmt->body);
    assert_not_contains('Telepon konfirmasi laporan', $mgmt->body, 'Management tidak punya modul Follow Up');
    assert_not_contains('Piutang belum dibayar', $mgmt->body);
    // status follow up yang lewat tanggal ikut disinkronkan menjadi Overdue
    assert_same('Overdue', Database::fetchValue("SELECT status FROM follow_up WHERE code = 'FUP-REP0000002'"));
    assert_same('Planned', Database::fetchValue('SELECT status FROM follow_up WHERE id = :id', ['id' => $fu]));
});

group('Phase 7 · Reports');

test('akses laporan per role (PRD): Management semua + export, Viewer tanpa financial & export', function () use ($repSetup) {
    $repSetup();
    foreach (['Marketing', 'Sales'] as $role) {
        $c = client_as($role);
        assert_status(403, $c->get('/reports'), $role);
        assert_status(403, $c->get('/reports/po'), $role);
    }
    $m = client_as('Management');
    assert_status(200, $m->get('/reports'));
    foreach (['customer', 'lead', 'activity', 'po', 'delivery', 'complaint', 'financial'] as $type) {
        assert_status(200, $m->get('/reports/' . $type), 'Management ' . $type);
    }
    $v = client_as('Viewer');
    $index = $v->get('/reports');
    assert_status(200, $index);
    assert_not_contains('Laporan Financial', $index->body);
    assert_status(200, $v->get('/reports/po'));
    assert_status(200, $v->get('/reports/complaint'), 'Viewer boleh melihat laporan complaint');
    assert_status(403, $v->get('/reports/financial'));
    assert_status(403, $v->get('/reports/po/export', ['format' => 'csv']), 'Viewer tidak boleh export');
    assert_not_contains('/reports/po/export', $v->get('/reports/po')->body, 'tombol export disembunyikan');
    assert_status(404, $m->get('/reports/tidak-ada'));
    assert_status(400, $m->get('/reports/po/export', ['format' => 'pdf']));
});

test('laporan PO: angka sesuai database, filter periode/customer/PIC', function () use ($repSetup) {
    $ids = $repSetup();
    $c = client_as('Management');
    $res = $c->get('/reports/po', ['from' => '2026-03-01', 'to' => '2026-03-31', 'customer_id' => $ids['customer']]);
    assert_status(200, $res);
    assert_contains('PO/REP/001', $res->body);
    assert_not_contains('PO/REP/LUAR', $res->body, 'PO di luar periode tidak ikut');
    assert_not_contains('PO/REP/EVIL', $res->body, 'customer lain tidak ikut');
    assert_contains('1.500', $res->body, 'qty order 1000 + 500');
    assert_contains('600', $res->body, 'terkirim hanya status Delivered');
    assert_contains('900', $res->body, 'outstanding 1500 − 600');
    assert_contains('40,0% dari order', $res->body);
    $byPic = $c->get('/reports/po', ['pic' => $ids['pic']]);
    assert_contains('PO/REP/001', $byPic->body);
    assert_contains('PO/REP/LUAR', $byPic->body);
    assert_not_contains('PO/REP/EVIL', $byPic->body, 'PIC = PIC marketing customer');
});

test('laporan lead (win rate), delivery, financial (umur piutang), customer', function () use ($repSetup) {
    $ids = $repSetup();
    $c = client_as('Management');
    $lead = $c->get('/reports/lead', ['from' => '2026-03-01', 'to' => '2026-03-31']);
    assert_contains('50,0%', $lead->body, 'win rate = 1 won / (1 won + 1 lost)');
    assert_contains('Rp 2.500.000', $lead->body, 'pipeline lead berjalan');
    $del = $c->get('/reports/delivery', ['from' => '2026-03-01', 'to' => '2026-03-31', 'customer_id' => $ids['customer']]);
    assert_contains('Qty diterima customer', $del->body);
    assert_contains('>600<', $del->body, 'hanya Delivered/Partial dihitung');
    $fin = $c->get('/reports/financial', ['customer_id' => $ids['customer']]);
    assert_contains('Rp 3.500.000,50', $fin->body, 'nilai invoice');
    assert_contains('Rp 2.000.000,50', $fin->body, 'sisa tagihan');
    assert_contains('31–60 hari', $fin->body, 'umur piutang 45 hari');
    assert_same('Overdue', Database::fetchValue("SELECT status FROM invoices_payments WHERE invoice_number = 'INV/REP/002'"));
    $cust = $c->get('/reports/customer', ['customer_id' => $ids['customer']]);
    assert_contains('PT Laporan Uji', $cust->body);
    assert_contains('Piutang', $cust->body, 'Management punya reports.financial');
    $viewer = client_as('Viewer')->get('/reports/customer', ['customer_id' => $ids['customer']]);
    assert_status(200, $viewer);
    assert_not_contains('Piutang', $viewer->body, 'kolom keuangan tersembunyi tanpa reports.financial');
});

test('export CSV: UTF-8 BOM, header, aman dari formula injection, tercatat di audit', function () use ($repSetup) {
    $repSetup();
    $c = client_as('Management');
    $res = $c->get('/reports/po/export', ['format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-31']);
    assert_status(200, $res);
    assert_contains('text/csv', $res->headers['content-type'] ?? '');
    assert_contains('attachment', $res->headers['content-disposition'] ?? '');
    assert_true(str_starts_with($res->body, "\xEF\xBB\xBF"), 'BOM UTF-8');
    $lines = array_values(array_filter(explode("\n", substr($res->body, 3))));
    $header = str_getcsv($lines[0], ',', '"', '');
    assert_same('No. PO', $header[0]);
    $rows = array_map(static fn ($l) => str_getcsv($l, ',', '"', ''), array_slice($lines, 1));
    $byNumber = [];
    foreach ($rows as $r) {
        $byNumber[$r[0]] = $r;
    }
    assert_true(isset($byNumber['PO/REP/001']), 'PO dalam periode');
    assert_false(isset($byNumber['PO/REP/LUAR']));
    $evil = $byNumber['PO/REP/EVIL'] ?? null;
    assert_true($evil !== null);
    assert_same("'=HYPERLINK(\"http://x\")", $evil[1], 'sel teks diawali = diberi tanda petik');
    assert_same('1500', $byNumber['PO/REP/001'][array_search('Qty order', $header, true)], 'angka ditulis mentah');
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'export' AND entity_type = 'report' AND entity_label LIKE 'Purchase Order (CSV)%'"));
});

test('export Excel: file .xlsx valid berisi sheet Ringkasan & Data', function () use ($repSetup) {
    $repSetup();
    $res = client_as('Admin')->get('/reports/financial/export', ['format' => 'xlsx']);
    assert_status(200, $res);
    assert_contains('spreadsheetml', $res->headers['content-type'] ?? '');
    assert_true(str_starts_with($res->body, 'PK'), 'arsip ZIP');
    $tmp = tempnam(sys_get_temp_dir(), 'pikrep');
    file_put_contents($tmp, $res->body);
    $zip = new ZipArchive();
    assert_true($zip->open($tmp) === true);
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    assert_contains('Ringkasan', $workbook);
    assert_contains('Data', $workbook);
    $data = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
    assert_contains('INV/REP/002', $data);
    $zip->close();
    unlink($tmp);
});
