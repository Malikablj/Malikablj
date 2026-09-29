'use strict';

/**
 * Phase 06 — sign-in and role restrictions in the browser. The menu and buttons follow the role, and — more
 * importantly — the server refuses what the role may not do even when the browser asks directly (authorization is
 * enforced by the backend, not by hiding buttons).
 */

const assert = require('node:assert/strict');
const { after, before, test } = require('node:test');

const { apiCall, assertNoProblems, drawer, expectToast, go, idle, main, rows, shot, startApp } = require('./support');

let app;

before(async () => { app = await startApp(); });
after(async () => { if (app) await app.close(); });

async function navLabels(page) {
  return page.locator('.sidebar .nav-item span').allTextContents();
}

test('akun belum terdaftar melihat layar pendaftaran; setelah Admin menambahkannya lewat Pengaturan › User, ia dapat masuk', async () => {
  const guest = await app.open('baru@example.com');
  await guest.page.getByRole('heading', { name: 'Akun belum terdaftar' }).waitFor();
  assert.ok(await guest.page.getByText('baru@example.com').first().isVisible());
  await shot(guest.page, 'roles-01-not-registered');

  const admin = await app.signIn('admin');
  await go(admin.page, 'settings?tab=users');
  await admin.page.getByRole('button', { name: 'User baru' }).click();
  const form = drawer(admin.page, 'User baru');
  await form.getByLabel(/^Nama/).fill('Bayu Baru');
  await form.getByLabel(/^Email/).fill('Baru@Example.com');
  await form.getByLabel(/^Role/).selectOption('SALES');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(admin.page, 'User ditambahkan.');
  await rows(admin.page, 'Bayu Baru').waitFor();

  await guest.page.getByRole('button', { name: 'Coba lagi' }).click();
  await guest.page.getByRole('heading', { name: 'Selamat datang' }).waitFor();
  assert.ok(await guest.page.getByText('Bayu Baru').first().isVisible());
  await guest.page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await guest.page.getByRole('heading', { name: 'Dashboard' }).waitFor();
  assertNoProblems(admin);
  await guest.context.close();
  await admin.context.close();
});

