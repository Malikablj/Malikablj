'use strict';

/**
 * Node-only checks that need control over the emulated environment: Script Properties, the signed-in user, the
 * script lock, and call counts (batching). Each test loads a fresh project.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');

const { loadGasProject } = require('../tools/gas-emulator/load-gas');

/** Values from the vm context have another realm's prototypes; compare plain copies. */
function plain(value) {
  return JSON.parse(JSON.stringify(value));
}

function load(envOptions) {
  return loadGasProject({ env: envOptions });
}

function newDatabase(context) {
  const spreadsheet = context.SpreadsheetApp.create('db');
  context.initializeDatabase_({ spreadsheet, actor: 'owner@example.com' });
  return spreadsheet;
}

function expectAppError(callback, code) {
  let error = null;
  try {
    callback();
  } catch (caught) {
    error = caught;
  }
  assert.ok(error, `expected ${code} but nothing was thrown`);
  assert.equal(error.code, code, `expected ${code} but got ${error.code}: ${error.message}`);
  return error;
}

test('setupDatabase membuat spreadsheet, menyimpan ID, dan idempoten', () => {
  const { context, env } = load({ properties: {}, defaultSheetName: 'Hoja 1' });
  const first = context.setupDatabase();
  assert.equal(first.created, true);
  assert.equal(env.properties.DATABASE_SPREADSHEET_ID, first.spreadsheetId);
  const spreadsheet = env.spreadsheets.get(first.spreadsheetId);
  assert.deepEqual(spreadsheet.getSheets().map((s) => s.getName()), plain(context.getTableNames_()),
    'sheet bawaan berlokal lain ikut dihapus');
  assert.equal(spreadsheet.getSpreadsheetTimeZone(), 'Asia/Jakarta');
  const second = context.setupDatabase();
  assert.equal(second.created, false);
  assert.equal(second.spreadsheetId, first.spreadsheetId);
  assert.equal(second.initialization.changed, false);
  assert.ok(env.logs.some((line) => line.startsWith('setupDatabase:')));
});

test('setupDatabase memindahkan file ke DRIVE_ROOT_FOLDER_ID bila diisi', () => {
  const { context, env } = load({ properties: { DRIVE_ROOT_FOLDER_ID: 'folder-1' }, folders: ['folder-1'] });
  const result = context.setupDatabase();
  assert.equal(env.files.get(result.spreadsheetId).parent, 'folder-1');
});

test('konfigurasi database yang kosong atau salah memberi pesan jelas', () => {
  const empty = load({ properties: {} });
  expectAppError(() => empty.context.getDatabaseSpreadsheet_(), 'CONFIG_MISSING');
  const wrong = load({ properties: { DATABASE_SPREADSHEET_ID: 'tidak-ada' } });
  expectAppError(() => wrong.context.initializeDatabase(), 'CONFIG_MISSING');
});

test('fungsi pemeliharaan menolak pengguna lain dan email kosong, menerima ADMIN_EMAILS', () => {
  const maintenance = ['setupDatabase', 'initializeDatabase', 'verifyDatabase', 'runDatabaseSelfTest',
    'setInitialProperties', 'profileSourceWorkbook', 'validateMigrationMapping', 'dryRunMigration', 'runMigration',
    'verifyMigration'];
  const stranger = load({ activeUserEmail: 'sales@example.com', effectiveUserEmail: 'owner@example.com' });
  for (const name of maintenance) expectAppError(() => stranger.context[name](), 'FORBIDDEN');
  assert.equal(stranger.env.spreadsheets.size, 0, 'tidak ada spreadsheet yang dibuat');

  const anonymous = load({ activeUserEmail: '', effectiveUserEmail: 'owner@example.com' });
  for (const name of maintenance) expectAppError(() => anonymous.context[name](), 'FORBIDDEN');

  const admin = load({
    activeUserEmail: 'Admin.PIK@example.com',
    effectiveUserEmail: 'owner@example.com',
    properties: { ADMIN_EMAILS: 'lain@example.com; admin.pik@example.com' },
  });
  const result = admin.context.setupDatabase();
  assert.equal(result.created, true);
  const audit = admin.context.withDatabaseSpreadsheet_(admin.env.spreadsheets.get(result.spreadsheetId),
    () => admin.context.loadTable_('AUDIT_LOG').records);
  assert.equal(audit[0].actor_email, 'admin.pik@example.com', 'pelaku tercatat');
});

