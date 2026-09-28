'use strict';

/**
 * Phase 03 migration pipeline (PROFILE → MAP → VALIDATE → DRY RUN → MIGRATE → VERIFY → REPORT) on a synthetic workbook
 * (tests/fixtures/synthetic-workbook.js, invented data only): mapping rules, traceability, relations that are never
 * guessed, rows that are never dropped, the dry-run gate, idempotent reruns, user edits and conflicts, resuming after
 * the time limit, verification catching a damaged migration, reconciliation, the report and the CLI.
 * The real workbook is only used by the last test, locally, when it is present (it is never committed).
 */

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { test } = require('node:test');

const { analyzeWorkbook } = require('../migration/scripts/lib/analyze');
const { buildMigrationPackage } = require('../migration/scripts/lib/migration/build-package');
const { MAPPING_DECISIONS } = require('../migration/scripts/lib/migration/mapping');
const { call, createRehearsal, plain, runUntilComplete, uploadPackage } = require('../migration/scripts/lib/migration/rehearsal');
const { buildMarkdownReport, buildSummary } = require('../migration/scripts/lib/migration/report');
const { loadTarget } = require('../migration/scripts/lib/migration/target');
const { ID, SOURCE_A, syntheticSheets, writeWorkbook } = require('./fixtures/synthetic-workbook');

const PROJECT_ROOT = path.resolve(__dirname, '..');
const AS_OF = '2025-06-30';
const WORKBOOK_NAME = 'Synthetic.xlsx';
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pik-migration-test-'));
const target = loadTarget();
const sha256 = (data) => crypto.createHash('sha256').update(data).digest('hex');

/** Workbook → package. Each call writes its own file so tests never share state. */
function build(sheets = syntheticSheets()) {
  const dir = fs.mkdtempSync(path.join(tmp, 'wb-'));
  const file = writeWorkbook(path.join(dir, WORKBOOK_NAME), sheets);
  const analysis = analyzeWorkbook(file, { asOf: AS_OF });
  return { file, analysis, pkg: buildMigrationPackage(analysis, target) };
}

const basePackage = build();

function record(pkg, tableName, id) {
  const found = pkg.tables[tableName].find((item) => item.id === id);
  assert.ok(found, `${tableName} ${id} ada di paket`);
  return found;
}

function issuesOf(pkg, recordId) {
  return pkg.tables.MIGRATION_ISSUES.filter((issue) => issue.record_id === recordId);
}

function transformation(pkg, tableName, id, column) {
  return pkg.transformations.find((item) => item.table === tableName && item.id === id && item.column === column);
}

/** Recomputes packageHash after a deliberate change (a package changed without it is rejected by the hash check). */
function rehash(pkg) {
  const copy = JSON.parse(JSON.stringify(pkg));
  delete copy.packageHash;
  copy.packageHash = sha256(JSON.stringify(copy));
  return copy;
}

/** Emulated Apps Script project with an empty database and the package uploaded, as the operator prepares it. */
function prepared(pkg) {
  const rehearsal = createRehearsal();
  uploadPackage(rehearsal, JSON.stringify(pkg));
  return rehearsal;
}

function migrated(pkg = basePackage.pkg) {
  const rehearsal = prepared(pkg);
  const dryRun = call(rehearsal, 'dryRunMigration');
  assert.equal(dryRun.result && dryRun.result.ok, true, JSON.stringify(dryRun.error || (dryRun.result && dryRun.result.errors)));
  const runs = runUntilComplete(rehearsal);
  const last = runs[runs.length - 1];
  assert.equal(last.result && last.result.completed, true, JSON.stringify(last.error));
  return { rehearsal, dryRun: dryRun.result, run: last.result };
}

function rows(rehearsal, tableName) {
  rehearsal.context.resetDbCache_();
  return plain(rehearsal.context.loadTable_(tableName).records);
}

function stored(rehearsal, tableName, id) {
  return rows(rehearsal, tableName).find((item) => item.id === id) || null;
}

function sheetOf(rehearsal, tableName) {
  return rehearsal.context.getDatabaseSpreadsheet_().getSheetByName(tableName);
}

/** Changes one cell straight in the sheet, bypassing the application (a manual edit or a damaged migration). */
function setCell(rehearsal, tableName, id, column, value) {
  const sheet = sheetOf(rehearsal, tableName);
  const values = sheet.getDataRange().getValues();
  const row = values.findIndex((item) => item[0] === id);
  assert.ok(row > 0, `${id} ada di sheet ${tableName}`);
  sheet.getRange(row + 1, values[0].indexOf(column) + 1).setValue(value);
}

function checkStatus(verify, prefix) {
  const check = verify.checks.find((item) => item.name.startsWith(prefix));
  assert.ok(check, `pemeriksaan "${prefix}" ada`);
  return check.status;
}

const failedChecks = (verify) => verify.checks.filter((check) => check.status === 'FAIL').map((check) => check.name);

// ---------------------------------------------------------------------------------------------------------------
// PROFILE + MAP + package VALIDATE
// ---------------------------------------------------------------------------------------------------------------

test('profil: workbook sumber hanya dibaca (SHA-256 dan waktu ubah sama sebelum dan sesudah)', () => {
  const dir = fs.mkdtempSync(path.join(tmp, 'ro-'));
  const file = writeWorkbook(path.join(dir, WORKBOOK_NAME));
  const before = { hash: sha256(fs.readFileSync(file)), mtime: fs.statSync(file).mtimeMs };
  const analysis = analyzeWorkbook(file, { asOf: AS_OF });
  const pkg = buildMigrationPackage(analysis, target);
  assert.equal(sha256(fs.readFileSync(file)), before.hash);
  assert.equal(fs.statSync(file).mtimeMs, before.mtime);
  assert.equal(pkg.source.sha256, before.hash, 'hash sumber tercatat di paket');
  assert.equal(pkg.source.file, WORKBOOK_NAME);
});