test('Viewer: hanya baca — tanpa tombol tambah/ubah, menu keuangan tersembunyi, URL langsung ditolak, server menolak penulisan', async () => {
  const viewer = await app.signIn('viewer');
  const page = viewer.page;
  const nav = await navLabels(page);
  assert.ok(nav.includes('Customer') && nav.includes('Laporan'));
  assert.ok(!nav.includes('Invoice & Pembayaran'), 'keuangan tidak tampil untuk Viewer');
  assert.equal(await main(page).getByRole('button', { name: 'Aktivitas' }).count(), 0, 'tanpa aksi cepat di dashboard');

  await go(page, 'customers');
  await idle(page);
  assert.equal(await page.getByRole('button', { name: 'Customer baru' }).count(), 0);
  await rows(page, 'PT Contoh Satu').click();
  await page.getByRole('heading', { name: 'PT Contoh Satu' }).waitFor();
  assert.equal(await main(page).getByRole('button', { name: 'Aktivitas', exact: true }).count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Aksi lainnya' }).count(), 0);
  assert.equal(await page.getByRole('tab', { name: /Invoice/ }).count(), 0, 'tab invoice tidak ada');

  await go(page, 'invoices');
  await page.getByRole('heading', { name: 'Tidak ada akses' }).waitFor();
  await shot(page, 'roles-02-viewer-forbidden');

  const write = await apiCall(page, 'customers.create', { data: { name: 'Dari konsol browser' } });
  assert.equal(write.success, false);
  assert.equal(write.error.code, 'FORBIDDEN');
  const read = await apiCall(page, 'invoices.list', {});
  assert.equal(read.error.code, 'FORBIDDEN', 'data keuangan ditolak server, bukan hanya disembunyikan');

  await go(page, 'settings');
  const tabs = await page.getByRole('tab').allTextContents();
  assert.deepEqual(tabs, ['Profil']);
  await page.locator('tr', { hasText: 'Customer' }).getByText('Baca', { exact: true }).waitFor();
  assertNoProblems(viewer);
  await viewer.context.close();
});

test('Sales: boleh CRM, PO/delivery hanya baca; server menolak delivery dan keuangan', async () => {
  const sales = await app.signIn('sales');
  const page = sales.page;
  await go(page, 'customers');
  assert.equal(await page.getByRole('button', { name: 'Customer baru' }).count(), 1);
  await go(page, 'purchase-orders');
  await idle(page);
  assert.equal(await page.getByRole('button', { name: 'PO baru' }).count(), 0);
  await rows(page, 'PO/UJI/001').click();
  await page.getByRole('heading', { name: 'PO/UJI/001' }).waitFor();
  assert.equal(await main(page).getByRole('button', { name: 'Delivery', exact: true }).count(), 0);
  assert.equal(await page.getByRole('combobox', { name: 'Status PO' }).count(), 0, 'status PO tidak dapat diubah');
  await go(page, 'deliveries');
  await idle(page);
  assert.equal(await page.getByRole('button', { name: 'Catat delivery' }).count(), 0);
  const delivery = await apiCall(page, 'deliveries.create', { data: { po_line_id: 'POL-00000000D1', quantity: 1 } });
  assert.equal(delivery.error.code, 'FORBIDDEN');
  const po = await apiCall(page, 'purchaseOrders.setStatus', { id: 'PO-00000000C1', status: 'CLOSED' });
  assert.equal(po.error.code, 'FORBIDDEN');
  assert.ok(!(await navLabels(page)).includes('Invoice & Pembayaran'));
  assertNoProblems(sales);
  await sales.context.close();
});

test('Marketing: boleh membuat PO, tetapi delivery hanya dilihat; Management: melihat keuangan tanpa mengubah', async () => {
  const marketing = await app.signIn('marketing');
  await go(marketing.page, 'purchase-orders');
  assert.equal(await marketing.page.getByRole('button', { name: 'PO baru' }).count(), 1);
  await go(marketing.page, 'purchase-orders/PO-00000000C1');
  await marketing.page.getByRole('combobox', { name: 'Status PO' }).waitFor();
  assert.equal(await main(marketing.page).getByRole('button', { name: 'Delivery', exact: true }).count(), 0);
  assertNoProblems(marketing);
  await marketing.context.close();

  const management = await app.signIn('management');
  const page = management.page;
  assert.ok((await navLabels(page)).includes('Invoice & Pembayaran'));
  await go(page, 'invoices');
  await idle(page);
  await page.locator('.kpi', { hasText: 'Piutang' }).waitFor();
  assert.equal(await page.getByRole('button', { name: 'Invoice baru' }).count(), 0);
  assert.ok(await rows(page).count() > 0, 'daftar invoice tampil');
  const pay = await apiCall(page, 'invoices.recordPayment', { id: 'PAY-00000000A2', paid_amount: 1, payment_date: '2026-01-01' });
  assert.equal(pay.error.code, 'FORBIDDEN');
  await go(page, 'customers');
  assert.equal(await page.getByRole('button', { name: 'Customer baru' }).count(), 0);
  await shot(page, 'roles-03-management-invoices');
  assertNoProblems(management);
  await management.context.close();
});

test('keluar dari aplikasi kembali ke layar masuk; user yang dinonaktifkan Admin langsung kehilangan akses', async () => {
  const sales = await app.signIn('sales');
  await sales.page.locator('.user-button').click();
  await sales.page.getByRole('menuitem', { name: 'Keluar' }).click();
  await sales.page.getByText('Anda telah keluar').waitFor();
  await sales.page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await sales.page.getByRole('heading', { name: 'Dashboard' }).waitFor();

  const admin = await app.signIn('admin');
  const users = await apiCall(admin.page, 'users.list', { search: 'sales@example.com' });
  const salesUser = users.data.items[0];
  await go(admin.page, 'settings?tab=users');
  await rows(admin.page, 'Sandi Sales').getByRole('button', { name: 'Aksi' }).click();
  await admin.page.getByRole('menuitem', { name: 'Arsipkan' }).click();
  await admin.page.getByRole('alertdialog').getByRole('button', { name: 'Arsipkan' }).click();
  await expectToast(admin.page, 'Diarsipkan.');

  // Not go(): the first server call answers NOT_REGISTERED and replaces the whole app, possibly before a page view could
  // be observed. Wait for that outcome itself.
  await sales.page.evaluate(() => PIK.router.go('customers', {}));
  await sales.page.getByRole('heading', { name: 'Akun belum terdaftar' }).waitFor();
  assert.ok(await sales.page.getByText(/dinonaktifkan/).isVisible());
  await shot(sales.page, 'roles-04-deactivated');

  const restored = await apiCall(admin.page, 'users.restore', { id: salesUser.id });
  assert.equal(restored.success, true);
  assertNoProblems(admin);
  await sales.context.close();
  await admin.context.close();
});
