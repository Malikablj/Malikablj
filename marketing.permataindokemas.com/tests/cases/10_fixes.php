<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Migrator;
use App\Helpers\Paginator;
use App\Models\Customer;

group('Perbaikan bug (update Oktober 2026 — 8)');

/** OEF baru lewat form + keputusan PPIC "bisa diproses"; @return array{id:int,line:int,schedule:int} */
$fixOef = static function (string $number, int $qty, bool $approve = true): array {
    client_as('PPIC');
    $res = client_as('Marketing')->post('/purchase-orders', ['order_number' => $number, 'customer_name' => 'PT Perbaikan Uji', 'sales_name' => 'Sales Uji', 'po_date' => today(),
        'product_name' => 'Botol Perbaikan ' . $number, 'product_spec' => 'PET', 'order_qty' => (string) $qty, 'requested_date' => date('Y-m-d', strtotime('+7 days'))]);
    assert_redirect($res, '/purchase-orders/');
    $po = Database::fetch('SELECT * FROM purchase_orders WHERE order_number = :n', ['n' => $number]);
    if ($approve) {
        client_as('PPIC')->post('/purchase-orders/' . $po['id'] . '/ppic', ['decision' => 'approve', 'ppic_note' => 'OK']);
    }
    return ['id' => (int) $po['id'], 'line' => (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]),
        'schedule' => (int) $po['schedule_delivery_id']];
};