test('setInitialProperties hanya mengisi properti yang kosong', () => {
  const { context, env } = load({ properties: { APP_NAME: 'Nama Khusus' } });
  const result = context.setInitialProperties();
  assert.deepEqual(plain(result.added), ['TIMEZONE']);
  assert.equal(env.properties.APP_NAME, 'Nama Khusus');
  assert.equal(env.properties.TIMEZONE, 'Asia/Jakarta');
});

test('semua penulisan terjadi di bawah script lock; lock sibuk memberi LOCK_TIMEOUT tanpa menulis', () => {
  const { context, env } = load();
  const spreadsheet = newDatabase(context);
  context.withDatabaseSpreadsheet_(spreadsheet, () => {
    env.resetCalls();
    const [customer] = context.dbInsert_('CUSTOMERS', [{ name: 'Customer Uji Lock' }], { actor: 'owner@example.com' });
    context.dbUpdate_('CUSTOMERS', customer.id, { notes: 'x' }, { actor: 'owner@example.com' });
    context.dbArchive_('CUSTOMERS', customer.id, { actor: 'owner@example.com' });
    context.updateSetting_('DEFAULT_PAGE_SIZE', '30', { actor: 'owner@example.com' });
    const writes = env.calls.filter((call) => call.mutating);
    assert.ok(writes.length > 0);
    assert.deepEqual(writes.filter((call) => !call.locked), [], 'penulisan tanpa lock');
    assert.equal(env.lockHeld, false, 'lock dilepas');

    env.lockAvailable = false;
    env.resetCalls();
    expectAppError(() => context.dbInsert_('CUSTOMERS', [{ name: 'Customer Uji Sibuk' }], {}), 'LOCK_TIMEOUT');
    assert.equal(env.calls.filter((call) => call.mutating).length, 0, 'tidak ada yang ditulis');
  });
  env.lockAvailable = true;
  env.resetCalls();
  context.initializeDatabase_({ spreadsheet, actor: 'owner@example.com' });
  assert.deepEqual(env.calls.filter((call) => call.mutating && !call.locked), [], 'initializer memakai lock');
});

test('insert batch memakai satu setValues untuk data dan satu untuk audit', () => {
  const { context, env } = load();
  const spreadsheet = newDatabase(context);
  context.withDatabaseSpreadsheet_(spreadsheet, () => {
    const batch = Array.from({ length: 50 }, (_, i) => ({ name: `Customer Uji Batch ${i}` }));
    env.resetCalls();
    context.dbInsert_('CUSTOMERS', batch, { actor: 'owner@example.com' });
    const count = (method, sheet) => env.calls.filter((c) => c.method === method && c.sheet === sheet).length;
    assert.equal(count('Range.setValues', 'CUSTOMERS'), 1);
    assert.equal(count('Range.setValues', 'AUDIT_LOG'), 1);
    assert.equal(count('Range.setValue', 'CUSTOMERS'), 0);
    assert.ok(count('Range.getValues', 'CUSTOMERS') <= 2, 'header + data dibaca sekali');
  });
});

