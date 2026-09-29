<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Number;
use App\Helpers\Permission;
use App\Helpers\SqlFile;
use App\Helpers\Validator;
use App\Models\PoLine;

group('Phase 1 · Unit: validator, angka, permission');

test('validator: required, email, max, in', function () {
    $v = Validator::make(['name' => '  ', 'email' => 'bukan-email', 'status' => 'X', 'note' => str_repeat('a', 11)], [
        'name' => 'required|string|max:10', 'email' => 'nullable|email', 'status' => 'required|in:Active,Inactive', 'note' => 'nullable|string|max:10',
    ]);
    assert_true($v->fails());
    $e = $v->errors();
    assert_contains('wajib diisi', $e['name']);
    assert_contains('email', $e['email']);
    assert_contains('tidak valid', $e['status']);
    assert_contains('maksimal 10', $e['note']);
});

test('validator: normalisasi (trim, kosong => null, cast integer)', function () {
    $v = Validator::make(['name' => '  PT Maju  ', 'phone' => '', 'qty' => '10.000', 'active' => null], [
        'name' => 'required|string', 'phone' => 'nullable|phone', 'qty' => 'required|integer|min:1', 'active' => 'boolean',
    ]);
    assert_false($v->fails(), json_encode($v->errors()));
    assert_same(['name' => 'PT Maju', 'phone' => null, 'qty' => 10000, 'active' => 0], $v->validated());
});

test('validator: integer & numeric menolak input tidak valid', function () {
    $v = Validator::make(['a' => '12abc', 'b' => '1,2,3', 'c' => '-5'], ['a' => 'integer', 'b' => 'numeric', 'c' => 'integer|min:1']);
    assert_same(['a', 'b', 'c'], array_keys($v->errors()));
});

test('validator: tanggal, datetime, jam', function () {
    $v = Validator::make(['d1' => '2026-02-30', 'd2' => '2026-09-29', 'dt' => '2026-09-29T14:05', 't' => '25:00'], [
        'd1' => 'date', 'd2' => 'date', 'dt' => 'datetime', 't' => 'time',
    ]);
    assert_same(['d1', 't'], array_keys($v->errors()));
    assert_same('2026-09-29 14:05:00', $v->validated()['dt']);
});

test('validator: URL hanya http/https (tolak javascript:)', function () {
    $v = Validator::make(['a' => 'javascript:alert(1)', 'b' => 'https://drive.google.com/file/d/x/view'], ['a' => 'url', 'b' => 'url']);
    assert_same(['a'], array_keys($v->errors()));
});

test('validator: after_or_equal', function () {
    $v = Validator::make(['start' => '2026-09-10', 'end' => '2026-09-01'], ['start' => 'date', 'end' => 'date|after_or_equal:start']);
    assert_true(isset($v->errors()['end']));
});

test('angka: parseDecimal mendukung format mesin & Indonesia', function () {
    assert_same('1250000.5', Number::parseDecimal('1250000.50'));
    assert_same('1250000.5', Number::parseDecimal('1.250.000,50'));
    assert_same('1250000', Number::parseDecimal('1.250.000'));
    assert_same('270.27', Number::parseDecimal('270,27'));
    assert_same('1250000.5', Number::parseDecimal('1,250,000.50'));
    assert_same(null, Number::parseDecimal('12a'));
});

test('angka: parseIndonesianText (koreksi migrasi) ketat', function () {
    assert_same('1846.85', Number::parseIndonesianText(' 1.846,85'));
    assert_same('1891.892', Number::parseIndonesianText('1.891,892'));
    assert_same('270.27', Number::parseIndonesianText('270,27'));
    assert_same('62162220', Number::parseIndonesianText(' 62.162.220,00 '));
    assert_same(null, Number::parseIndonesianText('1846.85'));
});

test('angka: format rupiah & qty', function () {
    assert_same('Rp 1.250.000', Number::money(1250000));
    assert_same('Rp 1.250.000,50', Number::money('1250000.50'));
    assert_same('18.810', Number::qty(18810));
    assert_same('—', Number::qty(null));
});

test('permission: matriks role sesuai PRD', function () {
    assert_true(Permission::allows('Admin', 'anything.at_all'));
    assert_true(Permission::allows('Marketing', 'purchase_orders.create'));
    assert_true(Permission::allows('Marketing', 'products.delete'));
    assert_false(Permission::allows('Marketing', 'stock.view'));
    assert_false(Permission::allows('Marketing', 'finance.view'));
    assert_true(Permission::allows('Sales', 'leads.edit'));
    assert_false(Permission::allows('Sales', 'purchase_orders.view'));
    assert_true(Permission::allows('Management', 'reports.financial'));
    assert_true(Permission::allows('Management', 'stock.view'));
    assert_false(Permission::allows('Management', 'leads.view'));
    assert_true(Permission::allows('Viewer', 'customers.view'));
    assert_false(Permission::allows('Viewer', 'customers.create'));
    assert_false(Permission::allows('Viewer', 'reports.financial'));
    assert_false(Permission::allows('Viewer', 'users.view'));
    assert_false(Permission::allows(null, 'dashboard.view'));
});

