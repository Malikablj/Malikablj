<?php
// Data contoh yang rapi & realistis untuk file desain Figma (database pik_design).
putenv('DB_DATABASE=pik_design');
$root = '/home/user/Malikablj/marketing.permataindokemas.com';
require $root . '/app/bootstrap.php';
require $root . '/tests/lib.php';
define('TEST_BASE_URL', 'http://127.0.0.1:18788');
use App\Helpers\Database as D;
function as_user(string $email): HttpClient { $c = new HttpClient(TEST_BASE_URL); $r = $c->login($email, 'PikDev2026!'); if ($r->status !== 302) { throw new RuntimeException("login $email gagal {$r->status}"); } $c->get('/'); return $c; }
$ok = function (HttpResponse $r, string $what) { if ($r->status >= 400) { preg_match_all('/invalid-feedback d-block">([^<]*)/', $r->body, $m); echo "!! $what → {$r->status} " . implode(' | ', $m[1]) . "\n"; } };
$uid = static fn (string $e): int => (int) D::fetchValue('SELECT id FROM users WHERE email = :e', ['e' => $e]);
$d = static fn (int $days): string => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));

$sales = as_user('sales@pik.test');
$mkt = as_user('marketing@pik.test');
$orders = [
    // [no, customer, sales client, product, spec, qty, po_date offset, requested offset, ship_to]
    ['OEF-2610-0118', 'PT Cantik Mandiri', $sales, 'Botol PET 100ml Bening', "PET bening, leher 24/410\nSablon 1 warna putih", 24000, -1, 9, 'Gudang PT Cantik Mandiri, Cikarang'],
    ['OEF-2610-0117', 'CV Glow Beauty', $mkt, 'Jar PP 30gr Putih', 'PP putih doff, tutup emas', 12000, -2, 6, 'CV Glow Beauty, Bandung'],
    ['OEF-2610-0116', 'PT Natura Kosmetika', $sales, 'Pump Lotion 24/410 Hitam', 'Pump hitam, dip tube 160 mm', 30000, -3, 5, 'PT Natura Kosmetika, Tangerang'],
    ['OEF-2610-0115', 'PT Sinar Herbal Nusantara', $mkt, 'Botol HDPE 250ml Natural', 'HDPE natural, tutup flip top', 18000, -4, 3, 'PT Sinar Herbal Nusantara, Bogor'],
    ['OEF-2610-0114', 'PT Derma Skin Lab', $sales, 'Tube 50ml Laminasi', 'Laminasi ABL, cetak 4 warna', 15000, -6, 8, 'PT Derma Skin Lab, Jakarta Barat'],
    ['OEF-2610-0113', 'PT Aroma Wangi Indonesia', $mkt, 'Botol Kaca 50ml Spray', 'Kaca bening, spray gold', 8000, -8, 4, 'PT Aroma Wangi Indonesia, Surabaya'],
    ['OEF-2610-0112', 'PT Cantik Mandiri', $sales, 'Tutup Flip Top 24mm Putih', 'PP putih glossy', 40000, -10, -2, 'Gudang PT Cantik Mandiri, Cikarang'],
    ['OEF-2610-0111', 'UD Berkah Jaya', $mkt, 'Pot Cream 50gr Akrilik', 'Akrilik double wall, tutup silver', 6000, -12, -3, 'UD Berkah Jaya, Semarang'],
    ['OEF-2610-0110', 'CV Mutiara Pack', $sales, 'Botol PET 60ml Amber', 'PET amber, tutup ulir 20/410', 20000, -15, -5, 'CV Mutiara Pack, Bekasi'],
    ['OEF-2610-0109', 'PT Natura Kosmetika', $sales, 'Botol PET 100ml Bening', 'PET bening polos', 25000, -18, -6, 'PT Natura Kosmetika, Tangerang'],
    ['OEF-2610-0108', 'CV Glow Beauty', $mkt, 'Tube 100ml Putih', 'PE putih, cetak 2 warna', 10000, -20, -8, 'CV Glow Beauty, Bandung'],
    ['OEF-2610-0107', 'PT Derma Skin Lab', $sales, 'Jar PP 30gr Putih', 'PP putih doff', 9000, -22, -10, 'PT Derma Skin Lab, Jakarta Barat'],
];
$ids = [];
foreach (array_reverse($orders) as [$no, $cust, $client, $prod, $spec, $qty, $po, $req, $ship]) {
    $name = $client === $sales ? 'Budi Santoso' : 'Rina Wulandari';
    $ok($client->post('/purchase-orders', ['order_number' => $no, 'customer_name' => $cust, 'sales_name' => $name, 'po_number' => 'PO/' . substr($no, 4), 'po_date' => $d($po),
        'product_name' => $prod, 'product_spec' => $spec, 'order_qty' => (string) $qty, 'requested_date' => $d($req), 'ship_to' => $ship, 'payment_term' => 'NET 30']), 'OEF ' . $no);
    $ids[$no] = (int) D::fetchValue('SELECT id FROM purchase_orders WHERE order_number = :n', ['n' => $no]);
}
// tanggal OEF dibuat sesuai po_date agar dashboard realistis
foreach ($orders as [$no, , , , , , $po]) {
    D::query('UPDATE purchase_orders SET created_at = :t WHERE id = :id', ['t' => $d($po) . ' 09:15:00', 'id' => $ids[$no]]);
}
$ppic = as_user('ppic@pik.test');
foreach (['OEF-2610-0107', 'OEF-2610-0108', 'OEF-2610-0109', 'OEF-2610-0110', 'OEF-2610-0111', 'OEF-2610-0112', 'OEF-2610-0113', 'OEF-2610-0114', 'OEF-2610-0115'] as $no) {
    $ok($ppic->post('/purchase-orders/' . $ids[$no] . '/ppic', ['decision' => 'approve', 'ppic_note' => 'Jadwal produksi sesuai permintaan']), 'approve ' . $no);
}
$ok($ppic->post('/purchase-orders/' . $ids['OEF-2610-0116'] . '/ppic', ['decision' => 'reject', 'ppic_note' => 'Material pump hitam kosong sampai akhir bulan']), 'reject');
// delivery: edit jadwal otomatis jadi Delivered / Partial
$deliver = function (string $no, string $status, int $qty, string $sj, int $dayOffset) use ($ppic, $ids, $d, $ok) {
    $po = D::fetch('SELECT schedule_delivery_id FROM purchase_orders WHERE id = :id', ['id' => $ids[$no]]);
    $line = (int) D::fetchValue('SELECT id FROM po_lines WHERE po_id = :p', ['p' => $ids[$no]]);
    $ok($ppic->post('/deliveries/' . $po['schedule_delivery_id'], ['po_line_id' => (string) $line, 'delivery_date' => $d($dayOffset), 'sj_number' => $sj, 'destination' => '',
        'delivered_qty' => (string) $qty, 'status' => $status, 'note' => '', 'attachment' => '']), 'delivery ' . $no);
};
$deliver('OEF-2610-0107', 'Delivered', 9000, 'SJ/PIK/2610/0201', -11);
$deliver('OEF-2610-0108', 'Delivered', 10000, 'SJ/PIK/2610/0204', -9);
$deliver('OEF-2610-0109', 'Partial', 15000, 'SJ/PIK/2610/0207', -7);
$deliver('OEF-2610-0110', 'Delivered', 20000, 'SJ/PIK/2610/0209', -5);
$deliver('OEF-2610-0111', 'Partial', 4000, 'SJ/PIK/2610/0211', -3);
$deliver('OEF-2610-0112', 'On Delivery', 40000, 'SJ/PIK/2610/0214', 0);

