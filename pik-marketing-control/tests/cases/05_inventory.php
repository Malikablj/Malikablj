<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;
use App\Models\PoLine;
use App\Models\Product;

$invSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $cid = Customer::create(['name' => 'PT Inventori Uji', 'status' => 'Active']);
    $p1 = Database::insert('products', ['code' => 'PRD-INV0000001', 'name' => 'Jar Inventori 30gr', 'product_code' => '[JAR30]', 'unit' => 'pcs']);
    $p2 = Database::insert('products', ['code' => 'PRD-INV0000002', 'name' => 'Tube Inventori 50ml', 'unit' => 'pcs']);
    $po = Database::insert('purchase_orders', ['code' => 'PO-INV0000001', 'po_number' => 'PO/INV/001', 'customer_id' => $cid, 'po_date' => today(), 'status' => 'Open', 'review_status' => 'Approved']);
    $l1 = Database::insert('po_lines', ['code' => 'POL-INV000001', 'po_id' => $po, 'product_id' => $p1, 'order_qty' => 800]);
    Database::insert('deliveries', ['code' => 'DEL-INV000001', 'po_id' => $po, 'po_line_id' => $l1, 'product_id' => $p1, 'delivery_date' => today(), 'delivered_qty' => 300, 'status' => 'Delivered']);
    return $ids = ['customer' => $cid, 'p1' => $p1, 'p2' => $p2, 'po' => $po, 'line' => $l1];
};

group('Phase 5 · Products');

test('validasi produk & cegah duplikat persis (nama + varian + kode)', function () use ($invSetup) {
    $invSetup();
    $c = client_as('Marketing');
    assert_status(200, $c->get('/products/create'));
    $form = $c->get('/products/create');
    assert_not_contains('Kapasitas produksi per hari', $form->body, 'kapasitas per hari diganti Qty arsip OEF');
    $r = $c->post('/products', ['name' => '', 'unit' => '', 'is_active' => '1']);
    assert_status(422, $r);
    assert_contains('Nama produk wajib diisi', $r->body);
    assert_contains('Satuan wajib diisi', $r->body);
    $ok = $c->post('/products', ['name' => 'Botol Serum 20ml', 'product_code' => '[BS20]', 'variant' => 'Amber', 'unit' => 'pcs', 'capacity_per_day' => '12.000', 'is_active' => '1']);
    assert_redirect($ok, '/products/');
    $p = Database::fetch("SELECT * FROM products WHERE name = 'Botol Serum 20ml'");
    assert_same(null, $p['capacity_per_day'], 'kolom lama tidak diisi lagi dari form');
    assert_same(1, (int) $p['is_active']);
    assert_same('Botol', Product::group($p), 'kelompok otomatis dari nama');
    $dup = $c->post('/products', ['name' => ' botol serum 20ML ', 'product_code' => '[bs20]', 'variant' => 'amber', 'unit' => 'pcs', 'is_active' => '1']);
    assert_status(422, $dup);
    assert_contains('sudah ada', $dup->body);
    $other = $c->post('/products', ['name' => 'Botol Serum 20ml', 'product_code' => '[BS20]', 'variant' => 'Clear', 'unit' => 'pcs', 'is_active' => '1']);
    assert_redirect($other, '/products/', 'varian berbeda boleh');
});

test('nonaktifkan produk: hilang dari pilihan PO baru, data lama tetap', function () use ($invSetup) {
    $ids = $invSetup();
    $c = client_as('Marketing');
    $r = $c->post('/products/' . $ids['p1'] . '/edit', []);
    assert_status(405, $r, 'edit hanya GET');
    $upd = $c->post('/products/' . $ids['p1'], ['name' => 'Jar Inventori 30gr', 'product_code' => '[JAR30]', 'unit' => 'pcs', 'is_active' => '0']);
    assert_redirect($upd, '/products/' . $ids['p1']);
    assert_same(0, (int) Database::fetchValue('SELECT is_active FROM products WHERE id = :id', ['id' => $ids['p1']]));
    assert_false(array_key_exists($ids['p1'], Product::selectOptions()), 'tidak muncul di pilihan PO baru');
    assert_true(array_key_exists($ids['p1'], Product::selectOptions(true, $ids['p1'])), 'tetap muncul saat mengedit data lama');
    $page = $c->get('/purchase-orders/' . $ids['po']);
    assert_contains('Jar Inventori 30gr', $page->body, 'PO lama tetap menampilkan produk');
    $c->post('/products/' . $ids['p1'], ['name' => 'Jar Inventori 30gr', 'product_code' => '[JAR30]', 'unit' => 'pcs', 'is_active' => '1']);
});

