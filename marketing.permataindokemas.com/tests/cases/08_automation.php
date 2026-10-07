<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\Automation;

$autoSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $pic = create_user('Sales', 'pic.auto@pik.test');
    $mkt = create_user('Marketing', 'mkt.auto@pik.test');
    $inactive = create_user('Sales', 'nonaktif.auto@pik.test');
    // PPIC mengisi surat jalan → ikut diingatkan untuk delivery mendatang
    $ppic = create_user('PPIC', 'ppic.auto@pik.test');
    $admin = create_user('Admin', 'admin.auto@pik.test');
    Database::update('users', ['is_active' => 0], 'id = :id', ['id' => $inactive]);
    $cust = Customer::create(['name' => 'PT Otomasi Uji', 'status' => 'Active', 'marketing_pic_id' => $mkt]);
    return $ids = ['pic' => $pic, 'mkt' => $mkt, 'inactive' => $inactive, 'admin' => $admin, 'ppic' => $ppic, 'customer' => $cust];
};

/** @return list<array<string,mixed>> */
function notifications_for(int $userId, string $type): array
{
    return Database::fetchAll('SELECT * FROM notifications WHERE user_id = :u AND type = :t ORDER BY id', ['u' => $userId, 't' => $type]);
}

group('Phase 8 · Otomasi & notifikasi');

test('follow up hari ini & terlewat → notifikasi ke PIC, status Overdue, tanpa duplikat', function () use ($autoSetup) {
    $ids = $autoSetup();
    $today = today();
    $due = Database::insert('follow_up', ['code' => 'FUP-AUT0000001', 'customer_id' => $ids['customer'], 'pic_user_id' => $ids['pic'], 'follow_up_date' => $today,
        'follow_up_time' => '14:30:00', 'follow_up_type' => 'Phone Call', 'purpose' => 'Telepon otomasi', 'status' => 'Planned']);
    $late = Database::insert('follow_up', ['code' => 'FUP-AUT0000002', 'customer_id' => $ids['customer'], 'pic_user_id' => $ids['pic'], 'follow_up_date' => date('Y-m-d', strtotime($today . ' -2 days')),
        'follow_up_type' => 'Email', 'purpose' => 'Email terlewat otomasi', 'status' => 'Planned']);
    Database::insert('follow_up', ['code' => 'FUP-AUT0000003', 'customer_id' => $ids['customer'], 'pic_user_id' => $ids['pic'], 'follow_up_date' => $today,
        'follow_up_type' => 'Visit', 'purpose' => 'Tanpa pengingat', 'status' => 'Planned', 'reminder' => 0]);
    Database::insert('follow_up', ['code' => 'FUP-AUT0000004', 'customer_id' => $ids['customer'], 'pic_user_id' => $ids['inactive'], 'follow_up_date' => $today,
        'follow_up_type' => 'Visit', 'purpose' => 'PIC nonaktif', 'status' => 'Planned']);
    $r = Automation::run($today);
    assert_true($r !== null);
    $dueN = notifications_for($ids['pic'], 'followup_due');
    assert_same(1, count($dueN), 'hanya follow up dengan pengingat aktif');
    assert_contains('Telepon otomasi', $dueN[0]['title']);
    assert_contains('14:30', (string) $dueN[0]['message']);
    assert_same('/follow-ups/' . $due . '/edit', $dueN[0]['link']);
    $lateN = notifications_for($ids['pic'], 'followup_overdue');
    assert_same(1, count($lateN));
    assert_same('Overdue', Database::fetchValue('SELECT status FROM follow_up WHERE id = :id', ['id' => $late]));
    assert_same(0, count(Database::fetchAll('SELECT id FROM notifications WHERE user_id = :u', ['u' => $ids['inactive']])), 'user nonaktif tidak diberi notifikasi');
    // dijalankan ulang: tidak ada notifikasi ganda
    Automation::run($today);
    assert_same(1, count(notifications_for($ids['pic'], 'followup_due')));
    assert_same(1, count(notifications_for($ids['pic'], 'followup_overdue')));
});