$admin = $uid('admin@pik.test'); $s = $uid('sales@pik.test'); $m = $uid('marketing@pik.test');
$cust = static fn (string $n): int => (int) D::fetchValue('SELECT id FROM customers WHERE name = :n', ['n' => $n]);
$n = 0; $code = static function (string $p) use (&$n): string { $n++; return $p . '-DSG' . str_pad((string) $n, 6, '0', STR_PAD_LEFT); };
// lengkapi customer
$info = ['PT Cantik Mandiri' => ['Kosmetik', 'Jl. Industri Raya No. 12, Cikarang'], 'CV Glow Beauty' => ['Skincare', 'Jl. Soekarno-Hatta No. 88, Bandung'], 'PT Natura Kosmetika' => ['Kosmetik', 'Kawasan Industri Jatake, Tangerang'],
    'PT Sinar Herbal Nusantara' => ['Herbal', 'Jl. Raya Bogor Km 30, Bogor'], 'PT Derma Skin Lab' => ['Skincare', 'Jl. Daan Mogot No. 5, Jakarta Barat'], 'PT Aroma Wangi Indonesia' => ['Parfum', 'Jl. Rungkut Industri II, Surabaya'],
    'UD Berkah Jaya' => ['Distributor', 'Jl. Kaligawe No. 21, Semarang'], 'CV Mutiara Pack' => ['Distributor', 'Jl. Raya Narogong, Bekasi']];