test('tabel tumbuh melewati kapasitas awal dengan format dan validasi tetap benar', () => {
  const { context, env } = load();
  const spreadsheet = newDatabase(context);
  context.withDatabaseSpreadsheet_(spreadsheet, () => {
    const initialRows = spreadsheet.getSheetByName('PRODUCTS').getMaxRows();
    const batch = Array.from({ length: initialRows + 10 }, (_, i) => ({ name: `Produk Uji ${i}`, product_code: `00${i}` }));
    context.dbInsert_('PRODUCTS', batch.slice(0, 900), { actor: 'owner@example.com' });
    context.dbInsert_('PRODUCTS', batch.slice(900), { actor: 'owner@example.com' });
    const sheet = spreadsheet.getSheetByName('PRODUCTS');
    assert.ok(sheet.getMaxRows() > initialRows, 'baris ditambah');
    const table = context.getTableDef_('PRODUCTS');
    const lastRow = sheet.getLastRow();
    assert.equal(lastRow, initialRows + 11);
    const code = sheet.getRange(lastRow, table.columnNames.indexOf('product_code') + 1).getValue();
    assert.equal(code, `00${initialRows + 9}`, 'teks dengan nol di depan tetap teks setelah tumbuh');
    const rules = sheet.getRange(sheet.getMaxRows(), 1, 1, table.columns.length).getDataValidations()[0];
    assert.equal(rules[table.columnNames.indexOf('is_active')].getCriteriaType(), 'CHECKBOX', 'checkbox di baris baru');
    assert.ok(context.numberFormatsMatch_(sheet, table, 2, sheet.getMaxRows() - 1), 'format seluruh kolom');
    assert.equal(env.calls.filter((c) => c.method === 'Sheet.insertRowsAfter' && c.sheet === 'PRODUCTS').length, 1);
    const report = context.verifyDatabase_({ spreadsheet });
    assert.equal(report.ok, true, JSON.stringify(report.errors.slice(0, 3)));
    assert.equal(report.tables.PRODUCTS.rows, initialRows + 10);
  });
});

test('runDatabaseSelfTest lulus di emulator dan membuang spreadsheet uji tanpa menyentuh database', () => {
  const { context, env } = load();
  const production = context.setupDatabase();
  const before = JSON.stringify(env.properties);
  const summary = context.runDatabaseSelfTest();
  assert.equal(summary.failed, 0, JSON.stringify(summary.results.filter((r) => r.status !== 'PASSED')));
  assert.equal(summary.passed, summary.total);
  assert.equal(summary.total, context.getDatabaseTestCases_().length);
  const testFiles = [...env.files.values()].filter((file) => file.id !== production.spreadsheetId);
  assert.ok(testFiles.length >= 2);
  assert.ok(testFiles.every((file) => file.trashed), 'spreadsheet uji dibuang ke trash');
  assert.equal(JSON.stringify(env.properties), before, 'Script Properties tidak berubah');
  const productionReport = context.verifyDatabase_({ spreadsheet: env.spreadsheets.get(production.spreadsheetId), includeAuditLog: true });
  assert.equal(productionReport.tables.CUSTOMERS.rows, 0, 'database produksi tidak berisi data uji');
  assert.equal(productionReport.tables.AUDIT_LOG.rows, 1, 'hanya audit DB_INIT');
  const filtered = context.runDatabaseSelfTest('schema');
  assert.equal(filtered.total, 3);
});

test('verifyDatabase publik mencatat ringkasan ke Logger', () => {
  const { context, env } = load();
  context.setupDatabase();
  const report = context.verifyDatabase();
  assert.equal(report.ok, true, JSON.stringify(report.errors.slice(0, 3)));
  assert.ok(env.logs.some((line) => line.startsWith('verifyDatabase: {"ok":true')));
});

test('respons web: health, aksi API tidak dikenal/kosong, error internal disamarkan', () => {
  const { context } = load();
  const health = context.getAppHealth();
  assert.equal(health.success, true);
  assert.equal(health.data.schemaVersion, context.getSchema_().version);
  const unknown = context.api('tidak.ada', {});
  assert.equal(unknown.success, false);
  assert.equal(unknown.error.code, 'NOT_FOUND');
  assert.equal(context.api('', {}).error.code, 'VALIDATION_ERROR');
  assert.equal(context.api('session.get', [1, 2]).error.code, 'VALIDATION_ERROR', 'payload array ditolak');
  const internal = context.errorResponse_(new TypeError('x is undefined'));
  assert.equal(internal.error.code, 'INTERNAL_ERROR');
  assert.ok(!internal.error.message.includes('undefined'), 'detail teknis tidak bocor');
});

test('doGet merender halaman dengan include_ privat', () => {
  const { context } = load();
  const output = context.doGet();
  assert.equal(output.getTitle(), 'PIK Marketing Control');
  const html = output.getContent();
  assert.ok(html.includes('--color-bg: #F5F5F7'), 'style disisipkan');
  assert.ok(html.includes("PIK.api('session.get'"), 'script aplikasi disisipkan');
  assert.ok(!html.includes('<?'), 'semua scriptlet dievaluasi');
});