test('delivery mendatang → PIC marketing customer & PPIC; invoice tidak lagi diproses; lead target closing → PIC lead', function () use ($autoSetup) {
    $ids = $autoSetup();
    $today = today();
    $prod = Database::insert('products', ['code' => 'PRD-AUT0000001', 'name' => 'Botol Otomasi', 'unit' => 'pcs']);
    $po = Database::insert('purchase_orders', ['code' => 'PO-AUT0000001', 'po_number' => 'PO/AUT/001', 'customer_id' => $ids['customer'], 'po_date' => $today, 'status' => 'Open']);
    $line = Database::insert('po_lines', ['code' => 'POL-AUT000001', 'po_id' => $po, 'product_id' => $prod, 'order_qty' => 100]);
    $soon = Database::insert('deliveries', ['code' => 'DEL-AUT000001', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prod, 'sj_number' => 'SJ-AUT-SOON',
        'delivery_date' => date('Y-m-d', strtotime($today . ' +1 day')), 'delivered_qty' => 50, 'status' => 'Scheduled']);
    $far = Database::insert('deliveries', ['code' => 'DEL-AUT000002', 'po_id' => $po, 'po_line_id' => $line, 'product_id' => $prod, 'sj_number' => 'SJ-AUT-FAR',
        'delivery_date' => date('Y-m-d', strtotime($today . ' +20 days')), 'delivered_qty' => 50, 'status' => 'Scheduled']);
    $inv = Database::insert('invoices_payments', ['code' => 'PAY-AUT0000001', 'customer_id' => $ids['customer'], 'invoice_number' => 'INV/AUT/001', 'invoice_date' => date('Y-m-d', strtotime($today . ' -40 days')),
        'due_date' => date('Y-m-d', strtotime($today . ' -10 days')), 'invoice_amount' => '750000.00', 'paid_amount' => '0.00', 'status' => 'Unpaid']);
    $lead = Database::insert('leads', ['code' => 'LEAD-AUT000001', 'customer_id' => $ids['customer'], 'lead_name' => 'Lead target closing', 'status' => 'Negotiation',
        'pic_user_id' => $ids['pic'], 'expected_close_date' => date('Y-m-d', strtotime($today . ' +2 days'))]);
    Database::insert('leads', ['code' => 'LEAD-AUT000002', 'customer_id' => $ids['customer'], 'lead_name' => 'Lead sudah Won', 'status' => 'Won',
        'pic_user_id' => $ids['pic'], 'expected_close_date' => $today]);

    Automation::run($today);
    $del = Database::fetchAll("SELECT * FROM notifications WHERE user_id = :u AND type = 'delivery_upcoming' AND entity_id = :id", ['u' => $ids['mkt'], 'id' => $soon]);
    assert_same(1, count($del), 'PIC marketing customer diingatkan');
    assert_contains('SJ-AUT-SOON', $del[0]['title']);
    assert_same('/deliveries/' . $soon, $del[0]['link']);
    assert_same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM notifications WHERE type = 'delivery_upcoming' AND entity_id = :id", ['id' => $far]), 'di luar rentang pengingat tidak diingatkan');
    assert_same(0, count(notifications_for($ids['pic'], 'delivery_upcoming')), 'Sales yang bukan PIC customer tidak diingatkan');
    $ppicN = Database::fetchAll("SELECT * FROM notifications WHERE user_id = :u AND type = 'delivery_upcoming' AND entity_id = :id", ['u' => $ids['ppic'], 'id' => $soon]);
    assert_same(1, count($ppicN), 'PPIC (pengisi surat jalan) diingatkan');
    assert_same(0, count(notifications_for($ids['admin'], 'delivery_upcoming')), 'Admin tidak ikut sebagai PPIC');

    // Menu Finance dihapus: status invoice lama tidak diubah & tidak ada notifikasi invoice
    assert_same('Unpaid', Database::fetchValue('SELECT status FROM invoices_payments WHERE id = :id', ['id' => $inv]));
    assert_same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM notifications WHERE type = 'invoice_overdue'"));

    $leadN = notifications_for($ids['pic'], 'lead_closing');
    assert_same(1, count($leadN), 'lead Won tidak diingatkan');
    assert_contains('Lead target closing', $leadN[0]['title']);
    assert_same('/leads/' . $lead, $leadN[0]['link']);
});

