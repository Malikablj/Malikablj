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

test('validasi Order Entry Form', function () use ($opsSetup) {
    $ids = $opsSetup();
    $c = client_as('Marketing');
    $form = $c->get('/purchase-orders/create', ['customer_id' => $ids['customer']]);
    assert_status(200, $form);
    assert_contains('Simpan &amp; kirim ke PPIC', $form->body);
    assert_contains('value="PT Operasi Uji"', $form->body, 'nama customer terisi dari ?customer_id');
    assert_contains('name="order_number"', $form->body, 'No. order diisi manual');
    $r1 = $c->post('/purchase-orders', ['order_number' => '', 'customer_name' => '', 'sales_name' => '', 'po_date' => '2026-13-01', 'product_name' => '', 'order_qty' => '', 'product_spec' => '', 'requested_date' => '']);
    assert_status(422, $r1);
    assert_contains('No. order wajib diisi', $r1->body);
    assert_contains('Nama customer wajib diisi', $r1->body);
    assert_contains('Nama sales wajib diisi', $r1->body);
    assert_contains('Tanggal order harus berupa tanggal', $r1->body);
    assert_contains('Nama produk wajib diisi', $r1->body);
    assert_contains('Spesifikasi produk wajib diisi', $r1->body);
    assert_contains('Permintaan selesai / kirim wajib diisi', $r1->body);
    $r2 = $c->post('/purchase-orders', ['order_number' => 'OEF-OPS-001', 'customer_name' => 'PT Operasi Uji', 'sales_name' => 'Sales Uji', 'po_number' => 'PO/OPS/001', 'po_date' => today(),
        'product_name' => 'Botol Ops 100ml', 'product_spec' => 'PET bening', 'order_qty' => '0', 'is_subcont' => '1', 'supplier' => '',
        'requested_date' => date('Y-m-d', strtotime('-1 day'))]);
    assert_status(422, $r2);
    assert_contains('Qty produk minimal 1', $r2->body);
    assert_contains('Supplier wajib diisi untuk order subcont', $r2->body);
    assert_false((bool) Database::fetchValue("SELECT 1 FROM purchase_orders WHERE po_number = 'PO/OPS/001'"), 'tidak ada order setengah jadi');
});

test('buat OEF: No. order manual, customer & produk lama dipakai, jadwal delivery otomatis, PPIC Pending', function () use ($opsSetup) {
    $ids = $opsSetup();
    client_as('PPIC'); // pastikan ada user PPIC penerima notifikasi
    $c = client_as('Marketing');
    $customers = (int) Database::fetchValue('SELECT COUNT(*) FROM customers');
    $res = $c->post('/purchase-orders', ['order_number' => ' OEF-OPS-001 ', 'customer_name' => ' pt operasi  UJI ', 'sales_name' => 'Sales Uji', 'po_number' => 'PO/OPS/001', 'po_date' => today(), 'payment_term' => 'DP 50%',
        'product_name' => ' botol ops 100ML ', 'product_spec' => "PET bening\nPrinting 1 warna", 'order_qty' => '1.000', 'requested_date' => date('Y-m-d', strtotime('+10 days')),
        'ship_to' => 'Gudang Cikarang', 'remark' => 'Urgent']);
    assert_redirect($res, '/purchase-orders/');
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    assert_same('Open', $po['status']);
    assert_same('Pending', $po['ppic_status']);
    assert_same('OEF-OPS-001', $po['order_number'], 'No. order sesuai isian manual');
    assert_same($ids['customer'], (int) $po['customer_id'], 'nama customer sama (beda huruf/spasi) → customer lama dipakai');
    assert_same($customers, (int) Database::fetchValue('SELECT COUNT(*) FROM customers'), 'tidak ada customer ganda');
    $lines = Database::fetchAll('SELECT product_id, order_qty FROM po_lines WHERE po_id = :id ORDER BY id', ['id' => $po['id']]);
    assert_same(1, count($lines));
    assert_same($ids['p1'], (int) $lines[0]['product_id'], 'nama produk sama (beda huruf/spasi) → produk lama dipakai');
    assert_same(1000, (int) $lines[0]['order_qty']);
    assert_same("PET bening\nPrinting 1 warna", Database::fetchValue('SELECT spec FROM products WHERE id = :id', ['id' => $ids['p1']]));
    assert_same(1000, (int) Database::fetchValue('SELECT qty FROM products WHERE id = :id', ['id' => $ids['p1']]), 'qty OEF tersimpan sebagai arsip produk');
    $sched = Database::fetch('SELECT * FROM deliveries WHERE id = :id', ['id' => $po['schedule_delivery_id']]);
    assert_same('Scheduled', $sched['status']);
    assert_same($po['requested_date'], $sched['delivery_date']);
    assert_same('Gudang Cikarang', $sched['destination']);
    assert_same(1000, (int) $sched['delivered_qty']);
    assert_true((bool) Database::fetchValue("SELECT 1 FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.role = 'PPIC' AND n.type = 'oef_ppic'"), 'PPIC dapat notifikasi');
    // baris kedua ditambahkan dari halaman detail (order multi-produk tetap didukung)
    assert_redirect($c->post('/purchase-orders/' . $po['id'] . '/lines', ['product_name' => 'POT OPS 50GR', 'order_qty' => '500']), '/purchase-orders/' . $po['id']);
    assert_same($ids['p2'], (int) Database::fetchValue('SELECT product_id FROM po_lines WHERE po_id = :p ORDER BY id DESC LIMIT 1', ['p' => $po['id']]), 'baris tambahan: produk diketik manual, produk lama dipakai');
    $page = $c->get('/purchase-orders/' . $po['id']);
    assert_status(200, $page);
    assert_contains('Botol Ops 100ml', $page->body);
    assert_contains('1.500', $page->body, 'total order 1.500');
    assert_contains('Menunggu PPIC', $page->body);
    // No. order & No. PO customer unik (tidak peka huruf besar/kecil)
    $dup = $c->post('/purchase-orders', ['order_number' => 'oef-ops-001', 'customer_name' => 'PT Operasi Uji', 'sales_name' => 'Sales Uji', 'po_number' => 'po/ops/001', 'po_date' => today(),
        'product_name' => 'Botol Ops 100ml', 'product_spec' => 'x', 'order_qty' => '1', 'requested_date' => today()]);
    assert_status(422, $dup);
    assert_contains('No. order sudah dipakai order lain', $dup->body);
    assert_contains('No. PO customer sudah dipakai', $dup->body);
});