test('paket: setiap baris sumber tercatat tepat sekali; tanpa error struktural', () => {
  const { pkg, analysis } = basePackage;
  assert.deepEqual(pkg.structuralErrors, []);
  const sourceRows = Object.values(analysis.tables).reduce((total, table) => total + table.records.length, 0);
  assert.equal(pkg.accounting.length, sourceRows, 'satu entri accounting per baris sumber');
  const keys = pkg.accounting.map((entry) => `${entry.sheet}!${entry.row}`);
  assert.equal(new Set(keys).size, keys.length, 'tidak ada baris yang tercatat dua kali');
  assert.deepEqual(pkg.expectations.dispositions, { MIGRATED: 40, EXCLUDED: 3, REPRESENTED: 7, NOT_MIGRATED: 7, ISSUE_ONLY: 2 });
  const businessRecords = pkg.loadOrder.filter((name) => name !== 'MIGRATION_ISSUES')
    .reduce((total, name) => total + pkg.tables[name].length, 0);
  assert.equal(businessRecords, pkg.expectations.dispositions.MIGRATED, 'setiap record bisnis berasal dari satu baris MIGRATED');
  assert.equal(pkg.packageHash, rehash(pkg).packageHash, 'packageHash = SHA-256 isi paket');
});

test('paket: ID workbook dipakai sebagai ID permanen; lineage dan import_ref tersimpan', () => {
  const { pkg } = basePackage;
  const po = record(pkg, 'PURCHASE_ORDERS', ID.po.open);
  assert.equal(po.source_file, SOURCE_A);
  assert.equal(po.source_sheet, 'PO');
  assert.equal(po.legacy_row, 5);
  assert.equal(po.import_ref, `${WORKBOOK_NAME}#PURCHASE_ORDERS!2`);
  assert.equal(po.is_legacy, true);
  assert.match(po.migration_hash, /^[0-9a-f]{64}$/);
  for (const name of pkg.loadOrder) {
    for (const item of pkg.tables[name]) assert.match(item.id, /^[A-Z]{2,4}-[0-9A-F]{10}$/, `${name}: ID bukan nomor baris`);
  }
  const customer = record(pkg, 'CUSTOMERS', ID.customer.one);
  assert.equal(customer.source_file, undefined, 'sheet tanpa lineage legacy: hanya import_ref');
  assert.equal(customer.import_ref, `${WORKBOOK_NAME}#CUSTOMERS!2`);
});

test('pemetaan: status, nilai legacy apa adanya, dan setiap transformasi tercatat dengan nilai asal', () => {
  const { pkg } = basePackage;
  const hold = record(pkg, 'PURCHASE_ORDERS', ID.po.hold);
  assert.equal(hold.status, 'ON_HOLD', 'D4 default');
  assert.equal(hold.status_legacy, 'Hold');
  assert.equal(record(pkg, 'PURCHASE_ORDERS', ID.po.cancelled).status, 'CANCELLED');
  const placeholder = record(pkg, 'PURCHASE_ORDERS', ID.po.placeholder);
  assert.equal(placeholder.po_number, null, 'teks pengganti bukan nomor PO (D7)');
  assert.equal(placeholder.po_number_legacy, 'Tanpa Nomor');
  assert.match(transformation(pkg, 'PURCHASE_ORDERS', ID.po.placeholder, 'po_number').rule, /^D7/);

  assert.equal(record(pkg, 'CUSTOMERS', ID.customer.one).notes, 'Catatan dengan spasi');
  const trim = transformation(pkg, 'CUSTOMERS', ID.customer.one, 'notes');
  assert.equal(trim.from, '  Catatan dengan spasi  ');
  assert.match(trim.rule, /^T-01/);
  assert.equal(record(pkg, 'PO_LINES', ID.line.openBottle).product_name_legacy, ' Botol  Uji 30ml ', 'nilai legacy apa adanya');

  const paid = record(pkg, 'INVOICES_PAYMENTS', ID.invoice.paid);
  assert.equal(paid.amount, 1234.57);
  assert.equal(transformation(pkg, 'INVOICES_PAYMENTS', ID.invoice.paid, 'amount').from, 1234.567);
  assert.equal(paid.payment_receipt_number, '12345678');
  assert.match(transformation(pkg, 'INVOICES_PAYMENTS', ID.invoice.paid, 'payment_receipt_number').rule, /^T-08/);
  assert.deepEqual([ID.invoice.paid, ID.invoice.unpaid, ID.invoice.partial].map((id) => record(pkg, 'INVOICES_PAYMENTS', id).payment_status),
    ['PAID', 'UNPAID', 'PARTIAL']);
  assert.deepEqual([ID.invoice.paid, ID.invoice.unpaid].map((id) => record(pkg, 'INVOICES_PAYMENTS', id).invoice_type), ['INV', 'TUM']);

  assert.deepEqual([ID.financial.paid, ID.financial.unpaid, ID.financial.partial].map((id) => record(pkg, 'PO_FINANCIALS', id).payment_status),
    ['PAID', 'UNPAID', 'PARTIAL'], 'LUNAS / BELUM LUNAS dibedakan dengan nilai legacy (D11)');
  assert.deepEqual(transformation(pkg, 'PO_FINANCIALS', ID.financial.partial, 'payment_status').from,
    { Status: 'BELUM LUNAS', Outstanding: 1000, TotalInclPPN: 3330 });
  assert.equal(record(pkg, 'PO_FINANCIALS', ID.financial.partial).status_legacy, 'BELUM LUNAS');

  assert.equal(record(pkg, 'DELIVERIES', ID.delivery.linked).status, 'DELIVERED');
  const negative = record(pkg, 'DELIVERIES', ID.delivery.negative);
  assert.equal(negative.quantity, -20, 'qty negatif disimpan apa adanya (D9)');
  assert.equal(negative.status, null);
  assert.equal(record(pkg, 'STOCK', ID.stock.ready).status, 'READY');
  const leadtime = record(pkg, 'LEADTIME', ID.leadtime.planned);
  assert.equal(leadtime.status, 'SCHEDULED');
  assert.equal(leadtime.status_legacy, 'On Proses');
  assert.equal(record(pkg, 'PRODUCTS', ID.product.tubeTwin).is_active, false);

  for (const item of pkg.transformations) {
    assert.deepEqual(record(pkg, item.table, item.id)[item.column], item.to, `${item.table}.${item.column} ${item.id}: nilai hasil = log`);
  }
});