test('business rule: outstanding = order - delivered + return', function () {
    assert_same(84, PoLine::outstanding(18810, 18726, 0));
    assert_same(4372, PoLine::outstanding(230000, 242932, 17304));
    assert_same(-1488, PoLine::outstanding(250000, 261181, 9693));
});

test('SqlFile memecah statement dengan benar (komentar & string)', function () {
    $s = SqlFile::statements("-- komentar; tidak dieksekusi\nINSERT INTO t VALUES ('a;b');\n/* blok ; */ SELECT 1;");
    assert_same(["INSERT INTO t VALUES ('a;b')", 'SELECT 1'], $s);
});

group('Phase 1 · HTTP: setup, login, keamanan');

test('database kosong: /login diarahkan ke /setup, dashboard tidak crash setelah setup', function () {
    $c = new HttpClient(TEST_BASE_URL);
    assert_redirect($c->get('/'), '/login');
    assert_redirect($c->get('/login'), '/setup');
    $c->get('/setup');
    $res = $c->post('/setup', ['name' => 'Admin QA', 'email' => 'admin.qa@pik.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123']);
    assert_redirect($res, '/');
    $dash = $c->get('/');
    assert_status(200, $dash);
    assert_contains('Customer aktif', $dash->body);
    assert_contains('Database masih kosong', $dash->body);
    assert_status(404, (new HttpClient(TEST_BASE_URL))->get('/setup'), 'setup harus tertutup setelah ada user');
});

