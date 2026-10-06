<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;
use App\Models\PoLine;

group('Phase 4 · Order Entry Form (OEF) & review PPIC');

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

/** Data form OEF lengkap (bisa ditimpa per test). */
$oefForm = static function (array $override = []): array {
    return array_replace([
        'order_number' => 'OEF/OPS/001', 'po_date' => today(), 'sales_name' => 'Rina Sales', 'customer_name' => 'PT Operasi Uji', 'po_number' => 'PO/OPS/001',
        'requested_delivery_date' => date('Y-m-d', strtotime(today() . ' +14 days')), 'delivery_address' => "Gudang PT Operasi Uji\nJl. Industri 7, Bekasi",
        'remark' => 'Kirim pagi hari',
        'lines' => [
            ['product_name' => 'botol  ops 100ML', 'item_description' => 'Amber, ulir 24/410', 'order_qty' => '1.000', 'unit' => 'pcs', 'subcont_supplier' => ''],
            ['product_name' => '', 'item_description' => '', 'order_qty' => '', 'unit' => '', 'subcont_supplier' => ''],
            ['product_name' => 'Tutup Ops 24mm Hitam', 'item_description' => 'Flip top', 'order_qty' => '500', 'unit' => 'pcs', 'subcont_supplier' => 'CV Subcont Tutup'],
        ],
    ], $override);
};

test('validasi OEF: field wajib, produk & qty, tanggal kirim', function () use ($opsSetup) {
    $opsSetup();
    $c = client_as('Marketing');
    assert_status(200, $c->get('/purchase-orders/create'));
    $r1 = $c->post('/purchase-orders', ['order_number' => '', 'po_date' => '2026-13-01', 'sales_name' => '', 'customer_name' => '', 'requested_delivery_date' => '', 'delivery_address' => '',
        'lines' => [['product_name' => '', 'order_qty' => '']]]);
    assert_status(422, $r1);
    foreach (['No order wajib diisi', 'Nama sales wajib diisi', 'Nama customer wajib diisi', 'Tanggal order harus berupa tanggal', 'Permintaan selesai/kirim wajib diisi',
        'Tujuan kirim wajib diisi', 'Tambahkan minimal satu produk'] as $msg) {
        assert_contains($msg, $r1->body);
    }
    $r2 = $c->post('/purchase-orders', ['order_number' => 'OEF/OPS/001', 'po_date' => today(), 'sales_name' => 'Rina', 'customer_name' => 'PT Operasi Uji',
        'requested_delivery_date' => date('Y-m-d', strtotime(today() . ' -1 day')), 'delivery_address' => 'Bekasi',
        'lines' => [['product_name' => 'Botol Ops 100ml', 'order_qty' => '0'], ['product_name' => '', 'order_qty' => '5']]]);
    assert_status(422, $r2);
    assert_contains('Produk 1: Qty minimal 1', $r2->body);
    assert_contains('Produk 2: Nama produk wajib diisi', $r2->body);
    assert_contains('tidak boleh sebelum tanggal order', $r2->body);
    assert_false((bool) Database::fetchValue("SELECT 1 FROM purchase_orders WHERE order_number = 'OEF/OPS/001'"), 'tidak ada OEF setengah jadi');
});

