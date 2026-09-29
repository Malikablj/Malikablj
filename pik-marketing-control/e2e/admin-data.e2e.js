'use strict';

/**
 * Phase 06 — migrated data and the Admin / finance / inventory screens in the browser: legacy records are marked and
 * explained, an Admin links a legacy delivery to its PO line (D3) and resolves the migration issue, adds an activity
 * type and uses it, changes a setting, records an invoice and a partial payment, records stock and a lead-time
 * schedule — every number checked against the server afterwards.
 */

const assert = require('node:assert/strict');
const { after, afterEach, before, test } = require('node:test');

const { api, assertNoProblems, drawer, expectToast, go, idle, kpiValue, pick, rows, shot, startApp, statValue } = require('./support');

let app;
let session;
let page;

before(async () => {
  app = await startApp();
  session = await app.signIn('admin');
  page = session.page;
});
after(async () => { if (app) await app.close(); });
afterEach(async () => {
  // The app's own page-change cleanup: closes drawers, dialogs, menus and the search palette with their listeners.
  if (page) await page.evaluate(() => { if (window.PIK && PIK.closeAllLayers) PIK.closeAllLayers(); });
});

/** Rows of the "Item PO" table on a PO detail page. */
function lineRows() {
  return page.locator('#main section', { has: page.getByRole('heading', { name: /^Item PO/ }) }).locator('.table-scroll tbody tr');
}

