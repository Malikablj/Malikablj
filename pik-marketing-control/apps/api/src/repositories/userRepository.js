import { query } from '../db/pool.js';
import { conditions, findPage, likePattern, orderBy, updateAssignments } from '../utils/sql.js';

const PUBLIC_COLUMNS = 'u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.created_at, u.updated_at';

export async function findByEmailWithHash(email) {
  const { rows } = await query(`SELECT u.*, u.password_hash FROM users u WHERE lower(u.email) = lower($1)`, [email]);
  return rows[0] ?? null;
}

export async function findById(id, db) {
  const { rows } = await query(`SELECT ${PUBLIC_COLUMNS} FROM users u WHERE u.id = $1`, [id], db);
  return rows[0] ?? null;
}

export async function findByIdWithHash(id) {
  const { rows } = await query(`SELECT u.*, u.password_hash FROM users u WHERE u.id = $1`, [id]);
  return rows[0] ?? null;
}

export async function list({ q, role, is_active: isActive, sort, page, page_size: pageSize }) {
  const w = conditions();
  if (q) {
    const p = w.param(likePattern(q));
    w.add(`(u.name ILIKE ${p} OR u.email ILIKE ${p})`);
  }
  if (role) w.add(`u.role = ${w.param(role)}`);
  if (isActive !== undefined) w.add(`u.is_active = ${w.param(isActive)}`);
  return findPage({
    select: `SELECT ${PUBLIC_COLUMNS}`,
    from: 'FROM users u',
    where: w.sql(),
    params: w.params,
    order: orderBy(sort, { name: 'u.name', email: 'u.email', role: 'u.role', last_login_at: 'u.last_login_at' }, 'u.name ASC', 'u.id'),
    page,
    pageSize,
  });
}

/** Active users for owner/PIC pickers (small list, no paging). */
export async function options() {
  const { rows } = await query(`SELECT id, name, role FROM users WHERE is_active ORDER BY name`);
  return rows;
}

export async function create({ name, email, role, passwordHash, isActive, createdBy }) {
  const { rows } = await query(
    `INSERT INTO users (name, email, password_hash, role, is_active, created_by, updated_by)
     VALUES ($1, $2, $3, $4, $5, $6, $6)
     RETURNING id, name, email, role, is_active, last_login_at, created_at, updated_at`,
    [name, email, passwordHash, role, isActive, createdBy],
  );
  return rows[0];
}

export async function update(id, values, updatedBy) {
  const assignments = updateAssignments(values, ['name', 'email', 'role', 'is_active']);
  if (!assignments) return findById(id);
  const n = assignments.params.length;
  const { rows } = await query(
    `UPDATE users SET ${assignments.sql}, updated_by = $${n + 1} WHERE id = $${n + 2}
     RETURNING id, name, email, role, is_active, last_login_at, created_at, updated_at`,
    [...assignments.params, updatedBy, id],
  );
  return rows[0] ?? null;
}

export async function updatePassword(id, passwordHash, updatedBy) {
  await query(`UPDATE users SET password_hash = $1, updated_by = $2 WHERE id = $3`, [passwordHash, updatedBy, id]);
}

export async function touchLastLogin(id) {
  await query(`UPDATE users SET last_login_at = now() WHERE id = $1`, [id]);
}

export async function countActiveAdmins() {
  const { rows } = await query(`SELECT count(*)::int AS n FROM users WHERE role = 'ADMIN' AND is_active`);
  return rows[0].n;
}
