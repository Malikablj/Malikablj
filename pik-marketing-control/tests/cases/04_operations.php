<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;
use App\Models\PoLine;

group('Phase 4 · Purchase Orders');

$opsSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $cid = Customer::create(['name' => 'PT Operasi Uji', 'status' => 'Active']);
    $p1 = Database::insert('products', ['code' => 'PRD-OPS0000001', 'name' => 'Botol Ops 100ml', 'unit' => 'pcs']);
    $p2 = Database::insert('products', ['code' => 'PRD-OPS0000002', 'name' => 'Pot Ops 50gr', 'unit' => 'pcs']);
    return $ids = ['customer' => $cid, 'p1' => $p1, 'p2' => $p2];
};

test('validasi PO + baris produk', function () use ($opsSetup) {
    $ids = $opsSetup();
    $c = client_as('Marketing');
    assert_status(200, $c->get('/purchase-orders/create', ['customer_id' => $ids['customer']]));
    $r1 = $c->post('/purchase-orders', ['po_number' => '', 'customer_id' => '', 'po_date' => '2026-13-01', 'status' => 'Open', 'lines' => [['product_id' => '', 'order_qty' => '']]]);
    assert_status(422, $r1);
    assert_contains('Nomor PO wajib diisi', $r1->body);
    assert_contains('Customer wajib diisi', $r1->body);
    assert_contains('Tanggal PO harus berupa tanggal', $r1->body);
    assert_contains('Tambahkan minimal satu produk', $r1->body);
    $r2 = $c->post('/purchase-orders', ['po_number' => 'PO/OPS/001', 'customer_id' => (string) $ids['customer'], 'po_date' => today(), 'status' => 'Open',
        'lines' => [['product_id' => (string) $ids['p1'], 'order_qty' => '0'], ['product_id' => '999999', 'order_qty' => '5']]]);
    assert_status(422, $r2);
    assert_contains('Baris 1: Qty order minimal 1', $r2->body);
    assert_contains('Baris 2: Produk tidak ditemukan', $r2->body);
    assert_false((bool) Database::fetchValue("SELECT 1 FROM purchase_orders WHERE po_number = 'PO/OPS/001'"), 'tidak ada PO setengah jadi');
});

test('buat PO dengan beberapa baris (baris kosong diabaikan)', function () use ($opsSetup) {
    $ids = $opsSetup();
    $c = client_as('Marketing');
    $res = $c->post('/purchase-orders', ['po_number' => 'PO/OPS/001', 'customer_id' => (string) $ids['customer'], 'po_date' => today(), 'payment_term' => 'DP 50%', 'status' => 'Open',
        'lines' => [['product_id' => (string) $ids['p1'], 'order_qty' => '1.000', 'remark' => 'Printing 1 warna'], ['product_id' => '', 'order_qty' => ''], ['product_id' => (string) $ids['p2'], 'order_qty' => '500']]]);
    assert_redirect($res, '/purchase-orders/');
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    assert_same('Open', $po['status']);
    $lines = Database::fetchAll('SELECT product_id, order_qty FROM po_lines WHERE po_id = :id ORDER BY id', ['id' => $po['id']]);
    assert_same(2, count($lines));
    assert_same(1000, (int) $lines[0]['order_qty']);
    $page = $c->get('/purchase-orders/' . $po['id']);
    assert_status(200, $page);
    assert_contains('Botol Ops 100ml', $page->body);
    assert_contains('1.500', $page->body, 'total order 1.500');
    // nomor PO unik (tidak peka huruf besar/kecil)
    $dup = $c->post('/purchase-orders', ['po_number' => 'po/ops/001', 'customer_id' => (string) $ids['customer'], 'po_date' => today(), 'status' => 'Open', 'lines' => [['product_id' => (string) $ids['p1'], 'order_qty' => '1']]]);
    assert_status(422, $dup);
    assert_contains('Nomor PO sudah dipakai', $dup->body);
});

group('Phase 4 · Delivery, Return & Outstanding');

