/**
 * Follow-ups. The derived `state` (OVERDUE / TODAY / UPCOMING / DONE / CANCELLED) always comes
 * from the database function follow_up_state() with the business "today".
 */
import { query } from '../db/pool.js';
import { conditions, findPage, insertRow, likePattern, orderBy, sqlDate, updateRow } from '../utils/sql.js';

export const WRITABLE = [
  'customer_id',
  'lead_id',
  'activity_id',
  'owner_user_id',
  'follow_up_date',
  'follow_up_time',
  'priority',
  'status',
  'notes',
  'completed_at',
];

const select = (today) => `
  SELECT f.*, follow_up_state(f.follow_up_date, f.status, ${sqlDate(today)}) AS state,
         c.name AS customer_name, l.name AS lead_name, u.name AS owner_name,
         pc.name AS contact_name, coalesce(pc.whatsapp, pc.phone) AS contact_phone`;

const FROM = `
  FROM follow_ups f
  JOIN customers c ON c.id = f.customer_id
  LEFT JOIN leads l ON l.id = f.lead_id
  LEFT JOIN users u ON u.id = f.owner_user_id
  LEFT JOIN LATERAL (
    SELECT ct.name, ct.phone, ct.whatsapp FROM contacts ct
    WHERE ct.id = coalesce(l.contact_id, (SELECT id FROM contacts WHERE customer_id = f.customer_id AND is_primary AND is_active LIMIT 1))
  ) pc ON TRUE`;

export async function list(filters, today) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(f.notes ILIKE ${p} OR c.name ILIKE ${p} OR l.name ILIKE ${p})`);
  }
  if (filters.state?.length) w.add(`follow_up_state(f.follow_up_date, f.status, ${sqlDate(today)}) = ANY(${w.param(filters.state)})`);
  if (filters.priority?.length) w.add(`f.priority = ANY(${w.param(filters.priority)})`);
  if (filters.customer_id) w.add(`f.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.lead_id) w.add(`f.lead_id = ${w.param(filters.lead_id)}`);
  if (filters.owner_user_id) w.add(`f.owner_user_id = ${w.param(filters.owner_user_id)}`);
  if (filters.from) w.add(`f.follow_up_date >= ${w.param(filters.from)}`);
  if (filters.to) w.add(`f.follow_up_date <= ${w.param(filters.to)}`);
  return findPage({
    select: select(today),
    from: FROM,
    countFrom: 'FROM follow_ups f JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id',
    where: w.sql(),
    params: w.params,
    order: orderBy(
      filters.sort,
      { follow_up_date: 'f.follow_up_date', priority: "array_position(ARRAY['HIGH','MEDIUM','LOW'], f.priority)", customer_name: 'c.name', completed_at: 'f.completed_at' },
      'f.follow_up_date ASC, f.follow_up_time ASC NULLS LAST',
      'f.id',
    ),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

/** Counts per derived state, optionally for one owner. */
export async function summary(today, timeZone, ownerId) {
  const params = [timeZone];
  let ownerFilter = '';
  if (ownerId) {
    params.push(ownerId);
    ownerFilter = 'WHERE f.owner_user_id = $2';
  }
  const t = sqlDate(today);
  const { rows } = await query(
    `SELECT
       count(*) FILTER (WHERE follow_up_state(f.follow_up_date, f.status, ${t}) = 'TODAY') AS today,
       count(*) FILTER (WHERE follow_up_state(f.follow_up_date, f.status, ${t}) = 'OVERDUE') AS overdue,
       count(*) FILTER (WHERE follow_up_state(f.follow_up_date, f.status, ${t}) = 'UPCOMING') AS upcoming,
       count(*) FILTER (WHERE follow_up_state(f.follow_up_date, f.status, ${t}) = 'UPCOMING'
                          AND f.follow_up_date <= ${t} + 7) AS upcoming_7_days,
       count(*) FILTER (WHERE f.status = 'DONE' AND f.completed_at >= ((${t} - 7)::timestamp AT TIME ZONE $1)) AS done_last_7_days
     FROM follow_ups f ${ownerFilter}`,
    params,
  );
  return rows[0];
}

export async function findById(id, today, db) {
  const { rows } = await query(`${select(today)} ${FROM} WHERE f.id = $1`, [id], db);
  return rows[0] ?? null;
}

export function insert(values, actorId, db) {
  return insertRow('follow_ups', values, WRITABLE, actorId, db);
}

export function update(id, values, actorId, db) {
  return updateRow('follow_ups', id, values, WRITABLE, actorId, db);
}