test('OEF dengan customer & produk baru → dicatat otomatis di menu Customers & Products', function () use ($opsSetup) {
    $ids = $opsSetup();
    $c = client_as('Sales');
    $res = $c->post('/purchase-orders', ['order_number' => 'OEF-OPS-002', 'customer_name' => 'PT Operasi Uji', 'sales_name' => 'Sales Uji', 'po_date' => today(),
        'product_name' => 'Jar Ops Baru 30gr', 'product_spec' => 'PP putih, tutup emas', 'order_qty' => '2000', 'is_subcont' => '1', 'supplier' => 'CV Maklon Uji',
        'requested_date' => date('Y-m-d', strtotime('+20 days'))]);
    assert_redirect($res, '/purchase-orders/');
    $prod = Database::fetch("SELECT * FROM products WHERE name = 'Jar Ops Baru 30gr'");
    assert_true($prod !== null, 'produk baru tercatat');
    assert_same('OEF', $prod['source']);
    assert_same('PP putih, tutup emas', $prod['spec']);
    assert_same(2000, (int) $prod['qty'], 'qty OEF tersimpan di produk baru');
    $po = Database::fetch('SELECT * FROM purchase_orders WHERE id = (SELECT po_id FROM po_lines WHERE product_id = :p)', ['p' => $prod['id']]);
    assert_same(1, (int) $po['is_subcont']);
    assert_same('CV Maklon Uji', $po['supplier']);
    assert_true($po['po_number'] === null, 'No. PO customer boleh kosong');
    assert_same($ids['customer'], (int) $po['customer_id']);
    // customer baru diketik manual → tercatat otomatis di menu Customers
    $new = $c->post('/purchase-orders', ['order_number' => 'OEF-OPS-003', 'customer_name' => 'CV Pelanggan Baru OEF', 'sales_name' => 'Sales Tester', 'po_date' => today(),
        'product_name' => 'Jar Ops Baru 30gr', 'product_spec' => 'PP putih', 'order_qty' => '300', 'requested_date' => date('Y-m-d', strtotime('+5 days'))]);
    assert_redirect($new, '/purchase-orders/');
    $flash = $c->get((string) parse_url((string) $new->location, PHP_URL_PATH));
    assert_contains('Customer baru &quot;CV Pelanggan Baru OEF&quot; dicatat otomatis di menu Customers', $flash->body);
    $cust = Database::fetch("SELECT * FROM customers WHERE name = 'CV Pelanggan Baru OEF'");
    assert_true($cust !== null, 'customer baru tercatat');
    assert_same('Active', $cust['status']);
    assert_contains('Dicatat otomatis dari Order Entry Form OEF-OPS-003', (string) $cust['notes']);
    assert_same((int) Database::fetchValue("SELECT id FROM users WHERE email = 'sales.qa@pik.test'"), (int) $cust['marketing_pic_id'], 'PIC = sales yang dikenali');
    assert_same((int) $cust['id'], (int) Database::fetchValue("SELECT customer_id FROM purchase_orders WHERE order_number = 'OEF-OPS-003'"));
    assert_same(300, (int) Database::fetchValue("SELECT qty FROM products WHERE name = 'Jar Ops Baru 30gr'"), 'qty arsip = OEF terakhir');
    $list = client_as('Marketing')->get('/customers', ['q' => 'Pelanggan Baru']);
    assert_contains('CV Pelanggan Baru OEF', $list->body, 'muncul di menu Customers');
    // "PT." vs "PT" (normalisasi nama) tidak membuat customer ganda
    $c->post('/purchase-orders', ['order_number' => 'OEF-OPS-004', 'customer_name' => 'PT. Operasi Uji', 'sales_name' => 'Sales Uji', 'po_date' => today(),
        'product_name' => 'Botol Ops 100ml', 'product_spec' => 'x', 'order_qty' => '10', 'requested_date' => today()]);
    assert_same($ids['customer'], (int) Database::fetchValue("SELECT customer_id FROM purchase_orders WHERE order_number = 'OEF-OPS-004'"));
});

