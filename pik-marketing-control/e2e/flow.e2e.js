'use strict';

/**
 * Phase 06 — the main business flow in the real web app, as a user clicks through it:
 * Login → Dashboard → Customer → Contact → Lead → Activity (+ next follow-up) → Follow-up done → Purchase order with
 * items → Delivery (over-quantity confirmation) → Return → Outstanding → Dashboard, then form validation (client and
 * server), the audit trail of what was done, and the record history. Data: the invented test workbook, migrated with
 * the real runner, plus what this test creates.
 */

const assert = require('node:assert/strict');
const { after, afterEach, before, test } = require('node:test');

const { api, assertNoProblems, drawer, expectToast, go, idle, kpiValue, main, pick, rows, shot, startApp, statValue } = require('./support');

let app;
let session;
let page;
const state = {};

before(async () => {
  app = await startApp();
  session = await app.open('admin');
  page = session.page;
});

after(async () => {
  if (app) await app.close();
});

// A failed step must not leave a drawer open over the next test.
afterEach(async () => {
  // The app's own page-change cleanup: closes drawers, dialogs, menus and the search palette with their listeners.
  if (page) await page.evaluate(() => { if (window.PIK && PIK.closeAllLayers) PIK.closeAllLayers(); });
});

function field(scope, label) {
  return scope.getByLabel(new RegExp('^' + label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
}

test('login: identitas akun Google tampil, Masuk membuka dashboard dengan KPI dari data migrasi', async () => {
  await page.getByRole('heading', { name: 'Selamat datang' }).waitFor();
  await shot(page, 'flow-01-login');
  assert.ok(await page.getByText('owner@example.com').first().isVisible());
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.getByRole('heading', { name: 'Dashboard' }).waitFor();
  await idle(page);
  const kpi = (label) => kpiValue(page, label);
  const summary = await api(page, 'dashboard.summary', {});
  state.before = summary.kpis;
  assert.equal(await kpi('Total customer'), String(summary.kpis.totalCustomers));
  assert.equal(await kpi('PO terbuka'), String(summary.kpis.openPurchaseOrders));
  assert.equal(await kpi('Outstanding'), new Intl.NumberFormat('id-ID').format(summary.kpis.outstandingQuantity));
  await shot(page, 'flow-02-dashboard');
});

test('customer: buat dari form, langsung membuka workspace customer', async () => {
  await page.locator('.sidebar').getByRole('button', { name: 'Customer', exact: true }).click();
  await page.getByRole('heading', { name: 'Customer', exact: true }).waitFor();
  await page.getByRole('button', { name: 'Customer baru' }).first().click();
  const form = drawer(page, 'Customer baru');
  await field(form, 'Nama customer').fill('PT Uji Coba Kemasan');
  await field(form, 'Kode customer').fill('E2E-001');
  await field(form, 'Industri').fill('Kosmetik');
  await field(form, 'Telepon').fill('021-5550101');
  await field(form, 'Email').fill('Purchasing@UjiCoba.example');
  await shot(page, 'flow-03-customer-form');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Customer ditambahkan.');
  await page.getByRole('heading', { name: 'PT Uji Coba Kemasan' }).waitFor();
  state.customerId = await page.evaluate(() => PIK.router.current.path.split('/')[1]);
  assert.match(state.customerId, /^CUS-[0-9A-F]{10}$/);
  const record = (await api(page, 'customers.get', { id: state.customerId })).customer;
  assert.equal(record.email, 'purchasing@ujicoba.example', 'email disimpan huruf kecil');
  assert.equal(record.owner_name, 'owner@example.com', 'PIC default = user aktif');
});

test('contact: tambah contact utama dari workspace customer', async () => {
  await page.getByRole('button', { name: 'Aksi lainnya' }).click();
  await page.getByRole('menuitem', { name: 'Contact baru' }).click();
  const form = drawer(page, 'Contact baru');
  assert.equal(await field(form, 'Customer').inputValue(), 'PT Uji Coba Kemasan', 'customer terisi dari konteks');
  await field(form, 'Nama contact').fill('Rina Pembelian');
  await field(form, 'Jabatan').fill('Purchasing Manager');
  await field(form, 'WhatsApp').fill('0812-555-0101');
  await form.getByLabel('Jadikan contact utama customer ini').check();
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Contact ditambahkan.');
  await idle(page);
  await page.getByRole('tab', { name: /Contact/ }).click();
  await rows(page, 'Rina Pembelian').waitFor();
  assert.ok(await rows(page, 'Rina Pembelian').locator('.badge', { hasText: 'Utama' }).isVisible());
});

test('lead: buat lead dengan nilai format Indonesia; muncul di pipeline pada tahap New', async () => {
  await page.getByRole('button', { name: 'Aksi lainnya' }).click();
  await page.getByRole('menuitem', { name: 'Lead baru' }).click();
  const form = drawer(page, 'Lead baru');
  await field(form, 'Nama lead').fill('Botol serum 30 ml lini baru');
  await field(form, 'Estimasi nilai').fill('25.000.000');
  await form.getByText('= Rp 25.000.000').waitFor();
  await pick(form, 'Contact', 'Rina', 'Rina Pembelian');
  await form.getByLabel(/^Prioritas/).selectOption('HIGH');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Lead ditambahkan.');
  const leads = await api(page, 'leads.list', { filters: { customer_id: state.customerId } });
  assert.equal(leads.total, 1);
  state.lead = leads.items[0];
  assert.equal(state.lead.estimated_value, 25000000);
  assert.equal(state.lead.contact_name, 'Rina Pembelian');
  assert.equal(state.lead.status, 'NEW');

  await go(page, 'leads?view=board');
  const column = page.locator('.kanban-col', { hasText: 'New' }).first();
  await column.getByText('Botol serum 30 ml lini baru').waitFor();
  await shot(page, 'flow-04-pipeline');
});

test('pipeline: seret kartu lead ke tahap Qualified (drag & drop) tersimpan di server', async () => {
  const card = page.locator('.kanban-card', { hasText: 'Botol serum 30 ml lini baru' });
  const target = page.locator('.kanban-col[data-status="QUALIFIED"]');
  await card.dragTo(target);
  await expectToast(page, 'Qualified');
  await page.locator('.kanban-col[data-status="QUALIFIED"] .kanban-card', { hasText: 'Botol serum 30 ml lini baru' }).waitFor();
  const lead = (await api(page, 'leads.get', { id: state.lead.id })).lead;
  assert.equal(lead.status, 'QUALIFIED');
});

test('aktivitas + follow-up berikutnya dalam satu simpan; follow-up muncul di Hari ini', async () => {
  await go(page, `customers/${state.customerId}`);
  await main(page).getByRole('button', { name: 'Aktivitas', exact: true }).click();
  const form = drawer(page, 'Catat aktivitas');
  await field(form, 'Jenis aktivitas').selectOption('VISIT');
  await field(form, 'Subjek').fill('Kunjungan pabrik dan presentasi sampel');
  await field(form, 'Deskripsi').fill('Customer tertarik botol 30 ml; minta sampel warna amber.');
  await pick(form, 'Lead', 'serum', 'Botol serum 30 ml lini baru');
  await form.getByLabel('Jadwalkan follow-up berikutnya').check();
  const today = await page.evaluate(() => PIK.today());
  await field(form, 'Tanggal follow-up').fill(today);
  await field(form, 'Tujuan follow-up').fill('Konfirmasi sampel amber');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Aktivitas dicatat.');
  await idle(page);
  await page.getByText('Konfirmasi sampel amber').first().waitFor();

  await go(page, 'follow-ups?tab=TODAY');
  const row = rows(page, 'Konfirmasi sampel amber');
  await row.waitFor();
  assert.ok(await row.locator('.badge', { hasText: 'Hari ini' }).isVisible());
  const bell = page.getByRole('button', { name: /Notifikasi follow-up/ });
  await page.waitForFunction(() => document.querySelector('.icon-button .dot'));
  await bell.click();
  await page.locator('.menu').getByText('Konfirmasi sampel amber').waitFor();
  await page.keyboard.press('Escape');
  await shot(page, 'flow-05-follow-ups');
});

test('follow-up: selesaikan dengan hasil dan jadwalkan berikutnya', async () => {
  const row = rows(page, 'Konfirmasi sampel amber');
  await row.getByRole('button', { name: 'Selesai' }).click();
  const form = drawer(page, 'Selesaikan follow-up');
  await form.getByRole('button', { name: 'Tandai selesai' }).click();
  await form.getByText('Hasil follow-up wajib diisi.').waitFor();
  await field(form, 'Hasil follow-up').fill('Sampel disetujui, lanjut penawaran harga.');
  await form.getByLabel('Jadwalkan follow-up berikutnya').check();
  const nextDay = await page.evaluate(() => PIK.addDays(PIK.today(), 3));
  await field(form, 'Tanggal').fill(nextDay);
  await field(form, 'Tujuan').fill('Kirim penawaran harga');
  await form.getByRole('button', { name: 'Tandai selesai' }).click();
  await expectToast(page, 'Follow-up selesai.');
  await idle(page);
  await page.getByRole('tab', { name: /Mendatang/ }).click();
  await rows(page, 'Kirim penawaran harga').waitFor();
  const done = await api(page, 'followUps.list', { filters: { bucket: 'DONE', customer_id: state.customerId } });
  assert.equal(done.total, 1);
  assert.equal(done.items[0].result, 'Sampel disetujui, lanjut penawaran harga.');
});

test('purchase order: header + dua item dalam satu simpan; detail menunjukkan order dan outstanding', async () => {
  await go(page, `customers/${state.customerId}`);
  await page.getByRole('button', { name: 'Aksi lainnya' }).click();
  await page.getByRole('menuitem', { name: 'PO baru' }).click();
  const form = drawer(page, 'Purchase order baru');
  await field(form, 'Nomor PO').fill('PO/E2E/001');
  const items = form.locator('.card');
  await items.nth(0).getByPlaceholder('Pilih produk…').fill('Botol');
  await page.getByRole('option', { name: /Botol Uji 30ml/ }).first().dispatchEvent('mousedown');
  await items.nth(0).getByLabel('Qty order').fill('100');
  await items.nth(0).getByLabel('Harga satuan').fill('1.500');
  await form.getByRole('button', { name: 'Tambah item' }).click();
  await items.nth(1).getByPlaceholder('Pilih produk…').fill('Jar');
  await page.getByRole('option', { name: /Jar Uji 50gr/ }).first().dispatchEvent('mousedown');
  await items.nth(1).getByLabel('Qty order').fill('50');
  await shot(page, 'flow-06-po-form');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'PO dibuat.');
  await page.getByRole('heading', { name: 'PO/E2E/001' }).waitFor();
  await idle(page);
  state.poId = await page.evaluate(() => PIK.router.current.path.split('/')[1]);
  const stat = (label) => statValue(page, label);
  assert.equal(await stat('Order'), '150');
  assert.equal(await stat('Outstanding'), '150');
  assert.equal(await stat('Nilai PO'), 'Rp 150.000', 'harga "1.500" dibaca sebagai 1500 (format Indonesia)');
  const detail = await api(page, 'purchaseOrders.get', { id: state.poId });
  assert.equal(detail.lines.length, 2);
  assert.equal(detail.purchaseOrder.order_value, 150000);
  state.lineBottle = detail.lines.find((line) => /Botol/.test(line.product_label)).id;
});

test('delivery: qty melebihi sisa meminta konfirmasi; dibatalkan = tidak tersimpan; qty sesuai tersimpan', async () => {
  await main(page).getByRole('button', { name: 'Delivery', exact: true }).first().click();
  const form = drawer(page, 'Catat delivery');
  await pick(form, 'Item PO', 'E2E', 'PO/E2E/001 · [TSTBTL30] Botol Uji 30ml — Varian A');
  await form.locator('.form-alert', { hasText: /Order 100 · terkirim 0 · terjadwal 0 · sisa 100/ }).waitFor();
  await field(form, 'Qty kirim').fill('120');
  await field(form, 'Nomor SJ').fill('SJ/E2E/001');
  await form.getByRole('button', { name: 'Simpan' }).click();
  const confirm = page.getByRole('alertdialog', { name: 'Konfirmasi jumlah' });
  await confirm.waitFor();
  await shot(page, 'flow-07-over-quantity');
  await confirm.getByRole('button', { name: 'Batal' }).click();
  await form.getByText(/melebihi sisa baris PO \(100\)/).waitFor();
  assert.equal((await api(page, 'deliveries.list', { filters: { purchase_order_id: state.poId } })).total, 0, 'tidak ada yang tersimpan');
  await field(form, 'Qty kirim').fill('90');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Delivery dicatat.');
  await idle(page);
  const stat = (label) => statValue(page, label);
  assert.equal(await stat('Terkirim'), '90');
  assert.equal(await stat('Outstanding'), '60');
});

test('retur: dari item PO menambah outstanding kembali', async () => {
  await main(page).getByRole('button', { name: 'Retur', exact: true }).first().click();
  const form = drawer(page, 'Catat retur');
  await pick(form, 'Item PO', 'E2E', 'PO/E2E/001 · [TSTBTL30] Botol Uji 30ml — Varian A');
  await field(form, 'Qty retur').fill('10');
  await field(form, 'Alasan retur').fill('Cacat cetak pada 10 pcs');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Retur dicatat.');
  await idle(page);
  const stat = (label) => statValue(page, label);
  assert.equal(await stat('Retur'), '10');
  assert.equal(await stat('Outstanding'), '70', 'MAX(0, 150 − 90 + 10)');
  const line = (await api(page, 'purchaseOrders.get', { id: state.poId })).lines.find((l) => l.id === state.lineBottle);
  assert.deepEqual([line.order_quantity, line.delivered_quantity, line.returned_quantity, line.outstanding_quantity], [100, 90, 10, 20]);
  await shot(page, 'flow-08-po-detail', { fullPage: true });
});

test('dashboard: KPI berubah sesuai alur (customer, lead, PO terbuka, outstanding)', async () => {
  await page.locator('.sidebar').getByRole('button', { name: 'Dashboard', exact: true }).click();
  await page.getByRole('heading', { name: 'Dashboard' }).waitFor();
  await idle(page);
  const kpi = (label) => kpiValue(page, label);
  const b = state.before;
  assert.equal(await kpi('Total customer'), String(b.totalCustomers + 1));
  assert.equal(await kpi('Lead aktif'), String(b.activeLeads + 1));
  assert.equal(await kpi('PO terbuka'), String(b.openPurchaseOrders + 1));
  assert.equal(await kpi('Outstanding'), new Intl.NumberFormat('id-ID').format(b.outstandingQuantity + 70));
  assert.equal(await kpi('Follow-up hari ini'), '0', 'follow-up hari ini sudah selesai');
  await page.locator('.hbar', { hasText: 'Qualified' }).waitFor();
  await page.locator('.tl-item', { hasText: 'Kunjungan pabrik dan presentasi sampel' }).waitFor();
  await page.locator('.mini-item', { hasText: 'PO/E2E/001' }).waitFor();
  await shot(page, 'flow-09-dashboard-after');
});

test('PO line: tambah item dari detail PO; qty order di bawah qty terkirim meminta konfirmasi', async () => {
  await go(page, `purchase-orders/${state.poId}`);
  await page.getByRole('heading', { name: 'PO/E2E/001' }).waitFor();
  await idle(page);
  await main(page).getByRole('button', { name: 'Item', exact: true }).click();
  const add = drawer(page, 'Tambah item PO');
  await pick(add, 'Produk', 'Tube', 'Tube Uji 100ml');
  await field(add, 'Qty order').fill('30');
  await add.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Item PO ditambahkan.');
  await idle(page);
  await page.waitForFunction(() => /^180$/.test((Array.from(document.querySelectorAll('.stat')).find((el) => el.querySelector('.label').textContent === 'Order') || {}).textContent.replace(/\D/g, '')));
  assert.equal(await statValue(page, 'Outstanding'), '100');

  const items = page.locator('section').filter({ has: page.getByRole('heading', { name: /^Item PO/ }) });
  const bottle = items.locator('.table-scroll tbody tr', { hasText: 'Botol Uji 30ml' });
  await bottle.getByRole('button', { name: 'Aksi' }).click();
  await page.getByRole('menuitem', { name: 'Ubah' }).click();
  const edit = drawer(page, 'Ubah item PO');
  assert.equal(await field(edit, 'Produk').isDisabled(), true, 'produk terkunci karena sudah ada delivery');
  await field(edit, 'Qty order').fill('70');
  await edit.getByRole('button', { name: 'Simpan' }).click();
  const confirm = page.getByRole('alertdialog', { name: 'Konfirmasi jumlah' });
  await confirm.getByText(/lebih kecil dari qty yang sudah terkirim \(80/).waitFor();
  await confirm.getByRole('button', { name: 'Batal' }).click();
  await field(edit, 'Qty order').fill('90');
  await edit.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'Item PO diperbarui.');
  await idle(page);
  const line = (await api(page, 'purchaseOrders.get', { id: state.poId })).lines.find((l) => l.id === state.lineBottle);
  assert.deepEqual([line.order_quantity, line.outstanding_quantity], [90, 10]);
  assert.equal(await statValue(page, 'Outstanding'), '90', '10 + 50 + 30');
});

test('validasi form: wajib isi dan format dicek di browser; error server (kode ganda) tampil di kolomnya; input tidak hilang', async () => {
  await go(page, 'customers');
  await page.getByRole('button', { name: 'Customer baru' }).first().click();
  const form = drawer(page, 'Customer baru');
  let rpcCalls = 0;
  const count = () => { rpcCalls++; };
  page.on('request', (request) => { if (request.url().endsWith('/__rpc') && /customers\.create/.test(request.postData() || '')) count(); });
  await field(form, 'Email').fill('bukan-email');
  await field(form, 'Website').fill('www.tanpa-skema.example');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await form.getByText('Nama customer wajib diisi.').waitFor();
  await form.getByText('Format email tidak valid.').waitFor();
  await form.getByText('Tautan harus diawali http:// atau https://.').waitFor();
  assert.equal(rpcCalls, 0, 'form tidak valid tidak dikirim ke server');
  assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.id.indexOf('f-name') === 0), true, 'fokus ke kolom pertama yang salah');

  await field(form, 'Nama customer').fill('PT Kode Ganda');
  await field(form, 'Kode customer').fill('e2e-001');
  await field(form, 'Email').fill('ok@contoh.example');
  await field(form, 'Website').fill('https://contoh.example');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await form.getByText('Kode customer sudah dipakai customer lain.').waitFor();
  assert.equal(rpcCalls, 1);
  assert.equal(await field(form, 'Nama customer').inputValue(), 'PT Kode Ganda', 'input tetap');
  await shot(page, 'flow-10-validation');
  await form.getByRole('button', { name: 'Batal' }).click();
  const discard = page.getByRole('alertdialog', { name: 'Buang perubahan?' });
  await discard.waitFor();
  await discard.getByRole('button', { name: 'Buang' }).click();
  await drawer(page, 'Customer baru').waitFor({ state: 'detached' });
  assert.equal((await api(page, 'customers.list', { search: 'Kode Ganda' })).total, 0);
});