test('delivery Delivered mengurangi outstanding, Scheduled tidak; status PO → Partial', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    $line1 = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    $c = client_as('Marketing');
    assert_status(200, $c->get('/deliveries/create', ['po_line_id' => $line1]));
    $r = $c->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90001', 'delivered_qty' => '400', 'status' => 'Delivered']);
    assert_redirect($r, '/purchase-orders/' . $po['id']);
    $c->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => date('Y-m-d', strtotime('+2 days')), 'sj_number' => 'PIK-SJ-90002', 'delivered_qty' => '100', 'status' => 'Scheduled']);
    assert_same(['order_qty' => 1000, 'delivered_qty' => 400, 'return_qty' => 0, 'outstanding_qty' => 600], PoLine::totals($line1));
    assert_same('Partial', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]), 'status otomatis Partial');
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'auto_status' AND entity_id = :id", ['id' => $po['id']]));
    $del = Database::fetch("SELECT * FROM deliveries WHERE sj_number = 'PIK-SJ-90001'");
    assert_same((int) $po['id'], (int) $del['po_id'], 'po_id diturunkan dari baris');
    assert_true($del['product_id'] !== null);
});

test('over delivery wajib dikonfirmasi', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    $line1 = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    $c = client_as('Marketing');
    $r = $c->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90003', 'delivered_qty' => '700', 'status' => 'Delivered']);
    assert_status(422, $r);
    assert_contains('Qty melebihi outstanding baris PO (600 pcs)', $r->body);
    assert_contains('Konfirmasi kelebihan kirim', $r->body);
    $ok = $c->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90003', 'delivered_qty' => '700', 'status' => 'Delivered', 'confirm_over_delivery' => '1']);
    assert_redirect($ok, '/purchase-orders/');
    assert_same(-100, PoLine::totals($line1)['outstanding_qty'], '1000 - 1100 = -100 (over)');
    // edit: qty lama dikembalikan dulu saat pengecekan
    $d3 = (int) Database::fetchValue("SELECT id FROM deliveries WHERE sj_number = 'PIK-SJ-90003'");
    assert_status(200, $c->get('/deliveries/' . $d3 . '/edit'));
    $edit = $c->post('/deliveries/' . $d3, ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90003', 'delivered_qty' => '600', 'status' => 'Delivered']);
    assert_redirect($edit, '/deliveries/' . $d3);
    assert_same(0, PoLine::totals($line1)['outstanding_qty']);
});

test('PO otomatis Closed saat seluruh baris terpenuhi; retur menambah outstanding', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    $lines = Database::fetchColumn('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id', ['p' => $po['id']]);
    $c = client_as('Marketing');
    $c->post('/deliveries', ['po_line_id' => (string) $lines[1], 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90004', 'delivered_qty' => '500', 'status' => 'Delivered']);
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
    // retur 150 di baris 1
    assert_status(200, $c->get('/returns/create', ['po_line_id' => $lines[0]]));
    $bad = $c->post('/returns', ['po_line_id' => (string) $lines[0], 'return_date' => today(), 'return_qty' => '150', 'reason' => 'Rusak']);
    assert_status(422, $bad);
    assert_contains('Alasan tidak valid', $bad->body);
    $d1 = (int) Database::fetchValue("SELECT id FROM deliveries WHERE sj_number = 'PIK-SJ-90001'");
    $ok = $c->post('/returns', ['po_line_id' => (string) $lines[0], 'delivery_id' => (string) $d1, 'return_date' => today(), 'return_qty' => '150', 'reason' => 'Damage', 'note' => 'Pecah saat kirim']);
    assert_redirect($ok, '/purchase-orders/' . $po['id']);
    assert_same(['order_qty' => 1000, 'delivered_qty' => 1000, 'return_qty' => 150, 'outstanding_qty' => 150], PoLine::totals((int) $lines[0]));
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]), 'status Closed tidak diubah otomatis');
    // retur dari surat jalan PO lain ditolak
    $otherPo = Database::insert('purchase_orders', ['code' => 'PO-OPS0000002', 'po_number' => 'PO/OPS/002', 'customer_id' => Database::fetchValue("SELECT id FROM customers WHERE name='PT Operasi Uji'"), 'po_date' => today(), 'status' => 'Open']);
    $otherLine = Database::insert('po_lines', ['code' => 'POL-OPS0000002', 'po_id' => $otherPo, 'product_id' => Database::fetchValue("SELECT id FROM products WHERE code='PRD-OPS0000001'"), 'order_qty' => 10]);
    $wrong = $c->post('/returns', ['po_line_id' => (string) $otherLine, 'delivery_id' => (string) $d1, 'return_date' => today(), 'return_qty' => '1', 'reason' => 'Other']);
    assert_redirect($wrong, '/returns/create');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM returns WHERE po_line_id = :l', ['l' => $otherLine]));
});

