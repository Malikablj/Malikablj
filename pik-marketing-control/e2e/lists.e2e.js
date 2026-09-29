'use strict';

/**
 * Phase 06 — lists and search in the browser: server-side pagination with the state in the URL (survives a reload),
 * sorting by column header, debounced search, filters, archived records, the global search palette (Ctrl+K), the
 * keyboard alternative to dragging pipeline cards, and report filters with CSV export.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { after, afterEach, before, test } = require('node:test');

const { api, assertNoProblems, expectToast, go, idle, rows, shot, startApp } = require('./support');

let app;
let session;
let page;

before(async () => {
  app = await startApp();
  session = await app.signIn('admin');
  page = session.page;
  // 30 extra customers (invented) so the list needs a second page.
  for (let i = 0; i < 30; i++) {
    await api(page, 'customers.create', { data: { name: `PT Massal ${String(i).padStart(2, '0')}`, status: i % 3 === 0 ? 'POTENTIAL' : 'ACTIVE' } });
  }
});

after(async () => { if (app) await app.close(); });
afterEach(async () => {
  // The app's own page-change cleanup: closes drawers, dialogs, menus and the search palette with their listeners.
  if (page) await page.evaluate(() => { if (window.PIK && PIK.closeAllLayers) PIK.closeAllLayers(); });
});

function footer() {
  return page.locator('#main .table-foot').first();
}

test('pagination: 25 per halaman dari server, halaman di URL tetap setelah muat ulang', async () => {
  await go(page, 'customers');
  await idle(page);
  await footer().getByText('1–25 dari 33').waitFor();
  assert.equal(await rows(page).count(), 25);
  await page.getByRole('button', { name: 'Halaman berikutnya' }).click();
  await footer().getByText('26–33 dari 33').waitFor();
  assert.equal(await rows(page).count(), 8);
  assert.match(await page.evaluate(() => window.location.hash), /page=2/);
  await page.reload();
  await page.locator('.shell').waitFor();
  await footer().getByText('26–33 dari 33').waitFor();
  assert.ok(await page.getByText('Hal. 2 / 2').isVisible());
});

test('urutan: klik judul kolom membalik urutan (server), indikator arah tampil', async () => {
  await go(page, 'customers');
  await idle(page);
  assert.equal((await rows(page).first().locator('.primary').textContent()).trim(), 'CV Contoh Dua');
  await page.getByRole('columnheader', { name: /^Customer/ }).click();
  await page.waitForFunction(() => /dir=desc/.test(window.location.hash));
  await idle(page);
  await page.waitForFunction(() => document.querySelector('#main tbody tr .primary').textContent.trim() === 'PT Massal 29');
  assert.equal(await page.getByRole('columnheader', { name: /^Customer/ }).getAttribute('aria-sort'), 'descending');
  await page.getByRole('columnheader', { name: /^Outstanding/ }).click();
  await page.waitForFunction(() => document.querySelector('#main tbody tr .primary').textContent.trim() === 'PT Contoh Satu');
  assert.equal(await page.getByRole('columnheader', { name: /^Outstanding/ }).getAttribute('aria-sort'), 'descending', 'kolom angka: terbesar dulu');
});

test('pencarian: debounce (tidak memanggil server per ketikan), semua kata harus cocok', async () => {
  await go(page, 'customers');
  await idle(page);
  let calls = 0;
  const onRequest = (request) => { if (request.url().endsWith('/__rpc') && /customers\.list/.test(request.postData() || '')) calls++; };
  page.on('request', onRequest);
  await page.getByPlaceholder(/Cari nama, kode/).pressSequentially('massal 07', { delay: 40 });
  await page.waitForFunction(() => document.querySelectorAll('#main tbody tr').length === 1);
  page.off('request', onRequest);
  assert.ok(calls <= 2, `panggilan server saat mengetik: ${calls}`);
  assert.equal((await rows(page).first().locator('.primary').textContent()).trim(), 'PT Massal 07');
  await page.getByPlaceholder(/Cari nama, kode/).fill('tidak ada yang seperti ini');
  await page.getByRole('heading', { name: 'Tidak ada hasil' }).waitFor();
});

test('filter: status dan "Ada PO terbuka" dikirim ke server; kombinasi filter; hapus filter kembali semua', async () => {
  await go(page, 'customers');
  await idle(page);
  await page.getByRole('combobox', { name: 'Status' }).selectOption('POTENTIAL');
  await footer().getByText('1–11 dari 11').waitFor();
  await page.getByRole('button', { name: 'Ada PO terbuka' }).click();
  await page.getByRole('heading', { name: 'Tidak ada hasil' }).waitFor();
  await page.getByRole('combobox', { name: 'Status' }).selectOption('');
  await page.waitForFunction(() => document.querySelectorAll('#main tbody tr').length === 2);
  const names = (await rows(page).locator('.primary').allTextContents()).map((t) => t.trim()).sort();
  assert.deepEqual(names, ['PT Contoh Satu', 'PT Contoh Tiga']);
  assert.match(await page.evaluate(() => window.location.hash), /has_open_po=true/);
  await shot(page, 'lists-01-filters');
});

test('arsip: record diarsipkan hilang dari daftar, "Termasuk arsip" menampilkannya kembali (redup)', async () => {
  const target = (await api(page, 'customers.list', { search: 'Massal 00' })).items[0];
  await api(page, 'customers.archive', { id: target.id });
  await go(page, 'customers?q=Massal 00');
  await idle(page);
  await page.getByRole('heading', { name: 'Tidak ada hasil' }).waitFor();
  await page.getByRole('button', { name: 'Termasuk arsip' }).click();
  await rows(page, 'PT Massal 00').waitFor();
  assert.match(await rows(page, 'PT Massal 00').getAttribute('class'), /archived/);
});

test('pencarian global (Ctrl+K): hasil per modul, Enter membuka detail', async () => {
  await go(page, 'dashboard');
  await idle(page);
  await page.keyboard.press('Control+k');
  const palette = page.getByRole('dialog', { name: 'Pencarian global' });
  await palette.waitFor();
  await palette.getByRole('searchbox').fill('uji/002');
  await palette.locator('.palette-group', { hasText: 'Purchase order' }).waitFor();
  await shot(page, 'lists-02-global-search');
  await page.keyboard.press('Enter');
  await page.getByRole('heading', { name: 'PO/UJI/002' }).waitFor();
  await page.keyboard.press('Control+k');
  await palette.getByRole('searchbox').fill('Contoh Tiga');
  await palette.getByRole('option', { name: 'PT Contoh Tiga', exact: true }).click();
  await page.getByRole('heading', { name: 'PT Contoh Tiga' }).waitFor();
});

test('pencarian global ditutup oleh Back: tidak ada listener tertinggal yang menangkap Enter di halaman berikutnya', async () => {
  await go(page, 'customers');
  await go(page, 'products');
  await idle(page);
  await page.keyboard.press('Control+k');
  const palette = page.getByRole('dialog', { name: 'Pencarian global' });
  await palette.getByRole('searchbox').fill('uji/002');
  await palette.locator('.palette-group', { hasText: 'Purchase order' }).waitFor();
  await page.goBack();
  await page.getByRole('heading', { name: 'Customer', exact: true }).waitFor();
  await palette.waitFor({ state: 'detached' });
  await idle(page);
  await page.getByPlaceholder(/Cari nama, kode/).press('Enter');
  await page.waitForTimeout(400);
  assert.match(await page.evaluate(() => window.location.hash), /^#customers/, 'Enter tidak membuka hasil pencarian lama');
});

test('pipeline tanpa seret: menu kartu "Pindah ke" (alternatif keyboard) memindahkan lead', async () => {
  const customer = (await api(page, 'customers.list', { search: 'Contoh Satu' })).items[0];
  const lead = await api(page, 'leads.create', { data: { customer_id: customer.id, name: 'Jar krim 50 g', estimated_value: 7500000 } });
  await go(page, 'leads?view=board');
  await idle(page);
  await page.getByRole('button', { name: 'Pindah tahap Jar krim 50 g' }).click();
  await page.getByRole('menuitem', { name: 'Pindah ke Negotiation' }).click();
  await expectToast(page, 'Jar krim 50 g → Negotiation');
  await page.locator('.kanban-col[data-status="NEGOTIATION"] .kanban-card', { hasText: 'Jar krim 50 g' }).waitFor();
  assert.equal((await api(page, 'leads.get', { id: lead.id })).lead.status, 'NEGOTIATION');
  await page.getByRole('tab', { name: 'Daftar' }).click();
  await rows(page, 'Jar krim 50 g').waitFor();
});

test('laporan: filter periode/status, grafik dan tabel dari server, ekspor CSV terunduh', async () => {
  await go(page, 'reports?report=purchaseOrders&preset=all');
  await idle(page);
  await page.locator('.kpi', { hasText: 'Outstanding' }).waitFor();
  const data = await api(page, 'reports.purchaseOrders', {});
  assert.equal((await page.locator('.kpi').filter({ has: page.locator('.kpi-label', { hasText: /^Total$/ }) }).locator('.kpi-value').textContent()).trim(), String(data.summary.total));
  await page.getByRole('combobox', { name: 'Status' }).selectOption('CLOSED');
  await page.waitForFunction(() => /status=CLOSED/.test(window.location.hash));
  await idle(page);
  await page.waitForFunction(() => document.querySelectorAll('#main tbody tr').length === 1);
  await page.locator('.hbar', { hasText: 'Closed' }).first().waitFor();
  await shot(page, 'lists-03-report');
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Ekspor CSV' }).click()]);
  assert.match(download.suggestedFilename(), /^pik-purchase-orders-\d{4}-\d{2}-\d{2}\.csv$/);
  const csv = fs.readFileSync(await download.path(), 'utf8');
  assert.ok(csv.startsWith('﻿Nomor PO,Customer,Tanggal PO,Status'), csv.slice(0, 80));
  assert.equal(csv.trim().split(/\r?\n/).length, 2, 'header + 1 PO Closed');
  await expectToast(page, 'CSV diunduh: 1 baris.');
  assertNoProblems(session);
});