test('edit / tambah baris produk OEF → PPIC konfirmasi ulang, jadwal delivery ikut qty baru', function () use ($fixOef) {
    $o = $fixOef('OEF-FIX-001', 1000);
    assert_same('Approved', Database::fetchValue('SELECT ppic_status FROM purchase_orders WHERE id = :id', ['id' => $o['id']]));
    $c = client_as('Marketing');
    $edit = $c->get('/po-lines/' . $o['line'] . '/edit');
    assert_status(200, $edit);
    assert_not_contains('Produk dikunci', $edit->body, 'jadwal otomatis yang belum dikirim tidak mengunci baris');
    $product = (int) Database::fetchValue('SELECT product_id FROM po_lines WHERE id = :id', ['id' => $o['line']]);
    $res = $c->post('/po-lines/' . $o['line'], ['product_id' => (string) $product, 'order_qty' => '100000', 'remark' => '']);
    assert_redirect($res, '/purchase-orders/' . $o['id']);
    $po = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $o['id']]);
    assert_same('Pending', $po['ppic_status'], 'qty berubah → menunggu PPIC lagi');
    assert_same(null, $po['ppic_by']);
    assert_same(100000, (int) Database::fetchValue('SELECT delivered_qty FROM deliveries WHERE id = :id', ['id' => $o['schedule']]), 'jadwal mengikuti qty baru');
    assert_true((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE type = 'oef_ppic' AND entity_id = :id AND title LIKE 'Revisi OEF%'", ['id' => $o['id']]), 'PPIC dapat notifikasi revisi');

    // catatan saja → tidak dikirim ulang
    client_as('PPIC')->post('/purchase-orders/' . $o['id'] . '/ppic', ['decision' => 'approve', 'ppic_note' => 'OK']);
    $c->post('/po-lines/' . $o['line'], ['product_id' => (string) $product, 'order_qty' => '100000', 'remark' => 'warna bening']);
    assert_same('Approved', Database::fetchValue('SELECT ppic_status FROM purchase_orders WHERE id = :id', ['id' => $o['id']]));

    // tambah baris → dikirim ulang ke PPIC
    $add = $c->post('/purchase-orders/' . $o['id'] . '/lines', ['product_name' => 'Tutup Perbaikan', 'order_qty' => '500']);
    assert_redirect($add, '/purchase-orders/' . $o['id']);
    assert_same('Pending', Database::fetchValue('SELECT ppic_status FROM purchase_orders WHERE id = :id', ['id' => $o['id']]));

    // baris pertama (yang dirujuk jadwal otomatis) boleh dihapus selama belum ada pengiriman: jadwal pindah ke baris lain
    $second = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p AND id <> :l', ['p' => $o['id'], 'l' => $o['line']]);
    $del = $c->post('/po-lines/' . $o['line'] . '/delete');
    assert_redirect($del, '/purchase-orders/' . $o['id']);
    assert_false((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE id = :id', ['id' => $o['line']]), 'baris terhapus');
    $sched = Database::fetch('SELECT * FROM deliveries WHERE id = :id', ['id' => $o['schedule']]);
    assert_same([$second, 500], [(int) $sched['po_line_id'], (int) $sched['delivered_qty']], 'jadwal pindah ke baris tersisa');
});

test('jadwal otomatis tidak basi: dikirim lewat surat jalan lain → qty jadwal turun, order selesai → jadwal batal, tidak dibuat ulang', function () use ($fixOef) {
    $o = $fixOef('OEF-FIX-002', 1000);
    $ppic = client_as('PPIC');
    $ppic->post('/deliveries', ['po_line_id' => (string) $o['line'], 'delivery_date' => today(), 'sj_number' => 'SJ-FIX-1', 'delivered_qty' => '400', 'status' => 'Partial']);
    $sched = Database::fetch('SELECT status, delivered_qty FROM deliveries WHERE id = :id', ['id' => $o['schedule']]);
    assert_same(['Scheduled', 600], [$sched['status'], (int) $sched['delivered_qty']], 'qty jadwal = sisa order');
    $ppic->post('/deliveries', ['po_line_id' => (string) $o['line'], 'delivery_date' => today(), 'sj_number' => 'SJ-FIX-2', 'delivered_qty' => '600', 'status' => 'Delivered']);
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $o['id']]));
    assert_same('Cancelled', Database::fetchValue('SELECT status FROM deliveries WHERE id = :id', ['id' => $o['schedule']]), 'tidak lagi di "surat jalan mendatang"');
    $dash = client_as('Admin')->get('/');
    assert_not_contains('SJ-FIX', (string) Database::fetchValue('SELECT GROUP_CONCAT(sj_number) FROM deliveries WHERE status = \'Scheduled\' AND po_id = :p', ['p' => $o['id']]));
    assert_status(200, $dash);

    // jadwal dihapus Admin lalu OEF diedit: tidak dibuat ulang karena order sudah punya pengiriman
    client_as('Admin')->post('/deliveries/' . $o['schedule'] . '/delete');
    assert_same(null, Database::fetchValue('SELECT schedule_delivery_id FROM purchase_orders WHERE id = :id', ['id' => $o['id']]));
    App\Services\OrderEntry::update($o['id'], ['remark' => 'catatan baru'], null, null);
    assert_same(null, Database::fetchValue('SELECT schedule_delivery_id FROM purchase_orders WHERE id = :id', ['id' => $o['id']]), 'jadwal tidak muncul lagi');
    assert_same(2, (int) Database::fetchValue('SELECT COUNT(*) FROM deliveries WHERE po_id = :p', ['p' => $o['id']]));
});

test('migrasi: jadwal otomatis yang sudah basi dibatalkan saat update dipasang', function () use ($fixOef) {
    $o = $fixOef('OEF-FIX-003', 300);
    // keadaan lama: order Closed manual tetapi jadwal masih Scheduled
    Database::update('purchase_orders', ['status' => 'Closed', 'status_auto' => 0], 'id = :id', ['id' => $o['id']]);
    Database::update('deliveries', ['status' => 'Scheduled'], 'id = :id', ['id' => $o['schedule']]);
    Database::update('settings', ['setting_value' => '2026.10.4'], 'setting_key = :k', ['k' => 'schema_version']);
    $steps = Migrator::run();
    assert_true(in_array('deliveries: jadwal otomatis order yang sudah terpenuhi dibatalkan', $steps, true), 'langkah migrasi berjalan');
    assert_same('Cancelled', Database::fetchValue('SELECT status FROM deliveries WHERE id = :id', ['id' => $o['schedule']]));
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $o['id']]), 'Closed manual tetap');
    assert_false(in_array('deliveries: jadwal otomatis order yang sudah terpenuhi dibatalkan', Migrator::run(), true), 'idempotent');
});