function field(scope, label) {
  return scope.getByLabel(new RegExp('^' + label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\*?$'));
}

test('data migrasi: tanda Legacy, peringatan transaksi belum tertaut, sumber legacy dapat ditelusuri', async () => {
  await go(page, 'purchase-orders/PO-00000000C1');
  await page.getByRole('heading', { name: 'PO/UJI/001' }).waitFor();
  await idle(page);
  assert.ok(await page.locator('.legacy-tag').first().isVisible());
  const warning = page.locator('.form-alert.warn');
  assert.match(await warning.textContent(), /terkirim 50, retur 5/);
  assert.equal(await statValue(page, 'Outstanding'), '450');
  // D3 comparison: the workbook's own outstanding is shown only on lines where it differs from the computed value.
  assert.equal(await lineRows().count(), 2);
  assert.equal(await lineRows().filter({ hasText: /Legacy \d/ }).count(), 0, 'PO/UJI/001: hitungan = workbook');
  await go(page, 'purchase-orders/PO-00000000C2');
  await page.getByRole('heading', { name: 'PO/UJI/002' }).waitFor();
  const jar = lineRows().filter({ hasText: 'Jar Uji 50gr' });
  assert.match(await jar.textContent(), /20\s*Legacy 40/, 'retur legacy belum tertaut: hitungan 20, workbook 40');
  await go(page, 'purchase-orders/PO-00000000C3');
  const notice = page.locator('.form-alert.warn', { hasText: 'isu migrasi terbuka untuk data ini' });
  await notice.getByText('PO_CUSTOMER_MISSING').waitFor();
  await notice.getByRole('button', { name: 'Tinjau di Isu migrasi' }).click();
  await rows(page, 'PO_CUSTOMER_MISSING').waitFor();
  assert.equal(await page.getByPlaceholder(/Cari jenis isu/).inputValue(), 'PO-00000000C3');
  await go(page, 'customers/CUS-00000000A1');
  await page.getByText('Synthetic.xlsx#CUSTOMERS!2').waitFor();
  await go(page, 'customers/CUS-00000000A1?tab=history');
  await page.getByText('Dibuat oleh migrasi data legacy').waitFor();
});

test('D3: Admin menautkan delivery legacy ke item PO lewat form; outstanding PO dihitung ulang', async () => {
  await go(page, 'deliveries?linked=false');
  await idle(page);
  const row = rows(page, 'SJ/UJI/004');
  await row.waitFor();
  await row.getByRole('button', { name: 'Aksi' }).click();
  await page.getByRole('menuitem', { name: 'Ubah' }).click();
  const form = drawer(page, 'Ubah delivery');
  await pick(form, 'Item PO', 'UJI/001', 'PO/UJI/001 · [TSTBTL30] Botol Uji 30ml — Varian A');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Delivery diperbarui.');
  await go(page, 'purchase-orders/PO-00000000C1');
  await page.getByRole('heading', { name: 'PO/UJI/001' }).waitFor();
  await idle(page);
  assert.equal(await statValue(page, 'Terkirim'), '650');
  assert.equal(await statValue(page, 'Outstanding'), '400');
  assert.match(await page.locator('.form-alert.warn').textContent(), /terkirim 0, retur 5/);
  assert.match(await lineRows().filter({ hasText: 'Botol Uji 30ml' }).textContent(), /350\s*Legacy 400/, 'selisih dengan workbook terlihat');
  const delivery = (await api(page, 'deliveries.list', { search: 'SJ/UJI/004' })).items[0];
  assert.equal(delivery.is_legacy, true, 'tetap record legacy');
  assert.equal(delivery.product_id, 'PRD-00000000B1');
});

test('isu migrasi: Admin menyelesaikan isu dengan catatan; jumlah isu terbuka berkurang; tercatat di audit', async () => {
  await go(page, 'settings?tab=issues');
  await idle(page);
  const before = Number((await kpiValue(page, 'Isu terbuka')).replace(/\./g, ''));
  await page.getByPlaceholder(/Cari jenis isu/).fill('DEL-00000000E4');
  const row = rows(page, 'DELIVERY_PRODUCT_UNRESOLVED').filter({ hasText: 'DEL-00000000E4' });
  await row.waitFor();
  await row.click();
  const form = drawer(page, 'Isu migrasi');
  await form.getByText('Relasi data legacy tidak ditebak').waitFor();
  await field(form, 'Status penyelesaian').selectOption('RESOLVED');
  await field(form, 'Catatan penyelesaian').fill('Ditautkan Admin ke item PO botol setelah cek surat jalan (D3).');
  await shot(page, 'admin-01-issue');
  await form.getByRole('button', { name: 'Simpan status' }).click();
  await expectToast(page, 'Status isu disimpan.');
  await page.waitForFunction((n) => {
    const tile = Array.from(document.querySelectorAll('.kpi')).find((k) => k.querySelector('.kpi-label').textContent === 'Isu terbuka');
    return tile && Number(tile.querySelector('.kpi-value').textContent.replace(/\./g, '')) === n - 1;
  }, before);
  const audit = await api(page, 'audit.list', { filters: { entity_type: 'MIGRATION_ISSUES' } });
  assert.equal(audit.items[0].action, 'UPDATE');
  assert.deepEqual(audit.items[0].changes.resolution_status, ['OPEN', 'RESOLVED']);
});

test('nilai pilihan: jenis aktivitas baru dibuat Admin dan langsung dapat dipakai di form', async () => {
  await go(page, 'settings?tab=enums');
  await idle(page);
  const card = page.locator('section.card', { hasText: 'ACTIVITY_TYPE' });
  await card.getByRole('button', { name: 'Nilai' }).click();
  const form = drawer(page, 'Nilai baru ACTIVITY_TYPE');
  await field(form, 'Nilai (kode)').fill('site survey');
  await field(form, 'Label').fill('Site Survey');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Nilai pilihan disimpan.');
  await card.getByText('SITE_SURVEY').waitFor();

  await go(page, 'activities');
  await page.getByRole('button', { name: 'Catat aktivitas' }).first().click();
  const activity = drawer(page, 'Catat aktivitas');
  await pick(activity, 'Customer', 'Contoh Satu', 'PT Contoh Satu');
  await field(activity, 'Jenis aktivitas').selectOption({ label: 'Site Survey' });
  await field(activity, 'Subjek').fill('Survei lini pengisian customer');
  await activity.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Aktivitas dicatat.');
  await rows(page, 'Survei lini pengisian customer').locator('.badge', { hasText: 'Site Survey' }).waitFor();
});

test('pengaturan: nama perusahaan diubah dan dipakai aplikasi; nilai yang salah tipe ditolak dengan pesan jelas', async () => {
  await go(page, 'settings?tab=settings');
  await idle(page);
  const companyRow = page.locator('tr', { has: page.getByRole('textbox', { name: 'COMPANY_NAME' }) });
  await page.getByRole('textbox', { name: 'COMPANY_NAME' }).fill('PT Uji Kemasan Nusantara');
  await companyRow.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Pengaturan COMPANY_NAME disimpan.');
  const pageRow = page.locator('tr', { has: page.getByRole('textbox', { name: 'DEFAULT_PAGE_SIZE' }) });
  await page.getByRole('textbox', { name: 'DEFAULT_PAGE_SIZE' }).fill('dua puluh');
  await pageRow.getByRole('button', { name: 'Simpan' }).click();
  await page.locator('.toast.error', { hasText: 'INTEGER' }).waitFor();
  await page.reload();
  await page.locator('.shell').waitFor();
  await page.locator('.sidebar-brand', { hasText: 'PT Uji Kemasan Nusantara' }).waitFor();
});

test('invoice & pembayaran: invoice baru (jenis dari nomor), pembayaran sebagian → Partial, sisa tagihan benar', async () => {
  await go(page, 'invoices');
  await idle(page);
  const before = (await api(page, 'dashboard.summary', {})).finance.receivable;
  await page.getByRole('button', { name: 'Invoice baru' }).first().click();
  const form = drawer(page, 'Invoice baru');
  await pick(form, 'PO', 'UJI/001', 'PO/UJI/001');
  await field(form, 'Nomor invoice').fill('PIK/09/2026/INV/0099');
  const due = await page.evaluate(() => PIK.addDays(PIK.today(), 30));
  await field(form, 'Jatuh tempo').fill(due);
  await field(form, 'Nilai invoice').fill('1.000.000');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Invoice ditambahkan.');
  const row = rows(page, 'PIK/09/2026/INV/0099');
  await row.waitFor();
  assert.ok(await row.locator('.badge', { hasText: 'Unpaid' }).isVisible());
  assert.ok(await row.getByText('INV', { exact: true }).isVisible(), 'jenis INV dari segmen nomor');

  await row.click();
  const payment = drawer(page, 'Catat pembayaran');
  await field(payment, 'Total dibayar').fill('1.200.000');
  await payment.getByRole('button', { name: 'Simpan pembayaran' }).click();
  await payment.getByText(/Tidak boleh melebihi nilai invoice/).waitFor();
  await field(payment, 'Total dibayar').fill('400.000');
  await field(payment, 'Nomor bukti bayar').fill('BKM/E2E/01');
  await payment.getByRole('button', { name: 'Simpan pembayaran' }).click();
  await expectToast(page, 'Pembayaran dicatat.');
  await row.locator('.badge', { hasText: 'Partial' }).waitFor();
  assert.ok(await row.getByText('Rp 600.000').isVisible());
  const after = (await api(page, 'dashboard.summary', {})).finance.receivable;
  assert.equal(after - before, 600000);
  await shot(page, 'admin-02-invoices');
});

test('stok dan lead time: catatan stok baru menjadi stok terbaru produk; jadwal lead time tidak mengubah outstanding', async () => {
  await go(page, 'stock');
  await idle(page);
  await page.getByRole('button', { name: 'Catat stok' }).first().click();
  const form = drawer(page, 'Catat stok');
  await pick(form, 'Produk', 'Tube', 'Tube Uji 100ml');
  await field(form, 'Qty').fill('250');
  await field(form, 'Gudang').fill('Gudang Utama');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Stok dicatat.');
  await rows(page, 'Tube Uji 100ml').filter({ hasText: '250' }).waitFor();
  await go(page, 'products/PRD-00000000B3');
  await page.getByRole('heading', { name: /Tube Uji 100ml/ }).waitFor();
  await idle(page);
  assert.equal(await statValue(page, 'Stok FG'), '250');

  await go(page, 'lead-time');
  await idle(page);
  await page.getByRole('button', { name: 'Jadwal baru' }).first().click();
  const schedule = drawer(page, 'Jadwal lead time baru');
  await pick(schedule, 'Item PO', 'UJI/001', 'PO/UJI/001 · [TSTJAR50] Jar Uji 50gr');
  await field(schedule, 'Tanggal rencana kirim').fill(await page.evaluate(() => PIK.addDays(PIK.today(), 14)));
  await field(schedule, 'Qty rencana').fill('25');
  await schedule.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Jadwal ditambahkan.');
  await rows(page, 'Jar Uji 50gr').filter({ hasText: '25' }).waitFor();
  await go(page, 'purchase-orders/PO-00000000C1');
  await page.getByRole('heading', { name: 'PO/UJI/001' }).waitFor();
  await idle(page);
  assert.equal(await statValue(page, 'Outstanding'), '400', 'jadwal bukan pengiriman (D10)');
  assert.ok(await page.locator('section', { hasText: 'Jadwal lead time' }).locator('tbody tr').count() >= 1);
  await shot(page, 'admin-03-po-after', { fullPage: true });
  assertNoProblems(session);
});
