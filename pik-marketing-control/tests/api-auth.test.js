'use strict';

/**
 * Email + password sign-in (src/auth/PasswordAuth.gs, src/core/Crypto.gs) — the sign-in for a web app on a regular
 * Gmail account, where Google does not tell the app who the visitor is ('anonymous' in these tests).
 *
 * The hand-written SHA-256 / HMAC / PBKDF2 are checked against Node's crypto module; then the whole flow runs through
 * api(): initial password from the Admin, forced change, session tokens (tampering, expiry, revocation), role changes,
 * deactivation, the failed sign-in limit, the Admin reset, setupAdminAccount() for the first Admin, and that the
 * password hash never reaches a response or AUDIT_LOG. All data is invented.
 */

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { test } = require('node:test');

const { loadGasProject } = require('../tools/gas-emulator/load-gas');
const { OWNER, createApp } = require('./support/api-harness');

const hex = (bytes) => Buffer.from(bytes).toString('hex');
const utf8 = (text) => [...Buffer.from(text, 'utf8')];

const GINA = { name: 'Gina Gmail', email: 'gina.contoh@gmail.example', role: 'SALES' };
const INITIAL = 'Sementara123';
const NEW_PASSWORD = 'Rahasia2026';

/** App with a Sales user who has an initial password set by the Admin. */
function appWithPasswordUser() {
  const app = createApp();
  const gina = app.ok('admin', 'users.create', { data: GINA, password: INITIAL });
  return { app, gina };
}

function login(app, email, password) {
  return app.call('anonymous', 'auth.login', { email, password });
}