test('detail produk: Qty arsip OEF, outstanding & daftar OEF; hapus diblokir bila dipakai', function () use ($invSetup) {
    $ids = $invSetup();
    $c = client_as('Marketing');
    $page = $c->get('/products/' . $ids['p1']);
    assert_status(200, $page);
    assert_contains('PO/INV/001', $page->body);
    assert_contains('Qty (arsip OEF)', $page->body);
    assert_contains('800', $page->body, 'qty arsip OEF = total qty order');
    assert_contains('500', $page->body, 'outstanding 800 − 300 = 500');
    assert_not_contains('Stok FG', $page->body, 'Marketing tidak punya akses stok');
    $list = $c->get('/products', ['q' => 'JAR30']);
    assert_contains('Pot / Jar', $list->body, 'kolom kelompok');
    assert_contains('1 OEF', $list->body);
    assert_contains('Jar Inventori 30gr', $c->get('/products', ['category' => 'Pot / Jar'])->body, 'filter kelompok');
    assert_not_contains('Tube Inventori 50ml', $c->get('/products', ['category' => 'Pot / Jar'])->body);
    $del = $c->post('/products/' . $ids['p1'] . '/delete');
    assert_redirect($del, '/products/' . $ids['p1']);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM products WHERE id = :id', ['id' => $ids['p1']]));
    assert_contains('tidak dapat dihapus', $c->get('/products/' . $ids['p1'])->body);
    // produk tanpa relasi boleh dihapus
    $free = Database::insert('products', ['code' => 'PRD-INV0000099', 'name' => 'Produk Bebas Relasi', 'unit' => 'pcs']);
    assert_redirect($c->post('/products/' . $free . '/delete'), '/products');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM products WHERE id = :id', ['id' => $free]));
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'delete' AND entity_type = 'product' AND entity_id = :id", ['id' => $free]));
});

test('list produk: cari, filter nonaktif & outstanding', function () use ($invSetup) {
    $ids = $invSetup();
    $c = client_as('Viewer');
    $res = $c->get('/products', ['q' => 'JAR30']);
    assert_status(200, $res);
    assert_contains('Jar Inventori 30gr', $res->body);
    assert_not_contains('Tube Inventori 50ml', $res->body);
    $open = $c->get('/products', ['open' => '1']);
    assert_contains('Jar Inventori 30gr', $open->body);
    assert_not_contains('Tube Inventori 50ml', $open->body, 'tanpa outstanding tidak tampil');
    assert_status(403, $c->get('/products/create'));
    assert_status(403, $c->post('/products', ['name' => 'X', 'unit' => 'pcs']));
    // Management hanya melihat produk; PPIC tidak punya menu Produk — link produk di OEF tidak dirender
    $m = client_as('Management');
    assert_status(200, $m->get('/products/' . $ids['p1']));
    assert_status(403, $m->get('/products/create'));
    $ppic = client_as('PPIC');
    assert_status(403, $ppic->get('/products/' . $ids['p1']));
    $po = $ppic->get('/purchase-orders/' . $ids['po']);
    assert_status(200, $po);
    assert_not_contains('/products/' . $ids['p1'] . '"', $po->body);
});

group('Phase 5 · Stock');