test('relasi tidak ditebak: relasi kosong tetap kosong dan dijelaskan isu; R-3 hanya untuk kecocokan persis dan unik', () => {
  const { pkg } = basePackage;
  const withoutPo = record(pkg, 'DELIVERIES', ID.delivery.withoutPo);
  assert.equal(withoutPo.purchase_order_id, null);
  const poIssue = issuesOf(pkg, ID.delivery.withoutPo).find((issue) => issue.issue_type === 'DELIVERY_PO_UNRESOLVED');
  assert.equal(poIssue.existing_issue_id, ID.issue.withoutPo, 'isu workbook terwakili');

  const withoutProduct = record(pkg, 'DELIVERIES', ID.delivery.withoutProduct);
  assert.equal(withoutProduct.purchase_order_id, ID.po.open);
  assert.equal(withoutProduct.product_id, null, 'PO punya dua baris: produk tidak ditebak');
  assert.equal(withoutProduct.po_line_id, null);

  const orphan = record(pkg, 'DELIVERIES', ID.delivery.orphan);
  assert.equal(orphan.purchase_order_id, null, 'ID yang tidak ada tidak disimpan');
  const fk = issuesOf(pkg, ID.delivery.orphan).find((issue) => issue.issue_type === 'FK_NOT_FOUND');
  assert.equal(fk.value, 'PO-FFFFFFFFFF', 'ID asli disimpan di isu');
  assert.equal(issuesOf(pkg, ID.delivery.orphan).filter((issue) => issue.issue_type === 'REFERENCE_UNRESOLVED').length, 0);

  assert.equal(record(pkg, 'PURCHASE_ORDERS', ID.po.hold).customer_id, null);
  assert.ok(issuesOf(pkg, ID.po.hold).some((issue) => issue.issue_type === 'PO_CUSTOMER_MISSING'));

  const exact = record(pkg, 'INBOUND_MAKLON', ID.inbound.exact);
  assert.equal(exact.purchase_order_id, ID.po.open, 'nomor PO persis dan unik');
  assert.equal(exact.product_id, ID.product.bottle, 'kode komponen persis dan unik');
  assert.match(transformation(pkg, 'INBOUND_MAKLON', ID.inbound.exact, 'product_id').rule, /^R-3/);
  const ambiguous = record(pkg, 'INBOUND_MAKLON', ID.inbound.ambiguous);
  assert.equal(ambiguous.purchase_order_id, null, 'nomor PO dipakai dua PO');
  assert.equal(ambiguous.product_id, null, 'kode dipakai dua produk');
  assert.deepEqual(issuesOf(pkg, ID.inbound.ambiguous).map((issue) => issue.issue_type).sort(),
    ['INBOUND_COMPONENT_UNRESOLVED', 'INBOUND_PO_UNRESOLVED']);

  for (const [key, expectation] of Object.entries(pkg.expectations.unmatched)) {
    const [tableName, column] = key.split('.');
    for (const item of pkg.tables[tableName].filter((candidate) => candidate[column] === null || candidate[column] === undefined)) {
      assert.ok(issuesOf(pkg, item.id).some((issue) => expectation.issueTypes.includes(issue.issue_type)),
        `${key} ${item.id}: relasi kosong punya isu penjelas`);
    }
  }
});

