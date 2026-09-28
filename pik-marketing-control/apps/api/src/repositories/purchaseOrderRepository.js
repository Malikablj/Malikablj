/**
 * Purchase orders and lines. Quantities delivered/returned/outstanding always come from the
 * database views (v_po_line_fulfillment, v_purchase_order_summary); nothing is stored.
 */
import { PO_OPEN_STATUSES } from '@pik/shared';
import { query } from '../db/pool.js';
import { codeList, conditions, findPage, insertRow, likePattern, orderBy, sqlDate, updateRow } from '../utils/sql.js';

export const HEADER_WRITABLE = [
  'po_number',
  'customer_id',
  'po_date',
  'expected_delivery_date',
  'status',
  'owner_user_id',
  'notes',
  'cancel_reason',
  'cancelled_at',
];
export const LINE_WRITABLE = ['purchase_order_id', 'line_no', 'product_id', 'order_quantity', 'unit', 'unit_price', 'notes'];

const select = (today) => `
  SELECT po.*, c.name AS customer_name, c.customer_code, u.name AS owner_name,
         s.line_count, s.ordered_quantity, s.delivered_quantity, s.in_progress_quantity, s.returned_quantity,
         s.outstanding_quantity, s.total_value, s.unpriced_line_count,
         s.unallocated_delivered_quantity, s.unallocated_returned_quantity,
         (po.status IN ${codeList(PO_OPEN_STATUSES)} AND s.outstanding_quantity > 0
          AND po.expected_delivery_date < ${sqlDate(today)}) AS is_late`;

const FROM = `
  FROM purchase_orders po
  JOIN customers c ON c.id = po.customer_id
  JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id
  LEFT JOIN users u ON u.id = po.owner_user_id`;

export async function list(filters, today) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(po.po_number ILIKE ${p} OR c.name ILIKE ${p})`);
  }
  if (filters.status?.length) w.add(`po.status = ANY(${w.param(filters.status)})`);
  if (filters.customer_id) w.add(`po.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.owner_user_id) w.add(`po.owner_user_id = ${w.param(filters.owner_user_id)}`);
  if (filters.po_date_from) w.add(`po.po_date >= ${w.param(filters.po_date_from)}`);
  if (filters.po_date_to) w.add(`po.po_date <= ${w.param(filters.po_date_to)}`);
  if (filters.expected_from) w.add(`po.expected_delivery_date >= ${w.param(filters.expected_from)}`);
  if (filters.expected_to) w.add(`po.expected_delivery_date <= ${w.param(filters.expected_to)}`);
  if (filters.has_outstanding === true) w.add('s.outstanding_quantity > 0');
  if (filters.has_outstanding === false) w.add('s.outstanding_quantity = 0');
  if (filters.late) {
    w.add(`po.status IN ${codeList(PO_OPEN_STATUSES)} AND s.outstanding_quantity > 0 AND po.expected_delivery_date < ${sqlDate(today)}`);
  }
  return findPage({
    select: select(today),
    from: FROM,
    countFrom: `FROM purchase_orders po JOIN customers c ON c.id = po.customer_id
                JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id`,
    where: w.sql(),
    params: w.params,
    order: orderBy(
      filters.sort,
      {
        po_date: 'po.po_date',
        po_number: 'po.po_number',
        customer_name: 'c.name',
        expected_delivery_date: 'po.expected_delivery_date',
        outstanding_quantity: 's.outstanding_quantity',
        total_value: 's.total_value',
        status: 'po.status',
        created_at: 'po.created_at',
      },
      'po.po_date DESC NULLS LAST, po.created_at DESC',
      'po.id',
    ),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findById(id, today, db) {
  const { rows } = await query(`${select(today)} ${FROM} WHERE po.id = $1`, [id], db);
  return rows[0] ?? null;
}

export async function findHeader(id, db) {
  const { rows } = await query('SELECT * FROM purchase_orders WHERE id = $1', [id], db);
  return rows[0] ?? null;
}

export async function lines(purchaseOrderId, db) {
  const { rows } = await query(
    `SELECT l.*, coalesce(l.unit, p.unit) AS unit, p.name AS product_name, p.product_code,
            v.delivered_quantity, v.in_progress_quantity, v.returned_quantity, v.outstanding_quantity,
            l.order_quantity * l.unit_price AS line_value
     FROM po_lines l
     LEFT JOIN products p ON p.id = l.product_id
     JOIN v_po_line_fulfillment v ON v.po_line_id = l.id
     WHERE l.purchase_order_id = $1
     ORDER BY l.line_no NULLS LAST, l.created_at`,
    [purchaseOrderId],
    db,
  );
  return rows;
}

export async function findLine(lineId, db) {
  const { rows } = await query(
    `SELECT l.*, v.outstanding_quantity, v.delivered_quantity
     FROM po_lines l JOIN v_po_line_fulfillment v ON v.po_line_id = l.id WHERE l.id = $1`,
    [lineId],
    db,
  );
  return rows[0] ?? null;
}

/** Deliveries and returns that reference a line (a referenced line cannot change product or be removed). */
export async function lineUsage(lineId, db) {
  const { rows } = await query(
    `SELECT (SELECT count(*) FROM deliveries WHERE po_line_id = $1) + (SELECT count(*) FROM returns WHERE po_line_id = $1) AS n`,
    [lineId],
    db,
  );
  return rows[0].n;
}

/** Transactions recorded against the PO (blocks changing its customer). */
export async function transactionCount(purchaseOrderId, db) {
  const { rows } = await query(
    `SELECT (SELECT count(*) FROM deliveries WHERE purchase_order_id = $1)
          + (SELECT count(*) FROM returns WHERE purchase_order_id = $1)
          + (SELECT count(*) FROM invoices_payments WHERE purchase_order_id = $1) AS n`,
    [purchaseOrderId],
    db,
  );
  return rows[0].n;
}

export async function activeDeliveryCount(purchaseOrderId, db) {
  const { rows } = await query(
    `SELECT count(*) AS n FROM deliveries WHERE purchase_order_id = $1 AND status IN ('SCHEDULED', 'ON_DELIVERY')`,
    [purchaseOrderId],
    db,
  );
  return rows[0].n;
}

export async function nextLineNo(purchaseOrderId, db) {
  const { rows } = await query(`SELECT coalesce(max(line_no), 0) + 1 AS n FROM po_lines WHERE purchase_order_id = $1`, [purchaseOrderId], db);
  return rows[0].n;
}

export function insertHeader(values, actorId, db) {
  return insertRow('purchase_orders', values, HEADER_WRITABLE, actorId, db);
}

export function updateHeader(id, values, actorId, db) {
  return updateRow('purchase_orders', id, values, HEADER_WRITABLE, actorId, db);
}

export function insertLine(values, actorId, db) {
  return insertRow('po_lines', values, LINE_WRITABLE, actorId, db);
}

export function updateLine(id, values, actorId, db) {
  return updateRow('po_lines', id, values, LINE_WRITABLE, actorId, db);
}

export async function deleteLine(id, db) {
  await query('DELETE FROM po_lines WHERE id = $1', [id], db);
}

export async function countLines(purchaseOrderId, db) {
  const { rows } = await query('SELECT count(*) AS n FROM po_lines WHERE purchase_order_id = $1', [purchaseOrderId], db);
  return rows[0].n;
}
