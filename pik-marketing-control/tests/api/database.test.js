/** Schema integrity: tables, keys, constraints, indexes and triggers in database/schema.sql. */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { closeDb, sql, truncateAll } from '../helpers/db.js';
import { insertContact, insertCustomer, insertLead, insertProduct, insertPurchaseOrder } from '../helpers/factories.js';

beforeAll(truncateAll);
afterAll(closeDb);

async function expectDbError(promise, code, constraint) {
  const error = await promise.then(
    () => null,
    (caught) => caught,
  );
  expect(error, `expected ${code} ${constraint ?? ''}`).not.toBeNull();
  expect(error.code).toBe(code);
  if (constraint) expect(error.constraint).toBe(constraint);
}

describe('schema', () => {
  it('has every table required by the specification', async () => {
    const { rows } = await sql(
      `SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'`,
    );
    const tables = rows.map((row) => row.table_name);
    for (const table of [
      'users', 'customers', 'contacts', 'products', 'leads', 'activities', 'follow_ups',
      'purchase_orders', 'po_lines', 'deliveries', 'returns', 'stock', 'leadtime',
      'inbound_maklon', 'invoices_payments', 'po_financials', 'migration_issues',
    ]) {
      expect(tables).toContain(table);
    }
  });

  it('keeps source lineage columns on every migrated table', async () => {
    const lineage = ['source_file', 'source_sheet', 'legacy_row', 'legacy_key', 'source_data', 'migrated_at'];
    for (const table of ['customers', 'contacts', 'products', 'leads', 'activities', 'follow_ups', 'purchase_orders',
      'po_lines', 'deliveries', 'returns', 'stock', 'leadtime', 'inbound_maklon', 'invoices_payments', 'po_financials']) {
      const { rows } = await sql(
        `SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1`,
        [table],
      );
      const columns = rows.map((row) => row.column_name);
      for (const column of [...lineage, 'created_at', 'updated_at']) {
        expect(columns, `${table}.${column}`).toContain(column);
      }
    }
  });

  it('indexes every foreign key column', async () => {
    const { rows } = await sql(`
      SELECT c.conrelid::regclass::text AS table_name, a.attname AS column_name
      FROM pg_constraint c
      JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
      WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace
        AND a.attname NOT IN ('created_by', 'updated_by', 'resolved_by', 'first_seen_run_id', 'last_seen_run_id')
        AND NOT EXISTS (
          SELECT 1 FROM pg_index i WHERE i.indrelid = c.conrelid AND i.indkey[0] = c.conkey[1]
        )`);
    expect(rows).toEqual([]);
  });
});

