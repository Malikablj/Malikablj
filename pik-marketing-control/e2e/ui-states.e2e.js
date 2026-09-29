'use strict';

/**
 * Phase 06 — UI states and responsive behaviour: loading skeletons, errors when the server cannot be reached (with
 * retry), a failed save that keeps what the user typed, empty states with a next step, confirmation before risky
 * changes, keyboard use (Escape, focus return, the "/" search shortcut), and the phone / tablet layouts (bottom
 * navigation, menu sheet, cards instead of tables, filters behind a button, forms as bottom sheets, no sideways scroll).
 */

const assert = require('node:assert/strict');
const { after, afterEach, before, test } = require('node:test');

const { VIEWPORTS, api, assertNoProblems, drawer, expectToast, go, idle, rows, shot, startApp } = require('./support');

let app;
const opened = [];

before(async () => { app = await startApp(); });
after(async () => { if (app) await app.close(); });
afterEach(async () => {
  while (opened.length) await opened.pop().context.close();
});

async function signIn(who, viewport) {
  const session = await app.signIn(who, { viewport });
  opened.push(session);
  return session;
}

async function noSidewaysScroll(page, label) {
  const [scrollWidth, width] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  assert.ok(scrollWidth <= width, `${label}: lebar konten ${scrollWidth} > layar ${width}`);
}

test('loading: skeleton saat data dimuat, lalu data tampil', async () => {
  const { page } = await signIn('admin');
  await page.route('**/__rpc', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 900));
    await route.continue();
  });
  await go(page, 'products');
  await page.locator('#main [aria-busy="true"]').first().waitFor();
  // The bar grows from width 0 (CSS transition), so wait for it instead of sampling its first frame.
  await page.locator('#loading-bar.active').waitFor({ state: 'visible', timeout: 2000 });
  await shot(page, 'states-01-loading');
  await rows(page, 'Botol Uji 30ml').waitFor({ timeout: 10000 });
  await page.unroute('**/__rpc');
});

test('error: server tidak terjangkau → pesan jelas + Coba lagi; setelah pulih data tampil', async () => {
  const session = await signIn('admin');
  const { page } = session;
  await page.route('**/__rpc', (route) => route.abort());
  await go(page, 'purchase-orders');
  await page.getByRole('heading', { name: 'Gagal memuat data' }).waitFor();
  assert.ok(await page.getByText('Tidak dapat terhubung ke server').isVisible());
  await shot(page, 'states-02-error');
  await page.unroute('**/__rpc');
  await page.getByRole('button', { name: 'Coba lagi' }).click();
  await rows(page, 'PO/UJI/001').waitFor();
});

test('simpan gagal (jaringan): form tetap terbuka dengan isiannya; simpan ulang berhasil', async () => {
  const { page } = await signIn('admin');
  await go(page, 'products');
  await idle(page);
  await page.getByRole('button', { name: 'Produk baru' }).click();
  const form = drawer(page, 'Produk baru');
  await form.getByLabel(/^Nama produk/).fill('Botol Uji 50ml');
  await form.getByLabel(/^Kode produk/).fill('TSTBTL50');
  await page.route('**/__rpc', (route) => route.abort());
  await form.getByRole('button', { name: 'Simpan' }).click();
  await form.getByText('Tidak dapat terhubung ke server').waitFor();
  assert.equal(await form.getByLabel(/^Nama produk/).inputValue(), 'Botol Uji 50ml');
  await shot(page, 'states-03-save-failed');
  await page.unroute('**/__rpc');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Produk ditambahkan.');
  await page.getByRole('heading', { name: /Botol Uji 50ml/ }).waitFor();
});

