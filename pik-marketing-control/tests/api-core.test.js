'use strict';

/**
 * Phase 04 backend — core: sign-in by Google account, first-Admin bootstrap, session payload, the authorization matrix
 * enforced for every route, the response envelope, write locking and batching, optimistic concurrency and the shared
 * list engine (search, filter, sort, page). Runs the real src/**.gs in the Apps Script emulator; all data is invented.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');

const { OWNER, TODAY, createApp, plain, seedOrder } = require('./support/api-harness');

const ROLES = ['admin', 'marketing', 'sales', 'management', 'viewer'];

test('login: akun tak terdaftar ditolak; pemilik skrip menjadi Admin pertama hanya bila belum ada Admin aktif', () => {
  const app = createApp({ bootstrap: false });
  const stranger = app.fail('stranger@example.com', 'session.get', {}, 'NOT_REGISTERED');
  assert.match(stranger.message, /belum terdaftar/);
  assert.equal(app.rows('USERS').length, 0, 'tamu tidak didaftarkan otomatis');

  // No Google identity (regular Gmail deployment): the browser must show the email + password form.
  app.env.activeUserEmail = '';
  const anonymous = app.context.api('session.get', {});
  assert.equal(anonymous.error.code, 'AUTH_REQUIRED');

  const session = app.ok(OWNER, 'session.login', {});
  assert.equal(session.user.role, 'ADMIN');
  assert.equal(session.user.email, OWNER);
  const [admin] = app.rows('USERS');
  assert.equal(admin.role, 'ADMIN');
  const bootstrapAudit = app.audit(admin.id).find((row) => row.action === 'CREATE');
  assert.match(bootstrapAudit.note, /Admin pertama/);

  // Another deployment owner cannot take over once an active Admin exists.
  app.env.effectiveUserEmail = 'other-owner@example.com';
  app.fail('other-owner@example.com', 'session.get', {}, 'NOT_REGISTERED');
  assert.equal(app.rows('USERS').length, 1);
});

test('login: role selalu dari USERS; user diarsipkan ditolak; last_login_at dicatat paling sering tiap 10 menit', () => {
  const app = createApp();
  const session = app.ok('viewer', 'session.login', { role: 'ADMIN', user: { role: 'ADMIN' } });
  assert.equal(session.user.role, 'VIEWER', 'role dari payload diabaikan');
  assert.ok(session.user.lastLoginAt);
  const loginsBefore = app.audit(app.users.viewer.id).filter((row) => row.action === 'UPDATE').length;
  app.ok('viewer', 'session.login', {});
  assert.equal(app.audit(app.users.viewer.id).filter((row) => row.action === 'UPDATE').length, loginsBefore, 'login ulang <10 menit tidak menulis');
  app.setNow('2026-09-28T03:30:00.000Z');
  app.ok('viewer', 'session.login', {});
  assert.equal(app.audit(app.users.viewer.id).filter((row) => row.action === 'UPDATE').length, loginsBefore + 1);

  app.ok('admin', 'users.archive', { id: app.users.viewer.id });
  const archived = app.fail('viewer', 'session.get', {}, 'NOT_REGISTERED');
  assert.match(archived.message, /dinonaktifkan/);
  app.ok('admin', 'users.restore', { id: app.users.viewer.id });
  app.ok('viewer', 'session.get', {});
});

test('sesi: izin per role sesuai matriks, enum dari sheet ENUMS, metadata form dari skema', () => {
  const app = createApp();
  const expected = {
    admin: { purchaseOrders: 'RW', deliveries: 'RW', finance: 'RW', users: 'RW', audit: 'R' },
    marketing: { customers: 'RW', purchaseOrders: 'RW', deliveries: 'R', finance: undefined, users: undefined },
    sales: { customers: 'RW', leads: 'RW', purchaseOrders: 'R', deliveries: 'R', finance: undefined },
    management: { customers: 'R', leads: 'R', finance: 'R', reports: 'R', settings: undefined },
    viewer: { dashboard: 'R', customers: 'R', purchaseOrders: 'R', finance: undefined, audit: undefined },
  };
  for (const role of ROLES) {
    const session = app.ok(role, 'session.get', {});
    for (const [module, access] of Object.entries(expected[role])) {
      assert.equal(session.permissions[module], access, `${role}.${module}`);
    }
    assert.deepEqual(session.enums.LEAD_STATUS.map((item) => item.value),
      ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTATION', 'NEGOTIATION', 'WON', 'LOST', 'DORMANT']);
    assert.equal(session.app.today, TODAY);
    assert.equal(session.app.timeZone, 'Asia/Jakarta');
    const name = session.schema.CUSTOMERS.columns.find((column) => column.name === 'name');
    assert.equal(name.required, true);
    assert.ok(!session.schema.AUDIT_LOG, 'tabel sistem tidak dikirim sebagai form');
  }
});

test('otorisasi: setiap route ditolak FORBIDDEN untuk role tanpa izin dan tidak pernah FORBIDDEN untuk role yang berizin', () => {
  const app = createApp();
  const routes = app.context.defineApiRoutes_();
  const matrix = JSON.parse(app.env.evaluate('JSON.stringify(MODULE_PERMISSIONS)'));
  let checked = 0;
  for (const action of Object.keys(routes)) {
    const permission = routes[action].permission;
    if (!permission) continue;
    const [module, access] = [permission[0], permission[1]];
    for (const role of ROLES) {
      const granted = matrix[module][role.toUpperCase()] || null;
      const allowed = access === 'RW' ? granted === 'RW' : granted !== null;
      const response = app.call(role, action, {});
      if (allowed) {
        assert.ok(response.success || response.error.code !== 'FORBIDDEN', `${role} seharusnya boleh ${action}`);
      } else {
        assert.equal(response.success, false, `${role} tidak boleh ${action}`);
        assert.equal(response.error.code, 'FORBIDDEN', `${role} ${action}: ${JSON.stringify(response.error)}`);
      }
      checked++;
    }
  }
  assert.ok(checked > 500, `semua route diperiksa (${checked})`);
  for (const table of app.context.getSchema_().tables) {
    if (table.kind !== 'data' || table.name === 'USERS') continue;
    assert.equal(app.rows(table.name).length, 0, `panggilan tanpa data tidak membuat record ${table.name}`);
  }
});

test('otorisasi: contoh dari PRD — Viewer/Management read-only, Sales tidak menulis PO/delivery, keuangan tersembunyi', () => {
  const app = createApp();
  const seed = seedOrder(app);
  app.fail('viewer', 'customers.create', { data: { name: 'X' } }, 'FORBIDDEN');
  app.fail('management', 'leads.create', { data: { name: 'X', customer_id: seed.customer.id } }, 'FORBIDDEN');
  app.ok('sales', 'customers.create', { data: { name: 'PT Sales Baru' } });
  app.fail('sales', 'purchaseOrders.update', { id: seed.po.id, data: { notes: 'x' } }, 'FORBIDDEN');
  app.fail('sales', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 1 } }, 'FORBIDDEN');
  app.fail('marketing', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 1 } }, 'FORBIDDEN');
  app.ok('marketing', 'purchaseOrders.update', { id: seed.po.id, data: { notes: 'Diperbarui marketing' } });
  app.fail('sales', 'invoices.list', {}, 'FORBIDDEN');
  app.ok('management', 'invoices.list', {});
  app.fail('management', 'invoices.create', { data: {} }, 'FORBIDDEN');
  app.fail('marketing', 'users.list', {}, 'FORBIDDEN');
  app.fail('management', 'audit.list', {}, 'FORBIDDEN');

  // Finance data is left out of shared screens for roles without finance access.
  assert.equal(app.ok('sales', 'customers.get', { id: seed.customer.id }).invoices, null);
  assert.equal(app.ok('sales', 'purchaseOrders.get', { id: seed.po.id }).invoices, null);
  assert.equal(app.ok('sales', 'dashboard.summary', {}).finance, null);
  assert.ok(app.ok('management', 'customers.get', { id: seed.customer.id }).invoices);
  assert.ok(app.ok('management', 'dashboard.summary', {}).finance);
});

test('envelope: validasi mengembalikan error per kolom; error tak terduga disamarkan; aksi tidak dikenal NOT_FOUND', () => {
  const app = createApp();
  const error = app.fail('admin', 'customers.create', { data: { email: 'bukan-email', website: 'ftp://x' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(error).sort(), ['email:TYPE', 'name:REQUIRED', 'website:TYPE'].sort());
  assert.equal(error.details.table, 'CUSTOMERS');

  const unknownField = app.fail('admin', 'customers.update', { id: 'CUS-0000000000', data: { name: 'X' } }, 'NOT_FOUND');
  assert.match(unknownField.message, /tidak ditemukan/);
  app.fail('admin', 'customers.list', { filters: { tidak_ada: 1 } }, 'VALIDATION_ERROR');
  app.fail('admin', 'customers.list', { sort: { field: 'password' } }, 'VALIDATION_ERROR');
  app.fail('admin', 'customers.create', 'bukan objek', 'VALIDATION_ERROR');

  // Fields the form must not set are ignored (whitelist) or rejected (system fields through generic writes).
  const customer = app.ok('admin', 'customers.create', { data: { name: 'PT Whitelist', id: 'CUS-AAAAAAAAAA', is_legacy: true, created_by: 'x' } });
  assert.notEqual(customer.id, 'CUS-AAAAAAAAAA');
  assert.equal(customer.is_legacy, false);
  assert.equal(customer.created_by, OWNER);

  const original = app.context.listCustomers_;
  app.context.listCustomers_ = () => { throw new TypeError('rahasia internal: sheet xyz'); };
  app.context.API_ROUTES_ = null;
  const internal = app.fail('admin', 'customers.list', {}, 'INTERNAL_ERROR');
  assert.ok(!internal.message.includes('rahasia'), 'detail teknis tidak bocor');
  app.context.listCustomers_ = original;
  app.context.API_ROUTES_ = null;
});

test('penulisan: semua di bawah LockService, batch setValues, audit mencatat pelaku; lock sibuk = LOCK_TIMEOUT tanpa menulis', () => {
  const app = createApp();
  app.env.resetCalls();
  const customer = app.ok('marketing', 'customers.create', { data: { name: 'PT Terkunci', industry: 'Kosmetik' } });
  const writes = app.mutatingCalls();
  assert.ok(writes.length > 0);
  assert.ok(writes.every((call) => call.locked), 'setiap penulisan terjadi saat lock dipegang');
  const setValues = writes.filter((call) => call.method === 'Range.setValues');
  assert.deepEqual(setValues.map((call) => call.sheet).sort(), ['AUDIT_LOG', 'CUSTOMERS'], 'satu setValues data + satu audit');

  const audit = app.audit(customer.id);
  assert.equal(audit.length, 1);
  assert.equal(audit[0].action, 'CREATE');
  assert.equal(audit[0].actor_email, 'marketing@example.com');
  assert.equal(customer.owner_user_id, app.users.marketing.id, 'PIC default = user aktif');

  app.env.lockAvailable = false;
  app.env.resetCalls();
  app.fail('marketing', 'customers.update', { id: customer.id, data: { industry: 'Farmasi' } }, 'LOCK_TIMEOUT');
  assert.equal(app.mutatingCalls().length, 0);
  app.env.lockAvailable = true;
  assert.equal(app.ok('viewer', 'customers.get', { id: customer.id }).customer.industry, 'Kosmetik', 'baca tidak butuh lock');
});

test('konkurensi: update dengan updated_at lama ditolak CONFLICT; perubahan tanpa isi baru tidak menulis', () => {
  const app = createApp();
  const customer = app.ok('sales', 'customers.create', { data: { name: 'PT Versi' } });
  const first = app.ok('sales', 'customers.update', { id: customer.id, expectedUpdatedAt: customer.updated_at, data: { phone: '021-555' } });
  const conflict = app.fail('marketing', 'customers.update', { id: customer.id, expectedUpdatedAt: customer.updated_at, data: { phone: '021-777' } }, 'CONFLICT');
  assert.equal(conflict.details.updatedAt, first.updated_at);
  app.env.resetCalls();
  const same = app.ok('sales', 'customers.update', { id: customer.id, expectedUpdatedAt: first.updated_at, data: { phone: '021-555' } });
  assert.equal(same.updated_at, first.updated_at);
  assert.equal(app.mutatingCalls().length, 0);
});

test('daftar: pencarian, filter, urutan, halaman, arsip opt-in; batas halaman dari SETTINGS', () => {
  const app = createApp();
  const names = ['PT Alfa Plastik', 'PT Beta Kemasan', 'CV Gamma Botol', 'PT Delta Plastik', 'PT Epsilon Kaca'];
  const created = names.map((name, index) => app.ok('admin', 'customers.create', {
    data: { name, status: index % 2 === 0 ? 'ACTIVE' : 'POTENTIAL', industry: index < 3 ? 'Kosmetik' : 'Farmasi' },
  }));
  let page = app.ok('viewer', 'customers.list', { search: 'plastik' });
  assert.deepEqual(page.items.map((item) => item.name), ['PT Alfa Plastik', 'PT Delta Plastik']);
  page = app.ok('viewer', 'customers.list', { search: 'pt  plastik alfa' });
  assert.deepEqual(page.items.map((item) => item.name), ['PT Alfa Plastik'], 'semua kata harus cocok');
  page = app.ok('viewer', 'customers.list', { filters: { status: 'ACTIVE' }, sort: { field: 'name', direction: 'desc' } });
  assert.deepEqual(page.items.map((item) => item.name), ['PT Epsilon Kaca', 'PT Alfa Plastik', 'CV Gamma Botol']);
  page = app.ok('viewer', 'customers.list', { filters: { status: ['ACTIVE', 'POTENTIAL'], industry: 'Farmasi' } });
  assert.equal(page.total, 2);
  page = app.ok('viewer', 'customers.list', { pageSize: 2, page: 2 });
  assert.deepEqual([page.total, page.page, page.pageSize, page.pageCount, page.items.length], [5, 2, 2, 3, 2]);
  page = app.ok('viewer', 'customers.list', { pageSize: 2, page: 99 });
  assert.equal(page.page, 3, 'halaman di luar jangkauan dijepit ke halaman terakhir');

  // Empty values stay last in both directions.
  const withoutIndustry = app.ok('admin', 'customers.create', { data: { name: 'PT Tanpa Industri' } });
  const desc = app.ok('viewer', 'customers.list', { sort: { field: 'industry', direction: 'desc' } }).items.map((item) => item.id);
  const asc = app.ok('viewer', 'customers.list', { sort: { field: 'industry', direction: 'asc' } }).items.map((item) => item.id);
  assert.equal(desc[desc.length - 1], withoutIndustry.id, 'kosong di akhir (menurun)');
  assert.equal(asc[asc.length - 1], withoutIndustry.id, 'kosong di akhir (menaik)');
  app.ok('admin', 'customers.archive', { id: withoutIndustry.id });

  app.ok('admin', 'customers.archive', { id: created[0].id });
  assert.equal(app.ok('viewer', 'customers.list', {}).total, 4, 'arsip tidak tampil secara default');
  assert.equal(app.ok('viewer', 'customers.list', { includeInactive: true }).total, 6, 'termasuk 2 arsip');

  app.fail('admin', 'settings.update', { key: 'MAX_PAGE_SIZE', value: '20' }, 'VALIDATION_ERROR'); // below DEFAULT_PAGE_SIZE 25
  app.ok('admin', 'settings.update', { key: 'DEFAULT_PAGE_SIZE', value: '5' });
  app.ok('admin', 'settings.update', { key: 'MAX_PAGE_SIZE', value: '20' });
  for (let i = 0; i < 20; i++) app.ok('admin', 'customers.create', { data: { name: `PT Massal ${String(i).padStart(2, '0')}` } });
  assert.equal(app.ok('viewer', 'customers.list', {}).pageSize, 5);
  assert.equal(app.ok('viewer', 'customers.list', { pageSize: 1000 }).pageSize, 20);
});

test('pilihan (picker) dan pencarian global hanya menampilkan modul yang boleh dibaca', () => {
  const app = createApp();
  const seed = seedOrder(app);
  app.ok('admin', 'customers.create', { data: { name: 'PT Kaca Nusantara' } });
  const options = app.ok('sales', 'customers.options', { search: 'kaca' });
  assert.deepEqual(options.items.map((item) => item.label), ['PT Kaca Nusantara']);
  app.ok('admin', 'customers.archive', { id: seed.customer.id });
  const withSelected = app.ok('sales', 'customers.options', { include: seed.customer.id });
  assert.ok(withSelected.items.some((item) => item.id === seed.customer.id), 'nilai terpilih yang diarsipkan tetap tersedia');
  app.ok('admin', 'customers.restore', { id: seed.customer.id });

  const search = app.ok('viewer', 'search.global', { query: 'botol' });
  assert.deepEqual(search.groups.map((group) => group.key), ['products']);
  const poSearch = app.ok('viewer', 'search.global', { query: 'PO/TEST' });
  assert.equal(poSearch.groups[0].items[0].id, seed.po.id);
  assert.deepEqual(app.ok('viewer', 'search.global', { query: 'b' }).groups, [], 'minimal 2 huruf');
  const users = app.ok('viewer', 'users.options', {});
  assert.equal(users.total, 5);
});
