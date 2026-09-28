/** Deliveries, returns, stock, lead time and inbound maklon. */
import * as ops from '../repositories/operationsRepository.js';
import * as poRepository from '../repositories/purchaseOrderRepository.js';
import { businessRule, notFound, validationError } from '../utils/errors.js';
import { assertCustomerExists, assertProductExists } from './references.js';

const numberFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 });

async function requirePo(purchaseOrderId) {
  const po = await poRepository.findHeader(purchaseOrderId);
  if (!po) throw validationError('PO tidak ditemukan.', { fields: { purchase_order_id: 'PO tidak ditemukan.' } });
  return po;
}

async function requireLineOfPo(lineId, purchaseOrderId) {
  const line = await poRepository.findLine(lineId);
  if (!line || line.purchase_order_id !== purchaseOrderId) {
    throw validationError('Item PO tidak ditemukan pada PO ini.', { fields: { po_line_id: 'Item PO tidak ditemukan pada PO ini.' } });
  }
  return line;
}

// ------------------------------------------------------------------ deliveries
export const listDeliveries = (filters) => ops.listDeliveries(filters);

export async function getDelivery(id) {
  const delivery = await ops.findDelivery(id);
  if (!delivery) throw notFound('Pengiriman tidak ditemukan.');
  return delivery;
}

/**
 * The product always comes from the PO line, so a delivery can never contradict its line.
 * Over-delivery is allowed (tolerances exist) but returned as a warning.
 */
async function deliveryValues(values, existing) {
  const purchaseOrderId = values.purchase_order_id ?? existing?.purchase_order_id;
  const lineId = values.po_line_id ?? existing?.po_line_id;
  const po = await requirePo(purchaseOrderId);
  if (po.status === 'CANCELLED') throw businessRule('PO sudah dibatalkan; tidak dapat mencatat pengiriman.');
  const line = await requireLineOfPo(lineId, purchaseOrderId);
  const changes = { ...values, product_id: line.product_id, item_name: line.product_id ? null : line.item_name };
  const warnings = [];
  const status = values.status ?? existing?.status;
  const quantity = values.quantity ?? existing?.quantity;
  if (status === 'DELIVERED') {
    const alreadyCounted = existing?.status === 'DELIVERED' && existing.po_line_id === lineId ? existing.quantity : 0;
    const available = line.outstanding_quantity + alreadyCounted;
    if (quantity > available) {
      warnings.push(`Qty kirim (${numberFormat.format(quantity)}) melebihi sisa outstanding item (${numberFormat.format(available)}).`);
    }
  }
  return { changes, warnings };
}

export async function createDelivery(values, user) {
  const { changes, warnings } = await deliveryValues(values, null);
  const id = await ops.insertDelivery(changes, user.id);
  return { delivery: await getDelivery(id), warnings };
}

export async function updateDelivery(id, values, user) {
  const existing = await getDelivery(id);
  const { changes, warnings } = await deliveryValues(values, existing);
  await ops.updateDelivery(id, changes, user.id);
  return { delivery: await getDelivery(id), warnings };
}

// --------------------------------------------------------------------- returns
export const listReturns = (filters) => ops.listReturns(filters);

export async function getReturn(id) {
  const record = await ops.findReturn(id);
  if (!record) throw notFound('Retur tidak ditemukan.');
  return record;
}

/** A return's PO must belong to its customer, and its line (if any) to that PO and product. */
async function returnValues(values, existing) {
  const merged = { ...existing, ...values };
  await assertCustomerExists(merged.customer_id);
  if (merged.purchase_order_id) {
    const po = await requirePo(merged.purchase_order_id);
    if (po.customer_id !== merged.customer_id) {
      throw validationError('PO yang dipilih bukan milik customer ini.', { fields: { purchase_order_id: 'PO bukan milik customer ini.' } });
    }
  }
  if (merged.po_line_id) {
    const line = await requireLineOfPo(merged.po_line_id, merged.purchase_order_id);
    if (merged.product_id && line.product_id && merged.product_id !== line.product_id) {
      throw validationError('Produk tidak sesuai dengan item PO.', { fields: { product_id: 'Produk tidak sesuai dengan item PO.' } });
    }
  }
  if (values.product_id) await assertProductExists(values.product_id);
  return values.product_id ? { ...values, item_name: null } : values;
}

export async function createReturn(values, user) {
  const id = await ops.insertReturn(await returnValues(values, null), user.id);
  return getReturn(id);
}

export async function updateReturn(id, values, user) {
  const existing = await getReturn(id);
  await ops.updateReturn(id, await returnValues(values, existing), user.id);
  return getReturn(id);
}

// ----------------------------------------------------------------------- stock
export const listCurrentStock = (filters) => ops.listCurrentStock(filters);
export const listStockHistory = (filters) => ops.listStockHistory(filters);

export async function stockOverview() {
  const [totals, warehouses] = await Promise.all([ops.stockTotals(), ops.warehouses()]);
  return { totals, warehouses };
}

export async function getStock(id) {
  const record = await ops.findStock(id);
  if (!record) throw notFound('Data stok tidak ditemukan.');
  return record;
}

export async function createStock(values, user) {
  await assertProductExists(values.product_id);
  const id = await ops.insertStock(values, user.id);
  return getStock(id);
}

export async function updateStock(id, values, user) {
  await getStock(id);
  if (values.product_id) await assertProductExists(values.product_id);
  await ops.updateStock(id, values, user.id);
  return getStock(id);
}

// ------------------------------------------------------------------- lead time
export const listLeadTimes = (filters) => ops.listLeadTimes(filters);

export async function getLeadTime(id) {
  const record = await ops.findLeadTime(id);
  if (!record) throw notFound('Lead time tidak ditemukan.');
  return record;
}

export async function createLeadTime(values, user) {
  if (values.product_id) await assertProductExists(values.product_id);
  const id = await ops.insertLeadTime(values, user.id);
  return getLeadTime(id);
}

export async function updateLeadTime(id, values, user) {
  await getLeadTime(id);
  if (values.product_id) await assertProductExists(values.product_id);
  await ops.updateLeadTime(id, values, user.id);
  return getLeadTime(id);
}

// -------------------------------------------------------------- inbound maklon
export const listInboundMaklon = (filters) => ops.listInboundMaklon(filters);

export async function getInboundMaklon(id) {
  const record = await ops.findInboundMaklon(id);
  if (!record) throw notFound('Data maklon masuk tidak ditemukan.');
  return record;
}

export async function createInboundMaklon(values, user) {
  if (values.product_id) await assertProductExists(values.product_id);
  if (values.purchase_order_id) await requirePo(values.purchase_order_id);
  const id = await ops.insertInboundMaklon(values, user.id);
  return getInboundMaklon(id);
}

export async function updateInboundMaklon(id, values, user) {
  await getInboundMaklon(id);
  if (values.product_id) await assertProductExists(values.product_id);
  if (values.purchase_order_id) await requirePo(values.purchase_order_id);
  await ops.updateInboundMaklon(id, values, user.id);
  return getInboundMaklon(id);
}