$i = 0;
foreach ($info as $name => [$ind, $addr]) {
    D::update('customers', ['industry' => $ind, 'address' => $addr, 'phone' => '021-89' . (110000 + $i * 731), 'email' => 'purchasing@' . strtolower(preg_replace('/[^a-z]/i', '', str_replace(['PT ', 'CV ', 'UD '], '', $name))) . '.co.id',
        'marketing_pic_id' => $i % 2 ? $m : $s, 'notes' => null], 'id = :id', ['id' => $cust($name)]);
    D::insert('contacts', ['code' => $code('CON'), 'customer_id' => $cust($name), 'name' => ['Siti Rahma', 'Andi Wijaya', 'Lina Kusuma', 'Fajar Nugroho', 'Ayu Pratiwi', 'Rudi Hartono', 'Dian Permata', 'Eko Prasetyo'][$i],
        'position' => ['Purchasing Manager', 'Owner', 'Procurement', 'Purchasing', 'R&D Manager', 'Purchasing', 'Owner', 'Purchasing'][$i], 'phone' => '0812' . (3400000 + $i * 9137), 'whatsapp' => '0812' . (3400000 + $i * 9137), 'is_primary' => 1, 'created_by' => $admin]);
    $i++;
}
D::insert('customers', ['code' => $code('CUS'), 'name' => 'PT Bumi Organik Lestari', 'status' => 'Potential', 'industry' => 'Herbal', 'address' => 'Jl. Gatot Subroto, Medan', 'marketing_pic_id' => $m, 'created_by' => $m]);
// leads
$leads = [
    ['Kemasan serum 30ml lini baru', 'PT Derma Skin Lab', 'Negotiation', 'High', 150000000, 50000, 5, $s],
    ['Botol kaca parfum 100ml', 'PT Aroma Wangi Indonesia', 'Quotation', 'Medium', 85000000, 20000, 12, $m],
    ['Jar krim wajah 50gr', 'PT Bumi Organik Lestari', 'Qualified', 'High', 120000000, 40000, 20, $m],
    ['Tube sunscreen 50ml', 'CV Glow Beauty', 'Contacted', 'Medium', 60000000, 30000, 25, $m],
    ['Botol sabun cair 500ml', 'PT Sinar Herbal Nusantara', 'New', 'Low', 45000000, 25000, 35, $s],
    ['Pump lotion hand body', 'PT Natura Kosmetika', 'Won', 'High', 210000000, 80000, -5, $s],
    ['Pot lip balm 10gr', 'UD Berkah Jaya', 'Lost', 'Low', 18000000, 15000, -15, $m],
];
foreach ($leads as [$t, $company, $st, $pr, $val, $q, $close, $pic]) {
    $lid = D::insert('leads', ['code' => $code('LED'), 'lead_name' => $t, 'company_name' => $company, 'customer_id' => $cust($company) ?: null, 'contact_name' => 'Purchasing', 'source' => 'Existing Customer',
        'product_interest' => $t, 'estimated_qty' => $q, 'potential_value' => $val, 'expected_close_date' => $d($close), 'status' => $st, 'priority' => $pr, 'pic_user_id' => $pic, 'last_contact' => $d(-2), 'created_by' => $pic]);
}
// aktivitas & follow up
$acts = [['Meeting', 'Presentasi sampel botol PET 100ml', 'PT Cantik Mandiri', -1], ['WhatsApp', 'Konfirmasi jadwal kirim OEF-2610-0112', 'PT Cantik Mandiri', 0], ['Visit', 'Kunjungan pabrik & diskusi desain jar', 'PT Bumi Organik Lestari', -2],
    ['Email', 'Kirim penawaran botol kaca parfum', 'PT Aroma Wangi Indonesia', -3], ['Phone Call', 'Follow up pembayaran DP', 'CV Mutiara Pack', -4], ['Sample', 'Kirim sampel tube sunscreen', 'CV Glow Beauty', -5]];