test('baris yang tidak dimigrasikan ke tabel bisnis tidak dibuang: baris asli lengkap disimpan di MIGRATION_ISSUES', () => {
  const { pkg } = basePackage;
  const excluded = pkg.accounting.filter((entry) => entry.disposition === 'EXCLUDED');
  assert.deepEqual(excluded.map((entry) => entry.sheet).sort(), ['DELIVERIES', 'LEADTIME', 'STOCK']);
  for (const entry of excluded) {
    const issue = pkg.tables.MIGRATION_ISSUES.find((item) => item.id === entry.issueIds[0]);
    assert.equal(issue.resolution_status, 'EXCLUDED');
    assert.equal(JSON.parse(issue.value)[Object.keys(JSON.parse(issue.value))[0]], entry.sourceId, 'baris asli di kolom value');
  }
  const header = pkg.tables.MIGRATION_ISSUES.find((issue) => issue.issue_type === 'STOCK_HEADER_ROW');
  assert.equal(JSON.parse(header.value).ProductLegacy, 'Nama Barang');

  const byRow = (sheet) => pkg.accounting.filter((entry) => entry.sheet === sheet);
  const contact = byRow('CONTACTS')[0];
  assert.equal(contact.disposition, 'ISSUE_ONLY');
  const contactIssue = pkg.tables.MIGRATION_ISSUES.find((issue) => issue.id === contact.issueIds[0]);
  assert.equal(contactIssue.issue_type, 'SHEET_ROW_WITHOUT_MAPPING');
  assert.equal(JSON.parse(contactIssue.value).ContactID, ID.contact);

  const enums = Object.fromEntries(byRow('ENUMS').map((entry) => [entry.sourceId, entry]));
  assert.equal(enums['FollowUpStatus|Overdue'].disposition, 'NOT_MIGRATED');
  assert.equal(enums['StockType|Ready'].recordId, 'STOCK_STATUS.READY');
  assert.equal(enums['Priority|Urgent'].disposition, 'ISSUE_ONLY');
  assert.equal(pkg.tables.MIGRATION_ISSUES.find((issue) => issue.id === enums['Priority|Urgent'].issueIds[0]).issue_type,
    'ENUM_NOT_REPRESENTED');
  assert.ok(byRow('MIGRATION_ISSUES').every((entry) => entry.disposition === 'REPRESENTED'));
  for (const issue of pkg.tables.MIGRATION_ISSUES) assert.ok(issue.resolution_note, `${issue.issue_type}: catatan penanganan`);
});

test('nilai di kolom yang tidak dimigrasikan dan nilai enum tanpa padanan dicatat sebagai isu, tidak dibuang', () => {
  const sheets = syntheticSheets();
  sheets.CUSTOMERS[0].PIC = 'Kontak Lama';
  sheets.STOCK[0].Status = 'Rusak';
  const { pkg } = build(sheets);
  assert.deepEqual(pkg.structuralErrors, []);
  const unmapped = issuesOf(pkg, ID.customer.one).find((issue) => issue.issue_type === 'UNMAPPED_SOURCE_VALUE');
  assert.equal(unmapped.field, 'PIC');
  assert.equal(unmapped.value, 'Kontak Lama');
  assert.ok(record(pkg, 'CUSTOMERS', ID.customer.one), 'record tetap dimigrasikan');
  const stock = record(pkg, 'STOCK', ID.stock.ready);
  assert.equal(stock.status, null, 'nilai tanpa padanan tidak dipaksakan');
  assert.equal(stock.status_legacy, 'Rusak');
  assert.equal(issuesOf(pkg, ID.stock.ready).find((issue) => issue.issue_type === 'VALUE_NOT_MAPPED').value, 'Rusak');
});

test('nilai wajib tanpa padanan (butuh keputusan bisnis) membuat dry run gagal dan memblokir migrasi', () => {
  const sheets = syntheticSheets();
  sheets.PURCHASE_ORDERS[0].Status = 'Menunggu Konfirmasi';
  const { pkg } = build(sheets);
  assert.ok(issuesOf(pkg, ID.po.open).some((issue) => issue.issue_type === 'VALUE_NOT_MAPPED'));
  const rehearsal = prepared(pkg);
  const dryRun = call(rehearsal, 'dryRunMigration').result;
  assert.equal(dryRun.ok, false);
  assert.ok(dryRun.errors.some((error) => error.table === 'PURCHASE_ORDERS' && error.id === ID.po.open && error.code === 'REQUIRED'));
  const run = call(rehearsal, 'runMigration');
  assert.equal(run.error.code, 'FORBIDDEN');
  assert.equal(run.spreadsheetWrites, 0);
  assert.equal(rows(rehearsal, 'PURCHASE_ORDERS').length, 0);
});

test('baris tanpa ID: tidak diberi ID tebakan; baris asli di isu dan paket diblokir sebagai error struktural', () => {
  const sheets = syntheticSheets();
  sheets.PO_LINES.push({ POID: ID.po.open, ProductID: ID.product.tube, OrderQuantity: 10, ProductNameLegacy: 'Tanpa ID' });
  const { pkg } = build(sheets);
  assert.equal(pkg.structuralErrors.length, 1);
  const entry = pkg.accounting.find((item) => item.sheet === 'PO_LINES' && item.disposition === 'ISSUE_ONLY');
  const issue = pkg.tables.MIGRATION_ISSUES.find((item) => item.id === entry.issueIds[0]);
  assert.equal(issue.issue_type, 'ID_MISSING');
  assert.equal(JSON.parse(issue.value).ProductNameLegacy, 'Tanpa ID');
  const rehearsal = prepared(pkg);
  const validate = call(rehearsal, 'validateMigrationMapping').result;
  assert.ok(validate.integrity.errors.some((error) => error.code === 'PACKAGE_STRUCTURAL'));
  assert.equal(call(rehearsal, 'dryRunMigration').result.ok, false);
  assert.equal(call(rehearsal, 'runMigration').error.code, 'FORBIDDEN');
});

test('ID yang dipakai beberapa baris sumber adalah error struktural (baris mana yang benar tidak ditebak)', () => {
  const sheets = syntheticSheets();
  sheets.RETURNS.push({ ...sheets.RETURNS[0], ReturnQuantity: 7 });
  const { pkg } = build(sheets);
  assert.ok(pkg.structuralErrors.some((error) => error.sheet === 'RETURNS' && error.message.includes(ID.ret.linked)));
  const rehearsal = prepared(pkg);
  const validate = call(rehearsal, 'validateMigrationMapping').result;
  assert.ok(validate.integrity.errors.some((error) => error.code === 'PACKAGE_STRUCTURAL'));
  assert.equal(call(rehearsal, 'dryRunMigration').result.ok, false);
  assert.equal(call(rehearsal, 'runMigration').error.code, 'FORBIDDEN');
});

