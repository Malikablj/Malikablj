'use strict';

/**
 * Phase 04 backend on migrated data: the synthetic workbook of Phase 03 (invented data) is migrated with the real
 * runner, then the application API is used on the result — every list and report works on legacy rows, the derived
 * outstanding matches the migration reconciliation, legacy gaps are shown rather than guessed, migrated records keep
 * their lineage and history, and an Admin can resolve a legacy delivery by linking it to its PO line (D3).
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

const { analyzeWorkbook } = require('../migration/scripts/lib/analyze');
const { buildMigrationPackage } = require('../migration/scripts/lib/migration/build-package');
const { call, createRehearsal, plain, runUntilComplete, uploadPackage } = require('../migration/scripts/lib/migration/rehearsal');
const { loadTarget } = require('../migration/scripts/lib/migration/target');
const { ID, syntheticSheets, writeWorkbook } = require('./fixtures/synthetic-workbook');
const { assertTransportSafe } = require('./support/api-harness');

function migratedApp() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pik-api-migrated-'));
  const file = writeWorkbook(path.join(dir, 'Synthetic.xlsx'), syntheticSheets());
  const pkg = buildMigrationPackage(analyzeWorkbook(file, { asOf: '2025-06-30' }), loadTarget());
  const rehearsal = createRehearsal({ now: '2026-09-28T03:00:00.000Z' });
  uploadPackage(rehearsal, JSON.stringify(pkg));
  assert.equal(call(rehearsal, 'dryRunMigration').result.ok, true);
  const runs = runUntilComplete(rehearsal);
  const run = runs[runs.length - 1].result;
  assert.equal(run.completed, true);
  const api = (action, payload = {}) => {
    const response = rehearsal.context.api(action, payload);
    assertTransportSafe(response, action);
    const data = plain(response);
    assert.equal(data.success, true, `${action}: ${JSON.stringify(data.error)}`);
    return data.data;
  };
  return { rehearsal, run, pkg, api };
}

const shared = migratedApp();

test('data migrasi: operator (pemilik skrip) menjadi Admin; setiap daftar, detail, dan laporan berjalan pada record legacy', () => {
  const { api, rehearsal } = shared;
  const session = api('session.login');
  assert.equal(session.user.role, 'ADMIN');
  assert.equal(session.user.email, rehearsal.operator);

  const routes = rehearsal.context.defineApiRoutes_();
  const lists = Object.keys(routes).filter((action) => /\.(list|board|counts|summary|options|lineOptions)$/.test(action));
  for (const action of lists) api(action, { includeInactive: true });
  for (const action of Object.keys(routes).filter((name) => name.startsWith('reports.'))) {
    api(action, {});
    assert.ok(api(action, { format: 'csv' }).csv.length > 0);
  }
  for (const [action, id] of [['customers.get', ID.customer.one], ['purchaseOrders.get', ID.po.open], ['purchaseOrders.get', ID.po.placeholder],
    ['products.get', ID.product.bottle], ['invoices.get', ID.invoice.partial]]) {
    api(action, { id });
  }
  assert.equal(api('purchaseOrders.list', { includeInactive: true }).total, shared.pkg.tables.PURCHASE_ORDERS.length);
  assert.equal(api('deliveries.list', { includeInactive: true }).total, shared.pkg.tables.DELIVERIES.length);
});

test('data migrasi: outstanding PO terbuka dari API sama dengan rekonsiliasi migrasi; transaksi tanpa baris PO dilaporkan', () => {
  const { api, run } = shared;
  const reconciliation = run.verification.reconciliation.summary.outstanding.openPurchaseOrders;
  const open = api('purchaseOrders.list', { filters: { open: true }, pageSize: 100 });
  assert.equal(open.total, reconciliation.purchaseOrders);
  const outstanding = open.items.reduce((sum, po) => sum + po.outstanding_quantity, 0);
  assert.equal(outstanding, reconciliation.computedTotal);
  assert.equal(api('dashboard.summary', {}).kpis.outstandingQuantity, reconciliation.computedTotal);

  const unlinked = api('deliveries.list', { filters: { linked: false }, includeInactive: true, pageSize: 100 });
  assert.ok(unlinked.total > 0);
  assert.ok(unlinked.items.every((item) => item.is_linked === false && item.is_legacy === true));
  const placeholder = api('purchaseOrders.get', { id: ID.po.placeholder });
  assert.equal(placeholder.purchaseOrder.is_legacy, true);
  const line = placeholder.lines[0];
  assert.equal(typeof line.outstanding_qty_legacy, 'number', 'nilai legacy tetap tersedia untuk dibandingkan');
});

test('data migrasi: lineage dan riwayat (MIGRATION_RUN) terlihat; record legacy dapat diedit tanpa kehilangan nilai legacy', () => {
  const { api } = shared;
  const detail = api('customers.get', { id: ID.customer.one });
  assert.equal(detail.customer.is_legacy, true);
  assert.ok(detail.customer.import_ref, 'import_ref menunjuk ke workbook sumber');
  const history = api('audit.history', { entityType: 'PURCHASE_ORDERS', entityId: ID.po.open });
  assert.equal(history.items[history.items.length - 1].action, 'MIGRATION_RUN');
  assert.equal(typeof history.items[history.items.length - 1].changes.count, 'number');

  const before = api('purchaseOrders.get', { id: ID.po.hold }).purchaseOrder;
  const edited = api('purchaseOrders.update', { id: ID.po.hold, expectedUpdatedAt: before.updated_at, data: { notes: 'Dicek ulang oleh Admin' } });
  assert.equal(edited.notes, 'Dicek ulang oleh Admin');
  assert.equal(edited.status_legacy, before.status_legacy);
  assert.equal(edited.is_legacy, true);
  const issues = api('migrationIssues.list', { pageSize: 100 });
  assert.equal(issues.counts.total, shared.pkg.tables.MIGRATION_ISSUES.length);
  assert.ok(issues.counts.open > 0);
});

test('data migrasi: Admin menautkan delivery legacy ke baris PO yang benar (D3); baris PO lain ditolak', () => {
  const { api, rehearsal } = shared;
  const raw = (action, payload) => plain(rehearsal.context.api(action, payload));
  const lineOf = () => api('purchaseOrders.get', { id: ID.po.open }).lines.find((line) => line.id === ID.line.openBottle);
  const before = lineOf();
  const legacy = api('deliveries.list', { filters: { linked: false, purchase_order_id: ID.po.open } }).items;
  assert.deepEqual(legacy.map((item) => [item.id, item.product_id, item.quantity]), [[ID.delivery.withoutProduct, null, 50]]);

  const wrong = raw('deliveries.update', { id: ID.delivery.withoutProduct, data: { po_line_id: ID.line.closedJar } });
  assert.equal(wrong.success, false);
  assert.deepEqual(wrong.error.details.errors.map((item) => item.code), ['REF_MISMATCH'], 'baris dari PO lain');

  const linked = api('deliveries.update', { id: ID.delivery.withoutProduct, data: { po_line_id: ID.line.openBottle } });
  assert.equal(linked.is_linked, true);
  assert.equal(linked.product_id, ID.product.bottle, 'produk mengikuti baris PO yang dipilih Admin');
  assert.equal(linked.is_legacy, true);
  const after = lineOf();
  assert.equal(after.delivered_quantity, before.delivered_quantity + 50);
  assert.equal(after.outstanding_quantity, before.outstanding_quantity - 50);
  const history = api('audit.history', { entityType: 'DELIVERIES', entityId: ID.delivery.withoutProduct });
  assert.deepEqual(history.items[0].changes.po_line_id, [null, ID.line.openBottle]);
  assert.equal(history.items[0].actor_email, rehearsal.operator);
});
