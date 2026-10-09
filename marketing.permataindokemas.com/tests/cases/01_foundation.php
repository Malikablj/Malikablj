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
    assert_same('500000', Number::parseDecimal('500.000'), 'titik + 3 digit = ribuan (format Indonesia)');
    assert_same('0.5', Number::parseDecimal('0.500'));
    assert_same('1846.85', Number::parseDecimal('1846.85'));
    assert_same('12.5', Number::parseDecimal('12,5'));
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
    assert_true(Permission::allows('Marketing', 'deliveries.view'));
    assert_false(Permission::allows('Marketing', 'deliveries.create'), 'Surat jalan hanya diisi PPIC');
    assert_true(Permission::allows('Sales', 'leads.edit'));
    assert_true(Permission::allows('Sales', 'purchase_orders.create'), 'Sales menginput OEF');
    assert_false(Permission::allows('Sales', 'purchase_orders.delete'));
    // hapus OEF massal khusus Admin (role dengan purchase_orders.* tidak ikut mendapatkannya)
    assert_true(Permission::allows('Admin', 'purchase_orders_bulk.delete'));
    foreach (['Marketing', 'Management', 'Sales', 'PPIC', 'Viewer'] as $role) {
        assert_false(Permission::allows($role, 'purchase_orders_bulk.delete'), $role . ' tanpa hapus massal');
    }
    assert_false(Permission::allows('Sales', 'deliveries.edit'));
    // PPIC: hanya tinjau OEF + menu Delivery (mengisi surat jalan)
    assert_true(Permission::allows('PPIC', 'purchase_orders.view'));
    assert_true(Permission::allows('PPIC', 'ppic.approve'));
    assert_false(Permission::allows('PPIC', 'purchase_orders.edit'));
    assert_true(Permission::allows('PPIC', 'deliveries.create'));
    assert_true(Permission::allows('PPIC', 'deliveries.edit'));
    foreach (['customers.view', 'products.view', 'stock.view', 'inbound.view', 'returns.view', 'leadtime.view', 'reports.view'] as $perm) {
        assert_false(Permission::allows('PPIC', $perm), 'PPIC tidak punya ' . $perm);
    }
    // Produksi (role Gudang digabung ke Produksi): Stock, Inbound Maklon, Inbound Supplier
    assert_true(Permission::allows('Produksi', 'stock.create'));
    assert_true(Permission::allows('Produksi', 'stock.edit'));
    assert_true(Permission::allows('Produksi', 'inbound.create'));
    assert_true(Permission::allows('Produksi', 'inbound_supplier.create'));
    assert_false(Permission::allows('Produksi', 'purchase_orders.view'));
    assert_false(Permission::allows('Produksi', 'deliveries.view'));
    assert_false(Permission::allows('Produksi', 'customers.view'));
    assert_false(Permission::allows('Gudang', 'stock.view'), 'role Gudang sudah dihapus');
    // Management & Viewer
    assert_true(Permission::allows('Management', 'reports.export'));
    assert_true(Permission::allows('Management', 'stock.view'));
    assert_false(Permission::allows('Management', 'stock.create'), 'stok diinput Produksi');
    assert_false(Permission::allows('Management', 'inbound.create'), 'inbound diinput Produksi');
    assert_true(Permission::allows('Management', 'inbound_supplier.view'));
    assert_false(Permission::allows('Management', 'deliveries.create'));
    assert_false(Permission::allows('Management', 'leads.view'));
    assert_true(Permission::allows('Viewer', 'customers.view'));
    assert_true(Permission::allows('Viewer', 'inbound_supplier.view'));
    assert_false(Permission::allows('Viewer', 'customers.create'));
    assert_false(Permission::allows('Viewer', 'reports.export'));
    assert_false(Permission::allows('Viewer', 'users.view'));
    // Menu Finance dihapus: tidak ada role non-Admin yang memilikinya
    foreach (Permission::ROLES as $role) {
        if ($role !== 'Admin') {
            assert_false(Permission::allows($role, 'finance.view'), $role . ' tanpa finance');
        }
    }
    assert_same(['Admin', 'Marketing', 'Sales', 'Management', 'PPIC', 'Produksi', 'Viewer'], Permission::ROLES);
    assert_false(Permission::allows(null, 'dashboard.view'));
});

test('business rule: outstanding = order - delivered + return', function () {
    assert_same(84, PoLine::outstanding(18810, 18726, 0));
    assert_same(4372, PoLine::outstanding(230000, 242932, 17304));
    assert_same(-1488, PoLine::outstanding(250000, 261181, 9693));
});