test('hapus delivery menghitung ulang outstanding; delivery yang direferensikan retur tidak bisa dihapus', function () {
    $c = client_as('Admin');
    $d1 = (int) Database::fetchValue("SELECT id FROM deliveries WHERE sj_number = 'PIK-SJ-90001'");
    $blocked = $c->post('/deliveries/' . $d1 . '/delete');
    assert_redirect($blocked, '/deliveries/' . $d1);
    $d2 = Database::fetch("SELECT * FROM deliveries WHERE sj_number = 'PIK-SJ-90002'");
    assert_redirect($c->post('/deliveries/' . $d2['id'] . '/delete'), '/purchase-orders/');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM deliveries WHERE id = :id', ['id' => $d2['id']]));
});

test('delivery legacy tanpa baris PO: hubungkan ke baris PO yang sama → issue selesai', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/002'");
    $line = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p', ['p' => $po['id']]);
    $legacy = Database::insert('deliveries', ['code' => 'DEL-OPS0000099', 'po_id' => $po['id'], 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-LEGACY', 'delivered_qty' => 4, 'status' => 'Delivered', 'migration_flag' => 'PRODUCT/LINE NOT MATCHED']);
    Database::insert('migration_issues', ['code' => 'ISS-OPS0000099', 'table_name' => 'DELIVERIES', 'record_code' => 'DEL-OPS0000099', 'record_id' => $legacy, 'issue_type' => 'PRODUCT/LINE NOT MATCHED', 'resolution_status' => 'Needs Review']);
    assert_same(0, PoLine::totals($line)['delivered_qty'], 'belum terhubung = belum dihitung');
    $c = client_as('Marketing');
    $page = $c->get('/deliveries/' . $legacy);
    assert_contains('Belum terhubung ke baris PO', $page->body);
    $otherLine = (int) Database::fetchValue("SELECT id FROM po_lines WHERE code = 'POL-TEST00001'");
    if ($otherLine) {
        assert_redirect($c->post('/deliveries/' . $legacy . '/link', ['po_line_id' => (string) $otherLine]), '/deliveries/' . $legacy);
        assert_same(null, Database::fetchValue('SELECT po_line_id FROM deliveries WHERE id = :id', ['id' => $legacy]), 'baris PO lain ditolak');
    }
    assert_redirect($c->post('/deliveries/' . $legacy . '/link', ['po_line_id' => (string) $line]), '/deliveries/' . $legacy);
    $row = Database::fetch('SELECT po_line_id, migration_flag FROM deliveries WHERE id = :id', ['id' => $legacy]);
    assert_same($line, (int) $row['po_line_id']);
    assert_same(null, $row['migration_flag']);
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-OPS0000099'"));
    assert_same(4, PoLine::totals($line)['delivered_qty']);
    assert_same('Partial', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
});

group('Phase 4 · PO line, hapus & otorisasi');

test('edit & hapus baris PO dengan pengaman relasi', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    $lines = Database::fetchColumn('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id', ['p' => $po['id']]);
    $c = client_as('Marketing');
    assert_status(200, $c->get('/po-lines/' . $lines[0] . '/edit'));
    $p2 = (int) Database::fetchValue("SELECT id FROM products WHERE code = 'PRD-OPS0000002'");
    $locked = $c->post('/po-lines/' . $lines[0], ['product_id' => (string) $p2, 'order_qty' => '1000']);
    assert_status(422, $locked);
    assert_contains('Produk tidak dapat diganti', $locked->body);
    assert_redirect($c->post('/po-lines/' . $lines[0], ['product_id' => Database::fetchValue('SELECT product_id FROM po_lines WHERE id = :id', ['id' => $lines[0]]), 'order_qty' => '1200']), '/purchase-orders/' . $po['id']);
    assert_same(1200, (int) Database::fetchValue('SELECT order_qty FROM po_lines WHERE id = :id', ['id' => $lines[0]]));
    assert_redirect($c->post('/po-lines/' . $lines[0] . '/delete'), '/purchase-orders/' . $po['id']);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE id = :id', ['id' => $lines[0]]), 'baris dengan delivery tidak terhapus');
    // tambah baris baru lalu hapus
    assert_redirect($c->post('/purchase-orders/' . $po['id'] . '/lines', ['product_id' => (string) $p2, 'order_qty' => '10']), '/purchase-orders/' . $po['id']);
    $new = (int) Database::fetchValue('SELECT MAX(id) FROM po_lines WHERE po_id = :p', ['p' => $po['id']]);
    assert_redirect($c->post('/po-lines/' . $new . '/delete'), '/purchase-orders/' . $po['id']);
    assert_false((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE id = :id', ['id' => $new]));
});

test('hapus PO: diblokir bila ada delivery; PO kosong boleh', function () {
    $c = client_as('Admin');
    $po = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    assert_redirect($c->post('/purchase-orders/' . $po . '/delete'), '/purchase-orders/' . $po);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM purchase_orders WHERE id = :id', ['id' => $po]));
    $c->get('/purchase-orders/create');
    $cid = (string) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT Operasi Uji'");
    $c->post('/purchase-orders', ['po_number' => 'PO/OPS/HAPUS', 'customer_id' => $cid, 'po_date' => today(), 'status' => 'Open', 'lines' => [['product_id' => (string) Database::fetchValue("SELECT id FROM products WHERE code='PRD-OPS0000001'"), 'order_qty' => '5']]]);
    $tmp = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE po_number = 'PO/OPS/HAPUS'");
    assert_redirect($c->post('/purchase-orders/' . $tmp . '/delete'), '/purchase-orders');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM purchase_orders WHERE id = :id', ['id' => $tmp]));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE po_id = :id', ['id' => $tmp]));
});

test('list PO: filter status berjalan, pencarian produk, ringkasan', function () {
    $c = client_as('Management');
    $res = $c->get('/purchase-orders', ['status' => 'open', 'q' => 'Ops']);
    assert_status(200, $res);
    assert_contains('PO/OPS/002', $res->body);
    assert_not_contains('PO/OPS/001', $res->body, 'PO Closed tidak termasuk berjalan');
    assert_status(200, $c->get('/deliveries', ['link' => 'unlinked']));
    assert_status(200, $c->get('/returns'));
});

test('otorisasi operations per role', function () {
    $sales = client_as('Sales');
    assert_status(403, $sales->get('/purchase-orders'));
    assert_status(403, $sales->get('/deliveries'));
    assert_status(403, $sales->get('/returns'));
    $viewer = client_as('Viewer');
    $po = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE po_number = 'PO/OPS/002'");
    assert_status(200, $viewer->get('/purchase-orders/' . $po));
    assert_status(403, $viewer->get('/purchase-orders/create'));
    assert_status(403, $viewer->post('/purchase-orders/' . $po . '/lines', ['product_id' => '1', 'order_qty' => '1']));
    assert_status(403, $viewer->post('/deliveries', ['po_line_id' => '1', 'delivery_date' => today(), 'delivered_qty' => '1', 'status' => 'Delivered']));
    assert_status(200, client_as('Management')->get('/purchase-orders/create'), 'Management punya akses PO');
});
