/** Minimal lookups used to validate references chosen by users. */
import { query } from '../db/pool.js';

export async function userStatus(id) {
  const { rows } = await query('SELECT is_active FROM users WHERE id = $1', [id]);
  return rows[0] ?? null;
}

export async function customerStatus(id, db) {
  const { rows } = await query('SELECT is_active FROM customers WHERE id = $1', [id], db);
  return rows[0] ?? null;
}

export async function product(id, db) {
  const { rows } = await query('SELECT id, unit, is_active FROM products WHERE id = $1', [id], db);
  return rows[0] ?? null;
}