foreach ($acts as $k => [$type, $subj, $c, $day]) {
    D::insert('activities', ['code' => $code('ACT'), 'customer_id' => $cust($c) ?: null, 'activity_date' => $d($day) . ' ' . (9 + $k) . ':30:00', 'activity_type' => $type, 'pic_user_id' => $k % 2 ? $m : $s, 'subject' => $subj,
        'description' => 'Customer merespons positif, menunggu konfirmasi berikutnya.', 'created_by' => $k % 2 ? $m : $s]);
}
$fus = [['Phone Call', 'Konfirmasi PO lanjutan botol PET', 'PT Cantik Mandiri', 0, '10:00'], ['Meeting', 'Review desain label tube sunscreen', 'CV Glow Beauty', 0, '14:00'], ['WhatsApp', 'Tanya status sampel jar', 'PT Bumi Organik Lestari', -2, null],
    ['Email', 'Kirim revisi penawaran parfum', 'PT Aroma Wangi Indonesia', -1, null], ['Visit', 'Kunjungan rutin ke gudang customer', 'PT Natura Kosmetika', 2, '09:00'], ['Quotation', 'Penawaran botol sabun cair', 'PT Sinar Herbal Nusantara', 4, null]];
foreach ($fus as $k => [$type, $purpose, $c, $day, $time]) {
    D::insert('follow_up', ['code' => $code('FU'), 'customer_id' => $cust($c) ?: null, 'pic_user_id' => $k % 2 ? $m : $s, 'follow_up_date' => $d($day), 'follow_up_time' => $time, 'follow_up_type' => $type, 'purpose' => $purpose,
        'status' => $day < 0 ? 'Overdue' : 'Planned', 'created_by' => $k % 2 ? $m : $s]);
}
// complaint & return
$po109 = $ids['OEF-2610-0109'];
$line109 = (int) D::fetchValue('SELECT id FROM po_lines WHERE po_id = :p', ['p' => $po109]);
$dl109 = (int) D::fetchValue('SELECT id FROM deliveries WHERE po_id = :p LIMIT 1', ['p' => $po109]);
D::insert('returns', ['code' => $code('RET'), 'record_type' => 'Return', 'customer_id' => $cust('PT Natura Kosmetika'), 'po_id' => $po109, 'po_line_id' => $line109, 'delivery_id' => $dl109, 'product_id' => D::fetchValue('SELECT product_id FROM po_lines WHERE id = :l', ['l' => $line109]),
    'return_date' => $d(-2), 'sj_number' => 'SJ/PIK/2610/0207', 'return_qty' => 300, 'reason' => 'Damage', 'complaint_detail' => '300 botol penyok saat pengiriman', 'complaint_status' => 'Open', 'created_by' => $s]);
$po107 = $ids['OEF-2610-0107'];
D::insert('returns', ['code' => $code('RET'), 'record_type' => 'Complaint', 'customer_id' => $cust('PT Derma Skin Lab'), 'po_id' => $po107, 'po_line_id' => (int) D::fetchValue('SELECT id FROM po_lines WHERE po_id = :p', ['p' => $po107]),
    'return_date' => $d(-6), 'reason' => 'Quality Issue', 'complaint_detail' => 'Warna tutup jar sedikit berbeda dari sampel', 'complaint_status' => 'Resolved', 'resolution_note' => 'Disepakati diskon 3% untuk order berikutnya', 'resolved_at' => $d(-4) . ' 10:00:00', 'created_by' => $s]);