test('outstanding di daftar OEF & laporan hanya order berjalan (Cancelled tidak dihitung)', function () use ($fixOef) {
    $o = $fixOef('OEF-FIX-004', 50000, false);
    client_as('PPIC')->post('/purchase-orders/' . $o['id'] . '/ppic', ['decision' => 'reject', 'ppic_note' => 'Material kosong']);
    assert_same('Cancelled', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $o['id']]));
    $summary = App\Models\PurchaseOrder::summary(['q' => 'OEF-FIX-004', 'status' => '', 'ppic' => '', 'customer' => 0, 'from' => '', 'to' => '', 'open' => '']);
    assert_same(0, (int) $summary['outstanding_qty'], 'order Cancelled tidak menambah outstanding');
    $list = client_as('Admin')->get('/purchase-orders', ['q' => 'OEF-FIX-004']);
    assert_contains('pcs belum terkirim · order berjalan', $list->body);
    $report = client_as('Management')->get('/reports/po', ['q' => 'OEF-FIX-004']);
    assert_status(200, $report);
    assert_contains('order berjalan</div>', $report->body, 'total kolom Outstanding diberi keterangan');
});

test('retur tidak boleh melebihi qty terkirim; data lama yang terlanjur lebih tetap bisa diedit', function () use ($fixOef) {
    $o = $fixOef('OEF-FIX-005', 1000);
    $c = client_as('Marketing');
    $none = $c->post('/returns', ['record_type' => 'Return', 'po_line_id' => (string) $o['line'], 'return_date' => today(), 'return_qty' => '700', 'reason' => 'Damage', 'complaint_detail' => 'retur tanpa kiriman']);
    assert_status(422, $none);
    assert_contains('Belum ada barang terkirim', $none->body);
    client_as('PPIC')->post('/deliveries', ['po_line_id' => (string) $o['line'], 'delivery_date' => today(), 'sj_number' => 'SJ-FIX-5', 'delivered_qty' => '300', 'status' => 'Partial']);
    $over = $c->post('/returns', ['record_type' => 'Return', 'po_line_id' => (string) $o['line'], 'return_date' => today(), 'return_qty' => '301', 'reason' => 'Damage', 'complaint_detail' => 'retur lebih']);
    assert_status(422, $over);
    assert_contains('maks. 300 pcs', $over->body);
    $ok = $c->post('/returns', ['record_type' => 'Return', 'po_line_id' => (string) $o['line'], 'return_date' => today(), 'return_qty' => '300', 'reason' => 'Damage', 'complaint_detail' => 'retur pas']);
    assert_redirect($ok, '/returns/');
    $id = (int) Database::fetchValue("SELECT id FROM returns WHERE complaint_detail = 'retur pas'");
    // data lama: qty retur lebih besar dari kiriman, tetap bisa diedit selama qty tidak dinaikkan
    Database::update('returns', ['return_qty' => 900], 'id = :id', ['id' => $id]);
    $edit = $c->post('/returns/' . $id, ['record_type' => 'Return', 'po_line_id' => (string) $o['line'], 'return_date' => today(), 'return_qty' => '900', 'reason' => 'Damage', 'note' => 'dicek ulang']);
    assert_redirect($edit, '/returns/' . $id);
    $up = $c->post('/returns/' . $id, ['record_type' => 'Return', 'po_line_id' => (string) $o['line'], 'return_date' => today(), 'return_qty' => '901', 'reason' => 'Damage']);
    assert_status(422, $up);
});

