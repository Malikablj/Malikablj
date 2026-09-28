import { query } from '../db/pool.js';
import { conditions, findPage, insertRow, likePattern, orderBy, updateRow } from '../utils/sql.js';

export const WRITABLE = ['customer_id', 'contact_id', 'lead_id', 'type', 'subject', 'description', 'owner_user_id', 'activity_at'];

const SELECT = `
  SELECT a.*, c.name AS customer_name, ct.name AS contact_name, l.name AS lead_name, u.name AS owner_name`;

const FROM = `
  FROM activities a
  JOIN customers c ON c.id = a.customer_id
  LEFT JOIN contacts ct ON ct.id = a.contact_id
  LEFT JOIN leads l ON l.id = a.lead_id
  LEFT JOIN users u ON u.id = a.owner_user_id`;

/** from/to are business dates, converted to instants in the business timezone. */
export async function list(filters, timeZone) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(a.subject ILIKE ${p} OR a.description ILIKE ${p} OR c.name ILIKE ${p})`);
  }
  if (filters.type?.length) w.add(`a.type = ANY(${w.param(filters.type)})`);
  if (filters.customer_id) w.add(`a.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.contact_id) w.add(`a.contact_id = ${w.param(filters.contact_id)}`);
  if (filters.lead_id) w.add(`a.lead_id = ${w.param(filters.lead_id)}`);
  if (filters.owner_user_id) w.add(`a.owner_user_id = ${w.param(filters.owner_user_id)}`);
  if (filters.from || filters.to) {
    const tz = w.param(timeZone);
    if (filters.from) w.add(`a.activity_at >= (${w.param(filters.from)}::date::timestamp AT TIME ZONE ${tz})`);
    if (filters.to) w.add(`a.activity_at < ((${w.param(filters.to)}::date + 1)::timestamp AT TIME ZONE ${tz})`);
  }
  return findPage({
    select: SELECT,
    from: FROM,
    countFrom: 'FROM activities a JOIN customers c ON c.id = a.customer_id',
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { activity_at: 'a.activity_at', type: 'a.type', customer_name: 'c.name' }, 'a.activity_at DESC', 'a.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findById(id, db) {
  const { rows } = await query(`${SELECT} ${FROM} WHERE a.id = $1`, [id], db);
  return rows[0] ?? null;
}

export function insert(values, actorId, db) {
  return insertRow('activities', values, WRITABLE, actorId, db);
}

export function update(id, values, actorId, db) {
  return updateRow('activities', id, values, WRITABLE, actorId, db);
}