test('kriptografi: SHA-256, HMAC-SHA256, PBKDF2, UTF-8 dan base64url sama dengan modul crypto Node', () => {
  const { context } = loadGasProject();
  assert.equal(hex(context.sha256Bytes_(utf8('abc'))), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
  assert.equal(hex(context.sha256Bytes_([])), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
  // RFC 4231 test case 2 and a key longer than one block (test case 6).
  assert.equal(hex(context.hmacSha256Bytes_(utf8('Jefe'), utf8('what do ya want for nothing?'))),
    '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843');
  assert.equal(hex(context.hmacSha256Bytes_(new Array(131).fill(0xaa), utf8('Test Using Larger Than Block-Size Key - Hash Key First'))),
    '60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54');
  for (let n = 0; n < 150; n++) {
    const data = [...crypto.randomBytes(n * 2 % 300)];
    const key = [...crypto.randomBytes(n % 140)];
    assert.equal(hex(context.sha256Bytes_(data)), crypto.createHash('sha256').update(Buffer.from(data)).digest('hex'));
    assert.equal(hex(context.hmacSha256Bytes_(key, data)), crypto.createHmac('sha256', Buffer.from(key)).update(Buffer.from(data)).digest('hex'));
  }
  for (let n = 0; n < 25; n++) {
    const password = [...crypto.randomBytes(n * 3 % 70)];
    const salt = [...crypto.randomBytes(n % 33)];
    const iterations = 1 + n * 7;
    const length = 1 + (n * 5) % 70;
    assert.equal(hex(context.pbkdf2Sha256Bytes_(password, salt, iterations, length)),
      crypto.pbkdf2Sync(Buffer.from(password), Buffer.from(salt), iterations, length, 'sha256').toString('hex'));
  }
  for (const text of ['', 'abc', 'Kata sandi ✓', '😀', 'a\ud800b', '\udc00x']) {
    assert.equal(hex(context.utf8Bytes_(text)), Buffer.from(text, 'utf8').toString('hex'), JSON.stringify(text));
  }
  for (let n = 0; n < 60; n++) {
    const data = [...crypto.randomBytes(n)];
    assert.equal(context.bytesToBase64Url_(data), Buffer.from(data).toString('base64url'));
    assert.equal(hex(context.base64UrlToBytes_(Buffer.from(data).toString('base64url'))), hex(data));
  }
  assert.equal(context.base64UrlToBytes_('ab+c'), null);
  assert.equal(context.base64UrlToBytes_('abcde'), null);
  assert.equal(context.constantTimeEqual_('abc', 'abc'), true);
  assert.equal(context.constantTimeEqual_('abc', 'abd'), false);
  assert.equal(context.constantTimeEqual_('abc', 'abcd'), false);
});

test('password: disimpan sebagai hash PBKDF2 bergaram; hash tidak pernah ada di respons, metadata, atau AUDIT_LOG', () => {
  const { app, gina } = appWithPasswordUser();
  assert.equal(gina.has_password, true);
  assert.equal('password_hash' in gina, false, 'respons users.create');
  const stored = app.rows('USERS').find((row) => row.id === gina.id);
  assert.match(stored.password_hash, /^pbkdf2_sha256\$100000\$[A-Za-z0-9_-]{22}\$[A-Za-z0-9_-]{43}$/);
  assert.equal(stored.password_hash.includes(INITIAL), false);
  assert.equal(stored.must_change_password, true);

  // Same password, different salt → different hash.
  const other = app.ok('admin', 'users.create', { data: { name: 'Hana Gmail', email: 'hana.contoh@gmail.example', role: 'VIEWER' }, password: INITIAL });
  const otherStored = app.rows('USERS').find((row) => row.id === other.id);
  assert.notEqual(otherStored.password_hash, stored.password_hash);

  const list = app.ok('admin', 'users.list', { pageSize: 100 });
  list.items.forEach((user) => assert.equal('password_hash' in user, false, 'users.list'));
  assert.equal(list.items.find((user) => user.id === gina.id).has_password, true);
  assert.equal(list.items.find((user) => user.email === 'viewer@example.com').has_password, false);
  const session = app.ok('admin', 'session.get');
  assert.equal(session.schema.USERS.columns.some((column) => column.name === 'password_hash'), false, 'metadata form');
  assert.equal(JSON.stringify(session).includes('pbkdf2'), false);

  const createEntry = app.audit(gina.id).find((row) => row.action === 'CREATE');
  assert.equal(JSON.parse(createEntry.changes_json).password_hash, '[disembunyikan]');
  app.ok('admin', 'users.setPassword', { id: gina.id, password: 'Pengganti456' });
  const allAudit = JSON.stringify(app.rows('AUDIT_LOG'));
  assert.equal(allAudit.includes('pbkdf2_sha256'), false, 'tidak ada hash di AUDIT_LOG');
  const history = app.ok('admin', 'audit.history', { entityType: 'USERS', entityId: gina.id });
  assert.ok(history.items.some((entry) => entry.changes && entry.changes.password_hash), 'perubahan password tetap terlihat');
  assert.equal(JSON.stringify(history).includes('pbkdf2'), false);
});

test('login: tanpa identitas Google → AUTH_REQUIRED; email/password salah dijawab sama; password sementara wajib diganti dulu', () => {
  const { app } = appWithPasswordUser();
  const anonymous = app.fail('anonymous', 'session.get', {}, 'AUTH_REQUIRED');
  assert.match(anonymous.message, /email dan password/);

  const missing = login(app, '', '');
  assert.deepEqual(app.fieldErrors(missing.error).sort(), ['email:REQUIRED', 'password:REQUIRED']);
  const wrongPassword = login(app, GINA.email, 'Salah12345');
  const unknownEmail = login(app, 'tidak.ada@gmail.example', 'Salah12345');
  const googleOnly = login(app, 'viewer@example.com', 'Salah12345');
  for (const response of [wrongPassword, unknownEmail, googleOnly]) {
    assert.equal(response.success, false);
    assert.equal(response.error.code, 'VALIDATION_ERROR');
    assert.equal(response.error.message, 'Email atau password salah.', 'pesan tidak membedakan email terdaftar atau tidak');
  }

  const signedIn = app.ok('anonymous', 'auth.login', { email: '  GINA.Contoh@Gmail.example ', password: INITIAL });
  assert.match(signedIn.token, /^v1\.USR-[0-9A-F]{10}\.\d+\.\d+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/);
  assert.equal(signedIn.session.user.email, GINA.email);
  assert.equal(signedIn.session.user.signInMethod, 'password');
  assert.equal(signedIn.session.user.mustChangePassword, true);
  assert.ok(signedIn.session.user.lastLoginAt);

  assert.equal(app.okWithToken(signedIn.token, 'session.get').user.mustChangePassword, true, 'session.get boleh');
  app.failWithToken(signedIn.token, 'customers.list', {}, 'PASSWORD_CHANGE_REQUIRED');
  app.failWithToken(signedIn.token, 'dashboard.summary', {}, 'PASSWORD_CHANGE_REQUIRED');

  const wrongCurrent = app.failWithToken(signedIn.token, 'auth.changePassword', { currentPassword: 'bukan', newPassword: NEW_PASSWORD }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(wrongCurrent), ['currentPassword:RULE']);
  for (const [weak, message] of [['pendek1', /minimal 8/], ['tanpaangka', /huruf dan angka/], ['12345678', /huruf dan angka/], [INITIAL, /berbeda/]]) {
    const error = app.failWithToken(signedIn.token, 'auth.changePassword', { currentPassword: INITIAL, newPassword: weak }, 'VALIDATION_ERROR');
    assert.match(error.message, message, weak);
  }
  const changed = app.okWithToken(signedIn.token, 'auth.changePassword', { currentPassword: INITIAL, newPassword: NEW_PASSWORD });
  assert.equal(changed.user.mustChangePassword, false);
  const ended = app.failWithToken(signedIn.token, 'session.get', {}, 'AUTH_REQUIRED');
  assert.match(ended.message, /Sesi Anda sudah berakhir/, 'token lama berakhir setelah ganti password');

  assert.ok(app.okWithToken(changed.token, 'customers.list').items, 'Sales membaca customer');
  app.failWithToken(changed.token, 'deliveries.create', { data: {} }, 'FORBIDDEN');
  app.failWithToken(changed.token, 'invoices.list', {}, 'FORBIDDEN');
  assert.equal(login(app, GINA.email, INITIAL).success, false, 'password lama tidak berlaku');
  assert.equal(app.ok('anonymous', 'auth.login', { email: GINA.email, password: NEW_PASSWORD }).session.user.mustChangePassword, false);
});

test('token: dipalsukan, kedaluwarsa, rahasia diganti, role diubah, user dinonaktifkan', () => {
  const { app, gina } = appWithPasswordUser();
  app.ok('admin', 'users.setPassword', { id: gina.id, password: NEW_PASSWORD, mustChange: false });
  const { token } = app.ok('anonymous', 'auth.login', { email: GINA.email, password: NEW_PASSWORD });
  assert.equal(app.okWithToken(token, 'session.get').user.role, 'SALES');

  const parts = token.split('.');
  const adminId = app.users.admin.id;
  const forged = [parts[0], adminId, ...parts.slice(2)].join('.');
  app.failWithToken(forged, 'session.get', {}, 'AUTH_REQUIRED');
  const extended = [parts[0], parts[1], parts[2], String(Number(parts[3]) + 1e10), parts[4], parts[5]].join('.');
  app.failWithToken(extended, 'session.get', {}, 'AUTH_REQUIRED');
  app.failWithToken(`${token.slice(0, -1)}${token.endsWith('A') ? 'B' : 'A'}`, 'session.get', {}, 'AUTH_REQUIRED');
  app.failWithToken('bukan-token', 'session.get', {}, 'AUTH_REQUIRED');

  // The role always comes from USERS, not from the token.
  const current = app.rows('USERS').find((row) => row.id === gina.id);
  app.ok('admin', 'users.update', { id: gina.id, data: { role: 'MARKETING' }, expectedUpdatedAt: current.updated_at });
  assert.equal(app.okWithToken(token, 'session.get').user.role, 'MARKETING');

  app.ok('admin', 'users.archive', { id: gina.id });
  const archived = app.failWithToken(token, 'customers.list', {}, 'NOT_REGISTERED');
  assert.match(archived.message, /dinonaktifkan/);
  const archivedLogin = login(app, GINA.email, NEW_PASSWORD);
  assert.equal(archivedLogin.error.code, 'NOT_REGISTERED');
  app.ok('admin', 'users.restore', { id: gina.id });
  assert.ok(app.okWithToken(token, 'customers.list'), 'dipulihkan: sesi berlaku lagi');

  app.setNow(new Date(Date.parse(app.now()) + 31 * 24 * 3600 * 1000).toISOString());
  const expired = app.failWithToken(token, 'session.get', {}, 'AUTH_REQUIRED');
  assert.equal(expired.details.reason, 'SESSION_ENDED');

  const fresh = app.ok('anonymous', 'auth.login', { email: GINA.email, password: NEW_PASSWORD });
  assert.ok(app.env.properties.AUTH_TOKEN_SECRET, 'rahasia token di Script Properties');
  app.env.properties.AUTH_TOKEN_SECRET = 'rahasia-lain';
  app.failWithToken(fresh.token, 'session.get', {}, 'AUTH_REQUIRED');
});

test('batas percobaan: 5 kali salah mengunci email 15 menit (juga untuk password benar); berhasil menghapus hitungan', () => {
  const { app, gina } = appWithPasswordUser();
  app.ok('admin', 'users.setPassword', { id: gina.id, password: NEW_PASSWORD, mustChange: false });
  for (let i = 0; i < 4; i++) assert.equal(login(app, GINA.email, `Salah${i}xxxx`).error.code, 'VALIDATION_ERROR');
  assert.equal(login(app, GINA.email, NEW_PASSWORD).success, true, 'berhasil sebelum batas: hitungan dihapus');
  for (let i = 0; i < 5; i++) assert.equal(login(app, GINA.email, `Salah${i}xxxx`).error.code, 'VALIDATION_ERROR');
  const locked = login(app, GINA.email, NEW_PASSWORD);
  assert.equal(locked.error.code, 'TOO_MANY_ATTEMPTS');
  assert.match(locked.error.message, /Coba lagi dalam 15 menit/);
  assert.equal(login(app, 'tidak.ada@gmail.example', 'Salah1xxxx').error.code, 'VALIDATION_ERROR', 'email lain tidak terkunci');

  app.setNow(new Date(Date.parse(app.now()) + 16 * 60 * 1000).toISOString());
  assert.equal(login(app, GINA.email, NEW_PASSWORD).success, true, 'terbuka setelah 15 menit');

  for (let i = 0; i < 5; i++) login(app, GINA.email, `Salah${i}xxxx`);
  assert.equal(login(app, GINA.email, NEW_PASSWORD).error.code, 'TOO_MANY_ATTEMPTS');
  app.ok('admin', 'users.setPassword', { id: gina.id, password: 'Pengganti456', mustChange: false });
  assert.equal(login(app, GINA.email, 'Pengganti456').success, true, 'reset oleh Admin membuka kunci');
});

test('reset oleh Admin: sesi lama berakhir, wajib ganti; bukan untuk akun sendiri; hanya Admin', () => {
  const { app, gina } = appWithPasswordUser();
  app.ok('admin', 'users.setPassword', { id: gina.id, password: NEW_PASSWORD, mustChange: false });
  const { token } = app.ok('anonymous', 'auth.login', { email: GINA.email, password: NEW_PASSWORD });

  const reset = app.ok('admin', 'users.setPassword', { id: gina.id, password: 'Sementara789' });
  assert.equal(reset.must_change_password, true);
  assert.equal('password_hash' in reset, false);
  app.failWithToken(token, 'session.get', {}, 'AUTH_REQUIRED');
  const again = app.ok('anonymous', 'auth.login', { email: GINA.email, password: 'Sementara789' });
  assert.equal(again.session.user.mustChangePassword, true);

  const own = app.fail('admin', 'users.setPassword', { id: app.users.admin.id, password: NEW_PASSWORD }, 'VALIDATION_ERROR');
  assert.match(own.message, /Profil/);
  app.fail('viewer', 'users.setPassword', { id: gina.id, password: NEW_PASSWORD }, 'FORBIDDEN');
  app.fail('marketing', 'users.setPassword', { id: gina.id, password: NEW_PASSWORD }, 'FORBIDDEN');
  const weak = app.fail('admin', 'users.setPassword', { id: gina.id, password: 'lemah' }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(weak), ['password:RULE']);
  const weakCreate = app.fail('admin', 'users.create', { data: { name: 'Ika', email: 'ika@gmail.example', role: 'VIEWER' }, password: 'lemah' }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(weakCreate), ['password:RULE']);
  assert.equal(app.rows('USERS').some((row) => row.email === 'ika@gmail.example'), false, 'user tidak dibuat bila password ditolak');

  // A Google-identity user may add a password without a current one; their Google sign-in keeps working.
  const viewerPassword = app.ok('viewer', 'auth.changePassword', { newPassword: 'ViewerBaru1' });
  assert.ok(viewerPassword.token);
  assert.equal(app.ok('viewer', 'session.get').user.signInMethod, 'google');
  assert.equal(app.ok('anonymous', 'auth.login', { email: 'viewer@example.com', password: 'ViewerBaru1' }).session.user.role, 'VIEWER');
});

test('setupAdminAccount (editor): Admin pertama dengan password sementara di log; pemulihan; hanya pemilik', () => {
  const app = createApp({ bootstrap: false });
  const first = app.context.setupAdminAccount();
  assert.equal(first.email, OWNER);
  assert.equal(first.created, true);
  assert.equal('temporaryPassword' in first, false, 'password tidak dikembalikan, hanya di log');
  const passwordLine = app.env.logs.find((line) => line.startsWith('Password sementara'));
  const temporary = passwordLine.split(': ')[1].trim();
  assert.match(temporary, /^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/);
  assert.ok(app.env.logs.some((line) => line.includes(OWNER)));

  const signedIn = app.ok('anonymous', 'auth.login', { email: OWNER, password: temporary });
  assert.equal(signedIn.session.user.role, 'ADMIN');
  assert.equal(signedIn.session.user.mustChangePassword, true);
  const changed = app.okWithToken(signedIn.token, 'auth.changePassword', { currentPassword: temporary, newPassword: NEW_PASSWORD });
  const created = app.okWithToken(changed.token, 'users.create', { data: GINA, password: INITIAL });
  assert.equal(created.email, GINA.email);

  // Recovery: another Admin demotes and archives the owner's account; the owner runs it again from the editor.
  const ginaLogin = app.ok('anonymous', 'auth.login', { email: GINA.email, password: INITIAL });
  const gina = app.okWithToken(ginaLogin.token, 'auth.changePassword', { currentPassword: INITIAL, newPassword: 'GinaBaru123' });
  const ginaRecord = app.rows('USERS').find((row) => row.email === GINA.email);
  app.okWithToken(changed.token, 'users.update', { id: ginaRecord.id, data: { role: 'ADMIN' }, expectedUpdatedAt: ginaRecord.updated_at });
  const ownerRecord = app.rows('USERS').find((row) => row.email === OWNER);
  app.okWithToken(gina.token, 'users.update', { id: ownerRecord.id, data: { role: 'VIEWER' }, expectedUpdatedAt: ownerRecord.updated_at });
  app.okWithToken(gina.token, 'users.archive', { id: ownerRecord.id });
  app.failWithToken(changed.token, 'session.get', {}, 'NOT_REGISTERED');

  app.env.activeUserEmail = OWNER;
  app.env.logs.length = 0;
  const recovered = app.context.setupAdminAccount();
  assert.equal(recovered.created, false);
  const restored = app.rows('USERS').find((row) => row.email === OWNER);
  assert.deepEqual([restored.role, restored.is_active, restored.must_change_password], ['ADMIN', true, true]);
  const newTemporary = app.env.logs.find((line) => line.startsWith('Password sementara')).split(': ')[1].trim();
  assert.notEqual(newTemporary, temporary);
  app.failWithToken(changed.token, 'session.get', {}, 'AUTH_REQUIRED');
  assert.equal(login(app, OWNER, NEW_PASSWORD).success, false, 'password lama tidak berlaku');
  assert.equal(app.ok('anonymous', 'auth.login', { email: OWNER, password: newTemporary }).session.user.role, 'ADMIN');

  app.env.activeUserEmail = 'orang.lain@example.com';
  assert.throws(() => app.context.setupAdminAccount(), /tidak memiliki akses/);
  app.env.activeUserEmail = '';
  assert.throws(() => app.context.setupAdminAccount(), /Identitas pengguna tidak dapat dipastikan/);
});