test('otomasi berjalan saat aplikasi dipakai, maksimal sekali per interval', function () use ($autoSetup) {
    $ids = $autoSetup();
    Database::insert('follow_up', ['code' => 'FUP-AUT0000010', 'customer_id' => $ids['customer'], 'pic_user_id' => $ids['pic'], 'follow_up_date' => today(),
        'follow_up_type' => 'WhatsApp', 'purpose' => 'Dipicu kunjungan halaman', 'status' => 'Planned']);
    Setting::set('automation_last_run', date('Y-m-d H:i:s', time() - 7200), false);
    Setting::set('automation_interval_minutes', '60', false);
    $c = client_as('Sales');
    $c->get('/');
    $n = Database::fetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND title LIKE '%Dipicu kunjungan halaman%'", ['u' => $ids['pic']]);
    assert_same(1, (int) $n, 'notifikasi dibuat oleh kunjungan halaman');
    Setting::flush();
    $last = Setting::get('automation_last_run');
    assert_true(strtotime((string) $last) >= time() - 120, 'waktu run terakhir diperbarui');
    assert_same(null, Automation::runIfDue(), 'belum lewat interval → tidak berjalan lagi');
    $picClient = new HttpClient(TEST_BASE_URL);
    assert_status(302, $picClient->login('pic.auto@pik.test', 'Rahasia123'));
    $bell = $picClient->get('/notifications');
    assert_contains('Dipicu kunjungan halaman', $bell->body, 'muncul di halaman notifikasi milik PIC');
    assert_not_contains('Dipicu kunjungan halaman', $c->get('/notifications')->body, 'tidak muncul untuk user lain');
});

test('lock mencegah dua otomasi berjalan bersamaan', function () {
    $other = new PDO(
        'mysql:host=' . App\Helpers\Env::get('DB_HOST', '127.0.0.1') . ';port=' . App\Helpers\Env::get('DB_PORT', '3306') . ';dbname=' . App\Helpers\Env::get('DB_DATABASE'),
        (string) App\Helpers\Env::get('DB_USERNAME'),
        (string) App\Helpers\Env::get('DB_PASSWORD')
    );
    assert_same('1', (string) $other->query("SELECT GET_LOCK('pik_automation', 0)")->fetchColumn());
    assert_same(null, Automation::run(), 'run kedua dilewati selama lock dipegang proses lain');
    $other->query("SELECT RELEASE_LOCK('pik_automation')");
    assert_true(Automation::run() !== null);
});

group('Phase 8 · Settings');

test('pengaturan: hanya Admin, validasi, tersimpan & dipakai; jalankan otomasi manual', function () {
    foreach (['Marketing', 'Sales', 'Management', 'PPIC', 'Produksi', 'Gudang', 'Viewer'] as $role) {
        assert_status(403, client_as($role)->get('/settings'), $role);
    }
    $c = client_as('Admin');
    $page = $c->get('/settings');
    assert_status(200, $page);
    assert_contains('Status otomasi', $page->body);
    assert_not_contains('invoice_default_due_days', $page->body, 'pengaturan keuangan dihapus');
    $bad = $c->post('/settings', ['company_name' => '', 'delivery_reminder_days' => '40', 'automation_interval_minutes' => '1', 'mail_transport' => 'mail']);
    assert_status(422, $bad);
    assert_contains('Nama perusahaan wajib diisi', $bad->body);
    assert_contains('Pengingat delivery maksimal 30', $bad->body);
    assert_contains('Interval otomasi minimal 5', $bad->body);
    assert_redirect($c->post('/settings', ['company_name' => 'PT Permata Indo Kemas', 'delivery_reminder_days' => '3', 'automation_interval_minutes' => '30', 'mail_transport' => 'mail']), '/settings');
    Setting::flush();
    assert_same('3', Setting::get('delivery_reminder_days'));
    assert_same(3, Setting::int('delivery_reminder_days'));
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE entity_type = 'setting' AND entity_label = 'delivery_reminder_days'"));
    $run = $c->post('/settings/automation/run');
    assert_redirect($run, '/settings');
    assert_contains('Otomasi selesai', $c->get('/settings')->body);
    assert_status(403, client_as('Viewer')->post('/settings/automation/run'));
    Setting::set('delivery_reminder_days', '2', false);
});

test('script cron dapat dijalankan dari command line', function () {
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/cron/automation.php') . ' --force 2>&1', $output, $code);
    assert_same(0, $code, implode("\n", $output));
    assert_contains('OK', implode("\n", $output));
});