// stok (lewat form, dikelompokkan otomatis)
$prod = as_user('produksi@pik.test');
foreach ([['Botol PET 100ml Bening', 'FG', 18500, 'Gudang A'], ['Botol PET 100ml Bening', 'WIP', 6000, 'Line 2'], ['Jar PP 30gr Putih', 'FG', 7200, 'Gudang A'], ['Jar PP 30gr Putih', 'Ready', 3000, 'Siap kirim'],
          ['Tutup Flip Top 24mm Putih', 'FG', 52000, 'Gudang B'], ['Tube 50ml Laminasi', 'WIP', 9000, 'Line 4'], ['Botol HDPE 250ml Natural', 'FG', 12400, 'Gudang A'], ['Botol HDPE 250ml Natural', 'Reserved', 8000, 'OEF-2610-0115'],
          ['Botol Kaca 50ml Spray', 'Ready', 4000, 'Siap kirim'], ['Pot Cream 50gr Akrilik', 'FG', 2600, 'Gudang B']] as [$p, $type, $q, $st]) {
    $ok($prod->post('/stock', ['product_name' => $p, 'stock_type' => $type, 'quantity' => (string) $q, 'status' => $st, 'notes' => '']), 'stok ' . $p);
}
$pid = static fn (string $nm): ?int => ($v = D::fetchValue('SELECT id FROM products WHERE name = :n', ['n' => $nm])) ? (int) $v : null;
foreach ([['CV Maklon Jaya', 'Tube 50ml Laminasi', 'Komponen', 5000, 50, -2, 'SJ/MKJ/1021'], ['PT Presisi Mold', 'Tutup Flip Top 24mm Putih', 'Komponen', 20000, 0, -4, 'SJ/PRM/0877'],
          ['CV Maklon Jaya', 'Pump Lotion 24/410 Hitam', 'Komponen', 10000, 120, -7, 'SJ/MKJ/1015']] as $k => [$vendor, $item, $type, $q, $rej, $day, $sj]) {
    D::insert('inbound_maklon', ['code' => $code('INB'), 'vendor' => $vendor, 'receiver' => 'Yusuf Hidayat', 'actual_inbound_date' => $d($day), 'sj_date' => $d($day - 1), 'sj_number' => $sj, 'product_id' => $pid($item),
        'component_name' => $item, 'type' => $type, 'quantity' => $q, 'reject_qty' => $rej, 'total_in' => $q - $rej, 'created_by' => $uid('produksi@pik.test')]);
}
foreach ([['PT Polimer Kimia Nusantara', 'Biji Plastik PET Resin', 'Bahan baku', 'kg', '2500', '0', -1, 'Gudang Bahan Baku'], ['PT Warna Prima', 'Masterbatch Putih', 'Bahan baku', 'kg', '150', '0', -3, 'Gudang Bahan Baku'],
          ['CV Label Indah', 'Label 50x30 Glossy', 'Label', 'roll', '120', '4', -5, 'Rak Label'], ['PT Karton Jaya', 'Karton Box 40x30x30', 'Kemasan', 'pcs', '2000', '35', -8, 'Gudang Kemasan']] as [$sup, $item, $cat, $unit, $q, $rej, $day, $loc]) {
    D::insert('inbound_supplier', ['code' => $code('ISP'), 'supplier' => $sup, 'inbound_date' => $d($day), 'sj_number' => 'SJ/' . strtoupper(substr(str_replace(' ', '', $sup), 2, 3)) . '/' . (300 + $day * -7), 'po_reference' => 'PO-BELI/2610/' . (40 - $day),
        'item_name' => $item, 'category' => $cat, 'unit' => $unit, 'quantity' => $q, 'reject_qty' => $rej === '0' ? null : $rej, 'total_in' => (string) ((float) $q - (float) $rej), 'receiver' => 'Yusuf Hidayat', 'location' => $loc, 'created_by' => $uid('produksi@pik.test')]);
}
foreach ([['OEF-2610-0115', 18000, 3, 'On Process'], ['OEF-2610-0114', 15000, 8, 'Planned'], ['OEF-2610-0113', 8000, 4, 'On Process']] as [$no, $q, $day, $st]) {
    D::insert('leadtime', ['code' => $code('LT'), 'po_id' => $ids[$no], 'product_id' => D::fetchValue('SELECT product_id FROM po_lines WHERE po_id = :p', ['p' => $ids[$no]]), 'quantity' => $q, 'delivery_date' => $d($day), 'status' => $st, 'created_by' => $m]);
}
App\Services\Automation::run();
echo "OK\n";
