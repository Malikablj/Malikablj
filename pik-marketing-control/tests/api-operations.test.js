'use strict';

/**
 * Phase 04 backend — operations: purchase orders with lines (one unit), deliveries and returns linked to PO lines, the
 * derived quantities (delivered, returned, outstanding = MAX(0, order − delivered + returned), scheduled), confirmable
 * over-quantity checks, guards that keep transactions attached to their PO, products, stock and lead-time schedules.
 * All data is invented.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');

const { TODAY, createApp, seedOrder } = require('./support/api-harness');

function progress(app, poId) {
  const detail = app.ok('viewer', 'purchaseOrders.get', { id: poId });
  const byLine = {};
  for (const line of detail.lines) {
    byLine[line.id] = [line.order_quantity, line.delivered_quantity, line.returned_quantity, line.outstanding_quantity, line.scheduled_quantity];
  }
  return { po: detail.purchaseOrder, byLine, detail };
}

test('PO: header + baris dalam satu unit; baris tidak valid = tidak ada yang tersimpan; default dan satuan dari produk', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const posBefore = app.rows('PURCHASE_ORDERS').length;
  const failed = app.fail('marketing', 'purchaseOrders.create', {
    data: { po_number: 'PO/TEST/002', customer_id: seed.customer.id },
    lines: [{ product_id: seed.productA.id, order_quantity: 10 }, { product_id: seed.productB.id, order_quantity: 0 }],
  }, 'VALIDATION_ERROR');
  assert.deepEqual(failed.details.errors.map((e) => [e.table, e.index, e.field, e.code]), [['PO_LINES', 1, 'order_quantity', 'MIN']]);
  assert.equal(app.rows('PURCHASE_ORDERS').length, posBefore, 'header tidak tersimpan tanpa barisnya');

  const po = app.ok('marketing', 'purchaseOrders.create', {
    data: { po_number: 'po/test/002', customer_id: seed.customer.id, expected_delivery_date: '2026-10-15' },
    lines: [{ product_id: seed.productA.id, order_quantity: '25' }],
  });
  assert.equal(po.status, 'OPEN');
  assert.equal(po.po_date, TODAY);
  assert.equal(po.owner_user_id, app.users.marketing.id);
  assert.equal(po.line_count, 1);
  assert.equal(po.fulfillment, 'NOT_STARTED');
  const line = app.ok('viewer', 'purchaseOrders.get', { id: po.id }).lines[0];
  assert.equal(line.unit, 'pcs', 'satuan diambil dari produk');
  assert.equal(line.order_quantity, 25);

  const duplicate = app.fail('marketing', 'purchaseOrders.create', { data: { po_number: 'PO / TEST / 002', customer_id: seed.customer.id } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(duplicate), ['po_number:UNIQUE'], 'nomor PO unik per customer (tanpa spasi/huruf besar-kecil)');
  const otherCustomer = app.ok('marketing', 'customers.create', { data: { name: 'PT Pemesan Lain' } });
  app.ok('marketing', 'purchaseOrders.create', { data: { po_number: 'PO/TEST/002', customer_id: otherCustomer.id } });
  app.ok('marketing', 'purchaseOrders.setStatus', { id: po.id, status: 'CANCELLED' });
  app.ok('marketing', 'purchaseOrders.create', { data: { po_number: 'PO/TEST/002', customer_id: seed.customer.id } });

  const badDate = app.fail('marketing', 'purchaseOrders.update', { id: po.id, data: { expected_delivery_date: '2026-01-01' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(badDate), ['expected_delivery_date:RULE']);
  app.fail('sales', 'purchaseOrders.create', { data: { po_number: 'X', customer_id: seed.customer.id } }, 'FORBIDDEN');
});

test('delivery: wajib baris PO; PO/produk dari baris; outstanding dihitung; kelebihan qty harus dikonfirmasi', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const missing = app.fail('admin', 'deliveries.create', { data: { quantity: 5 } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(missing), ['po_line_id:REQUIRED']);

  const first = app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 60, sj_number: 'SJ/001' } });
  assert.equal(first.purchase_order_id, seed.po.id);
  assert.equal(first.product_id, seed.productA.id);
  assert.equal(first.status, 'DELIVERED');
  assert.equal(first.delivery_date, TODAY);
  assert.equal(first.counts_as_delivered, true);
  assert.deepEqual(progress(app, seed.po.id).byLine[seed.lineA.id], [100, 60, 0, 40, 0]);

  // Scheduled deliveries do not count as delivered but reserve what is left.
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 30, status: 'SCHEDULED', delivery_date: '2026-10-03' } });
  assert.deepEqual(progress(app, seed.po.id).byLine[seed.lineA.id], [100, 60, 0, 40, 30]);
  const over = app.fail('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: '11' } }, 'VALIDATION_ERROR');
  assert.equal(over.details.confirmable, true);
  assert.equal(over.details.available, 10);
  assert.deepEqual(app.fieldErrors(over), ['quantity:OVER_QUANTITY'], 'qty berupa teks tetap diperiksa');
  const confirmed = app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 11 }, confirmOverQuantity: true });
  assert.deepEqual(progress(app, seed.po.id).byLine[seed.lineA.id], [100, 71, 0, 29, 30]);

  // Editing a delivery credits its own previous quantity; cancelled and archived deliveries stop counting.
  // Available for this delivery = outstanding 29 − scheduled 30 + its own 60 = 59.
  const edit = app.fail('admin', 'deliveries.update', { id: first.id, data: { quantity: 60.5 } }, 'VALIDATION_ERROR');
  assert.equal(edit.details.available, 59);
  app.ok('admin', 'deliveries.update', { id: first.id, data: { quantity: 58 } });
  app.ok('admin', 'deliveries.update', { id: confirmed.id, data: { status: 'CANCELLED' } });
  assert.deepEqual(progress(app, seed.po.id).byLine[seed.lineA.id], [100, 58, 0, 42, 30]);
  app.ok('admin', 'deliveries.archive', { id: first.id });
  assert.deepEqual(progress(app, seed.po.id).byLine[seed.lineA.id], [100, 0, 0, 100, 30]);
  app.ok('admin', 'deliveries.restore', { id: first.id });

  const mismatch = app.fail('admin', 'deliveries.update', { id: first.id, data: { po_line_id: seed.lineB.id } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(mismatch), ['po_line_id:REF_MISMATCH']);
  app.fail('admin', 'deliveries.update', { id: first.id, data: { po_line_id: null } }, 'VALIDATION_ERROR');

  const list = app.ok('viewer', 'deliveries.list', { filters: { purchase_order_id: seed.po.id, status: 'SCHEDULED' } });
  assert.equal(list.total, 1);
  assert.equal(list.items[0].customer_name, 'PT Contoh Kemasan');
  assert.equal(app.ok('viewer', 'deliveries.list', { search: 'sj/001' }).items[0].id, first.id);

  app.ok('marketing', 'purchaseOrders.setStatus', { id: seed.po.id, status: 'CLOSED' });
  const closed = app.fail('admin', 'deliveries.create', { data: { po_line_id: seed.lineB.id, quantity: 1 } }, 'VALIDATION_ERROR');
  assert.match(closed.message, /CLOSED/);
});

test('retur: dari baris PO menambah outstanding; retur melebihi qty terkirim harus dikonfirmasi; CANCELLED tidak dihitung', () => {
  const app = createApp();
  const seed = seedOrder(app);
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineB.id, quantity: 50 } });
  let state = progress(app, seed.po.id);
  assert.deepEqual(state.byLine[seed.lineB.id], [50, 50, 0, 0, 0]);
  assert.equal(state.po.fulfillment, 'PARTIAL');

  const ret = app.ok('admin', 'returns.create', { data: { po_line_id: seed.lineB.id, quantity: 8, reason: 'Retak' } });
  assert.equal(ret.status, 'OPEN');
  assert.equal(ret.purchase_order_id, seed.po.id);
  assert.equal(ret.product_id, seed.productB.id);
  assert.deepEqual(progress(app, seed.po.id).byLine[seed.lineB.id], [50, 50, 8, 8, 0]);

  const tooMany = app.fail('admin', 'returns.create', { data: { po_line_id: seed.lineB.id, quantity: 43 } }, 'VALIDATION_ERROR');
  assert.equal(tooMany.details.available, 42);
  app.ok('admin', 'returns.update', { id: ret.id, data: { status: 'CANCELLED' } });
  assert.deepEqual(progress(app, seed.po.id).byLine[seed.lineB.id], [50, 50, 0, 0, 0]);

  // A return without a line keeps its product (and PO when given); it is reported, not guessed onto a line.
  const loose = app.ok('admin', 'returns.create', { data: { product_id: seed.productA.id, purchase_order_id: seed.po.id, quantity: 2 } });
  assert.equal(loose.is_linked, false);
  state = progress(app, seed.po.id);
  assert.equal(state.po.unlinked_returned_quantity, 2);
  const mismatch = app.fail('admin', 'returns.create', { data: { po_line_id: seed.lineA.id, product_id: seed.productB.id, quantity: 1 } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(mismatch), ['po_line_id:REF_MISMATCH']);
  assert.equal(app.ok('viewer', 'returns.list', { filters: { linked: false } }).total, 1);
});

test('outstanding: kirim lebih dari order = 0 (bukan negatif) dan tercatat sebagai kelebihan; PO lengkap = COMPLETE', () => {
  const app = createApp();
  const seed = seedOrder(app);
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 105 }, confirmOverQuantity: true });
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineB.id, quantity: 50 } });
  const { po, detail } = progress(app, seed.po.id);
  const lineA = detail.lines.find((line) => line.id === seed.lineA.id);
  assert.equal(lineA.outstanding_quantity, 0);
  assert.equal(lineA.over_delivered_quantity, 5);
  assert.equal(lineA.line_value, 150000);
  assert.equal(po.outstanding_quantity, 0);
  assert.equal(po.fulfillment, 'COMPLETE');
  assert.equal(po.order_value, 150000, 'nilai hanya dari baris yang berharga');
  app.ok('admin', 'returns.create', { data: { po_line_id: seed.lineA.id, quantity: 10 } });
  assert.equal(progress(app, seed.po.id).po.outstanding_quantity, 5, 'MAX(0, 100 - 105 + 10)');
});

test('baris PO: produk tidak bisa diganti setelah ada transaksi; qty order < terkirim perlu konfirmasi; arsip dijaga', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const extra = app.ok('marketing', 'poLines.create', { data: { purchase_order_id: seed.po.id, product_id: seed.productB.id, order_quantity: 7 } });
  assert.equal(extra.outstanding_quantity, 7);
  assert.equal(extra.po_label, 'PO/TEST/001');
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 30 } });

  const swap = app.fail('marketing', 'poLines.update', { id: seed.lineA.id, data: { product_id: seed.productB.id } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(swap), ['product_id:IN_USE']);
  const below = app.fail('marketing', 'poLines.update', { id: seed.lineA.id, data: { order_quantity: 20 } }, 'VALIDATION_ERROR');
  assert.equal(below.details.confirmable, true);
  assert.deepEqual(app.fieldErrors(below), ['order_quantity:OVER_QUANTITY']);
  app.ok('marketing', 'poLines.update', { id: seed.lineA.id, data: { order_quantity: 20 }, confirmOverQuantity: true });
  app.ok('marketing', 'poLines.update', { id: seed.lineA.id, data: { order_quantity: 100, notes: 'Kembali ke 100' } });

  const archive = app.fail('marketing', 'poLines.archive', { id: seed.lineA.id }, 'VALIDATION_ERROR');
  assert.match(archive.message, /1 delivery/);
  app.ok('marketing', 'poLines.archive', { id: extra.id });
  const { po } = progress(app, seed.po.id);
  assert.equal(po.line_count, 2, 'baris diarsip tidak dihitung');
  assert.equal(po.order_quantity, 150);

  const poArchive = app.fail('marketing', 'purchaseOrders.archive', { id: seed.po.id }, 'VALIDATION_ERROR');
  assert.match(poArchive.message, /Cancelled/);
  const lineOptions = app.ok('viewer', 'purchaseOrders.lineOptions', { purchase_order_id: seed.po.id });
  assert.deepEqual(lineOptions.items.map((item) => item.outstanding_quantity).sort((a, b) => a - b), [50, 70]);
  app.ok('marketing', 'purchaseOrders.setStatus', { id: seed.po.id, status: 'CLOSED' });
  assert.equal(app.ok('viewer', 'purchaseOrders.lineOptions', {}).total, 0, 'baris PO tertutup tidak ditawarkan');
  assert.equal(app.ok('viewer', 'dashboard.summary', {}).kpis.openPurchaseOrders, 0);
});

test('daftar PO: filter terbuka/fulfillment/tanggal, urut outstanding, pencarian nomor dan customer', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const second = app.ok('marketing', 'purchaseOrders.create', {
    data: { po_number: 'PO/TEST/009', customer_id: seed.customer.id, po_date: '2026-08-01' },
    lines: [{ product_id: seed.productA.id, order_quantity: 400 }],
  });
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineB.id, quantity: 20 } });
  let list = app.ok('viewer', 'purchaseOrders.list', { sort: { field: 'outstanding_quantity', direction: 'desc' } });
  assert.deepEqual(list.items.map((item) => [item.po_number, item.outstanding_quantity]), [['PO/TEST/009', 400], ['PO/TEST/001', 130]]);
  list = app.ok('viewer', 'purchaseOrders.list', { filters: { fulfillment: 'PARTIAL' } });
  assert.deepEqual(list.items.map((item) => item.id), [seed.po.id]);
  list = app.ok('viewer', 'purchaseOrders.list', { filters: { po_date: { from: '2026-08-01', to: '2026-08-31' } } });
  assert.deepEqual(list.items.map((item) => item.id), [second.id]);
  list = app.ok('viewer', 'purchaseOrders.list', { search: 'contoh kemasan 009' });
  assert.deepEqual(list.items.map((item) => item.id), [second.id]);
  const options = app.ok('viewer', 'purchaseOrders.options', { customer_id: seed.customer.id, openOnly: true });
  assert.equal(options.total, 2);
  const kpis = app.ok('viewer', 'dashboard.summary', {}).kpis;
  assert.equal(kpis.openPurchaseOrders, 2);
  assert.equal(kpis.outstandingQuantity, 530);
});

test('produk dan stok: stok terbaru per produk/jenis, outstanding PO terbuka per produk, detail produk', () => {
  const app = createApp();
  const seed = seedOrder(app);
  app.fail('marketing', 'products.create', { data: { name: 'X' } }, 'FORBIDDEN');
  const invalid = app.fail('admin', 'products.create', { data: { name: '', lead_time_days: -3 } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(invalid).sort(), ['lead_time_days:MIN', 'name:REQUIRED']);

  app.ok('admin', 'stock.create', { data: { product_id: seed.productA.id, quantity: 500, stock_date: '2026-09-20', warehouse: 'Gudang A' } });
  const latest = app.ok('admin', 'stock.create', { data: { product_id: seed.productA.id, quantity: 420, warehouse: 'Gudang A' } });
  assert.equal(latest.stock_type, 'FG');
  assert.equal(latest.stock_date, TODAY);
  app.ok('admin', 'stock.create', { data: { product_id: seed.productA.id, stock_type: 'WIP', quantity: 80, stock_date: '2026-09-27' } });
  app.ok('admin', 'stock.create', { data: { product_id: seed.productB.id, quantity: 1000, stock_date: '2026-09-25', status: 'RESERVED' } });
  app.fail('admin', 'stock.create', { data: { product_id: seed.productB.id, quantity: -1 } }, 'VALIDATION_ERROR');
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 30 } });

  const products = app.ok('viewer', 'products.list', { sort: { field: 'product_code' } }).items;
  assert.deepEqual(products.map((p) => [p.product_code, p.stock_fg, p.stock_wip, p.open_outstanding_quantity]),
    [['BTL-100', 420, 80, 70], ['CAP-28', 1000, null, 50]]);
  assert.equal(app.ok('viewer', 'products.list', { filters: { has_open_order: true } }).total, 2);

  const summary = app.ok('viewer', 'stock.summary', {});
  assert.deepEqual(summary.totals, { FG: { products: 2, quantity: 1420 }, WIP: { products: 1, quantity: 80 } });
  assert.equal(summary.unlinkedRecords, 0);
  assert.equal(app.ok('viewer', 'stock.list', { filters: { product_id: seed.productA.id } }).total, 3);

  const detail = app.ok('viewer', 'products.get', { id: seed.productA.id });
  assert.equal(detail.product.label, 'BTL-100 Botol 100 ml');
  assert.equal(detail.stock.length, 3);
  assert.equal(detail.lines[0].customer_name, 'PT Contoh Kemasan');
  assert.equal(detail.lines[0].outstanding_quantity, 70);
  assert.equal(app.ok('viewer', 'products.options', { search: 'tutup' }).items[0].id, seed.productB.id);
});

test('lead time: PO dan produk dari baris PO, customer dari PO; jadwal tidak dihitung sebagai terkirim; inbound hanya baca', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const schedule = app.ok('admin', 'leadTime.create', { data: { po_line_id: seed.lineA.id, planned_date: '2026-10-12', quantity: 40, lead_time_days: 14 } });
  assert.equal(schedule.purchase_order_id, seed.po.id);
  assert.equal(schedule.product_id, seed.productA.id);
  assert.equal(schedule.customer_id, seed.customer.id);
  assert.equal(schedule.status, 'SCHEDULED');
  assert.equal(progress(app, seed.po.id).byLine[seed.lineA.id][1], 0, 'jadwal lead time bukan delivery (D10)');
  app.fail('marketing', 'leadTime.create', { data: { po_line_id: seed.lineA.id, planned_date: TODAY, quantity: 1 } }, 'FORBIDDEN');
  const list = app.ok('marketing', 'leadTime.list', { filters: { planned_date: { from: '2026-10-01' } } });
  assert.equal(list.items[0].customer_name, 'PT Contoh Kemasan');
  assert.equal(app.ok('viewer', 'purchaseOrders.get', { id: seed.po.id }).leadTimes.length, 1);
  assert.equal(app.ok('viewer', 'inbound.list', {}).total, 0);
  app.fail('admin', 'inbound.create', {}, 'NOT_FOUND');
});