test('VALIDATE: paket rusak atau diubah ditolak sebelum menyentuh database', () => {
  const { pkg } = basePackage;
  const cases = {
    PACKAGE_HASH: () => {
      const copy = JSON.parse(JSON.stringify(pkg));
      copy.tables.DELIVERIES[0].quantity = 1;
      return copy;
    },
    PACKAGE_SCHEMA_VERSION: () => rehash({ ...pkg, schemaVersion: pkg.schemaVersion + 1 }),
    PACKAGE_COLUMN: () => {
      const copy = JSON.parse(JSON.stringify(pkg));
      copy.tables.CUSTOMERS[0].kolom_asing = 'x';
      return rehash(copy);
    },
    PACKAGE_COUNT: () => {
      const copy = JSON.parse(JSON.stringify(pkg));
      copy.tables.RETURNS.pop();
      return rehash(copy);
    },
    PACKAGE_LOAD_ORDER: () => rehash({ ...pkg, loadOrder: ['PO_LINES', ...pkg.loadOrder.filter((name) => name !== 'PO_LINES')] }),
    PACKAGE_DUPLICATE_ID: () => {
      const copy = JSON.parse(JSON.stringify(pkg));
      copy.tables.STOCK.push({ ...copy.tables.STOCK[0] });
      return rehash(copy);
    },
  };
  for (const [code, make] of Object.entries(cases)) {
    const rehearsal = prepared(make());
    const validate = call(rehearsal, 'validateMigrationMapping').result;
    assert.equal(validate.ok, false, code);
    assert.ok(validate.integrity.errors.some((error) => error.code === code), `${code}: ${JSON.stringify(validate.integrity.errors)}`);
    const dryRun = call(rehearsal, 'dryRunMigration');
    assert.equal(dryRun.result.ok, false, `${code}: dry run gagal`);
    assert.equal(dryRun.spreadsheetWrites, 0);
  }
});

// ---------------------------------------------------------------------------------------------------------------
// DRY RUN → MIGRATE → VERIFY (Apps Script entry points in the emulator)
// ---------------------------------------------------------------------------------------------------------------

test('DRY RUN: tidak menulis apa pun; migrasi ditolak sebelum dry run dan untuk paket lain', () => {
  const rehearsal = prepared(basePackage.pkg);
  const early = call(rehearsal, 'runMigration');
  assert.equal(early.error.code, 'FORBIDDEN', 'migrasi sebelum dry run ditolak');
  assert.equal(early.spreadsheetWrites, 0);
  const dryRun = call(rehearsal, 'dryRunMigration');
  assert.equal(dryRun.result.ok, true);
  assert.equal(dryRun.spreadsheetWrites, 0, 'dry run tidak menulis ke spreadsheet');
  assert.equal(dryRun.result.errorCount, 0);
  for (const name of basePackage.pkg.loadOrder) {
    assert.equal(dryRun.result.plan[name].insert, basePackage.pkg.tables[name].length, `${name}: rencana insert`);
    assert.equal(rows(rehearsal, name).length, 0, `${name}: database tetap kosong`);
  }
  assert.deepEqual(dryRun.result.package.openDecisions.map((decision) => decision.id), ['D3', 'D4']);

  const other = build(Object.assign(syntheticSheets(), { RETURNS: syntheticSheets().RETURNS.slice(0, 1) })).pkg;
  uploadPackage(rehearsal, JSON.stringify(other));
  const mismatch = call(rehearsal, 'runMigration');
  assert.equal(mismatch.error.code, 'FORBIDDEN', 'dry run paket lain tidak berlaku');
  assert.equal(mismatch.spreadsheetWrites, 0);
});

test('dry run yang gagal memblokir migrasi; tidak ada data yang ditulis', () => {
  const broken = JSON.parse(JSON.stringify(basePackage.pkg));
  broken.tables.PURCHASE_ORDERS[0].status = 'BUKAN_STATUS';
  const rehearsal = prepared(rehash(broken));
  const dryRun = call(rehearsal, 'dryRunMigration').result;
  assert.equal(dryRun.ok, false);
  assert.ok(dryRun.errors.some((error) => error.code === 'ENUM' && error.field === 'status'));
  const run = call(rehearsal, 'runMigration');
  assert.equal(run.error.code, 'FORBIDDEN');
  assert.equal(run.spreadsheetWrites, 0);
});

test('MIGRATE + VERIFY: semua pemeriksaan lulus; audit ringkas per batch; rerun tidak menulis apa pun', () => {
  const { rehearsal, run } = migrated();
  const { pkg } = basePackage;
  const verify = run.verification;
  assert.equal(verify.ok, true, JSON.stringify(failedChecks(verify)));
  assert.equal(verify.summary.fail, 0);
  for (const name of pkg.loadOrder) {
    assert.equal(run.tables[name].inserted, pkg.tables[name].length, `${name}: semua record ditulis`);
    assert.equal(rows(rehearsal, name).length, pkg.tables[name].length);
  }
  const po = stored(rehearsal, 'PURCHASE_ORDERS', ID.po.open);
  assert.equal(po.created_by, 'system:migration');
  assert.match(po.migrated_at, /Z$/);
  assert.equal(stored(rehearsal, 'PO_LINES', ID.line.openBottle).product_name_legacy, ' Botol  Uji 30ml ', 'spasi legacy utuh di Sheets');
  assert.equal(stored(rehearsal, 'INVOICES_PAYMENTS', ID.invoice.paid).payment_receipt_number, '12345678', 'teks angka tetap teks');

  const audit = rows(rehearsal, 'AUDIT_LOG');
  const runs = audit.filter((entry) => entry.action === 'MIGRATION_RUN');
  assert.equal(runs.length, pkg.loadOrder.length, 'satu entri audit per tabel (satu batch)');
  assert.ok(runs.every((entry) => entry.note.includes(pkg.packageHash.slice(0, 12))), 'audit menyebut paket');
  assert.equal(audit.filter((entry) => entry.action === 'CREATE').length, 0);

  const rerun = call(rehearsal, 'runMigration');
  assert.equal(rerun.result.completed, true);
  assert.equal(rerun.spreadsheetWrites, 0, 'rerun idempoten');
  assert.ok(Object.values(rerun.result.tables).every((counts) => counts.inserted === 0 && counts.updated === 0));
  const again = call(rehearsal, 'verifyMigration').result;
  assert.equal(again.ok, true);
  assert.equal(checkStatus(again, 'Idempoten'), 'PASS');
});