group('Phase 4 · PPIC & jadwal delivery OEF');

test('PPIC: tidak bisa diproses wajib alasan → jadwal batal; revisi → Pending lagi; bisa diproses → On Process', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF-OPS-002'");
    $id = (int) $po['id'];
    // hanya PPIC / Admin yang boleh memutuskan
    assert_status(403, client_as('Marketing')->post('/purchase-orders/' . $id . '/ppic', ['decision' => 'approve']));
    $ppic = client_as('PPIC');
    $page = $ppic->get('/purchase-orders/' . $id);
    assert_status(200, $page);
    assert_contains('Bisa diproses', $page->body);
    assert_contains('Tidak bisa diproses', $page->body);
    assert_contains('PT Operasi Uji', $page->body, 'PPIC melihat nama customer');
    assert_not_contains('/customers/' . $po['customer_id'] . '"', $page->body, 'tanpa link ke menu Customers (PPIC tidak punya akses)');
    assert_not_contains('/purchase-orders/' . $id . '/edit', $page->body, 'PPIC hanya meninjau, tidak mengedit OEF');
    $noReason = $ppic->post('/purchase-orders/' . $id . '/ppic', ['decision' => 'reject', 'ppic_note' => '']);
    assert_redirect($noReason, '/purchase-orders/' . $id);
    assert_same('Pending', Database::fetchValue('SELECT ppic_status FROM purchase_orders WHERE id = :id', ['id' => $id]));
    $ppic->post('/purchase-orders/' . $id . '/ppic', ['decision' => 'reject', 'ppic_note' => 'Material PP putih kosong']);
    $row = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $id]);
    assert_same('Rejected', $row['ppic_status']);
    assert_same('Cancelled', $row['status']);
    assert_same('Material PP putih kosong', $row['ppic_note']);
    assert_same('Cancelled', Database::fetchValue('SELECT status FROM deliveries WHERE id = :id', ['id' => $row['schedule_delivery_id']]));
    assert_true((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE type = 'oef_ppic_result' AND entity_id = :id", ['id' => $id]), 'pembuat OEF dapat notifikasi');
    // revisi oleh sales → dikirim ulang ke PPIC, jadwal aktif lagi
    $sales = client_as('Sales');
    assert_status(200, $sales->get('/purchase-orders/' . $id . '/edit'));
    $newDate = date('Y-m-d', strtotime('+25 days'));
    $rev = $sales->post('/purchase-orders/' . $id, ['order_number' => $row['order_number'], 'customer_name' => 'PT Operasi Uji', 'sales_name' => 'Sales Uji', 'po_date' => $row['po_date'],
        'product_name' => 'Jar Ops Baru 30gr', 'product_spec' => 'PP natural, tutup emas', 'order_qty' => '2000', 'is_subcont' => '1', 'supplier' => 'CV Maklon Uji',
        'requested_date' => $newDate, 'status' => 'Cancelled']);
    assert_redirect($rev, '/purchase-orders/' . $id);
    $row = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $id]);
    assert_same('Pending', $row['ppic_status']);
    assert_same('Open', $row['status']);
    $sched = Database::fetch('SELECT * FROM deliveries WHERE id = :id', ['id' => $row['schedule_delivery_id']]);
    assert_same('Scheduled', $sched['status']);
    assert_same($newDate, $sched['delivery_date']);
    // bisa diproses
    $ppic->post('/purchase-orders/' . $id . '/ppic', ['decision' => 'approve', 'ppic_note' => 'OK, produksi minggu depan']);
    $row = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $id]);
    assert_same('Approved', $row['ppic_status']);
    assert_same('On Process', $row['status']);
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'ppic_approve' AND entity_id = :id", ['id' => $id]));
});

test('ubah jadwal delivery OEF dari halaman order & menu Delivery', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE order_number = 'OEF-OPS-002'");
    $id = (int) $po['id'];
    $ppic = client_as('PPIC');
    $date = date('Y-m-d', strtotime('+30 days'));
    assert_redirect($ppic->post('/purchase-orders/' . $id . '/schedule', ['delivery_date' => $date, 'reason' => 'Bahan baku terlambat']), '/purchase-orders/' . $id);
    $sched = Database::fetch('SELECT * FROM deliveries WHERE id = :id', ['id' => $po['schedule_delivery_id']]);
    assert_same($date, $sched['delivery_date']);
    assert_contains('Bahan baku terlambat', (string) $sched['note']);
    assert_same((string) $po['requested_date'], (string) Database::fetchValue('SELECT requested_date FROM purchase_orders WHERE id = :id', ['id' => $id]), 'tanggal permintaan customer tetap');
    $list = client_as('Marketing')->get('/deliveries');
    assert_status(200, $list);
    assert_contains((string) $po['order_number'], $list->body, 'jadwal OEF tampil di menu Delivery');
    // Sales & Marketing tidak boleh mengubah jadwal (hanya PPIC)
    assert_status(403, client_as('Sales')->post('/purchase-orders/' . $id . '/schedule', ['delivery_date' => today()]));
    assert_status(403, client_as('Marketing')->post('/purchase-orders/' . $id . '/schedule', ['delivery_date' => today()]));
});

