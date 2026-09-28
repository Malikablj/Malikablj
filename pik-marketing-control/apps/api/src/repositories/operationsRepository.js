/** Deliveries, returns, stock, lead time and inbound maklon (read models + writes). */
import { query } from '../db/pool.js';
import { conditions, findPage, insertRow, likePattern, orderBy, updateRow } from '../utils/sql.js';

// ------------------------------------------------------------------ deliveries
export const DELIVERY_WRITABLE = [
  'purchase_order_id',
  'po_line_id',
  'product_id',
  'item_name',
  'delivery_date',
  'quantity',
  'status',
  'delivery_number',
  'notes',
];

const DELIVERY_SELECT = `
  SELECT d.*, po.po_number, po.customer_id, c.name AS customer_name,
         coalesce(p.name, d.item_name) AS product_name, p.product_code, coalesce(l.unit, p.unit) AS unit, l.line_no`;
const DELIVERY_FROM = `
  FROM deliveries d
  JOIN purchase_orders po ON po.id = d.purchase_order_id
  JOIN customers c ON c.id = po.customer_id
  LEFT JOIN products p ON p.id = d.product_id
  LEFT JOIN po_lines l ON l.id = d.po_line_id`;

export async function listDeliveries(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(d.delivery_number ILIKE ${p} OR po.po_number ILIKE ${p} OR c.name ILIKE ${p} OR p.name ILIKE ${p} OR d.item_name ILIKE ${p})`);
  }
  if (filters.status?.length) w.add(`d.status = ANY(${w.param(filters.status)})`);
  if (filters.customer_id) w.add(`po.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.purchase_order_id) w.add(`d.purchase_order_id = ${w.param(filters.purchase_order_id)}`);
  if (filters.product_id) w.add(`d.product_id = ${w.param(filters.product_id)}`);
  if (filters.from) w.add(`d.delivery_date >= ${w.param(filters.from)}`);
  if (filters.to) w.add(`d.delivery_date <= ${w.param(filters.to)}`);
  return findPage({
    select: DELIVERY_SELECT,
    from: DELIVERY_FROM,
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { delivery_date: 'd.delivery_date', po_number: 'po.po_number', customer_name: 'c.name', quantity: 'd.quantity', status: 'd.status' }, 'd.delivery_date DESC NULLS LAST, d.created_at DESC', 'd.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findDelivery(id, db) {
  const { rows } = await query(`${DELIVERY_SELECT} ${DELIVERY_FROM} WHERE d.id = $1`, [id], db);
  return rows[0] ?? null;
}

export const insertDelivery = (values, actorId, db) => insertRow('deliveries', values, DELIVERY_WRITABLE, actorId, db);
export const updateDelivery = (id, values, actorId, db) => updateRow('deliveries', id, values, DELIVERY_WRITABLE, actorId, db);

// --------------------------------------------------------------------- returns
export const RETURN_WRITABLE = [
  'customer_id',
  'purchase_order_id',
  'po_line_id',
  'product_id',
  'item_name',
  'return_date',
  'quantity',
  'reason',
  'status',
  'return_number',
  'notes',
];

const RETURN_SELECT = `
  SELECT r.*, c.name AS customer_name, po.po_number,
         coalesce(p.name, r.item_name) AS product_name, p.product_code, coalesce(l.unit, p.unit) AS unit, l.line_no`;
const RETURN_FROM = `
  FROM returns r
  JOIN customers c ON c.id = r.customer_id
  LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
  LEFT JOIN products p ON p.id = r.product_id
  LEFT JOIN po_lines l ON l.id = r.po_line_id`;

export async function listReturns(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(r.return_number ILIKE ${p} OR po.po_number ILIKE ${p} OR c.name ILIKE ${p} OR p.name ILIKE ${p} OR r.reason ILIKE ${p})`);
  }
  if (filters.status?.length) w.add(`r.status = ANY(${w.param(filters.status)})`);
  if (filters.customer_id) w.add(`r.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.purchase_order_id) w.add(`r.purchase_order_id = ${w.param(filters.purchase_order_id)}`);
  if (filters.product_id) w.add(`r.product_id = ${w.param(filters.product_id)}`);
  if (filters.from) w.add(`r.return_date >= ${w.param(filters.from)}`);
  if (filters.to) w.add(`r.return_date <= ${w.param(filters.to)}`);
  return findPage({
    select: RETURN_SELECT,
    from: RETURN_FROM,
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { return_date: 'r.return_date', customer_name: 'c.name', quantity: 'r.quantity', status: 'r.status' }, 'r.return_date DESC NULLS LAST, r.created_at DESC', 'r.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findReturn(id, db) {
  const { rows } = await query(`${RETURN_SELECT} ${RETURN_FROM} WHERE r.id = $1`, [id], db);
  return rows[0] ?? null;
}

export const insertReturn = (values, actorId, db) => insertRow('returns', values, RETURN_WRITABLE, actorId, db);
export const updateReturn = (id, values, actorId, db) => updateRow('returns', id, values, RETURN_WRITABLE, actorId, db);

// ----------------------------------------------------------------------- stock
export const STOCK_WRITABLE = ['product_id', 'stock_type', 'quantity', 'warehouse', 'stock_date', 'notes'];

const STOCK_SELECT = `
  SELECT s.*, coalesce(p.name, s.item_name) AS product_name, p.product_code, p.unit, p.category`;

/** Current stock: the latest snapshot per product / stock type / warehouse (v_stock_current). */
export async function listCurrentStock(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(p.name ILIKE ${p} OR p.product_code ILIKE ${p} OR s.item_name ILIKE ${p} OR s.warehouse ILIKE ${p})`);
  }
  if (filters.stock_type?.length) w.add(`s.stock_type = ANY(${w.param(filters.stock_type)})`);
  if (filters.warehouse) w.add(`s.warehouse = ${w.param(filters.warehouse)}`);
  if (filters.product_id) w.add(`s.product_id = ${w.param(filters.product_id)}`);
  return findPage({
    select: STOCK_SELECT,
    from: 'FROM v_stock_current s LEFT JOIN products p ON p.id = s.product_id',
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { product_name: 'product_name', stock_type: 's.stock_type', quantity: 's.quantity', stock_date: 's.stock_date', warehouse: 's.warehouse' }, 'product_name ASC, s.stock_type', 's.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

/** All snapshots (history), newest first. */
export async function listStockHistory(filters) {
  const w = conditions();
  if (filters.product_id) w.add(`s.product_id = ${w.param(filters.product_id)}`);
  if (filters.stock_type?.length) w.add(`s.stock_type = ANY(${w.param(filters.stock_type)})`);
  if (filters.warehouse) w.add(`s.warehouse = ${w.param(filters.warehouse)}`);
  return findPage({
    select: `${STOCK_SELECT}, u.name AS updated_by_name`,
    from: 'FROM stock s LEFT JOIN products p ON p.id = s.product_id LEFT JOIN users u ON u.id = s.updated_by',
    where: w.sql(),
    params: w.params,
    order: 's.stock_date DESC NULLS LAST, s.updated_at DESC, s.id',
    page: filters.page,
    pageSize: filters.page_size,
  });
}

/** Totals of current stock per type and unit (quantities in different units are never added up). */
export async function stockTotals() {
  const { rows } = await query(
    `SELECT s.stock_type, coalesce(p.unit, '-') AS unit, sum(s.quantity) AS quantity, count(*) AS entries
     FROM v_stock_current s LEFT JOIN products p ON p.id = s.product_id
     GROUP BY s.stock_type, coalesce(p.unit, '-') ORDER BY s.stock_type, unit`,
  );
  return rows;
}

export async function warehouses() {
  const { rows } = await query(`SELECT DISTINCT warehouse FROM stock WHERE warehouse IS NOT NULL ORDER BY 1`);
  return rows.map((row) => row.warehouse);
}

export async function findStock(id, db) {
  const { rows } = await query(`${STOCK_SELECT} FROM stock s LEFT JOIN products p ON p.id = s.product_id WHERE s.id = $1`, [id], db);
  return rows[0] ?? null;
}

export const insertStock = (values, actorId, db) => insertRow('stock', values, STOCK_WRITABLE, actorId, db);
export const updateStock = (id, values, actorId, db) => updateRow('stock', id, values, STOCK_WRITABLE, actorId, db);

// ------------------------------------------------------------------- lead time
export const LEAD_TIME_WRITABLE = ['product_id', 'customer_id', 'lead_time_days', 'notes'];

const LEAD_TIME_SELECT = `
  SELECT lt.*, coalesce(p.name, lt.item_name) AS product_name, p.product_code, c.name AS customer_name`;
const LEAD_TIME_FROM = `FROM leadtime lt LEFT JOIN products p ON p.id = lt.product_id LEFT JOIN customers c ON c.id = lt.customer_id`;

export async function listLeadTimes(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(p.name ILIKE ${p} OR p.product_code ILIKE ${p} OR c.name ILIKE ${p} OR lt.item_name ILIKE ${p})`);
  }
  if (filters.product_id) w.add(`lt.product_id = ${w.param(filters.product_id)}`);
  if (filters.customer_id) w.add(`lt.customer_id = ${w.param(filters.customer_id)}`);
  return findPage({
    select: LEAD_TIME_SELECT,
    from: LEAD_TIME_FROM,
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { product_name: 'product_name', customer_name: 'c.name', lead_time_days: 'lt.lead_time_days' }, 'product_name ASC', 'lt.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findLeadTime(id, db) {
  const { rows } = await query(`${LEAD_TIME_SELECT} ${LEAD_TIME_FROM} WHERE lt.id = $1`, [id], db);
  return rows[0] ?? null;
}

export const insertLeadTime = (values, actorId, db) => insertRow('leadtime', values, LEAD_TIME_WRITABLE, actorId, db);
export const updateLeadTime = (id, values, actorId, db) => updateRow('leadtime', id, values, LEAD_TIME_WRITABLE, actorId, db);

// -------------------------------------------------------------- inbound maklon
export const MAKLON_WRITABLE = [
  'customer_id',
  'purchase_order_id',
  'product_id',
  'item_name',
  'inbound_date',
  'quantity',
  'unit',
  'document_number',
  'notes',
];

const MAKLON_SELECT = `
  SELECT m.*, c.name AS customer_name, po.po_number, coalesce(p.name, m.item_name) AS product_name, p.product_code`;
const MAKLON_FROM = `
  FROM inbound_maklon m
  LEFT JOIN customers c ON c.id = m.customer_id
  LEFT JOIN purchase_orders po ON po.id = m.purchase_order_id
  LEFT JOIN products p ON p.id = m.product_id`;

export async function listInboundMaklon(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(m.document_number ILIKE ${p} OR m.item_name ILIKE ${p} OR p.name ILIKE ${p} OR c.name ILIKE ${p} OR po.po_number ILIKE ${p})`);
  }
  if (filters.customer_id) w.add(`m.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.purchase_order_id) w.add(`m.purchase_order_id = ${w.param(filters.purchase_order_id)}`);
  if (filters.from) w.add(`m.inbound_date >= ${w.param(filters.from)}`);
  if (filters.to) w.add(`m.inbound_date <= ${w.param(filters.to)}`);
  return findPage({
    select: MAKLON_SELECT,
    from: MAKLON_FROM,
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { inbound_date: 'm.inbound_date', customer_name: 'c.name', quantity: 'm.quantity' }, 'm.inbound_date DESC NULLS LAST, m.created_at DESC', 'm.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findInboundMaklon(id, db) {
  const { rows } = await query(`${MAKLON_SELECT} ${MAKLON_FROM} WHERE m.id = $1`, [id], db);
  return rows[0] ?? null;
}

export const insertInboundMaklon = (values, actorId, db) => insertRow('inbound_maklon', values, MAKLON_WRITABLE, actorId, db);
export const updateInboundMaklon = (id, values, actorId, db) => updateRow('inbound_maklon', id, values, MAKLON_WRITABLE, actorId, db);