test('buat OEF: customer & produk diketik manual, produk baru otomatis masuk menu Produk', function () use ($opsSetup, $oefForm) {
    $ids = $opsSetup();
    $ppic = client_as('PPIC');
    $c = client_as('Marketing');
    $res = $c->post('/purchase-orders', $oefForm());
    assert_redirect($res, '/purchase-orders/');
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    assert_same('Open', $po['status']);
    assert_same('Pending', $po['review_status'], 'OEF baru menunggu review PPIC');
    assert_same($ids['customer'], (int) $po['customer_id'], 'customer yang sudah ada dipakai (tidak dobel)');
    assert_same('Rina Sales', $po['sales_name']);
    assert_same('PO/OPS/001', $po['po_number']);
    $lines = Database::fetchAll('SELECT * FROM po_lines WHERE po_id = :id ORDER BY id', ['id' => $po['id']]);
    assert_same(2, count($lines), 'baris kosong diabaikan');
    assert_same($ids['p1'], (int) $lines[0]['product_id'], 'nama produk dicocokkan tanpa peka spasi & huruf besar');
    assert_same(1000, (int) $lines[0]['order_qty']);
    assert_same('Amber, ulir 24/410', $lines[0]['item_description']);
    assert_same('CV Subcont Tutup', $lines[1]['subcont_supplier']);
    $newProduct = Database::fetch("SELECT * FROM products WHERE name = 'Tutup Ops 24mm Hitam'");
    assert_true($newProduct !== null, 'produk baru otomatis dibuat');
    assert_same('OEF', $newProduct['source']);
    assert_same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM products WHERE name = 'Botol Ops 100ml'"));
    assert_contains('produk baru otomatis ditambahkan', $c->get('/purchase-orders/' . $po['id'])->body);
    // notifikasi ke PPIC
    $ppicId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'ppic.qa@pik.test'");
    assert_true((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE user_id = :u AND type = 'oef_review' AND entity_id = :p", ['u' => $ppicId, 'p' => $po['id']]));
    assert_contains('OEF/OPS/001', $ppic->get('/notifications')->body);
    // customer baru otomatis dibuat
    $res2 = $c->post('/purchase-orders', $oefForm(['order_number' => 'OEF/OPS/002', 'po_number' => 'PO/OPS/002', 'customer_name' => 'CV Pelanggan Baru Ops',
        'lines' => [['product_name' => 'Pot Ops 50gr', 'order_qty' => '300']]]));
    assert_redirect($res2, '/purchase-orders/');
    $cust = Database::fetch("SELECT * FROM customers WHERE name = 'CV Pelanggan Baru Ops'");
    assert_same('OEF', $cust['source']);
    assert_same((int) Database::fetchValue("SELECT id FROM users WHERE email = 'marketing.qa@pik.test'"), (int) $cust['marketing_pic_id'], 'PIC = Marketing yang menginput');
    // No order & No PO customer unik
    $dup = $c->post('/purchase-orders', $oefForm(['order_number' => 'oef/ops/001', 'po_number' => 'PO/OPS/999']));
    assert_status(422, $dup);
    assert_contains('No order sudah dipakai', $dup->body);
    $dupPo = $c->post('/purchase-orders', $oefForm(['order_number' => 'OEF/OPS/998', 'po_number' => 'po/ops/001']));
    assert_contains('No PO customer sudah dipakai', $dupPo->body);
});

test('nama produk/customer kembar wajib dipilih dari saran (tanpa menebak)', function () use ($opsSetup, $oefForm) {
    $opsSetup();
    Database::insert('products', ['code' => 'PRD-OPS0000101', 'name' => 'Jar Kembar 10gr', 'variant' => 'Putih', 'unit' => 'pcs']);
    Database::insert('products', ['code' => 'PRD-OPS0000102', 'name' => 'Jar Kembar 10gr', 'variant' => 'Hitam', 'unit' => 'pcs']);
    $c = client_as('Marketing');
    $r = $c->post('/purchase-orders', $oefForm(['order_number' => 'OEF/OPS/KMB', 'po_number' => '', 'lines' => [['product_name' => 'Jar Kembar 10gr', 'order_qty' => '10']]]));
    assert_status(422, $r);
    assert_contains('2 produk bernama sama', $r->body);
    $ok = $c->post('/purchase-orders', $oefForm(['order_number' => 'OEF/OPS/KMB', 'po_number' => '', 'lines' => [['product_name' => 'Jar Kembar 10gr — Hitam', 'order_qty' => '10']]]));
    assert_redirect($ok, '/purchase-orders/');
    $pid = (int) Database::fetchValue("SELECT pl.product_id FROM po_lines pl JOIN purchase_orders p ON p.id = pl.po_id WHERE p.order_number = 'OEF/OPS/KMB'");
    assert_same('PRD-OPS0000102', Database::fetchValue('SELECT code FROM products WHERE id = :id', ['id' => $pid]));
    Database::query("DELETE pl FROM po_lines pl JOIN purchase_orders p ON p.id = pl.po_id WHERE p.order_number = 'OEF/OPS/KMB'");
    Database::query("DELETE FROM purchase_orders WHERE order_number = 'OEF/OPS/KMB'");
});

test('review: hanya PPIC; "Bisa diproses" menjadwalkan delivery otomatis', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    $m = client_as('Marketing');
    assert_status(403, $m->post('/purchase-orders/' . $po['id'] . '/approve'));
    $admin = client_as('Admin');
    assert_status(403, $admin->post('/purchase-orders/' . $po['id'] . '/approve'), 'Admin tidak bisa mengonfirmasi OEF (khusus PPIC)');
    assert_contains('Hanya role PPIC', $admin->get('/purchase-orders/' . $po['id'])->body);
    $ppic = client_as('PPIC');
    $page = $ppic->get('/purchase-orders/' . $po['id']);
    assert_contains('Bisa diproses', $page->body);
    assert_contains('btn btn-success', $page->body, 'tombol hijau');
    assert_contains('btn btn-danger', $page->body, 'tombol merah');
    // tidak bisa diproses wajib alasan
    $noReason = $ppic->post('/purchase-orders/' . $po['id'] . '/reject', ['review_note' => '']);
    assert_redirect($noReason, '/purchase-orders/' . $po['id']);
    assert_same('Pending', Database::fetchValue('SELECT review_status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
    assert_redirect($ppic->post('/purchase-orders/' . $po['id'] . '/approve', ['review_note' => 'Produksi minggu depan']), '/purchase-orders/' . $po['id']);
    $after = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $po['id']]);
    assert_same('Approved', $after['review_status']);
    assert_same('On Process', $after['status'], 'Open → On Process saat disetujui');
    assert_same('Produksi minggu depan', $after['review_note']);
    $sched = Database::fetchAll('SELECT * FROM deliveries WHERE po_id = :id ORDER BY id', ['id' => $po['id']]);
    assert_same(2, count($sched), 'satu jadwal per produk');
    assert_same('Scheduled', $sched[0]['status']);
    assert_same($po['requested_delivery_date'], $sched[0]['delivery_date'], 'tanggal = permintaan selesai/kirim');
    assert_same(1000, (int) $sched[0]['delivered_qty']);
    assert_same('OEF', $sched[0]['schedule_source']);
    assert_same('Gudang PT Operasi Uji Jl. Industri 7, Bekasi', $sched[0]['destination']);
    assert_same(null, $sched[0]['sj_number'], 'Surat Jalan diisi PPIC nanti');
    $line1 = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    assert_same(1000, PoLine::totals($line1)['outstanding_qty'], 'jadwal (Scheduled) belum mengurangi outstanding');
    $creator = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'marketing.qa@pik.test'");
    assert_true((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE user_id = :u AND type = 'oef_approved' AND entity_id = :p", ['u' => $creator, 'p' => $po['id']]));
    // keputusan hanya sekali (sampai OEF diubah)
    $again = $ppic->post('/purchase-orders/' . $po['id'] . '/approve');
    assert_redirect($again, '/purchase-orders/' . $po['id']);
    assert_contains('sudah direview', $ppic->get('/purchase-orders/' . $po['id'])->body);
    assert_same(2, (int) Database::fetchValue('SELECT COUNT(*) FROM deliveries WHERE po_id = :id', ['id' => $po['id']]), 'tidak ada jadwal dobel');
});

test('OEF diubah setelah disetujui → menunggu review lagi; persetujuan ulang menyesuaikan jadwal', function () use ($oefForm) {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    $m = client_as('Marketing');
    // ubah status saja tidak memicu review ulang
    $form = $oefForm(['status' => 'On Process']);
    unset($form['lines']);
    assert_redirect($m->post('/purchase-orders/' . $po['id'], $form), '/purchase-orders/' . $po['id']);
    assert_same('Approved', Database::fetchValue('SELECT review_status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
    $newDate = date('Y-m-d', strtotime(today() . ' +21 days'));
    $form['requested_delivery_date'] = $newDate;
    assert_redirect($m->post('/purchase-orders/' . $po['id'], $form), '/purchase-orders/' . $po['id']);
    assert_same('Pending', Database::fetchValue('SELECT review_status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]), 'perubahan isi → review ulang');
    $line1 = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    assert_redirect($m->post('/po-lines/' . $line1, ['product_name' => 'Botol Ops 100ml', 'item_description' => 'Amber', 'order_qty' => '1200', 'unit' => 'pcs']), '/purchase-orders/' . $po['id']);
    assert_redirect(client_as('PPIC')->post('/purchase-orders/' . $po['id'] . '/approve'), '/purchase-orders/' . $po['id']);
    $sched = Database::fetch('SELECT * FROM deliveries WHERE po_line_id = :l', ['l' => $line1]);
    assert_same($newDate, $sched['delivery_date'], 'jadwal otomatis mengikuti tanggal baru');
    assert_same(1200, (int) $sched['delivered_qty'], 'qty jadwal mengikuti qty baru');
    assert_same(2, (int) Database::fetchValue('SELECT COUNT(*) FROM deliveries WHERE po_id = :id', ['id' => $po['id']]));
});

test('"Tidak bisa diproses": alasan tersimpan, pembuat OEF diberi notifikasi, tanpa jadwal', function () use ($oefForm) {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/002'");
    $ppic = client_as('PPIC');
    assert_redirect($ppic->post('/purchase-orders/' . $po['id'] . '/reject', ['review_note' => 'Material tidak tersedia bulan ini']), '/purchase-orders/' . $po['id']);
    $row = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $po['id']]);
    assert_same('Rejected', $row['review_status']);
    assert_same('Material tidak tersedia bulan ini', $row['review_note']);
    assert_same('Open', $row['status']);
    assert_same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM deliveries WHERE po_id = :id', ['id' => $po['id']]));
    $m = client_as('Marketing');
    $creator = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'marketing.qa@pik.test'");
    $note = Database::fetch("SELECT * FROM notifications WHERE user_id = :u AND type = 'oef_rejected' AND entity_id = :p", ['u' => $creator, 'p' => $po['id']]);
    assert_true($note !== null);
    assert_contains('Material tidak tersedia', (string) $note['message']);
    $page = $m->get('/purchase-orders/' . $po['id']);
    assert_contains('Tidak bisa diproses', $page->body);
    assert_contains('Material tidak tersedia bulan ini', $page->body);
    // diperbaiki → diajukan ulang
    $form = $oefForm(['order_number' => 'OEF/OPS/002', 'po_number' => 'PO/OPS/002', 'customer_name' => 'CV Pelanggan Baru Ops', 'status' => 'Open',
        'requested_delivery_date' => date('Y-m-d', strtotime(today() . ' +45 days'))]);
    unset($form['lines']);
    assert_redirect($m->post('/purchase-orders/' . $po['id'], $form), '/purchase-orders/' . $po['id']);
    assert_same('Pending', Database::fetchValue('SELECT review_status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
    assert_same(null, Database::fetchValue('SELECT review_note FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
});

group('Phase 4 · Delivery, Surat Jalan (PPIC) & Outstanding');

test('delivery: Marketing hanya melihat; Surat Jalan hanya diisi PPIC; Delivered mengurangi outstanding', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    $line1 = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    $auto = Database::fetch('SELECT * FROM deliveries WHERE po_line_id = :l', ['l' => $line1]);
    $m = client_as('Marketing');
    assert_status(200, $m->get('/deliveries'));
    assert_status(403, $m->get('/deliveries/create'));
    assert_status(403, $m->post('/deliveries/' . $auto['id'], ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'X', 'delivered_qty' => '1', 'status' => 'Delivered']));
    // Admin boleh mengoreksi jadwal, tetapi nomor SJ tidak berubah
    $admin = client_as('Admin');
    assert_contains('Hanya PPIC yang dapat mengisi Surat Jalan', $admin->get('/deliveries/' . $auto['id'] . '/edit')->body);
    assert_redirect($admin->post('/deliveries/' . $auto['id'], ['po_line_id' => (string) $line1, 'delivery_date' => $auto['delivery_date'], 'sj_number' => 'SJ-PALSU',
        'delivered_qty' => (string) $auto['delivered_qty'], 'status' => 'Scheduled', 'destination' => $auto['destination']]), '/deliveries/' . $auto['id']);
    assert_same(null, Database::fetchValue('SELECT sj_number FROM deliveries WHERE id = :id', ['id' => $auto['id']]), 'SJ dari non-PPIC diabaikan');
    // PPIC mengisi SJ & mengirim sebagian
    $ppic = client_as('PPIC');
    assert_status(200, $ppic->get('/deliveries/' . $auto['id'] . '/edit'));
    $r = $ppic->post('/deliveries/' . $auto['id'], ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90001', 'delivered_qty' => '400',
        'status' => 'Delivered', 'destination' => 'Bekasi']);
    assert_redirect($r, '/deliveries/' . $auto['id']);
    $d = Database::fetch('SELECT * FROM deliveries WHERE id = :id', ['id' => $auto['id']]);
    assert_same('PIK-SJ-90001', $d['sj_number']);
    assert_same('OEF_EDITED', $d['schedule_source'], 'jadwal yang diubah PPIC tidak lagi disesuaikan otomatis');
    assert_same(['order_qty' => 1200, 'delivered_qty' => 400, 'return_qty' => 0, 'outstanding_qty' => 800], PoLine::totals($line1));
    assert_same('Partial', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]), 'status otomatis Partial');
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'auto_status' AND entity_id = :id", ['id' => $po['id']]));
    // jadwal baru dari PPIC
    $ppic->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => date('Y-m-d', strtotime('+2 days')), 'sj_number' => 'PIK-SJ-90002', 'delivered_qty' => '100', 'status' => 'Scheduled']);
    assert_same(800, PoLine::totals($line1)['outstanding_qty'], 'Scheduled tidak mengurangi outstanding');
    assert_contains('PIK-SJ-90001', $m->get('/deliveries', ['q' => 'OEF/OPS/001'])->body, 'cari delivery dengan No order');
});

test('over delivery wajib dikonfirmasi', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    $line1 = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    $c = client_as('PPIC');
    $r = $c->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90003', 'delivered_qty' => '900', 'status' => 'Delivered']);
    assert_status(422, $r);
    assert_contains('Qty melebihi outstanding produk OEF (800 pcs)', $r->body);
    assert_contains('Konfirmasi kelebihan kirim', $r->body);
    $ok = $c->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90003', 'delivered_qty' => '900', 'status' => 'Delivered', 'confirm_over_delivery' => '1']);
    assert_redirect($ok, '/purchase-orders/');
    assert_same(-100, PoLine::totals($line1)['outstanding_qty'], '1200 - 1300 = -100 (over)');
    $d3 = (int) Database::fetchValue("SELECT id FROM deliveries WHERE sj_number = 'PIK-SJ-90003'");
    $edit = $c->post('/deliveries/' . $d3, ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90003', 'delivered_qty' => '800', 'status' => 'Delivered']);
    assert_redirect($edit, '/deliveries/' . $d3);
    assert_same(0, PoLine::totals($line1)['outstanding_qty']);
});

test('OEF otomatis Closed saat seluruh produk terkirim; retur menambah outstanding', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    $lines = Database::fetchColumn('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id', ['p' => $po['id']]);
    $c = client_as('PPIC');
    $c->post('/deliveries', ['po_line_id' => (string) $lines[1], 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90004', 'delivered_qty' => '500', 'status' => 'Delivered']);
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
    $m = client_as('Marketing');
    assert_status(200, $m->get('/returns/create', ['po_line_id' => $lines[0]]));
    $bad = $m->post('/returns', ['case_type' => 'Retur', 'po_line_id' => (string) $lines[0], 'return_date' => today(), 'qty' => '150', 'reason' => 'Rusak']);
    assert_status(422, $bad);
    assert_contains('Alasan tidak valid', $bad->body);
    $d1 = (int) Database::fetchValue("SELECT id FROM deliveries WHERE sj_number = 'PIK-SJ-90001'");
    $ok = $m->post('/returns', ['case_type' => 'Retur', 'po_line_id' => (string) $lines[0], 'delivery_id' => (string) $d1, 'return_date' => today(), 'qty' => '150', 'reason' => 'Damage', 'note' => 'Pecah saat kirim']);
    assert_redirect($ok, '/returns/');
    assert_same(['order_qty' => 1200, 'delivered_qty' => 1200, 'return_qty' => 150, 'outstanding_qty' => 150], PoLine::totals((int) $lines[0]));
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]), 'status Closed tidak diubah otomatis');
    // retur dari surat jalan OEF lain ditolak
    $otherPo = Database::insert('purchase_orders', ['code' => 'PO-OPS0000009', 'po_number' => 'PO/OPS/LAIN', 'customer_id' => Database::fetchValue("SELECT id FROM customers WHERE name='PT Operasi Uji'"), 'po_date' => today(), 'status' => 'Open', 'review_status' => 'Approved']);
    $otherLine = Database::insert('po_lines', ['code' => 'POL-OPS0000009', 'po_id' => $otherPo, 'product_id' => Database::fetchValue("SELECT id FROM products WHERE code='PRD-OPS0000001'"), 'order_qty' => 10]);
    $wrong = $m->post('/returns', ['case_type' => 'Retur', 'po_line_id' => (string) $otherLine, 'delivery_id' => (string) $d1, 'return_date' => today(), 'qty' => '1', 'reason' => 'Other']);
    assert_status(422, $wrong);
    assert_contains('Surat jalan yang dipilih bukan untuk produk OEF ini', $wrong->body);
    assert_false((bool) Database::fetchValue('SELECT 1 FROM returns WHERE po_line_id = :l', ['l' => $otherLine]));
});