test('PPIC massal: centang beberapa / semua OEF di halaman → Bisa / Tidak bisa diproses (Admin & PPIC)', function () {
    $mkt = client_as('Marketing');
    $ids = [];
    foreach (['OEF-BLK-001', 'OEF-BLK-002', 'OEF-BLK-003'] as $i => $no) {
        assert_redirect($mkt->post('/purchase-orders', ['order_number' => $no, 'customer_name' => 'PT Massal Uji', 'sales_name' => 'Sales Uji', 'po_date' => today(),
            'product_name' => 'Botol Massal ' . $i, 'product_spec' => 'PET', 'order_qty' => '100', 'requested_date' => date('Y-m-d', strtotime('+7 days'))]), '/purchase-orders/');
        $ids[] = (int) Database::fetchValue('SELECT id FROM purchase_orders WHERE order_number = :n', ['n' => $no]);
    }
    $legacy = Database::insert('purchase_orders', ['code' => 'PO-BLK0000001', 'po_number' => 'PO/BLK/LAMA', 'customer_id' => Database::fetchValue('SELECT customer_id FROM purchase_orders WHERE id = :id', ['id' => $ids[0]]),
        'po_date' => today(), 'status' => 'Open']);
    $ppicOf = static fn (int $id): array => Database::fetch('SELECT ppic_status, status, ppic_note, schedule_delivery_id FROM purchase_orders WHERE id = :id', ['id' => $id]);

    // hanya Admin & PPIC: role lain tidak melihat checkbox dan ditolak backend
    $list = $mkt->get('/purchase-orders', ['q' => 'Massal']);
    assert_not_contains('ppic-bulk', $list->body);
    assert_not_contains('name="ids[]"', $list->body);
    foreach (['Marketing', 'Sales', 'Management', 'Viewer'] as $role) {
        assert_status(403, client_as($role)->post('/purchase-orders/ppic-bulk', ['ids' => [$ids[0]], 'decision' => 'approve']), $role);
    }
    $ppic = client_as('PPIC');
    $page = $ppic->get('/purchase-orders', ['q' => 'BLK', 'per_page' => '100']);
    assert_status(200, $page);
    assert_contains('action="/purchase-orders/ppic-bulk"', $page->body);
    assert_contains('data-check-all="ids[]"', $page->body, 'pilih semua di halaman ini');
    foreach ($ids as $id) {
        assert_contains('name="ids[]" value="' . $id . '"', $page->body);
    }
    assert_not_contains('name="ids[]" value="' . $legacy . '"', $page->body, 'PO lama tanpa PPIC tidak bisa dicentang');
    assert_contains('<option value="100" selected', $page->body, 'pilihan jumlah per halaman');
    assert_contains('name="return" value="/purchase-orders?q=BLK&amp;per_page=100"', $page->body);

    // validasi: tanpa centang, tanpa keputusan, tolak tanpa alasan → tidak ada yang berubah
    assert_redirect($ppic->post('/purchase-orders/ppic-bulk', ['decision' => 'approve']), '/purchase-orders');
    assert_redirect($ppic->post('/purchase-orders/ppic-bulk', ['ids' => $ids, 'decision' => 'hapus']), '/purchase-orders');
    $noReason = $ppic->post('/purchase-orders/ppic-bulk', ['ids' => $ids, 'decision' => 'reject', 'ppic_note' => '  ', 'return' => '/purchase-orders?q=PT+Massal&ppic=Pending']);
    assert_redirect($noReason, '/purchase-orders?q=PT+Massal&ppic=Pending', 'pencarian berspasi tetap dipertahankan');
    assert_contains('Isi alasan', $ppic->get('/purchase-orders', ['q' => 'BLK'])->body);
    assert_redirect($ppic->post('/purchase-orders/ppic-bulk', ['ids' => range(1, 101), 'decision' => 'approve']), '/purchase-orders');
    assert_redirect($ppic->post('/purchase-orders/ppic-bulk', ['ids' => [(string) $ids[0]], 'decision' => 'approve', 'return' => 'https://evil.test/x']), '/purchase-orders', 'return eksternal diabaikan');
    assert_same('Approved', $ppicOf($ids[0])['ppic_status']);
    foreach ([$ids[1], $ids[2]] as $id) {
        assert_same('Pending', $ppicOf($id)['ppic_status']);
    }

    // PPIC: Bisa diproses untuk semua yang dicentang (yang sudah Approved & PO lama dilewati)
    $res = $ppic->post('/purchase-orders/ppic-bulk', ['ids' => [$ids[0], $ids[1], $ids[2], $legacy], 'decision' => 'approve', 'ppic_note' => '', 'return' => '/purchase-orders?q=BLK&per_page=100']);
    assert_redirect($res, '/purchase-orders?q=BLK&per_page=100', 'kembali ke halaman & filter yang sama');
    $flash = $ppic->get('/purchase-orders', ['q' => 'BLK', 'per_page' => '100'])->body;
    assert_contains('2 order ditandai BISA diproses', $flash);
    assert_contains('1 order dilewati karena sudah berstatus', $flash);
    assert_contains('1 PO lama dilewati', $flash);
    foreach ($ids as $id) {
        $row = $ppicOf($id);
        assert_same('Approved', $row['ppic_status']);
        assert_same('On Process', $row['status']);
    }
    assert_same(null, Database::fetchValue('SELECT ppic_status FROM purchase_orders WHERE id = :id', ['id' => $legacy]));
    assert_same(3, (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'ppic_approve' AND entity_id IN (" . implode(',', $ids) . ')'));

    // Admin: Tidak bisa diproses (alasan wajib) → order batal & jadwal delivery dibatalkan, pembuat dapat notifikasi
    $admin = client_as('Admin');
    $adminPage = $admin->get('/purchase-orders', ['q' => 'BLK']);
    assert_contains('action="/purchase-orders/ppic-bulk"', $adminPage->body, 'Admin juga bisa');
    assert_redirect($admin->post('/purchase-orders/ppic-bulk', ['ids' => [$ids[1], $ids[2]], 'decision' => 'reject', 'ppic_note' => 'Mesin blow molding penuh']), '/purchase-orders');
    foreach ([$ids[1], $ids[2]] as $id) {
        $row = $ppicOf($id);
        assert_same('Rejected', $row['ppic_status']);
        assert_same('Cancelled', $row['status']);
        assert_same('Mesin blow molding penuh', $row['ppic_note']);
        assert_same('Cancelled', Database::fetchValue('SELECT status FROM deliveries WHERE id = :id', ['id' => $row['schedule_delivery_id']]));
        assert_true((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE type = 'oef_ppic_result' AND entity_id = :id", ['id' => $id]));
    }
    assert_same('Approved', $ppicOf($ids[0])['ppic_status'], 'yang tidak dicentang tidak berubah');
    // semua sudah berstatus sama → tidak ada yang diubah
    $none = $admin->post('/purchase-orders/ppic-bulk', ['ids' => [$ids[1], $ids[2]], 'decision' => 'reject', 'ppic_note' => 'Ulang']);
    assert_redirect($none, '/purchase-orders');
    assert_contains('Tidak ada order yang diubah', $admin->get('/purchase-orders')->body);
    assert_same('Mesin blow molding penuh', $ppicOf($ids[1])['ppic_note']);

    // bersihkan agar test lain (laporan, dashboard) tidak terpengaruh
    $in = implode(',', array_merge($ids, [$legacy]));
    Database::query("UPDATE purchase_orders SET schedule_delivery_id = NULL WHERE id IN ({$in})");
    Database::query("DELETE FROM deliveries WHERE po_id IN ({$in})");
    Database::query("DELETE FROM po_lines WHERE po_id IN ({$in})");
    Database::query("DELETE FROM notifications WHERE entity_type = 'purchase_order' AND entity_id IN ({$in})");
    Database::query("DELETE FROM purchase_orders WHERE id IN ({$in})");
    Database::query("DELETE FROM products WHERE name LIKE 'Botol Massal %'");
    Database::query("DELETE FROM customers WHERE name = 'PT Massal Uji'");
});

group('Phase 4 · Delivery, Return & Outstanding');

test('delivery Delivered mengurangi outstanding, Scheduled tidak; status PO → Partial', function () {
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    $line1 = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    // Surat jalan hanya diisi PPIC
    $m = client_as('Marketing');
    assert_status(403, $m->get('/deliveries/create', ['po_line_id' => $line1]));
    assert_status(403, $m->post('/deliveries', ['po_line_id' => (string) $line1, 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-MKT', 'delivered_qty' => '1', 'status' => 'Delivered']));
    assert_false((bool) Database::fetchValue("SELECT 1 FROM deliveries WHERE sj_number = 'PIK-SJ-MKT'"));
    $c = client_as('PPIC');
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
    $c = client_as('PPIC');
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
    client_as('PPIC')->post('/deliveries', ['po_line_id' => (string) $lines[1], 'delivery_date' => today(), 'sj_number' => 'PIK-SJ-90004', 'delivered_qty' => '500', 'status' => 'Delivered']);
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]));
    $c = client_as('Marketing');
    // retur 150 di baris 1
    assert_status(200, $c->get('/returns/create', ['po_line_id' => $lines[0]]));
    $bad = $c->post('/returns', ['record_type' => 'Return', 'po_line_id' => (string) $lines[0], 'return_date' => today(), 'return_qty' => '150', 'reason' => 'Rusak', 'complaint_detail' => 'Pecah']);
    assert_status(422, $bad);
    assert_contains('Alasan tidak valid', $bad->body);
    $d1 = (int) Database::fetchValue("SELECT id FROM deliveries WHERE sj_number = 'PIK-SJ-90001'");
    $ok = $c->post('/returns', ['record_type' => 'Return', 'po_line_id' => (string) $lines[0], 'delivery_id' => (string) $d1, 'return_date' => today(), 'return_qty' => '150', 'reason' => 'Damage',
        'complaint_detail' => '150 botol pecah saat kirim', 'note' => 'Pecah saat kirim']);
    assert_redirect($ok, '/returns/');
    $ret = Database::fetch("SELECT * FROM returns WHERE complaint_detail = '150 botol pecah saat kirim'");
    assert_same('Open', $ret['complaint_status']);
    assert_same((int) $po['customer_id'], (int) $ret['customer_id'], 'customer diturunkan dari order');
    assert_same(['order_qty' => 1000, 'delivered_qty' => 1000, 'return_qty' => 150, 'outstanding_qty' => 150], PoLine::totals((int) $lines[0]));
    assert_same('Closed', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po['id']]), 'status Closed tidak diubah otomatis');
    // retur dari surat jalan PO lain ditolak
    $otherPo = Database::insert('purchase_orders', ['code' => 'PO-OPS0000002', 'po_number' => 'PO/OPS/002', 'customer_id' => Database::fetchValue("SELECT id FROM customers WHERE name='PT Operasi Uji'"), 'po_date' => today(), 'status' => 'Open']);
    $otherLine = Database::insert('po_lines', ['code' => 'POL-OPS0000002', 'po_id' => $otherPo, 'product_id' => Database::fetchValue("SELECT id FROM products WHERE code='PRD-OPS0000001'"), 'order_qty' => 10]);
    $wrong = $c->post('/returns', ['record_type' => 'Return', 'po_line_id' => (string) $otherLine, 'delivery_id' => (string) $d1, 'return_date' => today(), 'return_qty' => '1', 'reason' => 'Other', 'complaint_detail' => 'x']);
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
    assert_status(403, client_as('Marketing')->post('/deliveries/' . $legacy . '/link', ['po_line_id' => (string) $line]), 'hanya PPIC');
    $c = client_as('PPIC');
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
    // tambah baris baru (nama produk diketik manual) lalu hapus
    assert_redirect($c->post('/purchase-orders/' . $po['id'] . '/lines', ['product_name' => 'Pot Ops 50gr', 'order_qty' => '10']), '/purchase-orders/' . $po['id']);
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
    $c->post('/purchase-orders', ['order_number' => 'OEF-OPS-HAPUS', 'po_number' => 'PO/OPS/HAPUS', 'customer_name' => 'PT Operasi Uji', 'sales_name' => 'Admin', 'po_date' => today(), 'product_name' => 'Botol Ops 100ml',
        'product_spec' => 'x', 'order_qty' => '5', 'requested_date' => today()]);
    $tmp = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE po_number = 'PO/OPS/HAPUS'");
    $sched = (int) Database::fetchValue('SELECT schedule_delivery_id FROM purchase_orders WHERE id = :id', ['id' => $tmp]);
    assert_true($sched > 0, 'jadwal otomatis dibuat');
    assert_redirect($c->post('/purchase-orders/' . $tmp . '/delete'), '/purchase-orders');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM purchase_orders WHERE id = :id', ['id' => $tmp]));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM po_lines WHERE po_id = :id', ['id' => $tmp]));
    assert_false((bool) Database::fetchValue('SELECT 1 FROM deliveries WHERE id = :id', ['id' => $sched]), 'jadwal otomatis ikut terhapus');
});

test('list order: filter status berjalan & PPIC, pencarian produk, ringkasan', function () {
    $c = client_as('Management');
    $res = $c->get('/purchase-orders', ['status' => 'open', 'q' => 'Ops']);
    assert_status(200, $res);
    assert_contains('PO/OPS/002', $res->body);
    assert_not_contains('PO/OPS/001', $res->body, 'PO Closed tidak termasuk berjalan');
    assert_status(200, $c->get('/deliveries', ['link' => 'unlinked']));
    assert_status(200, $c->get('/returns'));
    $pending = $c->get('/purchase-orders', ['ppic' => 'Pending']);
    assert_status(200, $pending);
    assert_contains('PO/OPS/001', $pending->body);
    assert_not_contains('PO/OPS/002', $pending->body, 'PO lama tanpa PPIC tidak termasuk Pending');
});

test('otorisasi operations per role', function () {
    $sales = client_as('Sales');
    assert_status(200, $sales->get('/purchase-orders'), 'Sales boleh input OEF');
    assert_status(200, $sales->get('/purchase-orders/create'));
    assert_status(403, $sales->post('/deliveries', ['po_line_id' => '1', 'delivery_date' => today(), 'delivered_qty' => '1', 'status' => 'Delivered']));
    assert_status(200, $sales->get('/returns/create'));
    $ppic = client_as('PPIC');
    assert_status(200, $ppic->get('/purchase-orders'));
    assert_status(403, $ppic->get('/purchase-orders/create'), 'PPIC hanya mengonfirmasi, tidak membuat OEF');
    $po2 = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE po_number = 'PO/OPS/002'");
    assert_status(403, $ppic->post('/purchase-orders/' . $po2, ['customer_name' => 'X', 'po_date' => today(), 'status' => 'Open']));
    assert_status(403, $ppic->get('/customers'), 'PPIC tanpa menu Customers');
    assert_status(403, $ppic->get('/stock'), 'PPIC tanpa menu Stock');
    assert_status(403, $ppic->get('/returns'), 'PPIC tanpa menu Complaint');
    assert_status(403, $ppic->get('/lead-times'), 'PPIC tanpa menu Lead Time');
    assert_status(200, $ppic->get('/deliveries'));
    assert_status(200, $ppic->get('/deliveries/create'), 'PPIC mengisi surat jalan');
    $nav = $ppic->get('/');
    assert_contains('href="/deliveries"', $nav->body);
    assert_not_contains('href="/customers"', $nav->body);
    assert_status(403, client_as('Marketing')->get('/deliveries/create'));
    assert_status(403, client_as('Management')->get('/deliveries/create'));
    $viewer = client_as('Viewer');
    $po = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE po_number = 'PO/OPS/002'");
    assert_status(200, $viewer->get('/purchase-orders/' . $po));
    assert_status(403, $viewer->get('/purchase-orders/create'));
    assert_status(403, $viewer->post('/purchase-orders/' . $po . '/lines', ['product_id' => '1', 'order_qty' => '1']));
    assert_status(403, $viewer->post('/deliveries', ['po_line_id' => '1', 'delivery_date' => today(), 'delivered_qty' => '1', 'status' => 'Delivered']));
    assert_status(200, client_as('Management')->get('/purchase-orders/create'), 'Management punya akses PO');
    foreach (['Produksi', 'Gudang'] as $role) {
        assert_status(403, client_as($role)->get('/purchase-orders'), $role . ' tanpa OEF');
        assert_status(403, client_as($role)->get('/deliveries'), $role . ' tanpa Delivery');
    }
});

group('Phase 4 · Complaint & Return');

test('complaint tanpa retur: bukti gambar/PDF, validasi file, outstanding tidak berubah', function () {
    $cid = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT Operasi Uji'");
    $po = Database::fetch("SELECT * FROM purchase_orders WHERE po_number = 'PO/OPS/001'");
    $line = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $po['id']]);
    $before = PoLine::totals($line);
    $dir = sys_get_temp_dir() . '/pik-complaint-' . getmypid();
    @mkdir($dir);
    $png = $dir . '/foto-cacat.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $pdf = $dir . '/laporan-qc.pdf';
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $fake = $dir . '/bukan-gambar.png';
    file_put_contents($fake, '<?php echo 1; ?>');

    $c = client_as('Marketing');
    $form = $c->get('/returns/create', ['po_id' => $po['id']]);
    assert_status(200, $form);
    assert_contains('Bukti complaint', $form->body);
    assert_contains('Email QC', $form->body);
    // file palsu ditolak
    $bad = $c->upload('/returns', ['record_type' => 'Complaint', 'customer_id' => (string) $cid, 'po_line_id' => (string) $line, 'return_date' => today(), 'reason' => 'Quality Issue',
        'complaint_detail' => 'Warna cetak pudar'], ['evidence[0]' => $fake]);
    assert_status(422, $bad);
    assert_contains('isi file bukan PNG', $bad->body);
    // complaint valid + 2 bukti + email QC
    $ok = $c->upload('/returns', ['record_type' => 'Complaint', 'customer_id' => (string) $cid, 'po_line_id' => (string) $line, 'return_date' => today(), 'reason' => 'Quality Issue',
        'complaint_detail' => 'Warna cetak pudar', 'qc_email' => 'QC@Pik.test; qc2@pik.test'], ['evidence[0]' => $png, 'evidence[1]' => $pdf]);
    assert_redirect($ok, '/returns/');
    $r = Database::fetch("SELECT * FROM returns WHERE complaint_detail = 'Warna cetak pudar'");
    assert_same('Complaint', $r['record_type']);
    assert_same(null, $r['return_qty']);
    assert_same('qc@pik.test, qc2@pik.test', $r['qc_email']);
    assert_true(in_array($r['email_status'], ['sent', 'failed'], true), 'email QC dicoba dikirim');
    assert_same($before, PoLine::totals($line), 'complaint tanpa retur tidak mengubah outstanding');
    $atts = Database::fetchAll('SELECT * FROM complaint_attachments WHERE return_id = :r ORDER BY id', ['r' => $r['id']]);
    assert_same(2, count($atts));
    assert_same('image/png', $atts[0]['mime_type']);
    assert_same('application/pdf', $atts[1]['mime_type']);
    // file hanya bisa dibuka lewat aplikasi
    $img = $c->get('/returns/attachments/' . $atts[0]['id']);
    assert_status(200, $img);
    assert_same(file_get_contents($png), $img->body);
    $page = $c->get('/returns/' . $r['id']);
    assert_status(200, $page);
    assert_contains('foto-cacat.png', $page->body);
    assert_contains('Selesai', $page->body);
    assert_contains('Tidak selesai', $page->body);
    // hapus satu bukti
    assert_redirect($c->post('/returns/attachments/' . $atts[1]['id'] . '/delete'), '/returns/' . $r['id']);
    assert_same(1, (int) Database::fetchValue('SELECT COUNT(*) FROM complaint_attachments WHERE return_id = :r', ['r' => $r['id']]));
    // Viewer boleh lihat, tidak boleh hapus
    assert_status(403, client_as('Viewer')->post('/returns/attachments/' . $atts[0]['id'] . '/delete'));
    @unlink($png); @unlink($pdf); @unlink($fake); @rmdir($dir);
});