test('rekonsiliasi: qty PO, delivery, retur, outstanding, dan invoice/pembayaran dihitung dari database', () => {
  const { run } = migrated();
  const summary = run.verification.reconciliation.summary;
  assert.deepEqual(summary.purchaseOrders, { count: 6, withoutCustomer: 1, withoutLines: 1, lines: 6, orderQuantity: 2150 });
  assert.deepEqual(summary.deliveries, {
    count: 7, quantity: 1500, linkedToLine: 4, quantityLinkedToLine: 1230, withoutPo: 2, negativeQuantity: 1,
  });
  assert.deepEqual(summary.returns, { count: 2, quantity: 25, linkedToLine: 0, withoutPo: 0 });
  // Lines: 1000-600, 50, MAX(0, 500-480), 200, MAX(0, 100-150) = 0 (kirim melebihi order), 300.
  assert.equal(summary.outstanding.computedTotal, 970, 'MAX(0, order - kirim tertaut + retur tertaut)');
  assert.equal(summary.outstanding.legacyTotal, 1090);
  assert.equal(summary.outstanding.equalToLegacy, 4);
  assert.equal(summary.outstanding.differentFromLegacy, 2);
  assert.equal(summary.outstanding.differentOnPoWithUnlinkedTransactions, 1, 'retur belum tertaut ke baris PO');
  assert.deepEqual(summary.outstanding.openPurchaseOrders, {
    scope: summary.outstanding.openPurchaseOrders.scope, purchaseOrders: 4, lines: 5, computedTotal: 950, legacyTotal: 1050,
    purchaseOrdersDifferent: 1,
  });
  const byPo = Object.fromEntries(run.verification.reconciliation.outstandingByPo.map((po) => [po.purchase_order_id, po]));
  assert.deepEqual([byPo[ID.po.placeholder].computed, byPo[ID.po.placeholder].legacy], [0, 100]);
  assert.equal(byPo[ID.po.closed].hasUnlinkedTransactions, true);
  assert.equal(summary.invoices.amount, 7034.57);
  assert.equal(summary.invoices.paid, 1534.57);
  assert.equal(summary.invoices.amountMinusPaid, 5500);
  assert.equal(summary.invoices.outstandingLegacy, 5500, 'outstanding legacy = invoice - pembayaran');
  assert.deepEqual(Object.keys(summary.invoices.byPaymentStatus).sort(), ['PAID', 'PARTIAL', 'UNPAID']);
  assert.equal(summary.poFinancials.totalInclPpn, 22755);
  const totals = run.verification.checks.find((check) => check.name.startsWith('Total numerik')).details.sums;
  assert.equal(totals['DELIVERIES.quantity'].expected, 1500);
  assert.equal(totals['RETURNS.quantity'].found, 25);
  assert.equal(totals['PO_LINES.outstanding_qty_legacy'].found, 1090);
});

test('migrasi berhenti di batas waktu dapat dilanjutkan; verifikasi mendeteksi migrasi yang belum lengkap', () => {
  const rehearsal = prepared(basePackage.pkg);
  assert.equal(call(rehearsal, 'dryRunMigration').result.ok, true);
  const pkg = rehearsal.project.run('readMigrationPackageFile_()');
  const marker = JSON.parse(rehearsal.env.properties.MIGRATION_DRY_RUN);
  const partial = plain(rehearsal.context.runMigrationPackage_(pkg, { dryRun: marker, maxChunks: 3 }));
  assert.equal(partial.completed, false);
  assert.equal(partial.stoppedAt, 'PO_LINES');
  assert.equal(rows(rehearsal, 'PURCHASE_ORDERS').length, 6);
  assert.equal(rows(rehearsal, 'PO_LINES').length, 0);
  const incomplete = call(rehearsal, 'verifyMigration').result;
  assert.equal(incomplete.ok, false, 'migrasi setengah jalan tidak lolos verifikasi');
  assert.equal(checkStatus(incomplete, 'Record PO_LINES'), 'FAIL');
  const resumed = call(rehearsal, 'runMigration').result;
  assert.equal(resumed.completed, true);
  assert.equal(resumed.tables.CUSTOMERS.inserted, 0, 'tabel yang sudah dimuat dilewati');
  assert.equal(resumed.tables.CUSTOMERS.skipped, 3);
  assert.equal(resumed.verification.ok, true, JSON.stringify(failedChecks(resumed.verification)));
});