test('hapus delivery menghitung ulang outstanding; delivery yang direferensikan retur tidak bisa dihapus', function () {
    $c = client_as('PPIC');
    $d1 = (int) Database::fetchValue("SELECT id FROM deliveries WHERE sj_number = 'PIK-SJ-90001'");
    $blocked = $c->post('/deliveries/' . $d1 . '/delete');
    assert_redirect($blocked, '/deliveries/' . $d1);
    $d2 = Database::fetch("SELECT * FROM deliveries WHERE sj_number = 'PIK-SJ-90002'");
    assert_redirect($c->post('/deliveries/' . $d2['id'] . '/delete'), '/purchase-orders/');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM deliveries WHERE id = :id', ['id' => $d2['id']]));
});

test('delivery legacy tanpa produk OEF: hubungkan ke produk OEF yang sama → issue selesai', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/LAIN'");
    $line = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p', ['p' => $po['id']]);
    $legacy = Database::insert('deliveries', ['code' => 'DEL-OPS0000099', 'po_id' => $po['id'], 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-LEGACY', 'delivered_qty' => 4, 'status' => 'Delivered', 'migration_flag' => 'PRODUCT/LINE NOT MATCHED']);
    Database::insert('migration_issues', ['code' => 'ISS-OPS0000099', 'table_name' => 'DELIVERIES', 'record_code' => 'DEL-OPS0000099', 'record_id' => $legacy, 'issue_type' => 'PRODUCT/LINE NOT MATCHED', 'resolution_status' => 'Needs Review']);
    assert_same(0, PoLine::totals($line)['delivered_qty'], 'belum terhubung = belum dihitung');
    $c = client_as('PPIC');
    $page = $c->get('/deliveries/' . $legacy);
    assert_contains('Belum terhubung ke produk OEF', $page->body);
    $otherLine = (int) Database::fetchValue("SELECT id FROM po_lines WHERE code = 'POL-TEST00001'");
    if ($otherLine) {
        assert_redirect($c->post('/deliveries/' . $legacy . '/link', ['po_line_id' => (string) $otherLine]), '/deliveries/' . $legacy);
        assert_same(null, Database::fetchValue('SELECT po_line_id FROM deliveries WHERE id = :id', ['id' => $legacy]), 'produk OEF lain ditolak');
    }
    assert_redirect($c->post('/deliveries/' . $legacy . '/link', ['po_line_id' => (string) $line]), '/deliveries/' . $legacy);
    $row = Database::fetch('SELECT po_line_id, migration_flag FROM deliveries WHERE id = :id', ['id' => $legacy]);
    assert_same($line, (int) $row['po_line_id']);
    assert_same(null, $row['migration_flag']);
    assert_same('Resolved', Database::fetchValue("SELECT resolution_status FROM migration_issues WHERE code = 'ISS-OPS0000099'"));
    assert_same(4, PoLine::totals($line)['delivered_qty']);
    assert_same('Partial', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
});

group('Phase 4 · Produk OEF, hapus & otorisasi');

test('edit & hapus produk OEF dengan pengaman relasi', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    $lines = Database::fetchColumn('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id', ['p' => $po['id']]);
    $c = client_as('Marketing');
    assert_status(200, $c->get('/po-lines/' . $lines[0] . '/edit'));
    $locked = $c->post('/po-lines/' . $lines[0], ['product_name' => 'Pot Ops 50gr', 'order_qty' => '1200']);
    assert_status(422, $locked);
    assert_contains('Produk tidak dapat diganti', $locked->body);
    assert_redirect($c->post('/po-lines/' . $lines[0], ['product_name' => 'Botol Ops 100ml', 'item_description' => 'Amber', 'order_qty' => '1300', 'unit' => 'pcs']), '/purchase-orders/' . $po['id']);
    assert_same(1300, (int) Database::fetchValue('SELECT order_qty FROM po_lines WHERE id = :id', ['id' => $lines[0]]));
    assert_redirect($c->post('/po-lines/' . $lines[0] . '/delete'), '/purchase-orders/' . $po['id']);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE id = :id', ['id' => $lines[0]]), 'baris dengan delivery tidak terhapus');
    // tambah produk baru (diketik) lalu hapus
    assert_redirect($c->post('/purchase-orders/' . $po['id'] . '/lines', ['product_name' => 'Pump Ops Baru', 'item_description' => 'Putih', 'order_qty' => '10']), '/purchase-orders/' . $po['id']);
    $new = (int) Database::fetchValue('SELECT MAX(id) FROM po_lines WHERE po_id = :p', ['p' => $po['id']]);
    assert_same('OEF', Database::fetchValue('SELECT pr.source FROM po_lines pl JOIN products pr ON pr.id = pl.product_id WHERE pl.id = :id', ['id' => $new]));
    assert_same('Pending', Database::fetchValue('SELECT review_status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]), 'produk ditambah → review ulang');
    assert_redirect($c->post('/po-lines/' . $new . '/delete'), '/purchase-orders/' . $po['id']);
    assert_false((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE id = :id', ['id' => $new]));
});

test('hapus OEF: diblokir bila ada delivery; OEF kosong boleh', function () use ($oefForm) {
    $c = client_as('Marketing');
    $po = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE order_number = 'OEF/OPS/001'");
    assert_redirect($c->post('/purchase-orders/' . $po . '/delete'), '/purchase-orders/' . $po);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM purchase_orders WHERE id = :id', ['id' => $po]));
    $c->post('/purchase-orders', $oefForm(['order_number' => 'OEF/OPS/HAPUS', 'po_number' => '', 'lines' => [['product_name' => 'Botol Ops 100ml', 'order_qty' => '5']]]));
    $tmp = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE order_number = 'OEF/OPS/HAPUS'");
    assert_true($tmp > 0);
    assert_redirect($c->post('/purchase-orders/' . $tmp . '/delete'), '/purchase-orders');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM purchase_orders WHERE id = :id', ['id' => $tmp]));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE po_id = :id', ['id' => $tmp]));
});

test('list OEF: filter review PPIC, status berjalan, pencarian no order/produk', function () {
    $c = client_as('Management');
    $pending = $c->get('/purchase-orders', ['ppic' => 'Pending']);
    assert_status(200, $pending);
    assert_contains('OEF/OPS/002', $pending->body);
    $res = $c->get('/purchase-orders', ['status' => 'open', 'q' => 'Ops']);
    assert_contains('PO/OPS/LAIN', $res->body);
    assert_not_contains('OEF/OPS/001', $res->body, 'OEF Closed tidak termasuk berjalan');
    assert_contains('OEF/OPS/001', $c->get('/purchase-orders', ['q' => 'Tutup Ops 24mm'])->body, 'cari nama produk');
    assert_status(200, $c->get('/deliveries', ['link' => 'unlinked']));
    assert_status(200, $c->get('/deliveries', ['sj' => 'missing']));
    assert_status(200, $c->get('/returns'));
});

test('otorisasi OEF & delivery per role', function () {
    $sales = client_as('Sales');
    assert_status(403, $sales->get('/purchase-orders'));
    assert_status(403, $sales->get('/deliveries'));
    assert_status(403, $sales->get('/returns'));
    $viewer = client_as('Viewer');
    $po = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE order_number = 'OEF/OPS/002'");
    assert_status(200, $viewer->get('/purchase-orders/' . $po));
    assert_status(403, $viewer->get('/purchase-orders/create'));
    assert_status(403, $viewer->post('/purchase-orders/' . $po . '/lines', ['product_name' => 'X', 'order_qty' => '1']));
    assert_status(403, $viewer->post('/purchase-orders/' . $po . '/approve'));
    assert_status(403, $viewer->post('/deliveries', ['po_line_id' => '1', 'delivery_date' => today(), 'delivered_qty' => '1', 'status' => 'Delivered']));
    $mgmt = client_as('Management');
    assert_status(403, $mgmt->get('/purchase-orders/create'), 'Management hanya memantau');
    assert_status(403, $mgmt->get('/deliveries/create'));
    $ppic = client_as('PPIC');
    assert_status(403, $ppic->get('/purchase-orders/create'), 'PPIC tidak membuat OEF');
    assert_status(403, $ppic->get('/purchase-orders/' . $po . '/edit'));
    assert_status(403, $ppic->get('/customers'));
    assert_status(200, $ppic->get('/deliveries/create'));
    foreach (['Produksi', 'Gudang', 'Purchasing'] as $role) {
        assert_status(403, client_as($role)->get('/purchase-orders'), $role . ' tanpa akses OEF');
    }
});