test('complaint: tombol Tidak selesai wajib alasan, Selesai, buka kembali; hasil masuk laporan', function () {
    $r = Database::fetch("SELECT * FROM returns WHERE complaint_detail = 'Warna cetak pudar'");
    $id = (int) $r['id'];
    $c = client_as('Marketing');
    assert_status(403, client_as('Sales')->post('/returns/' . $id . '/resolve', ['outcome' => 'resolved']), 'Sales tidak boleh menutup complaint');
    assert_redirect($c->post('/returns/' . $id . '/resolve', ['outcome' => 'unresolved', 'resolution_note' => '']), '/returns/' . $id);
    assert_same('Open', Database::fetchValue('SELECT complaint_status FROM returns WHERE id = :id', ['id' => $id]), 'tanpa alasan ditolak');
    $c->post('/returns/' . $id . '/resolve', ['outcome' => 'unresolved', 'resolution_note' => 'Customer menolak penggantian']);
    $row = Database::fetch('SELECT * FROM returns WHERE id = :id', ['id' => $id]);
    assert_same('Unresolved', $row['complaint_status']);
    assert_same('Customer menolak penggantian', $row['resolution_note']);
    assert_true($row['resolved_at'] !== null);
    $c->post('/returns/' . $id . '/resolve', ['outcome' => 'reopen']);
    assert_same('Open', Database::fetchValue('SELECT complaint_status FROM returns WHERE id = :id', ['id' => $id]));
    $c->post('/returns/' . $id . '/resolve', ['outcome' => 'resolved', 'resolution_note' => 'Diganti 200 pcs baru']);
    assert_same('Resolved', Database::fetchValue('SELECT complaint_status FROM returns WHERE id = :id', ['id' => $id]));

    $list = $c->get('/returns', ['status' => 'Resolved']);
    assert_status(200, $list);
    assert_contains($r['code'], $list->body);
    $report = client_as('Management')->get('/reports/complaint');
    assert_status(200, $report);
    assert_contains($r['code'], $report->body);
    assert_contains('Diganti 200 pcs baru', $report->body);
    assert_contains('Tidak selesai', $report->body);
    $csv = client_as('Management')->get('/reports/complaint/export', ['format' => 'csv']);
    assert_status(200, $csv);
    assert_contains('Warna cetak pudar', $csv->body);
});