test('deteksi base path untuk berbagai cara deploy Apache', function () {
    assert_same('', App\Helpers\Request::detectBasePath('/index.php', '/login'), 'DocumentRoot = public/');
    assert_same('', App\Helpers\Request::detectBasePath('/public/index.php', '/customers?page=2'), 'proyek utuh di document root');
    assert_same('/pik', App\Helpers\Request::detectBasePath('/pik/public/index.php', '/pik/customers'), 'proyek di subfolder');
    assert_same('/pik', App\Helpers\Request::detectBasePath('/pik/public/index.php', '/pik/'), 'subfolder, halaman awal');
    assert_same('/pik/public', App\Helpers\Request::detectBasePath('/pik/public/index.php', '/pik/public/login'), 'URL memuat /public secara eksplisit');
    assert_same('/pik', App\Helpers\Request::detectBasePath('/pik/index.php', '/pik/login'), 'alias langsung ke public/');
});

test('e_wrap: tetap di-escape, titik potong baris hanya setelah "/"', function () {
    assert_same('OEF/<wbr>PIK/<wbr>0001', e_wrap('OEF/PIK/0001'));
    assert_same('&lt;b&gt;/<wbr>&quot;x&quot;', e_wrap('<b>/"x"'));
    assert_same('', e_wrap(null));
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

test('database kosong: semua halaman daftar & form terbuka tanpa error (Admin)', function () {
    $router = new App\Helpers\Router();
    (require APP_ROOT . '/app/routes.php')($router);
    $c = client_as('Admin');
    $checked = 0;
    foreach ($router->all() as $route) {
        if ($route['method'] !== 'GET' || str_contains($route['pattern'], '{') || in_array($route['permission'], ['guest'], true)) {
            continue;
        }
        assert_status(200, $c->get($route['pattern']), 'GET ' . $route['pattern']);
        $checked++;
    }
    assert_true($checked >= 25, "halaman yang dicek: {$checked}");
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

test('blokir login: Admin dapat notifikasi, user tampil Terblokir, Admin bisa membuka blokir', function () {
    $uid = create_user('Sales', 'blokir.qa@pik.test');
    Database::update('users', ['name' => 'Rina Terblokir'], 'id = :id', ['id' => $uid]); // nama unik (tidak dikenali sebagai sales di OEF)
    $adminId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'admin.qa@pik.test'");
    $c = new HttpClient(TEST_BASE_URL);
    for ($i = 0; $i < 4; $i++) {
        assert_contains('Email atau password salah', $c->login('blokir.qa@pik.test', 'SalahTerus' . $i)->body);
    }
    assert_false((bool) Database::fetchValue("SELECT 1 FROM notifications WHERE type = 'user_blocked' AND entity_id = :id", ['id' => $uid]), 'belum terblokir → belum ada notifikasi');
    $fifth = $c->login('blokir.qa@pik.test', 'SalahLagi5');
    assert_status(422, $fifth);
    assert_contains('diblokir 15 menit', $fifth->body, 'user langsung tahu login diblokir');
    $notif = Database::fetch("SELECT * FROM notifications WHERE type = 'user_blocked' AND user_id = :u AND entity_id = :id", ['u' => $adminId, 'id' => $uid]);
    assert_true($notif !== null, 'Admin aktif dapat notifikasi user terblokir');
    assert_contains('Rina Terblokir', (string) $notif['title']);
    assert_contains('blokir.qa@pik.test', (string) $notif['message']);
    assert_same('/users?status=blocked', $notif['link']);
    assert_same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.type = 'user_blocked' AND u.role <> 'Admin'"), 'hanya Admin');
    $locked = $c->login('blokir.qa@pik.test', 'Rahasia123');
    assert_contains('Terlalu banyak percobaan', $locked->body, 'password benar pun ditolak selama terblokir');
    assert_contains('hubungi Admin', $locked->body);
    $notifCount = (int) Database::fetchValue("SELECT COUNT(*) FROM notifications WHERE type = 'user_blocked'");
    // email yang tidak terdaftar: pesan sama (tidak membocorkan email), tanpa notifikasi
    for ($i = 0; $i < 5; $i++) {
        $res = $c->login('tidak.ada@pik.test', 'SalahTerus' . $i);
    }
    assert_contains('diblokir 15 menit', $res->body);
    assert_same($notifCount, (int) Database::fetchValue("SELECT COUNT(*) FROM notifications WHERE type = 'user_blocked'"));

    $admin = client_as('Admin');
    $list = $admin->get('/users');
    assert_contains('sedang terblokir', $list->body, 'peringatan di halaman Users');
    assert_contains('Terblokir s/d', $list->body);
    $blockedList = $admin->get('/users', ['status' => 'blocked']);
    assert_contains('blokir.qa@pik.test', $blockedList->body);
    assert_contains('action="/users/' . $uid . '/unblock"', $blockedList->body);
    assert_not_contains('viewer.qa@pik.test', $blockedList->body, 'filter hanya user terblokir');
    assert_not_contains('tidak.ada@pik.test', $blockedList->body);
    assert_contains('Login user ini sedang terblokir', $admin->get('/users/' . $uid . '/edit')->body);
    assert_contains('User terblokir', $admin->get('/notifications')->body);

    assert_status(403, client_as('Viewer')->post('/users/' . $uid . '/unblock'), 'hanya Admin');
    $res = $admin->post('/users/' . $uid . '/unblock', ['return' => '/users?status=blocked']);
    assert_redirect($res, '/users?status=blocked');
    assert_contains('sudah dibuka', $admin->get('/users')->body);
    assert_same(null, App\Helpers\Auth::blockedUntil('blokir.qa@pik.test'));
    assert_same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM notifications WHERE type = 'user_blocked' AND entity_id = :id AND is_read = 0", ['id' => $uid]), 'notifikasi ditandai selesai');
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'user_unblock' AND entity_id = :id", ['id' => $uid]));
    assert_redirect((new HttpClient(TEST_BASE_URL))->login('blokir.qa@pik.test', 'Rahasia123'), '/', 'setelah dibuka bisa login lagi');
    assert_redirect($admin->post('/users/' . $uid . '/unblock'), '/users');
    assert_contains('tidak sedang terblokir', $admin->get('/users')->body);

    // IP yang ikut terkunci (batas per IP) juga dibuka
    $old = date('Y-m-d H:i:s', time() - 60);
    for ($i = 0; $i < 20; $i++) {
        Database::insert('login_attempts', ['email' => $i < 5 ? 'blokir.qa@pik.test' : 'acak' . $i . '@pik.test', 'ip_address' => '10.9.9.9', 'success' => 0, 'attempted_at' => $old]);
    }
    Database::insert('login_attempts', ['email' => 'lain@pik.test', 'ip_address' => '10.8.8.8', 'success' => 0, 'attempted_at' => $old]);
    assert_true(App\Helpers\Auth::blockedUntil('blokir.qa@pik.test') !== null);
    App\Helpers\Auth::unblock('blokir.qa@pik.test');
    assert_same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM login_attempts WHERE ip_address = '10.9.9.9'"));
    assert_same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM login_attempts WHERE ip_address = '10.8.8.8'"), 'IP lain tidak tersentuh');
    Database::query("DELETE FROM login_attempts WHERE email IN ('tidak.ada@pik.test', 'lain@pik.test')");
    App\Models\User::deleteUser($uid);
});

test('route tidak dikenal → 404, method salah → 405', function () {
    $c = client_as('Admin');
    assert_status(404, $c->get('/tidak-ada-halaman-ini'));
    assert_status(405, $c->get('/logout'));
});

test('setiap route: controller & method ada, parameter URL cocok, permission valid', function () {
    $router = new App\Helpers\Router();
    (require APP_ROOT . '/app/routes.php')($router);
    $routes = $router->all();
    assert_true(count($routes) > 20, 'routes terdaftar');
    $seen = [];
    foreach ($routes as $route) {
        $key = $route['method'] . ' ' . $route['pattern'];
        assert_true(!isset($seen[$key]), 'route ganda: ' . $key);
        $seen[$key] = true;
        [$class, $action] = $route['handler'];
        assert_true(method_exists($class, $action), "method $class::$action ada");
        preg_match_all('/\{(\w+)\}/', $route['pattern'], $m);
        $params = array_map(static fn (ReflectionParameter $p) => $p->getName(), (new ReflectionMethod($class, $action))->getParameters());
        assert_same($m[1], $params, "parameter $key");
        $perm = $route['permission'];
        assert_true(in_array($perm, ['guest', 'auth'], true) || preg_match('/^[a-z_]+\.[a-z_]+$/', $perm) === 1, "permission $key");
        if ($route['method'] === 'POST') {
            assert_true($perm !== 'guest' || in_array($route['pattern'], ['/login', '/setup'], true), "POST tanpa login hanya login/setup: $key");
        }
    }
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
    foreach (['Marketing', 'Sales', 'Management', 'PPIC', 'Produksi', 'Viewer'] as $role) {
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

test('Admin menghapus user: data tetap ada, PIC dilepas, audit tetap mencatat nama; tidak bisa hapus diri sendiri', function () {
    $uid = create_user('Sales', 'hapus.qa@pik.test');
    Database::update('users', ['name' => 'Dodi Dihapus'], 'id = :id', ['id' => $uid]);
    $victim = new HttpClient(TEST_BASE_URL);
    assert_redirect($victim->login('hapus.qa@pik.test', 'Rahasia123'), '/');
    $cust = App\Models\Customer::create(['name' => 'PT PIC Dihapus', 'status' => 'Active', 'marketing_pic_id' => $uid]);
    App\Models\Notification::send($uid, 'system', 'Untuk user yang dihapus');
    $adminId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'admin.qa@pik.test'");

    $admin = client_as('Admin');
    $list = $admin->get('/users', ['q' => 'hapus.qa']);
    assert_contains('action="/users/' . $uid . '/delete"', $list->body);
    $self = $admin->get('/users', ['q' => 'admin.qa']);
    assert_not_contains('action="/users/' . $adminId . '/delete"', $self->body, 'tidak ada tombol hapus untuk akun sendiri');
    $edit = $admin->get('/users/' . $uid . '/edit');
    assert_contains('Hapus user', $edit->body);
    assert_contains('1 customer (PIC marketing)', $edit->body, 'Admin melihat data yang ditangani user');
    assert_not_contains('/users/' . $adminId . '/delete', $admin->get('/users/' . $adminId . '/edit')->body);

    foreach (['Marketing', 'Management', 'Viewer'] as $role) {
        assert_status(403, client_as($role)->post('/users/' . $uid . '/delete'), $role);
    }
    assert_redirect($admin->post('/users/' . $adminId . '/delete'), '/users/' . $adminId . '/edit');
    assert_true((bool) Database::fetchValue('SELECT 1 FROM users WHERE id = :id', ['id' => $adminId]), 'tidak bisa menghapus akun sendiri');

    assert_redirect($admin->post('/users/' . $uid . '/delete'), '/users');
    assert_contains('sudah dihapus', $admin->get('/users')->body);
    assert_false((bool) Database::fetchValue('SELECT 1 FROM users WHERE id = :id', ['id' => $uid]));
    assert_same(null, Database::fetchValue('SELECT marketing_pic_id FROM customers WHERE id = :id', ['id' => $cust]), 'customer tetap ada, PIC kosong');
    assert_false((bool) Database::fetchValue('SELECT 1 FROM notifications WHERE user_id = :id', ['id' => $uid]));
    assert_same('Dodi Dihapus', Database::fetchValue("SELECT user_name FROM audit_logs WHERE action = 'login' AND entity_type = 'user' AND entity_id = :id ORDER BY id DESC LIMIT 1", ['id' => $uid]), 'audit lama tetap menyimpan nama');
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'delete' AND entity_type = 'user' AND entity_id = :id", ['id' => $uid]));
    assert_redirect($victim->get('/'), '/login', 'sesi user yang dihapus langsung berakhir');
    assert_status(422, (new HttpClient(TEST_BASE_URL))->login('hapus.qa@pik.test', 'Rahasia123'));
    assert_status(404, $admin->post('/users/' . $uid . '/delete'));
    Database::query("DELETE FROM login_attempts WHERE email = 'hapus.qa@pik.test'");
    Database::delete('customers', 'id = :id', ['id' => $cust]);
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
    App\Models\Notification::markAllRead($adminId); // notifikasi dari test sebelumnya (mis. user terblokir)
    App\Models\Notification::send($adminId, 'system', 'Tes notifikasi admin', 'Pesan', '/profile', null, null, 'test-1');
    assert_false(App\Models\Notification::send($adminId, 'system', 'Duplikat', null, null, null, null, 'test-1'), 'dedupe');
    $nid = (int) Database::fetchValue("SELECT id FROM notifications WHERE user_id = :u AND dedupe_key = 'test-1'", ['u' => $adminId]);
    $viewer = client_as('Viewer');
    assert_status(404, $viewer->get('/notifications/' . $nid . '/open'), 'user lain tidak boleh membuka');
    $admin = client_as('Admin');
    assert_contains('Tes notifikasi admin', $admin->get('/notifications')->body);
    assert_redirect($admin->get('/notifications/' . $nid . '/open'), '/profile');
    assert_same(0, App\Models\Notification::unreadCount($adminId));
    assert_same(0, App\Models\Notification::unreadCount($viewerId));
});
