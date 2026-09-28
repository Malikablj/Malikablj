import { query } from '../db/pool.js';
import { insertRow, updateRow } from '../utils/sql.js';

export const WRITABLE = ['customer_id', 'name', 'position', 'phone', 'email', 'whatsapp', 'is_primary', 'notes', 'is_active'];

export async function listForCustomer(customerId, { isActive = true } = {}) {
  const { rows } = await query(
    `SELECT * FROM contacts
     WHERE customer_id = $1 AND ($2::boolean IS NULL OR is_active = $2)
     ORDER BY is_primary DESC, name`,
    [customerId, isActive ?? null],
  );
  return rows;
}

export async function findById(id, db) {
  const { rows } = await query(
    `SELECT ct.*, c.name AS customer_name FROM contacts ct JOIN customers c ON c.id = ct.customer_id WHERE ct.id = $1`,
    [id],
    db,
  );
  return rows[0] ?? null;
}

/** Clears the primary flag of the customer's other contacts (one primary per customer). */
export async function clearPrimary(customerId, exceptId, actorId, db) {
  await query(
    `UPDATE contacts SET is_primary = FALSE, updated_by = $3
     WHERE customer_id = $1 AND is_primary AND id IS DISTINCT FROM $2`,
    [customerId, exceptId, actorId],
    db,
  );
}

export function insert(values, actorId, db) {
  return insertRow('contacts', values, WRITABLE, actorId, db);
}

export function update(id, values, actorId, db) {
  return updateRow('contacts', id, values, WRITABLE, actorId, db);
}