describe('constraints', () => {
  it('rejects a duplicate PO number for the same customer but allows it for another customer', async () => {
    const [a, b] = [await insertCustomer(), await insertCustomer()];
    await insertPurchaseOrder(a.id, { po_number: 'PO-DUP-1' });
    await expectDbError(insertPurchaseOrder(a.id, { po_number: 'PO-DUP-1' }), '23505', 'uq_purchase_orders_customer_po');
    await expect(insertPurchaseOrder(b.id, { po_number: 'PO-DUP-1' })).resolves.toBeTruthy();
  });

  it('rejects an activity whose lead belongs to another customer', async () => {
    const [a, b] = [await insertCustomer(), await insertCustomer()];
    const leadOfB = await insertLead(b.id);
    await expectDbError(
      sql(`INSERT INTO activities (customer_id, lead_id, type, subject, activity_at) VALUES ($1, $2, 'CALL', 'x', now())`, [
        a.id,
        leadOfB.id,
      ]),
      '23503',
      'fk_activities_lead_customer',
    );
  });

  it('rejects a lead whose contact belongs to another customer', async () => {
    const [a, b] = [await insertCustomer(), await insertCustomer()];
    const contactOfB = await insertContact(b.id);
    await expectDbError(
      sql(`INSERT INTO leads (customer_id, contact_id, name) VALUES ($1, $2, 'x')`, [a.id, contactOfB.id]),
      '23503',
      'fk_leads_contact_customer',
    );
  });

  it('rejects a delivery whose PO line belongs to another PO', async () => {
    const customer = await insertCustomer();
    const product = await insertProduct();
    const po1 = await insertPurchaseOrder(customer.id, { lines: [{ product_id: product.id, order_quantity: 10 }] });
    const po2 = await insertPurchaseOrder(customer.id, { lines: [{ product_id: product.id, order_quantity: 10 }] });
    await expectDbError(
      sql(
        `INSERT INTO deliveries (purchase_order_id, po_line_id, product_id, quantity, status) VALUES ($1, $2, $3, 5, 'DELIVERED')`,
        [po1.id, po2.lines[0].id, product.id],
      ),
      '23503',
      'fk_deliveries_line_po',
    );
  });

  it('rejects a return that names a PO line without its PO', async () => {
    const customer = await insertCustomer();
    const product = await insertProduct();
    const po = await insertPurchaseOrder(customer.id, { lines: [{ product_id: product.id, order_quantity: 10 }] });
    await expectDbError(
      sql(
        `INSERT INTO returns (customer_id, po_line_id, product_id, quantity, status) VALUES ($1, $2, $3, 1, 'RECEIVED')`,
        [customer.id, po.lines[0].id, product.id],
      ),
      '23514',
      'ck_returns_line_needs_po',
    );
  });

  it('rejects negative quantities and unknown statuses', async () => {
    const customer = await insertCustomer();
    const product = await insertProduct();
    const po = await insertPurchaseOrder(customer.id);
    await expectDbError(
      sql(`INSERT INTO po_lines (purchase_order_id, product_id, order_quantity) VALUES ($1, $2, -1)`, [po.id, product.id]),
      '23514',
      'ck_po_lines_quantity',
    );
    await expectDbError(
      sql(`UPDATE purchase_orders SET status = 'DONE' WHERE id = $1`, [po.id]),
      '23514',
      'ck_purchase_orders_status',
    );
  });

  it('allows only one active primary contact per customer', async () => {
    const customer = await insertCustomer();
    await insertContact(customer.id, { is_primary: true });
    await expectDbError(insertContact(customer.id, { is_primary: true }), '23505', 'uq_contacts_one_primary');
  });

  it('treats customer codes and user emails as unique', async () => {
    await insertCustomer({ customer_code: 'CUST-UNIQ' });
    await expectDbError(insertCustomer({ customer_code: 'CUST-UNIQ' }), '23505', 'uq_customers_customer_code');
    await sql(`INSERT INTO users (name, email, password_hash, role) VALUES ('A', 'Same@Test.local', 'x', 'VIEWER')`);
    await expectDbError(
      sql(`INSERT INTO users (name, email, password_hash, role) VALUES ('B', 'same@test.local', 'x', 'VIEWER')`),
      '23505',
      'uq_users_email',
    );
  });
});

describe('triggers and helpers', () => {
  it('maintains updated_at on update', async () => {
    const customer = await insertCustomer();
    await new Promise((resolve) => setTimeout(resolve, 20));
    const { rows } = await sql(`UPDATE customers SET notes = 'changed' WHERE id = $1 RETURNING created_at, updated_at`, [
      customer.id,
    ]);
    expect(rows[0].updated_at.getTime()).toBeGreaterThan(rows[0].created_at.getTime());
  });

  it('normalizes names for matching (case, punctuation, whitespace)', async () => {
    const { rows } = await sql(`SELECT normalize_key('  PT. Maju   Jaya, Tbk ') AS a, normalize_key('pt maju jaya tbk') AS b`);
    expect(rows[0].a).toBe('pt maju jaya tbk');
    expect(rows[0].a).toBe(rows[0].b);
  });
});