test('stok: produk diketik manual, qty otomatis dari box × isi, selisih wajib dikonfirmasi', function () use ($invSetup) {
    $ids = $invSetup();
    $c = client_as('Produksi');
    $form = $c->get('/stock/create', ['product_id' => $ids['p1']]);
    assert_status(200, $form);
    assert_contains('value="Jar Inventori 30gr [JAR30]"', $form->body, 'produk terisi dari link');
    $r = $c->post('/stock', ['product_name' => '', 'stock_type' => 'Gudang', 'box' => '5', 'qty_per_box' => '', 'quantity' => '']);
    assert_status(422, $r);
    assert_contains('Nama produk wajib diisi', $r->body);
    assert_contains('Tipe stok tidak valid', $r->body);
    $r2 = $c->post('/stock', ['product_name' => 'Jar Inventori 30gr', 'stock_type' => 'FG', 'box' => '5', 'qty_per_box' => '', 'quantity' => '']);
    assert_contains('Isi juga Qty per box', $r2->body);
    $r3 = $c->post('/stock', ['product_name' => 'Jar Inventori 30gr', 'stock_type' => 'FG', 'box' => '', 'qty_per_box' => '', 'quantity' => '']);
    assert_contains('Qty wajib diisi', $r3->body);
    $ok = $c->post('/stock', ['product_name' => 'jar inventori 30GR', 'stock_type' => 'FG', 'box' => '10', 'qty_per_box' => '120', 'quantity' => '', 'status' => 'Ready']);
    assert_status(302, $ok);
    assert_same(1200, (int) Database::fetchValue("SELECT quantity FROM stock WHERE product_id = :p AND stock_type = 'FG'", ['p' => $ids['p1']]), 'qty = 10 × 120, produk yang sudah ada dipakai');
    $mis = $c->post('/stock', ['product_name' => 'Jar Inventori 30gr [JAR30]', 'stock_type' => 'Ready', 'box' => '2', 'qty_per_box' => '100', 'quantity' => '235']);
    assert_status(422, $mis);
    assert_contains('berbeda dengan Box', $mis->body);
    assert_contains('confirm_qty_mismatch', $mis->body);
    $conf = $c->post('/stock', ['product_name' => 'Jar Inventori 30gr [JAR30]', 'stock_type' => 'Ready', 'box' => '2', 'qty_per_box' => '100', 'quantity' => '235', 'confirm_qty_mismatch' => '1']);
    assert_status(302, $conf);
    $c->post('/stock', ['product_name' => 'Jar Inventori 30gr', 'stock_type' => 'FG', 'quantity' => '300']);
    // produk baru diketik oleh Gudang → otomatis masuk daftar produk & dikelompokkan
    $g = client_as('Gudang');
    assert_status(302, $g->post('/stock', ['product_name' => 'Botol Gudang Baru 60ml', 'stock_type' => 'FG', 'quantity' => '700']));
    $newP = Database::fetch("SELECT * FROM products WHERE name = 'Botol Gudang Baru 60ml'");
    assert_same('Stok', $newP['source']);
    $pivot = $c->get('/stock');
    assert_status(200, $pivot);
    assert_contains('1.500', $pivot->body, 'FG per produk = 1.200 + 300');
    assert_contains('235', $pivot->body);
    assert_contains('Pot / Jar', $pivot->body, 'baris kelompok');
    assert_contains('Botol', $pivot->body);
    $botol = $c->get('/stock', ['group' => 'Botol']);
    assert_contains('Botol Gudang Baru 60ml', $botol->body);
    assert_not_contains('Jar Inventori 30gr', $botol->body, 'filter kelompok');
    $entries = $c->get('/stock', ['view' => 'entries', 'type' => 'Ready']);
    assert_contains('2 × 100', $entries->body);
    assert_not_contains('10 × 120', $entries->body, 'filter tipe Ready');
    assert_contains('Stok per kelompok', $g->get('/')->body, 'dashboard Gudang');
});

