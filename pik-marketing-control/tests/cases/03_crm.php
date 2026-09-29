<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;
use App\Models\FollowUp;

group('Phase 3 · Business rule follow up');

test('overdue = tanggal < hari ini dan status bukan Done (Cancelled juga ditutup)', function () {
    $today = '2026-09-29';
    assert_true(FollowUp::isOverdue('2026-09-28', 'Planned', $today));
    assert_true(FollowUp::isOverdue('2026-09-01', 'Reschedule', $today));
    assert_true(FollowUp::isOverdue('2026-09-01', 'Overdue', $today));
    assert_false(FollowUp::isOverdue('2026-09-28', 'Done', $today));
    assert_false(FollowUp::isOverdue('2026-09-28', 'Cancelled', $today));
    assert_false(FollowUp::isOverdue('2026-09-29', 'Planned', $today), 'hari ini belum overdue');
    assert_false(FollowUp::isOverdue('2026-09-30', 'Planned', $today));
});

group('Phase 3 · Leads & Kanban');

$crmCustomer = static function (): int {
    $id = Database::fetchValue("SELECT id FROM customers WHERE name = 'PT CRM Uji'");
    return $id ? (int) $id : Customer::create(['name' => 'PT CRM Uji', 'status' => 'Active']);
};

test('validasi lead', function () use ($crmCustomer) {
    $crmCustomer();
    $c = client_as('Sales');
    assert_status(200, $c->get('/leads/create'));
    $res = $c->post('/leads', ['lead_name' => '', 'status' => 'Menang', 'priority' => 'Medium', 'potential_value' => '-5']);
    assert_status(422, $res);
    assert_contains('Nama lead wajib diisi', $res->body);
    assert_contains('Status tidak valid', $res->body);
    $res2 = $c->post('/leads', ['lead_name' => 'Tanpa customer', 'status' => 'New', 'priority' => 'Medium']);
    assert_status(422, $res2);
    assert_contains('Pilih customer, atau isi nama perusahaan prospek', $res2->body);
});