test('arsip: konfirmasi, tidak tampil di daftar, dapat diurungkan; riwayat mencatat semua perubahan', async () => {
  await go(page, `customers/${state.customerId}`);
  await page.getByRole('button', { name: 'Aksi lainnya' }).click();
  await page.getByRole('menuitem', { name: 'Arsipkan customer' }).click();
  const confirm = page.getByRole('alertdialog', { name: 'Arsipkan data ini?' });
  await confirm.getByRole('button', { name: 'Arsipkan' }).click();
  const toast = await expectToast(page, 'Customer diarsipkan.');
  assert.equal((await api(page, 'customers.list', { search: 'Uji Coba Kemasan' })).total, 0);
  await toast.getByRole('button', { name: 'Urungkan' }).click();
  await expectToast(page, 'Dipulihkan.');
  assert.equal((await api(page, 'customers.list', { search: 'Uji Coba Kemasan' })).total, 1);

  await go(page, `customers/${state.customerId}?tab=history`);
  const history = page.locator('.timeline');
  await history.getByText('Restore').waitFor();
  assert.ok(await history.getByText('Archive').isVisible());
  assert.ok(await history.getByText('Create').first().isVisible());
});

test('audit log (Admin): perubahan alur tercatat dengan pelaku dan nilai lama → baru; pencarian juga membaca isi perubahan', async () => {
  await go(page, 'settings?tab=audit');
  await idle(page);
  const reply = page.waitForResponse((r) => r.url().endsWith('/__rpc') && (r.request().postData() || '').includes(state.lead.id));
  await page.getByPlaceholder(/Cari ID record/).fill(state.lead.id);
  await reply;
  await page.waitForFunction(() => !document.querySelector('#main [style*="opacity"]'));
  const row = rows(page, state.lead.id).filter({ hasText: 'Update' });
  await row.waitFor();
  assert.match(await row.locator('.change-list').textContent(), /status: NEW → QUALIFIED/);
  assert.match(await row.textContent(), /owner@example\.com/);
  // The lead itself (create + move) and the records that reference it (activity, follow-ups).
  const entities = await rows(page).locator('td:nth-child(4) > span:first-child').allTextContents();
  assert.ok(entities.length >= 4 && entities.length <= 10, `entri: ${entities.join(', ')}`);
  assert.deepEqual([...new Set(entities)].sort(), ['Aktivitas', 'Follow-up', 'Lead']);
  await shot(page, 'flow-11-audit');
  assertNoProblems(session);
});
