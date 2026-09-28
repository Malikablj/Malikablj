import { accessLevel, canRead, canWriteRecord, checkPoTransition, MODULE } from '@pik/shared';
import { withTransaction } from '../db/pool.js';
import * as financeRepository from '../repositories/financeRepository.js';
import * as operationsRepository from '../repositories/operationsRepository.js';
import * as poRepository from '../repositories/purchaseOrderRepository.js';
import { businessToday } from '../utils/dates.js';
import { businessRule, forbidden, notFound } from '../utils/errors.js';
import { assertActiveCustomer, assertActiveOwner, assertProductExists } from './references.js';

export function list(filters) {
  return poRepository.list(filters, businessToday());
}

export async function get(id) {
  const po = await poRepository.findById(id, businessToday());
  if (!po) throw notFound('PO tidak ditemukan.');
  return po;
}

/** Full PO: header + totals, lines with fulfillment, deliveries, returns and (if permitted) finance. */
export async function getDetail(id, user) {
  const po = await get(id);
  const [lines, deliveries, returns] = await Promise.all([
    poRepository.lines(id),
    operationsRepository.listDeliveries({ purchase_order_id: id, page: 1, page_size: 100 }),
    operationsRepository.listReturns({ purchase_order_id: id, page: 1, page_size: 100 }),
  ]);
  const detail = {
    ...po,
    lines,
    deliveries: deliveries.rows,
    returns: returns.rows,
    can_edit: canWriteRecord(user, MODULE.PURCHASE_ORDERS, po),
  };
  if (canRead(user.role, MODULE.FINANCE)) {
    const [invoices, financials] = await Promise.all([
      financeRepository.invoicesForPo(id, businessToday()),
      financeRepository.poFinancialsForPo(id),
    ]);
    Object.assign(detail, { invoices, financials });
  }
  return detail;
}

async function requireWritable(id, user) {
  const po = await poRepository.findHeader(id);
  if (!po) throw notFound('PO tidak ditemukan.');
  if (!canWriteRecord(user, MODULE.PURCHASE_ORDERS, po)) {
    throw forbidden('Anda hanya dapat mengubah PO milik Anda sendiri.');
  }
  return po;
}

function assertEditable(po) {
  if (po.status === 'CANCELLED') throw businessRule('PO sudah dibatalkan dan tidak dapat diubah.');
}

async function lineValues(line, db) {
  const product = await assertProductExists(line.product_id, db);
  return { ...line, unit: line.unit ?? product.unit ?? null };
}

/** Creates the PO and its lines atomically. */
export async function create({ lines, ...header }, user) {
  const ownerId = header.owner_user_id === undefined ? user.id : header.owner_user_id;
  if (accessLevel(user.role, MODULE.PURCHASE_ORDERS) === 'OWN' && ownerId !== user.id) {
    throw forbidden('Anda hanya dapat membuat PO atas nama Anda sendiri.');
  }
  await assertActiveCustomer(header.customer_id);
  await assertActiveOwner(ownerId);
  const id = await withTransaction(async (db) => {
    const poId = await poRepository.insertHeader({ ...header, owner_user_id: ownerId }, user.id, db);
    for (const [index, line] of lines.entries()) {
      await poRepository.insertLine({ ...(await lineValues(line, db)), purchase_order_id: poId, line_no: index + 1 }, user.id, db);
    }
    return poId;
  });
  return get(id);
}

export async function update(id, values, user) {
  const po = await requireWritable(id, user);
  assertEditable(po);
  if (values.owner_user_id !== undefined && values.owner_user_id !== po.owner_user_id) {
    if (accessLevel(user.role, MODULE.PURCHASE_ORDERS) === 'OWN') throw forbidden('Anda tidak dapat mengalihkan PO ke PIC lain.');
    await assertActiveOwner(values.owner_user_id);
  }
  if (values.customer_id && values.customer_id !== po.customer_id) {
    if ((await poRepository.transactionCount(id)) > 0) {
      throw businessRule('Customer tidak dapat diganti karena PO sudah memiliki pengiriman, retur atau invoice.');
    }
    await assertActiveCustomer(values.customer_id);
  }
  await poRepository.updateHeader(id, values, user.id);
  return get(id);
}

export async function changeStatus(id, { status, cancel_reason: cancelReason }, user) {
  const po = await requireWritable(id, user);
  const check = checkPoTransition({ from: po.status, to: status, role: user.role, cancelReason });
  if (!check.ok) throw businessRule(check.reason);
  if (status === po.status) return get(id);
  const changes = { status };
  if (status === 'CANCELLED') {
    if ((await poRepository.activeDeliveryCount(id)) > 0) {
      throw businessRule('Masih ada pengiriman yang dijadwalkan atau dalam perjalanan. Batalkan pengiriman tersebut terlebih dahulu.');
    }
    Object.assign(changes, { cancel_reason: cancelReason.trim(), cancelled_at: new Date() });
  } else if (po.status === 'CANCELLED') {
    Object.assign(changes, { cancel_reason: null, cancelled_at: null });
  }
  await poRepository.updateHeader(id, changes, user.id);
  return get(id);
}

export async function addLine(purchaseOrderId, line, user) {
  const po = await requireWritable(purchaseOrderId, user);
  assertEditable(po);
  if (po.status === 'CLOSED') throw businessRule('PO sudah Closed. Ubah status PO terlebih dahulu untuk menambah item.');
  await withTransaction(async (db) => {
    const lineNo = await poRepository.nextLineNo(purchaseOrderId, db);
    await poRepository.insertLine({ ...(await lineValues(line, db)), purchase_order_id: purchaseOrderId, line_no: lineNo }, user.id, db);
  });
  return getDetail(purchaseOrderId, user);
}

async function requireLine(lineId, user) {
  const line = await poRepository.findLine(lineId);
  if (!line) throw notFound('Item PO tidak ditemukan.');
  const po = await requireWritable(line.purchase_order_id, user);
  assertEditable(po);
  return { line, po };
}

export async function updateLine(lineId, values, user) {
  const { line } = await requireLine(lineId, user);
  if (values.product_id && values.product_id !== line.product_id) {
    if ((await poRepository.lineUsage(lineId)) > 0) {
      throw businessRule('Produk tidak dapat diganti karena item ini sudah memiliki pengiriman atau retur.');
    }
    await assertProductExists(values.product_id);
  }
  await poRepository.updateLine(lineId, values, user.id);
  return getDetail(line.purchase_order_id, user);
}

export async function deleteLine(lineId, user) {
  const { line } = await requireLine(lineId, user);
  if ((await poRepository.lineUsage(lineId)) > 0) {
    throw businessRule('Item ini sudah memiliki pengiriman atau retur sehingga tidak dapat dihapus.');
  }
  if ((await poRepository.countLines(line.purchase_order_id)) <= 1) {
    throw businessRule('PO harus memiliki minimal satu item.');
  }
  await poRepository.deleteLine(lineId);
  return getDetail(line.purchase_order_id, user);
}