test('empty state: modul tanpa data memberi arahan dan tombol langkah berikutnya', async () => {
  const { page } = await signIn('admin');
  await go(page, 'activities');
  await page.getByRole('heading', { name: 'Belum ada aktivitas' }).waitFor();
  assert.ok(await page.locator('#main .state').getByRole('button', { name: 'Catat aktivitas' }).isVisible());
  await go(page, 'follow-ups?tab=OVERDUE');
  await page.getByRole('heading', { name: 'Tidak ada follow-up terlambat' }).waitFor();
  await go(page, 'leads?view=board');
  await page.getByRole('heading', { name: 'Pipeline masih kosong' }).waitFor();
  await shot(page, 'states-04-empty');
});

test('konfirmasi: menutup PO yang masih outstanding meminta konfirmasi; Batal tidak mengubah apa pun', async () => {
  const { page } = await signIn('admin');
  await go(page, 'purchase-orders/PO-00000000C1');
  await page.getByRole('heading', { name: 'PO/UJI/001' }).waitFor();
  await page.getByRole('combobox', { name: 'Status PO' }).selectOption('CLOSED');
  const dialog = page.getByRole('alertdialog', { name: /Ubah status ke Closed/ });
  await dialog.getByText(/masih memiliki outstanding 450/).waitFor();
  await shot(page, 'states-05-confirm');
  await dialog.getByRole('button', { name: 'Batal' }).click();
  assert.equal(await page.getByRole('combobox', { name: 'Status PO' }).inputValue(), 'ON_PROCESS');
  assert.equal((await api(page, 'purchaseOrders.get', { id: 'PO-00000000C1' })).purchaseOrder.status, 'ON_PROCESS');
});

test('keyboard: "/" membuka pencarian, Escape menutup drawer dan fokus kembali ke tombolnya', async () => {
  const session = await signIn('admin');
  const { page } = session;
  await go(page, 'customers');
  await idle(page);
  await page.locator('body').click({ position: { x: 900, y: 120 } });
  await page.keyboard.press('/');
  await page.getByRole('dialog', { name: 'Pencarian global' }).waitFor();
  await page.keyboard.press('Escape');
  await page.getByRole('dialog', { name: 'Pencarian global' }).waitFor({ state: 'detached' });
  const trigger = page.getByRole('button', { name: 'Customer baru' });
  await trigger.focus();
  await page.keyboard.press('Enter');
  const form = drawer(page, 'Customer baru');
  await form.waitFor();
  await page.waitForFunction(() => document.activeElement && document.activeElement.id.indexOf('f-name') === 0, null, { timeout: 3000 });
  await page.keyboard.press('Escape');
  await form.waitFor({ state: 'detached' });
  assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Customer baru');
  assertNoProblems(session);
});

test('fokus: drawer yang baru terbuka tidak merebut fokus dari kolom yang sudah dipilih pengguna', async () => {
  // Found by the flow test: the drawer's first-frame autofocus could land after the user had already moved to another
  // field, so the text typed there went into "Nama customer". Here the user is in Email before the first frame.
  const session = await signIn('admin');
  const { page } = session;
  await go(page, 'customers');
  await idle(page);
  const result = await page.evaluate(() => new Promise((resolve) => {
    Array.from(document.querySelectorAll('#main button')).find((b) => b.textContent.trim() === 'Customer baru').click();
    const label = Array.from(document.querySelectorAll('.drawer label.field-label')).find((l) => /^Email\*?$/.test(l.textContent.trim()));
    const email = document.getElementById(label.htmlFor);
    email.focus();
    requestAnimationFrame(() => requestAnimationFrame(() => resolve({ stillInEmail: document.activeElement === email, id: document.activeElement.id })));
  }));
  assert.equal(result.stillInEmail, true, `fokus pindah ke ${result.id}`);
  await page.keyboard.type('ok@contoh.example');
  const form = drawer(page, 'Customer baru');
  assert.equal(await form.getByLabel(/^Email/).inputValue(), 'ok@contoh.example');
  assert.equal(await form.getByLabel(/^Nama customer/).inputValue(), '');
  assertNoProblems(session);
});

