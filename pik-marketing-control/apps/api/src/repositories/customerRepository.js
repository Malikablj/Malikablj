import { LEAD_OPEN_STATUSES, PO_OPEN_STATUSES } from '@pik/shared';
import { query } from '../db/pool.js';
import { codeList, conditions, findPage, insertRow, likePattern, orderBy, updateRow } from '../utils/sql.js';

export const WRITABLE = ['customer_code', 'name', 'industry', 'address', 'phone', 'email', 'website', 'status', 'notes', 'is_active'];

const LIST_SELECT = `
  SELECT c.id, c.customer_code, c.name, c.industry, c.phone, c.email, c.status, c.is_active,
         c.created_at, c.updated_at,
         pc.name AS primary_contact_name, coalesce(pc.whatsapp, pc.phone) AS primary_contact_phone,
         (SELECT count(*) FROM leads l WHERE l.customer_id = c.id AND l.status IN ${codeList(LEAD_OPEN_STATUSES)}) AS active_leads,
         (SELECT count(*) FROM purchase_orders po WHERE po.customer_id = c.id AND po.status IN ${codeList(PO_OPEN_STATUSES)}) AS open_purchase_orders,
         (SELECT max(a.activity_at) FROM activities a WHERE a.customer_id = c.id) AS last_activity_at`;

const LIST_FROM = `
  FROM customers c
  LEFT JOIN LATERAL (
    SELECT name, phone, whatsapp FROM contacts
    WHERE customer_id = c.id AND is_active ORDER BY is_primary DESC, name LIMIT 1
  ) pc ON TRUE`;

export async function list({ q, status, industry, is_active: isActive = true, sort, page, page_size: pageSize }) {
  const w = conditions();
  if (q) {
    const p = w.param(likePattern(q));
    w.add(`(c.name ILIKE ${p} OR c.customer_code ILIKE ${p} OR c.email ILIKE ${p} OR c.phone ILIKE ${p})`);
  }
  if (status?.length) w.add(`c.status = ANY(${w.param(status)})`);
  if (industry) w.add(`c.industry = ${w.param(industry)}`);
  if (isActive !== undefined) w.add(`c.is_active = ${w.param(isActive)}`);
  return findPage({
    select: LIST_SELECT,
    from: LIST_FROM,
    countFrom: 'FROM customers c',
    where: w.sql(),
    params: w.params,
    order: orderBy(
      sort,
      { name: 'c.name', customer_code: 'c.customer_code', status: 'c.status', created_at: 'c.created_at', last_activity_at: 'last_activity_at' },
      'c.name ASC',
      'c.id',
    ),
    page,
    pageSize,
  });
}

export async function findById(id, db) {
  const { rows } = await query(
    `SELECT c.*, cb.name AS created_by_name, ub.name AS updated_by_name
     FROM customers c
     LEFT JOIN users cb ON cb.id = c.created_by
     LEFT JOIN users ub ON ub.id = c.updated_by
     WHERE c.id = $1`,
    [id],
    db,
  );
  return rows[0] ?? null;
}

/** Workspace header figures for the customer detail page. */
export async function summary(id, today) {
  const { rows } = await query(
    `SELECT
       (SELECT count(*) FROM contacts WHERE customer_id = $1 AND is_active) AS contacts,
       (SELECT count(*) FROM leads WHERE customer_id = $1 AND status = ANY($2)) AS active_leads,
       (SELECT coalesce(sum(estimated_value), 0) FROM leads WHERE customer_id = $1 AND status = ANY($2)) AS pipeline_value,
       (SELECT count(*) FROM purchase_orders WHERE customer_id = $1 AND status = ANY($3)) AS open_purchase_orders,
       (SELECT coalesce(sum(s.outstanding_quantity), 0)
          FROM purchase_orders po JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id
          WHERE po.customer_id = $1 AND po.status = ANY($3)) AS outstanding_quantity,
       (SELECT count(*) FROM follow_ups WHERE customer_id = $1 AND follow_up_state(follow_up_date, status, $4::date) = 'OVERDUE') AS overdue_follow_ups,
       (SELECT min(follow_up_date) FROM follow_ups WHERE customer_id = $1 AND follow_up_state(follow_up_date, status, $4::date) IN ('TODAY', 'UPCOMING')) AS next_follow_up_date,
       (SELECT max(activity_at) FROM activities WHERE customer_id = $1) AS last_activity_at`,
    [id, LEAD_OPEN_STATUSES, PO_OPEN_STATUSES, today],
  );
  return rows[0];
}

export async function facets() {
  const { rows } = await query(
    `SELECT DISTINCT industry FROM customers WHERE industry IS NOT NULL AND is_active ORDER BY industry`,
  );
  return { industries: rows.map((row) => row.industry) };
}

export function insert(values, actorId, db) {
  return insertRow('customers', values, WRITABLE, actorId, db);
}

export function update(id, values, actorId, db) {
  return updateRow('customers', id, values, WRITABLE, actorId, db);
}