test('buat lead + notifikasi ke PIC lain + tampil di board', function () use ($crmCustomer) {
    $cid = $crmCustomer();
    $marketingId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'marketing.qa@pik.test'") ?: create_user('Marketing', 'marketing.qa@pik.test');
    $c = client_as('Sales');
    $res = $c->post('/leads', ['lead_name' => 'Botol serum 30ml launching', 'customer_id' => (string) $cid, 'status' => 'New', 'priority' => 'High',
        'potential_value' => '125000000', 'estimated_qty' => '50.000', 'expected_close_date' => date('Y-m-d', strtotime('+30 days')), 'pic_user_id' => (string) $marketingId]);
    assert_redirect($res, '/leads/');
    $lead = Database::fetch("SELECT * FROM leads WHERE lead_name = 'Botol serum 30ml launching'");
    assert_same(50000, (int) $lead['estimated_qty']);
    assert_same('125000000.00', (string) $lead['potential_value']);
    assert_true((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE user_id = :u AND type = 'lead_assigned'", ['u' => $marketingId]), 'PIC mendapat notifikasi');
    $board = $c->get('/leads');
    assert_status(200, $board);
    assert_contains('Botol serum 30ml launching', $board->body);
    assert_contains('data-kanban', $board->body);
});

test('Kanban: ubah status via AJAX (JSON) + audit + validasi', function () {
    $id = (int) Database::fetchValue("SELECT id FROM leads WHERE lead_name = 'Botol serum 30ml launching'");
    $c = client_as('Sales');
    $c->get('/leads');
    $ajax = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];
    $res = $c->post('/leads/' . $id . '/status', ['status' => 'Quotation'], true, $ajax);
    assert_status(200, $res);
    assert_same(true, $res->json()['ok'] ?? null);
    assert_same('Quotation', Database::fetchValue('SELECT status FROM leads WHERE id = :id', ['id' => $id]));
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'status_change' AND entity_id = :id", ['id' => $id]));
    $bad = $c->post('/leads/' . $id . '/status', ['status' => 'Hacked'], true, $ajax);
    assert_status(422, $bad);
    assert_same(false, $bad->json()['ok'] ?? null);
    // tanpa CSRF → 419 JSON
    $noCsrf = $c->post('/leads/' . $id . '/status', ['status' => 'Won'], false, $ajax);
    assert_status(419, $noCsrf);
    // Viewer tidak boleh mengubah status
    $viewer = client_as('Viewer');
    $viewer->get('/leads');
    assert_status(403, $viewer->post('/leads/' . $id . '/status', ['status' => 'Won'], true, $ajax));
    assert_same('Quotation', Database::fetchValue('SELECT status FROM leads WHERE id = :id', ['id' => $id]));
    // Form biasa (fallback tanpa JavaScript)
    assert_redirect($c->post('/leads/' . $id . '/status', ['status' => 'Negotiation', 'return' => '/leads']), '/leads');
    assert_same('Negotiation', Database::fetchValue('SELECT status FROM leads WHERE id = :id', ['id' => $id]));
});

test('list view: filter status aktif, sort nilai', function () {
    $c = client_as('Viewer');
    $res = $c->get('/leads/list', ['status' => 'open', 'sort' => 'value', 'dir' => 'desc']);
    assert_status(200, $res);
    assert_contains('Botol serum 30ml launching', $res->body);
    assert_not_contains('Buat Lead</a>', $res->body, 'Viewer tidak melihat tombol buat');
});

test('prospek → jadikan customer (customer + kontak dibuat, lead terhubung)', function () {
    $c = client_as('Marketing');
    $res = $c->post('/leads', ['lead_name' => 'Pot krim 50gr', 'company_name' => 'PT Prospek Baru Sejahtera', 'contact_name' => 'Ibu Sari',
        'phone' => '0811-2222-3333', 'status' => 'Qualified', 'priority' => 'Medium']);
    assert_redirect($res, '/leads/');
    $lid = (int) Database::fetchValue("SELECT id FROM leads WHERE lead_name = 'Pot krim 50gr'");
    assert_contains('Jadikan customer', $c->get('/leads/' . $lid)->body);
    assert_redirect($c->post('/leads/' . $lid . '/convert'), '/customers/');
    $cust = Database::fetch("SELECT * FROM customers WHERE name = 'PT Prospek Baru Sejahtera'");
    assert_true($cust !== null);
    assert_same('Potential', $cust['status']);
    assert_same((int) $cust['id'], (int) Database::fetchValue('SELECT customer_id FROM leads WHERE id = :id', ['id' => $lid]));
    assert_same('Ibu Sari', Database::fetchValue('SELECT name FROM contacts WHERE customer_id = :c AND is_primary = 1', ['c' => $cust['id']]));
    $again = $c->post('/leads/' . $lid . '/convert');
    assert_redirect($again, '/leads/' . $lid);
    assert_same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM customers WHERE name = 'PT Prospek Baru Sejahtera'"));
});

group('Phase 3 · Activities');

test('aktivitas untuk lead: customer otomatis dari lead, follow up otomatis, last contact terupdate', function () {
    $lid = (int) Database::fetchValue("SELECT id FROM leads WHERE lead_name = 'Botol serum 30ml launching'");
    $cid = (int) Database::fetchValue('SELECT customer_id FROM leads WHERE id = :id', ['id' => $lid]);
    $c = client_as('Sales');
    assert_status(200, $c->get('/activities/create', ['lead_id' => $lid]));
    $next = date('Y-m-d', strtotime('+3 days'));
    $res = $c->post('/activities', [
        'lead_id' => (string) $lid, 'activity_date' => date('Y-m-d') . 'T10:30', 'activity_type' => 'Quotation',
        'subject' => 'Kirim quotation v1', 'description' => 'Harga per pcs Rp 2.500', 'next_action' => 'Konfirmasi harga',
        'next_follow_up' => $next, 'create_follow_up' => '1', 'attachment' => 'https://drive.google.com/file/d/abc/view', 'return' => '/leads/' . $lid,
    ]);
    assert_redirect($res, '/leads/' . $lid);
    $act = Database::fetch("SELECT * FROM activities WHERE subject = 'Kirim quotation v1'");
    assert_same($cid, (int) $act['customer_id'], 'customer diisi dari lead');
    $fu = Database::fetch('SELECT * FROM follow_up WHERE activity_id = :a', ['a' => $act['id']]);
    assert_true($fu !== null, 'follow up otomatis dibuat');
    assert_same($next, $fu['follow_up_date']);
    assert_same('Konfirmasi harga', $fu['purpose']);
    assert_same('Planned', $fu['status']);
    $lead = Database::fetch('SELECT last_contact, next_follow_up FROM leads WHERE id = :id', ['id' => $lid]);
    assert_same(date('Y-m-d'), $lead['last_contact']);
    assert_same($next, $lead['next_follow_up']);
    $detail = $c->get('/customers/' . $cid, ['tab' => 'activities']);
    assert_contains('Kirim quotation v1', $detail->body);
});

test('validasi aktivitas: wajib customer/lead, lead milik customer lain, link berbahaya', function () use ($crmCustomer) {
    $c = client_as('Sales');
    $other = Customer::create(['name' => 'PT Lain Sendiri', 'status' => 'Active']);
    $lid = (int) Database::fetchValue("SELECT id FROM leads WHERE lead_name = 'Botol serum 30ml launching'");
    $r1 = $c->post('/activities', ['activity_date' => date('Y-m-d') . 'T09:00', 'activity_type' => 'Email', 'subject' => 'X']);
    assert_status(422, $r1);
    assert_contains('Pilih customer atau lead', $r1->body);
    $r2 = $c->post('/activities', ['customer_id' => (string) $other, 'lead_id' => (string) $lid, 'activity_date' => date('Y-m-d') . 'T09:00', 'activity_type' => 'Email', 'subject' => 'X']);
    assert_contains('Lead ini milik customer lain', $r2->body);
    $r3 = $c->post('/activities', ['customer_id' => (string) $other, 'activity_date' => date('Y-m-d') . 'T09:00', 'activity_type' => 'Hack', 'subject' => 'X', 'attachment' => 'javascript:alert(1)']);
    assert_contains('Jenis aktivitas tidak valid', $r3->body);
    assert_contains('link http/https', $r3->body);
});

test('edit & hapus aktivitas memperbarui data turunan lead', function () {
    $act = Database::fetch("SELECT * FROM activities WHERE subject = 'Kirim quotation v1'");
    $c = client_as('Sales');
    assert_status(200, $c->get('/activities/' . $act['id'] . '/edit'));
    $res = $c->post('/activities/' . $act['id'], ['lead_id' => (string) $act['lead_id'], 'customer_id' => (string) $act['customer_id'],
        'activity_date' => '2026-01-15T08:00', 'activity_type' => 'Quotation', 'subject' => 'Kirim quotation v1 (revisi)', 'create_follow_up' => '0']);
    assert_redirect($res, '/activities');
    assert_same('2026-01-15', Database::fetchValue('SELECT last_contact FROM leads WHERE id = :id', ['id' => $act['lead_id']]));
    $tmp = App\Models\Activity::saveActivity(null, ['customer_id' => $act['customer_id'], 'lead_id' => null, 'activity_date' => date('Y-m-d H:i:s'),
        'activity_type' => 'Other', 'pic_user_id' => null, 'subject' => 'Sementara', 'description' => null, 'next_action' => null, 'next_follow_up' => null, 'attachment' => null], false);
    assert_redirect($c->post('/activities/' . $tmp['id'] . '/delete'), '/activities');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM activities WHERE id = :id', ['id' => $tmp['id']]));
    $list = $c->get('/activities', ['type' => 'Quotation']);
    assert_contains('Kirim quotation v1 (revisi)', $list->body);
});

group('Phase 3 · Follow Up');

test('tab Today / Upcoming / Overdue / Completed sesuai aturan', function () {
    $cid = (int) Customer::create(['name' => 'PT Follow Up Uji', 'status' => 'Active']);
    $base = ['customer_id' => $cid, 'lead_id' => null, 'pic_user_id' => null, 'follow_up_type' => 'WhatsApp', 'follow_up_time' => null, 'result' => null, 'next_follow_up' => null, 'reminder' => 1, 'notes' => null];
    $today = date('Y-m-d');
    FollowUp::saveFollowUp(null, $base + ['follow_up_date' => date('Y-m-d', strtotime('-2 days')), 'purpose' => 'FU lewat', 'status' => 'Planned']);
    FollowUp::saveFollowUp(null, $base + ['follow_up_date' => $today, 'purpose' => 'FU hari ini', 'status' => 'Planned']);
    FollowUp::saveFollowUp(null, $base + ['follow_up_date' => date('Y-m-d', strtotime('+5 days')), 'purpose' => 'FU nanti', 'status' => 'Planned']);
    FollowUp::saveFollowUp(null, $base + ['follow_up_date' => date('Y-m-d', strtotime('-3 days')), 'purpose' => 'FU batal', 'status' => 'Cancelled']);
    FollowUp::saveFollowUp(null, $base + ['follow_up_date' => date('Y-m-d', strtotime('-4 days')), 'purpose' => 'FU beres', 'status' => 'Done']);
    $counts = FollowUp::tabCounts($today, ['customer_id' => $cid]);
    assert_same(['today' => 1, 'upcoming' => 1, 'overdue' => 1, 'completed' => 2, 'all' => 5], $counts);
    assert_same('Overdue', Database::fetchValue("SELECT status FROM follow_up WHERE purpose = 'FU lewat'"), 'status tersimpan Overdue');
    $c = client_as('Sales');
    $over = $c->get('/follow-ups', ['tab' => 'overdue', 'customer_id' => $cid]);
    assert_contains('FU lewat', $over->body);
    assert_not_contains('FU batal', $over->body);
    assert_contains('FU hari ini', $c->get('/follow-ups', ['tab' => 'today', 'customer_id' => $cid])->body);
    assert_contains('FU nanti', $c->get('/follow-ups', ['tab' => 'upcoming', 'customer_id' => $cid])->body);
    $done = $c->get('/follow-ups', ['tab' => 'completed', 'customer_id' => $cid]);
    assert_contains('FU beres', $done->body);
    assert_contains('FU batal', $done->body);
});

test('refreshOverdue otomatis & kembali aktif saat dijadwal ulang', function () {
    $id = (int) Database::fetchValue("SELECT id FROM follow_up WHERE purpose = 'FU nanti'");
    Database::update('follow_up', ['follow_up_date' => date('Y-m-d', strtotime('-1 day')), 'status' => 'Planned'], 'id = :id', ['id' => $id]);
    FollowUp::refreshOverdue(date('Y-m-d'));
    assert_same('Overdue', Database::fetchValue('SELECT status FROM follow_up WHERE id = :id', ['id' => $id]));
    $c = client_as('Sales');
    $bad = $c->post('/follow-ups/' . $id . '/reschedule', ['new_date' => date('Y-m-d', strtotime('-7 days'))]);
    assert_redirect($bad, '/follow-ups/' . $id . '/edit');
    $ok = $c->post('/follow-ups/' . $id . '/reschedule', ['new_date' => date('Y-m-d', strtotime('+2 days')), 'note' => 'Customer cuti']);
    assert_redirect($ok, '/follow-ups');
    $row = Database::fetch('SELECT status, follow_up_date, notes FROM follow_up WHERE id = :id', ['id' => $id]);
    assert_same('Reschedule', $row['status']);
    assert_contains('Customer cuti', (string) $row['notes']);
});

test('tandai selesai + follow up lanjutan', function () {
    $id = (int) Database::fetchValue("SELECT id FROM follow_up WHERE purpose = 'FU hari ini'");
    $c = client_as('Sales');
    assert_status(200, $c->get('/follow-ups/' . $id . '/edit'));
    $next = date('Y-m-d', strtotime('+7 days'));
    assert_redirect($c->post('/follow-ups/' . $id . '/done', ['result' => 'Customer minta sample', 'next_follow_up' => $next, 'next_purpose' => 'Kirim sample']), '/follow-ups');
    $row = Database::fetch('SELECT * FROM follow_up WHERE id = :id', ['id' => $id]);
    assert_same('Done', $row['status']);
    assert_true($row['completed_at'] !== null);
    assert_same('Customer minta sample', $row['result']);
    $new = Database::fetch("SELECT * FROM follow_up WHERE purpose = 'Kirim sample'");
    assert_same($next, $new['follow_up_date']);
    assert_same('Planned', $new['status']);
    assert_same((int) $row['customer_id'], (int) $new['customer_id']);
});

test('buat & edit follow up lewat form (status Overdue tidak bisa dipilih manual)', function () {
    $c = client_as('Marketing');
    $cid = (int) Database::fetchValue("SELECT id FROM customers WHERE name = 'PT Follow Up Uji'");
    assert_status(200, $c->get('/follow-ups/create', ['customer_id' => $cid]));
    $bad = $c->post('/follow-ups', ['customer_id' => (string) $cid, 'follow_up_date' => date('Y-m-d'), 'follow_up_type' => 'Email', 'purpose' => 'X', 'status' => 'Overdue']);
    assert_status(422, $bad);
    $res = $c->post('/follow-ups', ['customer_id' => (string) $cid, 'follow_up_date' => date('Y-m-d', strtotime('+1 day')), 'follow_up_time' => '14:30',
        'follow_up_type' => 'Visit', 'purpose' => 'Kunjungan pabrik', 'status' => 'Planned', 'reminder' => '1']);
    assert_redirect($res, '/follow-ups');
    $row = Database::fetch("SELECT * FROM follow_up WHERE purpose = 'Kunjungan pabrik'");
    assert_same('14:30:00', $row['follow_up_time']);
    // mundurkan tanggal ke masa lalu → otomatis Overdue
    $res2 = $c->post('/follow-ups/' . $row['id'], ['customer_id' => (string) $cid, 'follow_up_date' => date('Y-m-d', strtotime('-1 day')),
        'follow_up_type' => 'Visit', 'purpose' => 'Kunjungan pabrik', 'status' => 'Planned']);
    assert_redirect($res2, '/follow-ups');
    assert_same('Overdue', Database::fetchValue('SELECT status FROM follow_up WHERE id = :id', ['id' => $row['id']]));
    assert_redirect($c->post('/follow-ups/' . $row['id'] . '/delete'), '/follow-ups');
});

test('otorisasi CRM per role', function () {
    $mgmt = client_as('Management');
    assert_status(403, $mgmt->get('/leads'));
    assert_status(403, $mgmt->get('/follow-ups'));
    assert_status(403, $mgmt->get('/activities'));
    $viewer = client_as('Viewer');
    assert_status(200, $viewer->get('/follow-ups'));
    $id = (int) Database::fetchValue("SELECT id FROM follow_up WHERE purpose = 'Kirim sample'");
    assert_status(200, $viewer->get('/follow-ups/' . $id . '/edit'));
    assert_contains('Mode lihat saja', $viewer->get('/follow-ups/' . $id . '/edit')->body);
    assert_status(403, $viewer->post('/follow-ups/' . $id . '/done', ['result' => 'x']));
    assert_status(403, $viewer->get('/activities/create'));
    assert_status(403, $viewer->post('/leads', ['lead_name' => 'x', 'company_name' => 'y', 'status' => 'New', 'priority' => 'Low']));
    assert_status(403, client_as('Management')->get('/leads/create'));
});

test('hapus lead diblokir bila ada aktivitas; lead kosong boleh dihapus', function () {
    $c = client_as('Admin');
    $lid = (int) Database::fetchValue("SELECT id FROM leads WHERE lead_name = 'Botol serum 30ml launching'");
    assert_redirect($c->post('/leads/' . $lid . '/delete'), '/leads/' . $lid);
    assert_true((bool) Database::fetchValue('SELECT 1 FROM leads WHERE id = :id', ['id' => $lid]));
    $empty = App\Models\Lead::saveLead(null, ['lead_name' => 'Lead kosong', 'company_name' => 'PT Kosong', 'status' => 'New', 'priority' => 'Low']);
    assert_redirect($c->post('/leads/' . $empty . '/delete'), '/leads');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM leads WHERE id = :id', ['id' => $empty]));
});