test('nomor OEF tampil di laporan delivery & lead time (bukan kode internal)', function () use ($fixOef) {
    $o = $fixOef('OEF-FIX-006', 100);
    $code = (string) Database::fetchValue('SELECT code FROM purchase_orders WHERE id = :id', ['id' => $o['id']]);
    $report = client_as('Management')->get('/reports/delivery', ['q' => 'OEF-FIX-006']);
    assert_status(200, $report);
    assert_contains('OEF-FIX-006', $report->body);
    Database::insert('leadtime', ['code' => 'LT-FIX0000001', 'po_id' => $o['id'], 'delivery_date' => date('Y-m-d', strtotime('+3 days')), 'status' => 'Planned']);
    $lt = client_as('Admin')->get('/lead-times', ['q' => 'OEF-FIX-006']);
    assert_status(200, $lt);
    assert_contains('OEF-FIX-006', $lt->body, 'pencarian & tampilan memakai No. order');
    assert_not_contains('>' . $code . '<', $lt->body);
});

test('PIC yang sudah nonaktif tidak terhapus saat data diedit', function () {
    $pic = create_user('Sales', 'pic.nonaktif@pik.test');
    $cid = Customer::create(['name' => 'PT PIC Nonaktif', 'status' => 'Active', 'marketing_pic_id' => $pic]);
    Database::update('users', ['is_active' => 0], 'id = :id', ['id' => $pic]);
    $form = client_as('Admin')->get('/customers/' . $cid . '/edit');
    assert_status(200, $form);
    assert_contains('(nonaktif)', $form->body, 'PIC lama tetap ada di pilihan');
    assert_contains('value="' . $pic . '" selected', $form->body);
    assert_not_contains('(nonaktif)', client_as('Admin')->get('/customers/create')->body, 'data baru hanya PIC aktif');
});

test('follow up Done yang diedit tetap menyimpan waktu selesai', function () {
    $id = App\Models\FollowUp::saveFollowUp(null, ['customer_id' => Customer::create(['name' => 'PT Follow Fix', 'status' => 'Active']), 'follow_up_date' => today(),
        'follow_up_type' => 'Phone Call', 'purpose' => 'Cek fix', 'status' => 'Done']);
    Database::update('follow_up', ['completed_at' => '2026-01-02 03:04:05'], 'id = :id', ['id' => $id]);
    $row = Database::fetch('SELECT * FROM follow_up WHERE id = :id', ['id' => $id]);
    App\Models\FollowUp::saveFollowUp($id, ['customer_id' => $row['customer_id'], 'follow_up_date' => $row['follow_up_date'], 'follow_up_type' => 'Phone Call',
        'purpose' => 'Cek fix (diedit)', 'status' => 'Done']);
    assert_same('2026-01-02 03:04:05', Database::fetchValue('SELECT completed_at FROM follow_up WHERE id = :id', ['id' => $id]));
});

test('pagination: "…" hanya untuk lompatan ≥ 2 halaman', function () {
    $nums = static function (int $page, int $pages): string {
        preg_match_all('#<li class="page-item[^"]*">(?:<a class="page-link"[^>]*>|<span class="page-link">)([^<]+)</#', (new Paginator([], $pages * 10, $page, 10))->links(), $m);
        return implode(' ', $m[1]);
    };
    assert_same('1 2 3 4 5 6 7 8', $nums(4, 8));
    assert_same('1 2 3 4 5 … 20', $nums(3, 20));
    assert_same('1 … 8 9 10 11 12 … 20', $nums(10, 20));
    assert_same('1 2 3', $nums(1, 3));
});

test('upload melebihi batas server → pesan jelas (413), bukan "sesi kedaluwarsa"', function () {
    $c = client_as('Marketing');
    $limit = App\Models\ComplaintAttachment::postMaxBytes();
    if ($limit <= 0 || $limit > 64 * 1024 * 1024) {
        return; // batas tidak diatur / terlalu besar untuk diuji
    }
    $res = $c->post('/returns', ['complaint_detail' => str_repeat('x', $limit + 1024)]);
    assert_status(413, $res);
    assert_contains('Total file yang diunggah terlalu besar', $res->body);
});
