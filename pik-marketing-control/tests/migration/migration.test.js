/**
 * Migration engine tests against a synthetic fixture workbook: row counts, duplicate
 * detection, relationships (FKs, ambiguity, unresolved), lineage, business calculations,
 * dry-run isolation, re-run idempotency and protection of records edited in the app.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import ExcelJS from 'exceljs';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import productionMapping from '../../migration/mapping/workbook.mapping.js';
import { runMigration } from '../../migration/scripts/lib/engine.js';
import { validateMapping, fieldSpec } from '../../migration/scripts/lib/gate.js';
import { issuesToCsv } from '../../migration/scripts/lib/report.js';
import { verifyMigration } from '../../migration/scripts/lib/verify.js';
import { readWorkbook } from '../../migration/scripts/lib/workbook.js';
import { closeDb, sql, truncateAll } from '../helpers/db.js';
import { buildFixtureWorkbook, FIXTURE_SHEETS } from './fixtures/buildFixtureWorkbook.js';
import fixtureMapping from './fixtures/fixture.mapping.js';

const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pik-migration-'));
const workbookPath = path.join(tmpDir, 'fixture.xlsx');
const databaseUrl = process.env.DATABASE_URL_TEST;

const run = (mode, mapping = fixtureMapping, extra = {}) =>
  runMigration({ workbookPath, mapping, mode, databaseUrl, timeZone: 'Asia/Jakarta', ...extra });
const entityStats = (summary, name) => summary.entities.find((entity) => entity.entity === name);
const count = async (table) => (await sql(`SELECT count(*)::int AS n FROM ${table}`)).rows[0].n;

beforeAll(async () => {
  await truncateAll();
  await buildFixtureWorkbook(workbookPath);
  await sql(`INSERT INTO users (name, email, password_hash, role) VALUES ('Fixture Sales', 'fixture.sales@test.local', 'x', 'SALES')`);
});

afterAll(async () => {
  await closeDb();
  fs.rmSync(tmpDir, { recursive: true, force: true });
});

describe('readiness gate', () => {
  it('accepts the fixture mapping', async () => {
    const gate = validateMapping(await readWorkbook(workbookPath), fixtureMapping);
    expect(gate.errors).toEqual([]);
    expect(gate.ok).toBe(true);
  });

  it('rejects a workbook sheet that is neither mapped nor ignored', async () => {
    const gate = validateMapping(await readWorkbook(workbookPath), { ...fixtureMapping, ignoredSheets: {} });
    expect(gate.ok).toBe(false);
    expect(gate.errors.join('\n')).toContain('Sheet "Catatan" belum dipetakan');
  });

  it('rejects mapped columns that do not exist and invalid enum codes', async () => {
    const broken = structuredClone(fixtureMapping);
    broken.entities.customers.fields.name = 'Nama Pelanggan';
    broken.entities.products.fields.status = { column: 'Kategori', valueMap: { botol: 'BOTTLE' } };
    const gate = validateMapping(await readWorkbook(workbookPath), broken);
    expect(gate.errors.join('\n')).toContain('kolom "Nama Pelanggan" tidak ada di sheet "Pelanggan"');
    expect(gate.errors.join('\n')).toContain('"botol" -> "BOTTLE" bukan kode yang valid');
    expect(gate.entityErrors.customers).toBeGreaterThan(0);
  });

  it('blocks --apply while the mapping is PROVISIONAL or invalid, without touching the database', async () => {
    const provisional = await run('apply', { ...fixtureMapping, status: 'PROVISIONAL' });
    expect(provisional.summary.result).toBe('BLOCKED');
    const invalid = await run('apply', { ...fixtureMapping, ignoredSheets: {} });
    expect(invalid.summary.result).toBe('BLOCKED');
    expect(await count('customers')).toBe(0);
    expect(await count('migration_runs')).toBe(0);
  });

  it('keeps the shipped (provisional) mapping internally consistent', async () => {
    // Build a workbook that has exactly the sheets/columns the production mapping names.
    const columnsBySheet = {};
    const add = (sheet, column) => {
      if (column) (columnsBySheet[sheet] ??= new Set()).add(column);
    };
    for (const config of Object.values(productionMapping.entities)) {
      for (const value of Object.values(config.fields ?? {})) add(config.sheet, fieldSpec(value).column);
      for (const strategies of Object.values(config.refs ?? {})) {
        for (const strategy of strategies) {
          add(config.sheet, strategy.column);
          add(config.sheet, strategy.customerColumn);
        }
      }
      for (const columns of config.key ?? []) for (const column of columns) add(config.sheet, column);
      for (const column of Object.values(config.quantityColumns ?? {})) add(config.sheet, column);
    }
    const workbook = new ExcelJS.Workbook();
    for (const [sheet, columns] of Object.entries(columnsBySheet)) workbook.addWorksheet(sheet).addRow([...columns]);
    const file = path.join(tmpDir, 'shape.xlsx');
    await workbook.xlsx.writeFile(file);
    const gate = validateMapping(await readWorkbook(file), productionMapping);
    expect(gate.errors).toEqual([]);
    expect(productionMapping.status).toBe('PROVISIONAL');
  });
});

describe('dry run', () => {
  it('reports what would happen and persists nothing', async () => {
    const { summary, issues } = await run('dry-run');
    expect(summary.result).toBe('DRY_RUN_COMPLETED');
    expect(entityStats(summary, 'customers')).toMatchObject({ records: 8, inserted: 5, not_imported: 2, merged_duplicates: 1 });
    expect(issues.length).toBeGreaterThan(0);
    for (const table of ['customers', 'contacts', 'purchase_orders', 'po_lines', 'migration_runs', 'migration_issues']) {
      expect(await count(table), table).toBe(0);
    }
  });
});

describe('apply', () => {
  let summary;
  let issues;

  beforeAll(async () => {
    ({ summary, issues } = await run('apply'));
  });

  it('imports every entity and accounts for every source row', () => {
    expect(summary.result).toBe('APPLIED');
    const expected = {
      customers: { records: 8, inserted: 5, not_imported: 2, merged_duplicates: 1 },
      contacts: { records: 5, inserted: 3, not_imported: 2 },
      products: { records: 4, inserted: 3, not_imported: 1 },
      leads: { records: 2, inserted: 2 },
      activities: { records: 3, inserted: 2, not_imported: 1 },
      follow_ups: { records: 2, inserted: 2 },
      purchase_orders: { records: 4, inserted: 3, not_imported: 1 },
      po_lines: { records: 7, inserted: 4, not_imported: 3 },
      deliveries: { records: 6, inserted: 6 },
      returns: { records: 2, inserted: 2 },
      stock: { records: 4, inserted: 3, not_imported: 1 },
      leadtime: { records: 1, inserted: 1 },
      inbound_maklon: { records: 1, inserted: 1 },
      invoices_payments: { records: 1, inserted: 1 },
      po_financials: { records: 1, inserted: 1 },
    };
    for (const [entity, counts] of Object.entries(expected)) {
      const stats = entityStats(summary, entity);
      expect(stats, entity).toMatchObject(counts);
      expect(stats.inserted + stats.updated + stats.unchanged + stats.not_imported + stats.merged_duplicates, entity).toBe(
        stats.records,
      );
    }
  });

  it('stores exactly the imported rows in the database', async () => {
    expect(await count('customers')).toBe(5);
    expect(await count('contacts')).toBe(3);
    expect(await count('po_lines')).toBe(4);
    expect(await count('deliveries')).toBe(6);
    expect(await count('stock')).toBe(3);
  });

  it('preserves source lineage on migrated rows', async () => {
    const { rows } = await sql(`SELECT * FROM customers WHERE customer_code = 'C001'`);
    expect(rows[0]).toMatchObject({
      name: 'PT Fixture Satu',
      email: 'info@fixture1.test',
      source_file: 'fixture.xlsx',
      source_sheet: 'Pelanggan',
      legacy_row: 2,
      legacy_key: 'customers|0|C001',
    });
    expect(rows[0].source_data).toMatchObject({ Kode: 'C001', 'Nama Customer': 'PT Fixture Satu' });
    expect(rows[0].migrated_at).toBeInstanceOf(Date);
    const missing = await sql(
      `SELECT count(*)::int AS n FROM deliveries WHERE source_file IS NULL OR legacy_row IS NULL OR source_data IS NULL`,
    );
    expect(missing.rows[0].n).toBe(0);
  });

  it('detects duplicates: identical rows merge, conflicting keys are not imported', () => {
    const byType = (type, entity) => issues.filter((issue) => issue.issue_type === type && issue.entity_type === entity);
    expect(byType('DUPLICATE_ROW', 'customers')).toHaveLength(1);
    expect(byType('DUPLICATE_KEY_CONFLICT', 'customers')[0]).toMatchObject({ legacy_row: 5, severity: 'ERROR' });
    expect(byType('DUPLICATE_KEY_CONFLICT', 'products')[0]).toMatchObject({ legacy_row: 5 });
    expect(byType('POSSIBLE_DUPLICATE', 'deliveries')[0]).toMatchObject({ legacy_row: 6, candidate_reference: 'row 5' });
  });

  it('never picks one of several candidates automatically', async () => {
    const ambiguous = issues.find((issue) => issue.issue_type === 'AMBIGUOUS_REFERENCE');
    expect(ambiguous).toMatchObject({ entity_type: 'contacts', legacy_row: 4, severity: 'ERROR' });
    expect(ambiguous.candidate_reference).toContain('PT Kembar Jaya [C005]');
    expect(ambiguous.candidate_reference).toContain('PT. KEMBAR JAYA [C006]');
    const contact = await sql(`SELECT 1 FROM contacts WHERE name = 'Kontak Ambigu'`);
    expect(contact.rowCount).toBe(0);
  });

  it('resolves relationships by code, normalized name and PO number + customer', async () => {
    const { rows } = await sql(`
      SELECT c.name AS contact, cu.customer_code, c.is_primary
      FROM contacts c JOIN customers cu ON cu.id = c.customer_id ORDER BY c.name`);
    expect(rows).toEqual([
      { contact: 'Andi Fixture', customer_code: 'C001', is_primary: false }, // second primary demoted
      { contact: 'Budi Fixture', customer_code: 'C001', is_primary: true },
      { contact: 'Sari Fixture', customer_code: 'C002', is_primary: false },
    ]);
    const pos = await sql(`
      SELECT po.po_number, cu.customer_code FROM purchase_orders po JOIN customers cu ON cu.id = po.customer_id
      ORDER BY cu.customer_code, po.po_number`);
    expect(pos.rows).toEqual([
      { po_number: 'PO-001', customer_code: 'C001' },
      { po_number: 'PO-001', customer_code: 'C002' },
      { po_number: 'PO-002', customer_code: 'C002' },
    ]);
    const lead = await sql(`SELECT l.status, l.priority, l.estimated_value, u.email FROM leads l JOIN users u ON u.id = l.owner_user_id`);
    expect(lead.rows).toEqual([{ status: 'QUOTATION', priority: 'HIGH', estimated_value: 150000000, email: 'fixture.sales@test.local' }]);
  });

  it('keeps unmatched products usable as item text instead of dropping the row', async () => {
    const { rows } = await sql(`SELECT product_id, item_name, order_quantity FROM po_lines WHERE item_name IS NOT NULL`);
    expect(rows).toEqual([{ product_id: null, item_name: 'Produk Hilang', order_quantity: 10 }]);
    expect(issues.some((issue) => issue.issue_type === 'UNRESOLVED_PRODUCT' && issue.entity_type === 'po_lines')).toBe(true);
  });

  it('refuses ambiguous text dates and numbers instead of guessing', () => {
    const date = issues.find((issue) => issue.issue_type === 'AMBIGUOUS_DATE');
    expect(date).toMatchObject({ entity_type: 'activities', legacy_row: 4 });
    const number = issues.find((issue) => issue.issue_type === 'AMBIGUOUS_NUMBER');
    expect(number).toMatchObject({ entity_type: 'po_lines', legacy_row: 8, severity: 'ERROR' });
  });

  it('interprets Excel wall-clock times in the business timezone', async () => {
    const { rows } = await sql(`SELECT subject, activity_at FROM activities ORDER BY activity_at`);
    expect(rows[0].activity_at.toISOString()).toBe('2026-03-02T03:30:00.000Z'); // 10:30 WIB
    expect(rows.map((row) => row.subject)).toContain('Kunjungan pabrik'); // derived from description
  });

  it('computes delivered/returned/outstanding from the migrated transactions', async () => {
    const { rows } = await sql(`
      SELECT cu.customer_code, po.po_number, p.product_code, v.delivered_quantity, v.in_progress_quantity,
             v.returned_quantity, v.outstanding_quantity
      FROM v_po_line_fulfillment v
      JOIN po_lines l ON l.id = v.po_line_id
      JOIN purchase_orders po ON po.id = v.purchase_order_id
      JOIN customers cu ON cu.id = po.customer_id
      LEFT JOIN products p ON p.id = l.product_id
      ORDER BY cu.customer_code, po.po_number, p.product_code`);
    expect(rows).toEqual([
      { customer_code: 'C001', po_number: 'PO-001', product_code: 'P001', delivered_quantity: 700, in_progress_quantity: 0, returned_quantity: 50, outstanding_quantity: 350 },
      { customer_code: 'C001', po_number: 'PO-001', product_code: 'P002', delivered_quantity: 0, in_progress_quantity: 100, returned_quantity: 0, outstanding_quantity: 500 },
      { customer_code: 'C002', po_number: 'PO-001', product_code: null, delivered_quantity: 0, in_progress_quantity: 0, returned_quantity: 0, outstanding_quantity: 10 },
      { customer_code: 'C002', po_number: 'PO-002', product_code: 'P003', delivered_quantity: 2000, in_progress_quantity: 0, returned_quantity: 0, outstanding_quantity: 0 },
    ]);
    const summary = await sql(`
      SELECT s.unallocated_delivered_quantity FROM v_purchase_order_summary s
      JOIN purchase_orders po ON po.id = s.purchase_order_id JOIN customers cu ON cu.id = po.customer_id
      WHERE po.po_number = 'PO-001' AND cu.customer_code = 'C001'`);
    expect(summary.rows[0].unallocated_delivered_quantity).toBe(5);
  });

  it('derives payment status from amounts and reports contradicting source values', async () => {
    const { rows } = await sql(`SELECT payment_status, amount, paid_amount FROM invoices_payments`);
    expect(rows[0]).toEqual({ payment_status: 'PARTIAL', amount: 1000000, paid_amount: 400000 });
    expect(issues.some((issue) => issue.issue_type === 'INCONSISTENT_VALUE')).toBe(true);
  });

  it('unpivots stock type columns into separate stock records', async () => {
    const { rows } = await sql(`SELECT p.product_code, s.stock_type, s.quantity FROM stock s JOIN products p ON p.id = s.product_id ORDER BY 1, 2`);
    expect(rows).toEqual([
      { product_code: 'P001', stock_type: 'FG', quantity: 5000 },
      { product_code: 'P001', stock_type: 'WIP', quantity: 200 },
      { product_code: 'P002', stock_type: 'READY', quantity: 300 },
    ]);
  });

  it('records the run and persists every issue with its source row', async () => {
    expect(await count('migration_runs')).toBe(1);
    expect(await count('migration_issues')).toBe(summary.issues.total);
    const stored = await sql(`SELECT * FROM migration_issues WHERE issue_type = 'DUPLICATE_KEY_CONFLICT' AND entity_type = 'customers'`);
    expect(stored.rows[0]).toMatchObject({ source_sheet: 'Pelanggan', legacy_row: 5, resolution_status: 'OPEN' });
    expect(stored.rows[0].source_data).toMatchObject({ 'Nama Customer': 'PT Fixture Palsu' });
  });

  it('writes a CSV issue report that spreadsheet apps cannot execute as formulas', () => {
    const csv = issuesToCsv([{ severity: 'ERROR', issue_type: 'X', description: '=HYPERLINK("x")', source_data: { a: 1 } }]);
    expect(csv).toContain(`"'=HYPERLINK(""x"")"`);
  });

  it('passes verification (row counts, lineage, no silently dropped rows)', async () => {
    const result = await verifyMigration({ databaseUrl, mapping: fixtureMapping, workbookPath });
    const failures = result.checks.filter((check) => check.status === 'FAIL');
    expect(failures).toEqual([]);
    // The fixture's legacy "Outstanding" column disagrees for one line on purpose.
    const reconcile = result.checks.find((check) => check.name.startsWith('Reconcile po_lines.outstanding_quantity'));
    expect(reconcile).toMatchObject({ status: 'WARN' });
    expect(reconcile.detail).toContain('1/4 berbeda');
    const financials = result.checks.find((check) => check.name.startsWith('Reconcile po_financials'));
    expect(financials).toMatchObject({ status: 'PASS' });
  });
});

describe('re-running the migration', () => {
  it('is idempotent: no new rows, no duplicate issues', async () => {
    const issuesBefore = await count('migration_issues');
    const { summary } = await run('apply');
    for (const entity of summary.entities) {
      expect(entity.inserted, entity.entity).toBe(0);
      expect(entity.updated, entity.entity).toBe(0);
    }
    expect(entityStats(summary, 'customers').unchanged).toBe(5);
    expect(await count('customers')).toBe(5);
    expect(await count('deliveries')).toBe(6);
    expect(await count('migration_issues')).toBe(issuesBefore);
    expect(await count('migration_runs')).toBe(2);
  });

  it('never overwrites a record that was edited in the application after migration', async () => {
    await sql(`UPDATE customers SET industry = 'Diubah di aplikasi' WHERE customer_code = 'C002'`);
    const { summary, issues } = await run('apply');
    expect(entityStats(summary, 'customers')).toMatchObject({ skipped_modified_in_app: 1, unchanged: 4 });
    const { rows } = await sql(`SELECT industry FROM customers WHERE customer_code = 'C002'`);
    expect(rows[0].industry).toBe('Diubah di aplikasi');
    expect(issues.some((issue) => issue.issue_type === 'MODIFIED_IN_APP')).toBe(true);
  });

  it('updates records whose source changed, and auto-resolves issues that disappeared', async () => {
    const sheets = structuredClone(FIXTURE_SHEETS);
    sheets.Pelanggan[8][5] = 'Aktif'; // C006 status label fixed in the source
    sheets.Pelanggan[1][2] = 'Kosmetik & Farmasi'; // C001 industry changed
    await buildFixtureWorkbook(workbookPath, sheets);
    const { summary } = await run('apply');
    // C001: industry changed. C006: same status code, but its source row (lineage) changed.
    expect(entityStats(summary, 'customers')).toMatchObject({ updated: 2 });
    expect(summary.auto_resolved_issues).toBeGreaterThanOrEqual(1);
    const { rows } = await sql(`SELECT industry FROM customers WHERE customer_code = 'C001'`);
    expect(rows[0].industry).toBe('Kosmetik & Farmasi');
    const unmapped = await sql(`SELECT resolution_status FROM migration_issues WHERE issue_type = 'UNMAPPED_VALUE'`);
    expect(unmapped.rows[0].resolution_status).toBe('RESOLVED');
  });
});