test('kirim ulang email QC: tanpa email ditolak, email tidak valid ditolak saat simpan', function () {
    $cid = (string) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT Operasi Uji'");
    $c = client_as('Marketing');
    $res = $c->post('/returns', ['record_type' => 'Complaint', 'customer_id' => $cid, 'return_date' => today(), 'reason' => 'Other', 'complaint_detail' => 'Tanpa email QC', 'qc_email' => 'bukan-email']);
    assert_status(422, $res);
    assert_contains('Email tidak valid: bukan-email', $res->body);
    assert_redirect($c->post('/returns', ['record_type' => 'Complaint', 'customer_id' => $cid, 'return_date' => today(), 'reason' => 'Other', 'complaint_detail' => 'Tanpa email QC']), '/returns/');
    $id = (int) Database::fetchValue("SELECT id FROM returns WHERE complaint_detail = 'Tanpa email QC'");
    assert_redirect($c->post('/returns/' . $id . '/email'), '/returns/' . $id);
    assert_same(null, Database::fetchValue('SELECT email_status FROM returns WHERE id = :id', ['id' => $id]));
    // retur barang wajib order & qty
    $ret = $c->post('/returns', ['record_type' => 'Return', 'customer_id' => $cid, 'return_date' => today(), 'reason' => 'Damage', 'complaint_detail' => 'x']);
    assert_status(422, $ret);
    assert_contains('Order &amp; produk wajib diisi', $ret->body);
    assert_contains('Qty retur wajib diisi', $ret->body);
});
