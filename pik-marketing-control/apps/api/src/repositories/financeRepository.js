/** Invoices/payments and PO financial summaries. */
import { query } from '../db/pool.js';
import { conditions, findPage, insertRow, likePattern, orderBy, sqlDate, updateRow } from '../utils/sql.js';

export const INVOICE_WRITABLE = [
  'purchase_order_id',
  'invoice_number',
  'invoice_date',
  'due_date',
  'amount',
  'paid_amount',
  'payment_status',
  'payment_date',
  'notes',
];

const invoiceSelect = (today) => `
  SELECT i.*, po.po_number, po.customer_id, c.name AS customer_name,
         i.amount - i.paid_amount AS balance,
         (i.payment_status IN ('UNPAID', 'PARTIAL') AND i.due_date < ${sqlDate(today)}) AS is_overdue`;
const INVOICE_FROM = `
  FROM invoices_payments i
  JOIN purchase_orders po ON po.id = i.purchase_order_id
  JOIN customers c ON c.id = po.customer_id`;

function invoiceWhere(filters, today) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(i.invoice_number ILIKE ${p} OR po.po_number ILIKE ${p} OR c.name ILIKE ${p})`);
  }
  if (filters.payment_status?.length) w.add(`i.payment_status = ANY(${w.param(filters.payment_status)})`);
  if (filters.customer_id) w.add(`po.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.purchase_order_id) w.add(`i.purchase_order_id = ${w.param(filters.purchase_order_id)}`);
  if (filters.overdue) w.add(`i.payment_status IN ('UNPAID', 'PARTIAL') AND i.due_date < ${sqlDate(today)}`);
  if (filters.from) w.add(`i.invoice_date >= ${w.param(filters.from)}`);
  if (filters.to) w.add(`i.invoice_date <= ${w.param(filters.to)}`);
  return w;
}

export async function listInvoices(filters, today) {
  const w = invoiceWhere(filters, today);
  return findPage({
    select: invoiceSelect(today),
    from: INVOICE_FROM,
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { invoice_date: 'i.invoice_date', due_date: 'i.due_date', amount: 'i.amount', customer_name: 'c.name', invoice_number: 'i.invoice_number' }, 'i.invoice_date DESC NULLS LAST', 'i.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

/** Totals for the same filters: invoiced, paid, open balance and overdue balance (cancelled excluded). */
export async function invoiceTotals(filters, today) {
  const w = invoiceWhere(filters, today);
  const where = w.sql() ? `${w.sql()} AND i.payment_status <> 'CANCELLED'` : `WHERE i.payment_status <> 'CANCELLED'`;
  const { rows } = await query(
    `SELECT coalesce(sum(i.amount), 0) AS amount,
            coalesce(sum(i.paid_amount), 0) AS paid_amount,
            coalesce(sum(i.amount - i.paid_amount) FILTER (WHERE i.payment_status IN ('UNPAID', 'PARTIAL')), 0) AS open_balance,
            coalesce(sum(i.amount - i.paid_amount) FILTER (WHERE i.payment_status IN ('UNPAID', 'PARTIAL') AND i.due_date < ${sqlDate(today)}), 0) AS overdue_balance
     ${INVOICE_FROM} ${where}`,
    w.params,
  );
  return rows[0];
}

export async function findInvoice(id, today, db) {
  const { rows } = await query(`${invoiceSelect(today)} ${INVOICE_FROM} WHERE i.id = $1`, [id], db);
  return rows[0] ?? null;
}

export async function invoicesForPo(purchaseOrderId, today) {
  const { rows } = await query(`${invoiceSelect(today)} ${INVOICE_FROM} WHERE i.purchase_order_id = $1 ORDER BY i.invoice_date`, [purchaseOrderId]);
  return rows;
}

export const insertInvoice = (values, actorId, db) => insertRow('invoices_payments', values, INVOICE_WRITABLE, actorId, db);
export const updateInvoice = (id, values, actorId, db) => updateRow('invoices_payments', id, values, INVOICE_WRITABLE, actorId, db);

// ------------------------------------------------------------- PO financials
export const PO_FINANCIAL_WRITABLE = [
  'purchase_order_id',
  'currency',
  'po_value',
  'tax_amount',
  'total_amount',
  'invoiced_amount',
  'paid_amount',
  'outstanding_amount',
  'notes',
];

const FINANCIAL_SELECT = `
  SELECT f.*, po.po_number, po.customer_id, c.name AS customer_name, s.total_value AS computed_po_value`;
const FINANCIAL_FROM = `
  FROM po_financials f
  JOIN purchase_orders po ON po.id = f.purchase_order_id
  JOIN customers c ON c.id = po.customer_id
  JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id`;

export async function listPoFinancials(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(po.po_number ILIKE ${p} OR c.name ILIKE ${p})`);
  }
  if (filters.customer_id) w.add(`po.customer_id = ${w.param(filters.customer_id)}`);
  if (filters.purchase_order_id) w.add(`f.purchase_order_id = ${w.param(filters.purchase_order_id)}`);
  return findPage({
    select: FINANCIAL_SELECT,
    from: FINANCIAL_FROM,
    where: w.sql(),
    params: w.params,
    order: orderBy(filters.sort, { po_number: 'po.po_number', customer_name: 'c.name', total_amount: 'f.total_amount' }, 'po.po_number ASC', 'f.id'),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function findPoFinancial(id, db) {
  const { rows } = await query(`${FINANCIAL_SELECT} ${FINANCIAL_FROM} WHERE f.id = $1`, [id], db);
  return rows[0] ?? null;
}

export async function poFinancialsForPo(purchaseOrderId) {
  const { rows } = await query(`${FINANCIAL_SELECT} ${FINANCIAL_FROM} WHERE f.purchase_order_id = $1`, [purchaseOrderId]);
  return rows;
}

export const insertPoFinancial = (values, actorId, db) => insertRow('po_financials', values, PO_FINANCIAL_WRITABLE, actorId, db);
export const updatePoFinancial = (id, values, actorId, db) => updateRow('po_financials', id, values, PO_FINANCIAL_WRITABLE, actorId, db);
