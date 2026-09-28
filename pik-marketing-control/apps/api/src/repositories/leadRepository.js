import { FOLLOW_UP_OPEN_STATUSES, LEAD_STATUS } from '@pik/shared';
import { query } from '../db/pool.js';
import { codeList, conditions, findPage, insertRow, likePattern, orderBy, updateRow } from '../utils/sql.js';

export const WRITABLE = [
  'customer_id',
  'contact_id',
  'product_id',
  'name',
  'source',
  'estimated_value',
  'status',
  'priority',
  'owner_user_id',
  'expected_closing_date',
  'notes',
  'lost_reason',
  'status_changed_at',
  'closed_at',
];

const SELECT = `
  SELECT l.*, c.name AS customer_name, c.customer_code, ct.name AS contact_name,
         p.name AS product_name, p.product_code, u.name AS owner_name,
         (SELECT min(f.follow_up_date) FROM follow_ups f
          WHERE f.lead_id = l.id AND f.status IN ${codeList(FOLLOW_UP_OPEN_STATUSES)}) AS next_follow_up_date`;

const FROM = `
  FROM leads l
  JOIN customers c ON c.id = l.customer_id
  LEFT JOIN contacts ct ON ct.id = l.contact_id
  LEFT JOIN products p ON p.id = l.product_id
  LEFT JOIN users u ON u.id = l.owner_user_id`;

function filtersToWhere(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(l.name ILIKE ${p} OR c.name ILIKE ${p})`);
  }
  if (filters.status?.length) w.add(`l.status = ANY(${w.param(filters.status)})`);
  if (filters.priority?.length) w.add(`l.priority = ANY(${w.param(filters.priority)})`);
  if (filters.customer_id) w.add(`l.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.owner_user_id) w.add(`l.owner_user_id = ${w.param(filters.owner_user_id)}`);
  if (filters.closing_from) w.add(`l.expected_closing_date >= ${w.param(filters.closing_from)}`);
  if (filters.closing_to) w.add(`l.expected_closing_date <= ${w.param(filters.closing_to)}`);
  if (filters.source) w.add(`l.source = ${w.param(filters.source)}`);
  return w;
}

const SORTS = {
  updated_at: 'l.updated_at',
  created_at: 'l.created_at',
  name: 'l.name',
  customer_name: 'c.name',
  status: 'l.status',
  expected_closing_date: 'l.expected_closing_date',
  estimated_value: 'l.estimated_value',
};

export async function list(filters) {
  const w = filtersToWhere(filters);
  return findPage({
    select: SELECT,
    from: FROM,
    countFrom: 'FROM leads l JOIN customers c ON c.id = l.customer_id',
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, SORTS, 'l.updated_at DESC', 'l.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

/** Kanban data: per status the count, total estimated value and the most recently updated leads. */
export async function board(filters, perStatus = 50) {
  const w = filtersToWhere(filters);
  const limit = w.param(perStatus);
  const { rows } = await query(
    `SELECT * FROM (
       ${SELECT},
         row_number() OVER (PARTITION BY l.status ORDER BY l.updated_at DESC, l.id) AS position,
         count(*) OVER (PARTITION BY l.status) AS status_count,
         sum(l.estimated_value) OVER (PARTITION BY l.status) AS status_value
       ${FROM} ${w.sql()}
     ) ranked WHERE position <= ${limit}`,
    w.params,
  );
  return LEAD_STATUS.values.map((status) => {
    const leads = rows.filter((row) => row.status === status);
    return {
      status,
      count: leads[0]?.status_count ?? 0,
      total_value: leads[0]?.status_value ?? 0,
      leads: leads.map(({ position, status_count, status_value, ...lead }) => lead),
    };
  });
}

export async function findById(id, db) {
  const { rows } = await query(`${SELECT} ${FROM} WHERE l.id = $1`, [id], db);
  return rows[0] ?? null;
}

export function insert(values, actorId, db) {
  return insertRow('leads', values, WRITABLE, actorId, db);
}

export function update(id, values, actorId, db) {
  return updateRow('leads', id, values, WRITABLE, actorId, db);
}