test('stok legacy: hubungkan ke produk menyelesaikan issue; hapus baris judul menutup issue', function () use ($invSetup) {
    $ids = $invSetup();
    $legacy = Database::insert('stock', ['code' => 'STK-INV0000001', 'product_legacy' => 'JAR 30 GR PUTIH', 'stock_type' => 'FG', 'quantity' => 480]);
    Database::insert('migration_issues', ['code' => 'ISS-INV0000001', 'table_name' => 'STOCK', 'record_id' => $legacy, 'issue_type' => 'STOCK PRODUCT NOT MATCHED', 'description' => 'uji']);
    $header = Database::insert('stock', ['code' => 'STK-INV0000002', 'product_legacy' => 'Nama Barang', 'stock_type' => 'WIP']);
    Database::insert('migration_issues', ['code' => 'ISS-INV0000002', 'table_name' => 'STOCK', 'record_id' => $header, 'issue_type' => 'LEGACY HEADER ROW', 'description' => 'uji']);
    $c = client_as('Admin');
    $list = $c->get('/stock', ['view' => 'entries', 'link' => 'unlinked']);
    assert_contains('JAR 30 GR PUTIH', $list->body);
    $edit = $c->get('/stock/' . $legacy . '/edit');
    assert_status(200, $edit);
    assert_contains('belum terhubung ke master produk', $edit->body);
    // entri legacy boleh disimpan tanpa produk (mis. hanya koreksi qty)
    assert_status(302, $c->post('/stock/' . $legacy, ['product_name' => '', 'stock_type' => 'FG', 'quantity' => '480']));
    assert_same('Needs Review', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-INV0000001'"));
    assert_status(302, $c->post('/stock/' . $legacy, ['product_name' => 'Jar Inventori 30gr', 'stock_type' => 'FG', 'quantity' => '480']));
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-INV0000001'"));
    assert_same($ids['p1'], (int) Database::fetchValue('SELECT product_id FROM stock WHERE id = :id', ['id' => $legacy]));
    assert_status(302, $c->post('/stock/' . $header . '/delete'));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM stock WHERE id = :id', ['id' => $header]));
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-INV0000002'"));
    // produk yang punya stok tidak bisa dihapus
    assert_true(array_key_exists('stock', Product::dependents($ids['p1'])));
});

test('otorisasi stok: Produksi & Gudang mengisi; Marketing, Sales, PPIC, Purchasing ditolak; Viewer & Management lihat', function () use ($invSetup) {
    $ids = $invSetup();
    foreach (['Marketing', 'Sales', 'PPIC', 'Purchasing'] as $role) {
        assert_status(403, client_as($role)->get('/stock'), $role);
    }
    assert_status(403, client_as('Management')->get('/stock/create'));
    assert_status(200, client_as('Management')->get('/stock'));
    $v = client_as('Viewer');
    assert_status(200, $v->get('/stock'));
    assert_status(403, $v->get('/stock/create'));
    assert_status(403, $v->post('/stock', ['product_name' => 'Jar Inventori 30gr', 'stock_type' => 'FG', 'quantity' => '1']));
    $sid = (int) Database::fetchValue('SELECT id FROM stock WHERE product_id = :p LIMIT 1', ['p' => $ids['p1']]);
    assert_status(403, $v->post('/stock/' . $sid . '/delete'));
});

group('Phase 5 · Lead Time & Inbound Maklon');

test('lead time: dibuat dari baris PO, status terlambat dihitung otomatis', function () use ($invSetup) {
    $ids = $invSetup();
    $c = client_as('Marketing');
    $form = $c->get('/lead-times/create', ['po_line_id' => $ids['line']]);
    assert_status(200, $form);
    assert_contains('value="500"', $form->body, 'qty default = outstanding baris');
    $bad = $c->post('/lead-times', ['po_line_id' => '', 'delivery_date' => '31-12-2026', 'status' => 'Soon']);
    assert_status(422, $bad);
    assert_contains('Produk OEF wajib diisi', $bad->body);
    assert_contains('Estimasi tanggal delivery harus berupa tanggal', $bad->body);
    assert_contains('Status tidak valid', $bad->body);
    $past = date('Y-m-d', strtotime(today() . ' -3 days'));
    $ok = $c->post('/lead-times', ['po_line_id' => (string) $ids['line'], 'delivery_date' => $past, 'quantity' => '500', 'status' => 'Planned']);
    assert_redirect($ok, '/purchase-orders/' . $ids['po']);
    $lt = Database::fetch("SELECT * FROM leadtime WHERE po_id = :po", ['po' => $ids['po']]);
    assert_same($ids['p1'], (int) $lt['product_id'], 'produk diturunkan dari baris PO');
    $late = $c->get('/lead-times', ['status' => 'late']);
    assert_contains('PO/INV/001', $late->body);
    assert_contains('Terlambat', $late->body);
    assert_contains('Terlambat', $c->get('/purchase-orders/' . $ids['po'])->body, 'PO menampilkan estimasi terlambat');
    // selesai dikirim → tidak lagi terlambat
    assert_status(302, $c->post('/lead-times/' . $lt['id'], ['po_line_id' => (string) $ids['line'], 'delivery_date' => $past, 'quantity' => '500', 'status' => 'Delivered']));
    assert_not_contains('PO/INV/001', $c->get('/lead-times', ['status' => 'late'])->body);
});

test('lead time legacy tanpa PO: boleh diedit, menghubungkan ke PO menyelesaikan issue', function () use ($invSetup) {
    $ids = $invSetup();
    $lt = Database::insert('leadtime', ['code' => 'LT-INV0000001', 'po_number_legacy' => 'PO LAMA 77', 'product_legacy' => 'Jar putih', 'quantity' => 100, 'delivery_date' => today(), 'status' => 'On Process']);
    Database::insert('migration_issues', ['code' => 'ISS-INV0000003', 'table_name' => 'LEADTIME', 'record_id' => $lt, 'issue_type' => 'PO NOT FOUND', 'description' => 'uji']);
    $c = client_as('Marketing');
    $edit = $c->get('/lead-times/' . $lt . '/edit');
    assert_status(200, $edit);
    assert_contains('PO LAMA 77', $edit->body);
    assert_status(302, $c->post('/lead-times/' . $lt, ['po_line_id' => '', 'delivery_date' => today(), 'quantity' => '100', 'status' => 'Delayed']));
    assert_same('Delayed', Database::fetchValue('SELECT status FROM leadtime WHERE id = :id', ['id' => $lt]));
    assert_status(302, $c->post('/lead-times/' . $lt, ['po_line_id' => (string) $ids['line'], 'delivery_date' => today(), 'quantity' => '100', 'status' => 'Delayed']));
    assert_same($ids['po'], (int) Database::fetchValue('SELECT po_id FROM leadtime WHERE id = :id', ['id' => $lt]));
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-INV0000003'"));
    assert_status(403, client_as('Sales')->get('/lead-times'));
    assert_status(403, client_as('Viewer')->post('/lead-times/' . $lt . '/delete'));
    assert_status(403, client_as('Management')->post('/lead-times/' . $lt . '/delete'), 'Management hanya melihat');
    assert_status(302, $c->post('/lead-times/' . $lt . '/delete'));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM leadtime WHERE id = :id', ['id' => $lt]));
});

test('inbound maklon (input Gudang): validasi, total masuk = qty − reject, detail & edit', function () use ($invSetup) {
    $ids = $invSetup();
    $c = client_as('Gudang');
    assert_status(200, $c->get('/inbound/create'));
    $bad = $c->post('/inbound', ['vendor' => '', 'actual_inbound_date' => '', 'quantity' => '100', 'reject_qty' => '150', 'component_name' => '']);
    assert_status(422, $bad);
    assert_contains('Vendor wajib diisi', $bad->body);
    assert_contains('Tanggal barang masuk wajib diisi', $bad->body);
    $bad2 = $c->post('/inbound', ['vendor' => 'PIK', 'actual_inbound_date' => today(), 'quantity' => '100', 'reject_qty' => '150', 'component_name' => '']);
    assert_contains('Isi nama komponen atau pilih produk', $bad2->body);
    $bad3 = $c->post('/inbound', ['vendor' => 'PIK', 'actual_inbound_date' => today(), 'quantity' => '100', 'reject_qty' => '150', 'component_name' => 'Cap 24mm']);
    assert_contains('Qty reject tidak boleh melebihi qty diterima', $bad3->body);
    $ok = $c->post('/inbound', ['vendor' => 'PIK', 'receiver' => 'ALAMANDA', 'actual_inbound_date' => today(), 'sj_number' => 'SJ-MKL-01', 'po_id' => (string) $ids['po'],
        'component_name' => 'Cap 24mm', 'quantity' => '1.000', 'reject_qty' => '25']);
    assert_redirect($ok, '/inbound/');
    $row = Database::fetch("SELECT * FROM inbound_maklon WHERE sj_number = 'SJ-MKL-01'");
    assert_same(975, (int) $row['total_in']);
    $detail = $c->get('/inbound/' . $row['id']);
    assert_status(200, $detail);
    assert_contains('975', $detail->body);
    assert_contains('PO/INV/001', $detail->body);
    assert_status(302, $c->post('/inbound/' . $row['id'], ['vendor' => 'PIK', 'receiver' => 'ALAMANDA', 'actual_inbound_date' => today(), 'sj_number' => 'SJ-MKL-01',
        'component_name' => 'Cap 24mm', 'quantity' => '1000', 'reject_qty' => '']));
    assert_same(1000, (int) Database::fetchValue('SELECT total_in FROM inbound_maklon WHERE id = :id', ['id' => $row['id']]), 'reject kosong → total = qty');
    $list = $c->get('/inbound', ['vendor' => 'PIK']);
    assert_contains('SJ-MKL-01', $list->body);
    assert_status(403, client_as('Marketing')->get('/inbound'));
    assert_status(403, client_as('Produksi')->get('/inbound'));
    assert_status(403, client_as('Management')->get('/inbound/create'), 'Management hanya melihat');
    $v = client_as('Viewer');
    assert_status(200, $v->get('/inbound/' . $row['id']));
    assert_status(403, $v->get('/inbound/' . $row['id'] . '/edit'));
    assert_status(302, $c->post('/inbound/' . $row['id'] . '/delete'));
});

test('halaman inventory tidak crash tanpa data', function () {
    $c = client_as('Admin');
    foreach (['/products', '/stock', '/stock?view=entries', '/lead-times', '/inbound', '/stock?type=Reserved', '/lead-times?status=late&from=2030-01-01', '/inbound-supplier', '/stock?group=Lainnya'] as $path) {
        assert_status(200, $c->get($path), $path);
    }
    assert_same(0, PoLine::outstanding(0, 0, 0));
});

group('Inventory · Inbound Supplier (Purchasing)');

test('inbound supplier: hanya Purchasing yang menginput; validasi & ringkasan', function () {
    $c = client_as('Purchasing');
    assert_status(200, $c->get('/inbound-supplier/create'));
    $bad = $c->post('/inbound-supplier', ['supplier' => '', 'receive_date' => '', 'item_name' => '', 'quantity' => '0', 'unit' => 'pcs']);
    assert_status(422, $bad);
    foreach (['Supplier wajib diisi', 'Tanggal diterima wajib diisi', 'Nama barang wajib diisi', 'Qty datang minimal 1'] as $msg) {
        assert_contains($msg, $bad->body);
    }
    $bad2 = $c->post('/inbound-supplier', ['supplier' => 'PT Plastik Jaya', 'receive_date' => today(), 'item_name' => 'Resin PET', 'quantity' => '100', 'unit' => 'kg', 'reject_qty' => '150']);
    assert_contains('Qty reject tidak boleh melebihi qty datang', $bad2->body);
    $ok = $c->post('/inbound-supplier', ['supplier' => ' PT  Plastik Jaya ', 'receive_date' => today(), 'purchase_number' => 'PR/2610/001', 'sj_number' => 'SJ-PJ-77',
        'item_name' => 'Resin PET', 'specification' => 'Grade botol', 'quantity' => '1.000', 'unit' => 'kg', 'reject_qty' => '25', 'receiver' => 'Gudang bahan baku']);
    assert_redirect($ok, '/inbound-supplier/');
    $row = Database::fetch("SELECT * FROM inbound_supplier WHERE sj_number = 'SJ-PJ-77'");
    assert_same('PT Plastik Jaya', $row['supplier'], 'spasi berlebih dirapikan');
    assert_same(1000, (int) $row['quantity']);
    assert_same(25, (int) $row['reject_qty']);
    assert_true(str_starts_with((string) $row['code'], 'ISP-'));
    $detail = $c->get('/inbound-supplier/' . $row['id']);
    assert_contains('975', $detail->body, 'diterima bersih = 1.000 − 25');
    $list = $c->get('/inbound-supplier', ['supplier' => 'PT Plastik Jaya']);
    assert_contains('Resin PET', $list->body);
    assert_contains('975', $list->body);
    assert_contains('Resin PET', $c->get('/inbound-supplier', ['q' => 'PR/2610'])->body);
    assert_status(302, $c->post('/inbound-supplier/' . $row['id'], ['supplier' => 'PT Plastik Jaya', 'receive_date' => today(), 'item_name' => 'Resin PET', 'quantity' => '1000', 'unit' => 'kg', 'reject_qty' => '']));
    assert_same(0, (int) Database::fetchValue('SELECT reject_qty FROM inbound_supplier WHERE id = :id', ['id' => $row['id']]), 'reject kosong = 0');
    assert_contains('Inbound supplier terakhir', $c->get('/')->body, 'dashboard Purchasing');
    // otorisasi
    foreach (['Marketing', 'Sales', 'PPIC', 'Produksi', 'Gudang'] as $role) {
        assert_status(403, client_as($role)->get('/inbound-supplier'), $role);
    }
    $v = client_as('Viewer');
    assert_status(200, $v->get('/inbound-supplier/' . $row['id']));
    assert_status(403, $v->get('/inbound-supplier/create'));
    assert_status(403, client_as('Management')->post('/inbound-supplier/' . $row['id'] . '/delete'));
    assert_redirect($c->post('/inbound-supplier/' . $row['id'] . '/delete'), '/inbound-supplier');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM inbound_supplier WHERE id = :id', ['id' => $row['id']]));
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE entity_type = 'inbound_supplier' AND action = 'delete'"));
});