test('ponsel (390 px): navigasi bawah, menu lengkap, kartu, filter di balik tombol, form sebagai bottom sheet, tanpa scroll ke samping', async () => {
  const session = await signIn('admin', 'phone');
  const { page } = session;
  assert.ok(await page.locator('.bottom-nav').isVisible());
  assert.equal(await page.locator('.sidebar').isVisible(), false);
  assert.equal((await page.locator('.topbar-title').textContent()).trim(), 'Dashboard');
  await noSidewaysScroll(page, 'dashboard');
  await shot(page, 'mobile-01-dashboard');

  await page.locator('.bottom-nav').getByRole('button', { name: 'Customer' }).click();
  await page.locator('#main .cards-list .list-card', { hasText: 'PT Contoh Satu' }).waitFor();
  assert.equal(await page.locator('#main .table-scroll').first().isVisible(), false, 'tabel diganti kartu');
  const filterButton = page.getByRole('button', { name: /^Filter/ });
  assert.equal(await page.getByRole('combobox', { name: 'Status' }).isVisible(), false);
  await filterButton.click();
  await page.getByRole('combobox', { name: 'Status' }).selectOption('POTENTIAL');
  await page.waitForFunction(() => document.querySelectorAll('#main .cards-list .list-card').length === 1);
  assert.match(await filterButton.textContent(), /\(1\)/);
  await noSidewaysScroll(page, 'customers');
  await shot(page, 'mobile-02-customers');

  await page.locator('.bottom-nav').getByRole('button', { name: 'Menu' }).click();
  const sheet = page.getByRole('dialog', { name: 'Semua menu' });
  await sheet.getByRole('button', { name: 'Stok' }).click();
  await page.locator('.topbar-title', { hasText: 'Stok' }).waitFor();
  await noSidewaysScroll(page, 'stok');

  // Detail header: the action buttons wrap below the name instead of squeezing it to one letter per line.
  await go(page, 'customers/CUS-00000000A1');
  const name = page.locator('.detail-title h1');
  await name.waitFor();
  const nameBox = await name.boundingBox();
  assert.ok(nameBox.width > 150 && nameBox.height < 40, `nama customer terjepit (${Math.round(nameBox.width)}×${Math.round(nameBox.height)})`);
  const more = await page.getByRole('button', { name: 'Aksi lainnya' }).boundingBox();
  assert.ok(more.width <= 48, `tombol ikon tetap persegi (lebar ${Math.round(more.width)})`);
  await noSidewaysScroll(page, 'customer detail');

  await go(page, 'purchase-orders/PO-00000000C1');
  await page.getByRole('heading', { name: 'PO/UJI/001' }).waitFor();
  await noSidewaysScroll(page, 'PO detail');
  await shot(page, 'mobile-03-po-detail');
  await page.getByRole('button', { name: 'Delivery', exact: true }).first().click();
  const form = drawer(page, 'Catat delivery');
  await form.waitFor();
  await page.waitForTimeout(300);
  const box = await form.boundingBox();
  assert.equal(Math.round(box.width), VIEWPORTS.phone.width, 'lebar penuh');
  assert.ok(box.y > 0 && box.y < 60, `bottom sheet dari bawah (y=${box.y})`);
  await shot(page, 'mobile-04-form-sheet');
  assertNoProblems(session);
});

test('tablet (820 px): sidebar menjadi rel ikon, konten tetap lengkap tanpa scroll ke samping', async () => {
  const session = await signIn('management', 'tablet');
  const { page } = session;
  const sidebar = await page.locator('.sidebar').boundingBox();
  assert.ok(sidebar.width <= 80, `lebar sidebar ${sidebar.width}`);
  assert.equal(await page.locator('.sidebar .nav-item span').first().isVisible(), false, 'label disembunyikan, ikon tetap');
  for (const hash of ['dashboard', 'customers', 'purchase-orders', 'invoices', 'reports']) {
    await go(page, hash);
    await idle(page);
    await noSidewaysScroll(page, hash);
  }
  await shot(page, 'tablet-01-reports');
  assertNoProblems(session);
});
