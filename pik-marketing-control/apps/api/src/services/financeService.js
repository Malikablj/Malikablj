import { derivePaymentStatus } from '@pik/shared';
import * as financeRepository from '../repositories/financeRepository.js';
import * as poRepository from '../repositories/purchaseOrderRepository.js';
import { businessToday } from '../utils/dates.js';
import { notFound, validationError } from '../utils/errors.js';

async function requirePo(purchaseOrderId) {
  if (!(await poRepository.findHeader(purchaseOrderId))) {
    throw validationError('PO tidak ditemukan.', { fields: { purchase_order_id: 'PO tidak ditemukan.' } });
  }
}

export async function listInvoices(filters) {
  const today = businessToday();
  const [page, totals] = await Promise.all([
    financeRepository.listInvoices(filters, today),
    financeRepository.invoiceTotals(filters, today),
  ]);
  return { rows: page.rows, meta: { ...page.meta, totals } };
}

export async function getInvoice(id) {
  const invoice = await financeRepository.findInvoice(id, businessToday());
  if (!invoice) throw notFound('Invoice tidak ditemukan.');
  return invoice;
}

/** payment_status is derived from the amounts (CANCELLED is an explicit choice). */
function withPaymentStatus(values, existing) {
  const { is_cancelled: cancelledInput, ...rest } = values;
  const amount = values.amount ?? existing?.amount ?? 0;
  const paidAmount = values.paid_amount ?? existing?.paid_amount ?? 0;
  const cancelled = cancelledInput ?? existing?.payment_status === 'CANCELLED';
  return { ...rest, paid_amount: paidAmount, payment_status: derivePaymentStatus({ amount, paidAmount, cancelled }) };
}

export async function createInvoice(values, user) {
  await requirePo(values.purchase_order_id);
  const id = await financeRepository.insertInvoice(withPaymentStatus(values, null), user.id);
  return getInvoice(id);
}

export async function updateInvoice(id, values, user) {
  const existing = await getInvoice(id);
  if (values.purchase_order_id) await requirePo(values.purchase_order_id);
  await financeRepository.updateInvoice(id, withPaymentStatus(values, existing), user.id);
  return getInvoice(id);
}

export const listPoFinancials = (filters) => financeRepository.listPoFinancials(filters);

export async function getPoFinancial(id) {
  const record = await financeRepository.findPoFinancial(id);
  if (!record) throw notFound('Ringkasan keuangan PO tidak ditemukan.');
  return record;
}

export async function createPoFinancial(values, user) {
  await requirePo(values.purchase_order_id);
  const id = await financeRepository.insertPoFinancial(values, user.id);
  return getPoFinancial(id);
}

export async function updatePoFinancial(id, values, user) {
  await getPoFinancial(id);
  if (values.purchase_order_id) await requirePo(values.purchase_order_id);
  await financeRepository.updatePoFinancial(id, values, user.id);
  return getPoFinancial(id);
}
