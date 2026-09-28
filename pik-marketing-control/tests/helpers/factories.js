/**
 * Minimal row factories for tests. Names are obviously synthetic ("Test ...") so test data
 * can never be mistaken for business data.
 */
import { hashPassword } from '../../apps/api/src/utils/password.js';
import { sql } from './db.js';

export const TEST_PASSWORD = 'test-password-123';
let passwordHashPromise = null;
function testPasswordHash() {
  passwordHashPromise ??= hashPassword(TEST_PASSWORD);
  return passwordHashPromise;
}

let counter = 0;
const next = () => {
  counter += 1;
  return counter;
};

export async function insertUser({ role = 'ADMIN', name, email, isActive = true } = {}) {
  const n = next();
  const { rows } = await sql(
    `INSERT INTO users (name, email, password_hash, role, is_active) VALUES ($1, $2, $3, $4, $5) RETURNING *`,
    [name ?? `Test User ${n}`, email ?? `user${n}.${role.toLowerCase()}@test.local`, await testPasswordHash(), role, isActive],
  );
  return rows[0];
}

export async function insertCustomer(values = {}) {
  const n = next();
  const { rows } = await sql(
    `INSERT INTO customers (name, customer_code, status) VALUES ($1, $2, $3) RETURNING *`,
    [values.name ?? `Test Customer ${n}`, values.customer_code ?? null, values.status ?? 'ACTIVE'],
  );
  return rows[0];
}

export async function insertContact(customerId, values = {}) {
  const n = next();
  const { rows } = await sql(
    `INSERT INTO contacts (customer_id, name, is_primary) VALUES ($1, $2, $3) RETURNING *`,
    [customerId, values.name ?? `Test Contact ${n}`, values.is_primary ?? false],
  );
  return rows[0];
}

export async function insertProduct(values = {}) {
  const n = next();
  const { rows } = await sql(
    `INSERT INTO products (name, product_code, unit, customer_id) VALUES ($1, $2, $3, $4) RETURNING *`,
    [values.name ?? `Test Product ${n}`, values.product_code ?? `TP-${n}`, values.unit ?? 'pcs', values.customer_id ?? null],
  );
  return rows[0];
}

export async function insertLead(customerId, values = {}) {
  const n = next();
  const { rows } = await sql(
    `INSERT INTO leads (customer_id, name, status, owner_user_id) VALUES ($1, $2, $3, $4) RETURNING *`,
    [customerId, values.name ?? `Test Lead ${n}`, values.status ?? 'NEW', values.owner_user_id ?? null],
  );
  return rows[0];
}

export async function insertPurchaseOrder(customerId, { lines = [], ...values } = {}) {
  const n = next();
  const { rows } = await sql(
    `INSERT INTO purchase_orders (customer_id, po_number, status, po_date, owner_user_id)
     VALUES ($1, $2, $3, $4, $5) RETURNING *`,
    [customerId, values.po_number ?? `TEST-PO-${n}`, values.status ?? 'OPEN', values.po_date ?? null, values.owner_user_id ?? null],
  );
  const po = rows[0];
  po.lines = [];
  for (const [index, line] of lines.entries()) {
    const inserted = await sql(
      `INSERT INTO po_lines (purchase_order_id, line_no, product_id, order_quantity, unit, unit_price)
       VALUES ($1, $2, $3, $4, $5, $6) RETURNING *`,
      [po.id, index + 1, line.product_id, line.order_quantity, line.unit ?? 'pcs', line.unit_price ?? null],
    );
    po.lines.push(inserted.rows[0]);
  }
  return po;
}

export async function insertDelivery(po, line, { quantity, status = 'DELIVERED', lineless = false } = {}) {
  const { rows } = await sql(
    `INSERT INTO deliveries (purchase_order_id, po_line_id, product_id, quantity, status, delivery_date)
     VALUES ($1, $2, $3, $4, $5, CURRENT_DATE) RETURNING *`,
    [po.id, lineless ? null : line.id, line.product_id, quantity, status],
  );
  return rows[0];
}

export async function insertReturn(po, line, { quantity, status = 'RECEIVED' } = {}) {
  const { rows } = await sql(
    `INSERT INTO returns (customer_id, purchase_order_id, po_line_id, product_id, quantity, status, return_date, reason)
     VALUES ($1, $2, $3, $4, $5, $6, CURRENT_DATE, 'Test reason') RETURNING *`,
    [po.customer_id, po.id, line.id, line.product_id, quantity, status],
  );
  return rows[0];
}