test('password disimpan sebagai hash (bukan plaintext)', function () {
    $hash = (string) Database::fetchValue("SELECT password_hash FROM users WHERE email = 'admin.qa@pik.test'");
    assert_true(str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2'), 'harus hash bcrypt/argon');
    assert_true(password_verify('Rahasia123', $hash));
});

test('login salah → 422 dan pesan umum; login benar → redirect', function () {
    $c = new HttpClient(TEST_BASE_URL);
    $bad = $c->login('admin.qa@pik.test', 'salah-password1');
    assert_status(422, $bad);
    assert_contains('Email atau password salah', $bad->body);
    assert_redirect($c->login('admin.qa@pik.test', 'Rahasia123'), '/');
});

test('CSRF: POST tanpa token ditolak 419', function () {
    $c = client_as('Admin');
    assert_status(419, $c->post('/logout', [], false));
    assert_status(419, $c->post('/logout', ['_token' => str_repeat('a', 64)], false));
});

test('session id diganti saat login (anti session fixation)', function () {
    $c = new HttpClient(TEST_BASE_URL);
    $first = $c->get('/login');
    $before = $first->headers['set-cookie'] ?? '';
    $res = $c->login('admin.qa@pik.test', 'Rahasia123');
    $after = $res->headers['set-cookie'] ?? '';
    assert_true($after !== '' && $after !== $before, 'cookie session baru harus dikirim saat login');
    assert_contains('HttpOnly', $after);
    assert_contains('SameSite=Lax', $after);
});

test('header keamanan dikirim', function () {
    $res = (new HttpClient(TEST_BASE_URL))->get('/login');
    assert_contains("default-src 'self'", $res->headers['content-security-policy'] ?? '');
    assert_same('DENY', $res->headers['x-frame-options'] ?? '');
    assert_same('nosniff', $res->headers['x-content-type-options'] ?? '');
});

test('brute force: 5x gagal → login diblokir sementara', function () {
    create_user('Viewer', 'locked.qa@pik.test');
    $c = new HttpClient(TEST_BASE_URL);
    for ($i = 0; $i < 5; $i++) {
        $c->login('locked.qa@pik.test', 'SalahTerus' . $i);
    }
    $res = $c->login('locked.qa@pik.test', 'Rahasia123');
    assert_status(422, $res);
    assert_contains('Terlalu banyak percobaan', $res->body);
});

test('route tidak dikenal → 404, method salah → 405', function () {
    $c = client_as('Admin');
    assert_status(404, $c->get('/tidak-ada-halaman-ini'));
    assert_status(405, $c->get('/logout'));
});

test('logout via POST mengakhiri sesi', function () {
    $c = client_as('Viewer');
    assert_status(200, $c->get('/'));
    assert_redirect($c->post('/logout'), '/login');
    assert_redirect($c->get('/'), '/login');
});

group('Phase 1 · HTTP: user management & otorisasi backend');

test('Admin membuat user; validasi email unik & password policy', function () {
    $c = client_as('Admin');
    assert_status(200, $c->get('/users/create'));
    $bad = $c->post('/users', ['name' => '', 'email' => 'admin.qa@pik.test', 'role' => 'Hacker', 'password' => '123', 'is_active' => '1']);
    assert_status(422, $bad);
    assert_contains('Nama wajib diisi', $bad->body);
    assert_contains('Email sudah digunakan', $bad->body);
    assert_contains('Role tidak valid', $bad->body);
    $ok = $c->post('/users', ['name' => 'Sinta Sales', 'email' => 'SINTA@pik.test', 'role' => 'Sales', 'password' => 'Mulai2026', 'is_active' => '1', 'must_change_password' => '1']);
    assert_redirect($ok, '/users');
    $row = Database::fetch("SELECT role, must_change_password FROM users WHERE email = 'sinta@pik.test'");
    assert_same('Sales', $row['role'] ?? null);
    assert_same(1, (int) $row['must_change_password']);
});

test('user baru wajib ganti password sebelum memakai aplikasi', function () {
    $c = new HttpClient(TEST_BASE_URL);
    assert_redirect($c->login('sinta@pik.test', 'Mulai2026'), '/');
    assert_redirect($c->get('/'), '/profile/password');
    assert_redirect($c->get('/notifications'), '/profile/password');
    $c->get('/profile/password');
    $bad = $c->post('/profile/password', ['current_password' => 'salah', 'password' => 'Baru2026x', 'password_confirmation' => 'Baru2026x']);
    assert_status(422, $bad);
    assert_redirect($c->post('/profile/password', ['current_password' => 'Mulai2026', 'password' => 'Baru2026x', 'password_confirmation' => 'Baru2026x']), '/');
    assert_status(200, $c->get('/'));
});

test('otorisasi backend: non-admin ditolak 403 di area admin (GET & POST)', function () {
    foreach (['Marketing', 'Sales', 'Management', 'Viewer'] as $role) {
        $c = client_as($role);
        assert_status(403, $c->get('/users'), "{$role} GET /users");
        assert_status(403, $c->get('/audit-log'), "{$role} GET /audit-log");
        assert_status(403, $c->post('/users', ['name' => 'X', 'email' => 'x' . $role . '@pik.test', 'role' => 'Admin', 'password' => 'Rahasia123']), "{$role} POST /users");
    }
    assert_false((bool) Database::fetchValue("SELECT 1 FROM users WHERE email LIKE 'x%@pik.test'"), 'user tidak boleh terbuat');
});

test('admin terakhir tidak bisa diturunkan / menonaktifkan diri sendiri', function () {
    $c = client_as('Admin');
    $adminId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'admin.qa@pik.test'");
    // nonaktifkan admin lain agar admin.qa jadi satu-satunya admin aktif
    Database::update('users', ['is_active' => 0], "role = 'Admin' AND id <> :id", ['id' => $adminId]);
    $res = $c->post('/users/' . $adminId, ['name' => 'Admin QA', 'email' => 'admin.qa@pik.test', 'role' => 'Viewer', 'is_active' => '1']);
    assert_status(422, $res);
    assert_contains('minimal satu Admin aktif', $res->body);
    Database::update('users', ['is_active' => 1], "role = 'Admin'", []);
});

test('user dinonaktifkan langsung kehilangan akses (sesi aktif ikut berakhir)', function () {
    $victim = client_as('Management');
    assert_status(200, $victim->get('/'));
    Database::update('users', ['is_active' => 0], 'email = :e', ['e' => 'management.qa@pik.test']);
    assert_redirect($victim->get('/'), '/login');
    $res = (new HttpClient(TEST_BASE_URL))->login('management.qa@pik.test', 'Rahasia123');
    assert_status(422, $res);
    Database::update('users', ['is_active' => 1], 'email = :e', ['e' => 'management.qa@pik.test']);
});

test('audit log mencatat login, create user, dan dapat dibuka Admin', function () {
    $actions = Database::fetchColumn('SELECT DISTINCT action FROM audit_logs');
    foreach (['login', 'login_failed', 'create', 'password_change'] as $a) {
        assert_true(in_array($a, $actions, true), "audit action {$a} tercatat");
    }
    $c = client_as('Admin');
    $list = $c->get('/audit-log', ['action' => 'create']);
    assert_status(200, $list);
    assert_contains('sinta@pik.test', $list->body);
    $id = (int) Database::fetchValue("SELECT id FROM audit_logs WHERE action = 'create' AND entity_type = 'user' ORDER BY id DESC LIMIT 1");
    assert_status(200, $c->get('/audit-log/' . $id));
});

test('notifikasi: hanya milik sendiri, tandai dibaca', function () {
    $adminId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'admin.qa@pik.test'");
    $viewerId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'viewer.qa@pik.test'");
    App\Models\Notification::send($adminId, 'system', 'Tes notifikasi admin', 'Pesan', '/profile', null, null, 'test-1');
    assert_false(App\Models\Notification::send($adminId, 'system', 'Duplikat', null, null, null, null, 'test-1'), 'dedupe');
    $nid = (int) Database::fetchValue('SELECT id FROM notifications WHERE user_id = :u', ['u' => $adminId]);
    $viewer = client_as('Viewer');
    assert_status(404, $viewer->get('/notifications/' . $nid . '/open'), 'user lain tidak boleh membuka');
    $admin = client_as('Admin');
    assert_contains('Tes notifikasi admin', $admin->get('/notifications')->body);
    assert_redirect($admin->get('/notifications/' . $nid . '/open'), '/profile');
    assert_same(0, App\Models\Notification::unreadCount($adminId));
    assert_same(0, App\Models\Notification::unreadCount($viewerId));
});