test('perubahan pengguna dihormati: sumber berubah memperbarui record migrasi, record yang diedit tidak ditimpa', () => {
  const { rehearsal } = migrated();
  const admin = { actor: 'admin@example.com' };
  rehearsal.context.dbUpdate_('DELIVERIES', ID.delivery.closed, { notes: 'Dicek Admin' }, admin);
  rehearsal.context.dbUpdate_('DELIVERIES', ID.delivery.withoutPo, { purchase_order_id: ID.po.open }, admin);
  const poIssue = rows(rehearsal, 'MIGRATION_ISSUES')
    .find((issue) => issue.record_id === ID.delivery.withoutPo && issue.issue_type === 'DELIVERY_PO_UNRESOLVED');
  rehearsal.context.dbUpdate_('MIGRATION_ISSUES', poIssue.id, { resolution_status: 'RESOLVED', resolution_note: 'Ditautkan' }, admin);
  const afterEdits = call(rehearsal, 'verifyMigration').result;
  assert.equal(afterEdits.ok, true, `suntingan Admin bukan kerusakan: ${JSON.stringify(failedChecks(afterEdits))}`);
  assert.equal(afterEdits.checks.find((check) => check.name.startsWith('Record DELIVERIES')).details.editedByUsers, 2);
  assert.equal(afterEdits.reconciliation.summary.deliveries.withoutPo, 1, 'rekonsiliasi memakai data terkini');

  const sheets = syntheticSheets();
  sheets.DELIVERIES.find((row) => row.DeliveryID === ID.delivery.linked).DeliveredQuantity = 650;
  sheets.DELIVERIES.find((row) => row.DeliveryID === ID.delivery.closed).DeliveredQuantity = 510;
  sheets.PRODUCTS.find((row) => row.ProductID === ID.product.tubeTwin).Active = true;
  const next = build(sheets).pkg;
  assert.notEqual(next.packageHash, basePackage.pkg.packageHash);
  uploadPackage(rehearsal, JSON.stringify(next));
  const dryRun = call(rehearsal, 'dryRunMigration').result;
  assert.equal(dryRun.ok, true, JSON.stringify(dryRun.errors));
  assert.equal(dryRun.plan.DELIVERIES.update, 1);
  assert.equal(dryRun.plan.DELIVERIES.conflict, 1);
  assert.equal(dryRun.plan.PRODUCTS.update, 1);
  assert.ok(dryRun.warnings.some((warning) => warning.code === 'MIGRATION_CONFLICT'));

  rehearsal.env.resetCalls();
  const run = call(rehearsal, 'runMigration').result;
  assert.equal(run.completed, true);
  const linked = stored(rehearsal, 'DELIVERIES', ID.delivery.linked);
  assert.equal(linked.quantity, 650, 'record milik migrasi diperbarui');
  assert.equal(linked.updated_by, 'system:migration');
  const edited = stored(rehearsal, 'DELIVERIES', ID.delivery.closed);
  assert.equal(edited.quantity, 500, 'record yang diedit pengguna tidak ditimpa');
  assert.equal(edited.notes, 'Dicek Admin');
  const conflict = rows(rehearsal, 'MIGRATION_ISSUES').find((issue) => issue.issue_type === 'MIGRATION_CONFLICT');
  assert.equal(conflict.record_id, ID.delivery.closed);
  assert.equal(JSON.parse(conflict.value).quantity, 510, 'nilai sumber terbaru disimpan untuk ditinjau');
  assert.equal(stored(rehearsal, 'PRODUCTS', ID.product.tubeTwin).is_active, true, 'is_active mengikuti sumber');
  assert.equal(stored(rehearsal, 'DELIVERIES', ID.delivery.withoutPo).purchase_order_id, ID.po.open, 'tautan Admin tetap');
  assert.equal(stored(rehearsal, 'MIGRATION_ISSUES', poIssue.id).resolution_status, 'RESOLVED', 'tinjauan Admin tetap');
  const updates = rows(rehearsal, 'AUDIT_LOG').filter((entry) => entry.action === 'UPDATE' && entry.actor_email === 'system:migration');
  assert.deepEqual(updates.map((entry) => entry.entity_id).sort(), [ID.delivery.linked, ID.product.tubeTwin].sort());
  assert.deepEqual(JSON.parse(updates.find((entry) => entry.entity_id === ID.delivery.linked).changes_json).quantity, [600, 650]);
  assert.equal(run.verification.ok, true, JSON.stringify(failedChecks(run.verification)));

  const rerun = call(rehearsal, 'runMigration');
  assert.equal(rerun.spreadsheetWrites, 0, 'konflik tidak dicatat dua kali');
  assert.equal(rows(rehearsal, 'MIGRATION_ISSUES').filter((issue) => issue.issue_type === 'MIGRATION_CONFLICT').length, 1);
});

test('VERIFY mendeteksi migrasi yang rusak: record hilang, isi berubah, referensi yatim, baris ganda', () => {
  const damage = {
    'record hilang': (rehearsal) => {
      const sheet = sheetOf(rehearsal, 'DELIVERIES');
      const row = sheet.getDataRange().getValues().findIndex((item) => item[0] === ID.delivery.linked);
      sheet.deleteRows(row + 1, 1);
      return ['Record DELIVERIES', 'Setiap baris sumber', 'Total numerik'];
    },
    'qty berubah': (rehearsal) => {
      setCell(rehearsal, 'PO_LINES', ID.line.openBottle, 'order_quantity', 999);
      return ['Record PO_LINES', 'Total numerik'];
    },
    'referensi yatim': (rehearsal) => {
      setCell(rehearsal, 'DELIVERIES', ID.delivery.linked, 'purchase_order_id', 'PO-00000000FF');
      return ['verifyDatabase', 'Record DELIVERIES'];
    },
    'baris ganda': (rehearsal) => {
      const context = rehearsal.context;
      const sheet = sheetOf(rehearsal, 'STOCK');
      const copy = sheet.getDataRange().getValues().find((item) => item[0] === ID.stock.ready);
      context.appendRowsToSheet_(sheet, context.getTableDef_('STOCK'), [copy]);
      return ['verifyDatabase', 'Record STOCK'];
    },
  };
  for (const [label, apply] of Object.entries(damage)) {
    const { rehearsal } = migrated();
    const expected = apply(rehearsal);
    const verify = call(rehearsal, 'verifyMigration').result;
    assert.equal(verify.ok, false, `${label}: verifikasi gagal`);
    for (const prefix of expected) assert.equal(checkStatus(verify, prefix), 'FAIL', `${label}: ${prefix}`);
  }
});

