import { PO_OPEN_STATUSES } from '@pik/shared';
import { query } from '../db/pool.js';
import { codeList, conditions, findPage, insertRow, likePattern, orderBy, updateRow } from '../utils/sql.js';

export const WRITABLE = ['product_code', 'name', 'category', 'customer_id', 'description', 'unit', 'lead_time_days', 'status', 'is_active'];

const SELECT = `
  SELECT p.*, c.name AS customer_name,
         st.stock_fg, st.stock_wip, st.stock_ready, st.stock_reserved`;

// Current stock per type comes from v_stock_current (latest snapshot per type/warehouse).
const FROM = `
  FROM products p
  LEFT JOIN customers c ON c.id = p.customer_id
  LEFT JOIN LATERAL (
    SELECT sum(quantity) FILTER (WHERE stock_type = 'FG') AS stock_fg,
           sum(quantity) FILTER (WHERE stock_type = 'WIP') AS stock_wip,
           sum(quantity) FILTER (WHERE stock_type = 'READY') AS stock_ready,
           sum(quantity) FILTER (WHERE stock_type = 'RESERVED') AS stock_reserved
    FROM v_stock_current WHERE product_id = p.id
  ) st ON TRUE`;

export async function list(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(p.name ILIKE ${p} OR p.product_code ILIKE ${p} OR p.category ILIKE ${p})`);
  }
  if (filters.category) w.add(`p.category = ${w.param(filters.category)}`);
  if (filters.customer_id) w.add(`p.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.status?.length) w.add(`p.status = ANY(${w.param(filters.status)})`);
  // Archived products are hidden unless explicitly requested (?is_active=false).
  w.add(`p.is_active = ${w.param(filters.is_active ?? true)}`);
  return findPage({
    select: SELECT,
    from: FROM,
    countFrom: 'FROM products p',
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { name: 'p.name', product_code: 'p.product_code', category: 'p.category', created_at: 'p.created_at' }, 'p.name ASC', 'p.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findById(id, db) {
  const { rows } = await query(`${SELECT} ${FROM} WHERE p.id = $1`, [id], db);
  return rows[0] ?? null;
}

export async function currentStock(productId) {
  const { rows } = await query(
    `SELECT * FROM v_stock_current WHERE product_id = $1 ORDER BY stock_type, warehouse NULLS FIRST`,
    [productId],
  );
  return rows;
}

export async function leadTimes(productId) {
  const { rows } = await query(
    `SELECT lt.*, c.name AS customer_name FROM leadtime lt LEFT JOIN customers c ON c.id = lt.customer_id
     WHERE lt.product_id = $1 ORDER BY c.name NULLS FIRST`,
    [productId],
  );
  return rows;
}

/** Lines of open POs for this product that still have outstanding quantity. */
export async function openOrderLines(productId) {
  const { rows } = await query(
    `SELECT l.id, l.purchase_order_id, po.po_number, po.expected_delivery_date, po.status, c.name AS customer_name,
            l.order_quantity, coalesce(l.unit, p.unit) AS unit, v.delivered_quantity, v.outstanding_quantity
     FROM po_lines l
     JOIN purchase_orders po ON po.id = l.purchase_order_id
     JOIN customers c ON c.id = po.customer_id
     JOIN products p ON p.id = l.product_id
     JOIN v_po_line_fulfillment v ON v.po_line_id = l.id
     WHERE l.product_id = $1 AND po.status IN ${codeList(PO_OPEN_STATUSES)} AND v.outstanding_quantity > 0
     ORDER BY po.expected_delivery_date NULLS LAST, po.po_number
     LIMIT 50`,
    [productId],
  );
  return rows;
}

export async function facets() {
  const [categories, units] = await Promise.all([
    query(`SELECT DISTINCT category AS value FROM products WHERE category IS NOT NULL AND is_active ORDER BY 1`),
    query(`SELECT DISTINCT unit AS value FROM products WHERE unit IS NOT NULL AND is_active ORDER BY 1`),
  ]);
  return { categories: categories.rows.map((r) => r.value), units: units.rows.map((r) => r.value) };
}

export function insert(values, actorId, db) {
  return insertRow('products', values, WRITABLE, actorId, db);
}

export function update(id, values, actorId, db) {
  return updateRow('products', id, values, WRITABLE, actorId, db);
}