// ---------------------------------------------------------------------------------------------------------------
// REPORT and CLI
// ---------------------------------------------------------------------------------------------------------------

test('laporan: ringkasan JSON dan markdown memuat langkah, verifikasi, rekonsiliasi, dan DECISION REQUIRED', () => {
  const { pkg, analysis } = basePackage;
  const rehearsal = prepared(pkg);
  const steps = { mode: 'emulator' };
  steps.validate = call(rehearsal, 'validateMigrationMapping');
  steps.dryRun = call(rehearsal, 'dryRunMigration');
  steps.migrate = runUntilComplete(rehearsal);
  steps.rerun = call(rehearsal, 'runMigration');
  steps.verify = call(rehearsal, 'verifyMigration');
  const summary = buildSummary({ pkg, analysis, steps, generatedAt: '2025-07-01T00:00:00.000Z' });
  assert.equal(summary.verify.ok, true);
  assert.equal(summary.dryRun.spreadsheetWrites, 0);
  assert.equal(summary.rerun.spreadsheetWrites, 0);
  const markdown = buildMarkdownReport({
    pkg, analysis, steps, generatedAt: '2025-07-01T00:00:00.000Z', decisions: MAPPING_DECISIONS,
    outputs: { package: 'migration/reports/migration-package.json', list: [] },
  });
  for (const heading of ['## 1. Ringkasan pipeline', '## 2. Jumlah record', '## 5. Relasi', '## 6. Total numerik',
    '## 7. Rekonsiliasi', '### Outstanding quantity', '### Invoice dan pembayaran', '## 10. Keputusan',
    'DECISION REQUIRED — D3', 'DECISION REQUIRED — D4', '## 11. Menjalankan migrasi produksi']) {
    assert.ok(markdown.includes(heading), heading);
  }
  assert.ok(!/undefined|NaN|\[object Object\]/.test(markdown), 'tidak ada nilai rusak di laporan');
});

test('CLI migrate: pipeline lengkap, keluaran ke folder yang diminta, kode keluar 0', () => {
  const dir = fs.mkdtempSync(path.join(tmp, 'cli-'));
  const workbook = writeWorkbook(path.join(dir, WORKBOOK_NAME));
  const out = path.join(dir, 'out');
  const report = path.join(dir, 'REPORT.md');
  const result = spawnSync(process.execPath, [path.join(PROJECT_ROOT, 'migration/scripts/migrate.js'), 'migrate', workbook,
    '--out', out, '--report', report, '--as-of', AS_OF], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /DRY RUN\s+OK/);
  assert.match(result.stdout, /VERIFY\s+OK/);
  assert.match(result.stdout, /DECISION REQUIRED/);
  for (const name of ['migration-package.json', 'migration-dry-run.json', 'migration-run.json', 'migration-verify.json',
    'migration-summary.json', 'migration-issues.csv', 'migration-transformations.csv', 'migration-accounting.csv',
    'outstanding-reconciliation.csv', 'database/DELIVERIES.csv']) {
    assert.ok(fs.existsSync(path.join(out, name)), name);
  }
  assert.ok(fs.readFileSync(report, 'utf8').includes('# MIGRATION REPORT'));
  const refused = spawnSync(process.execPath, [path.join(PROJECT_ROOT, 'migration/scripts/migrate.js'), 'migrate', workbook,
    '--out', out, '--report', workbook], { encoding: 'utf8' });
  assert.notEqual(refused.status, 0, 'tidak pernah menulis di atas workbook sumber');
});

// ---------------------------------------------------------------------------------------------------------------
// Real workbook (local only; never committed)
// ---------------------------------------------------------------------------------------------------------------

const REAL_WORKBOOK = path.join(PROJECT_ROOT, 'migration', 'source', 'PIK_Master_Database_AppSheet.xlsx');

test('workbook asli (lokal): paket tanpa error struktural, dry run bersih, migrasi terverifikasi, rerun idempoten', {
  skip: fs.existsSync(REAL_WORKBOOK) ? false : 'workbook sumber tidak ada di migration/source/ (tidak di-commit)',
}, () => {
  const hash = sha256(fs.readFileSync(REAL_WORKBOOK));
  const analysis = analyzeWorkbook(REAL_WORKBOOK);
  const pkg = buildMigrationPackage(analysis, target);
  assert.equal(pkg.structuralErrors.length, 0);
  const sourceRows = Object.values(analysis.tables).reduce((total, table) => total + table.records.length, 0);
  assert.equal(pkg.accounting.length, sourceRows);
  const { rehearsal, dryRun, run } = migrated(pkg);
  assert.equal(dryRun.errorCount, 0);
  assert.equal(run.verification.ok, true, JSON.stringify(failedChecks(run.verification)));
  assert.equal(call(rehearsal, 'runMigration').spreadsheetWrites, 0);
  assert.equal(sha256(fs.readFileSync(REAL_WORKBOOK)), hash, 'workbook sumber tidak berubah');
});
