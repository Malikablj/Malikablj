/** Products, purchase orders, deliveries, returns, stock, lead time, maklon and finance through the API. */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { closePool } from '../../apps/api/src/db/pool.js';
import { addDays, businessToday } from '../../apps/api/src/utils/dates.js';
import { loginAllRoles } from '../helpers/api.js';
import { closeDb, sql, truncateAll } from '../helpers/db.js';

let as;
let customer;
let otherCustomer;
let bottle;
let jar;
const today = businessToday();

beforeAll(async () => {
  await truncateAll();
  as = await loginAllRoles();
  customer = (await as.admin.post('/api/customers', { name: 'PT Ops Satu' })).body.data;
  otherCustomer = (await as.admin.post('/api/customers', { name: 'PT Ops Dua' })).body.data;
  bottle = (await as.admin.post('/api/products', { name: 'Botol 100ml', product_code: 'BTL-100', unit: 'pcs' })).body.data;
  jar = (await as.admin.post('/api/products', { name: 'Jar 30g', product_code: 'JAR-30', unit: 'pcs' })).body.data;
});
afterAll(async () => {
  await closePool();
  await closeDb();
});

async function createPo(api = as.admin, body = {}) {
  const res = await api.post('/api/purchase-orders', {
    po_number: `PO-${Math.random().toString(36).slice(2, 8).toUpperCase()}`,
    customer_id: customer.id,
    po_date: today,
    expected_delivery_date: addDays(today, 14),
    lines: [
      { product_id: bottle.id, order_quantity: 1000, unit_price: 1500 },
      { product_id: jar.id, order_quantity: 500, unit_price: 2000 },
    ],
    ...body,
  });
  expect(res.status, JSON.stringify(res.body)).toBe(201);
  return (await as.admin.get(`/api/purchase-orders/${res.body.data.id}`)).body.data;
}

const deliver = (po, line, quantity, status = 'DELIVERED') =>
  as.admin.post('/api/deliveries', {
    purchase_order_id: po.id,
    po_line_id: line.id,
    delivery_date: today,
    quantity,
    status,
  });

describe('products', () => {
  it('creates, lists, archives and shows product detail with stock', async () => {
    expect(bottle).toMatchObject({ product_code: 'BTL-100', status: 'ACTIVE', is_active: true });
    await as.admin.post('/api/stock', { product_id: bottle.id, stock_type: 'FG', quantity: 5000, warehouse: 'Gudang A', stock_date: addDays(today, -7) });
    await as.admin.post('/api/stock', { product_id: bottle.id, stock_type: 'FG', quantity: 4200, warehouse: 'Gudang A', stock_date: today });
    await as.admin.post('/api/stock', { product_id: bottle.id, stock_type: 'WIP', quantity: 300, warehouse: 'Gudang A', stock_date: today });
    const detail = await as.viewer.get(`/api/products/${bottle.id}`);
    expect(detail.status).toBe(200);
    // Latest FG snapshot wins; history is kept.
    expect(detail.body.data).toMatchObject({ stock_fg: 4200, stock_wip: 300 });
    expect(detail.body.data.stock.map((s) => [s.stock_type, s.quantity])).toEqual([
      ['FG', 4200],
      ['WIP', 300],
    ]);
    const history = await as.viewer.get(`/api/stock/history?product_id=${bottle.id}`);
    expect(history.body.data).toHaveLength(3);
    const overview = await as.viewer.get('/api/stock/overview');
    expect(overview.body.data.totals).toEqual(expect.arrayContaining([expect.objectContaining({ stock_type: 'FG', unit: 'pcs', quantity: 4200 })]));

    const temp = (await as.admin.post('/api/products', { name: 'Produk Sementara' })).body.data;
    await as.admin.delete(`/api/products/${temp.id}`);
    const list = await as.viewer.get('/api/products?q=Sementara');
    expect(list.body.data).toHaveLength(0);
    const archived = await as.viewer.get('/api/products?q=Sementara&is_active=false');
    expect(archived.body.data.map((p) => p.id)).toEqual([temp.id]);
  });

  it('allows only Admin to write products and stock', async () => {
    for (const role of ['marketing', 'sales', 'management', 'viewer']) {
      expect((await as[role].post('/api/products', { name: 'X' })).status, role).toBe(403);
      expect((await as[role].post('/api/stock', { product_id: bottle.id, stock_type: 'FG', quantity: 1, stock_date: today })).status, role).toBe(403);
    }
  });
});

describe('purchase orders', () => {
  it('creates a PO with its lines atomically and computes totals', async () => {
    const po = await createPo();
    expect(po).toMatchObject({
      status: 'OPEN',
      customer_name: 'PT Ops Satu',
      owner_user_id: as.admin.user.id,
      line_count: 2,
      ordered_quantity: 1500,
      outstanding_quantity: 1500,
      total_value: 1000 * 1500 + 500 * 2000,
    });
    expect(po.lines.map((l) => [l.line_no, l.product_code, l.unit, l.outstanding_quantity])).toEqual([
      [1, 'BTL-100', 'pcs', 1000],
      [2, 'JAR-30', 'pcs', 500],
    ]);
  });

  it('rolls back the whole PO when a line is invalid', async () => {
    const before = (await sql('SELECT count(*)::int AS n FROM purchase_orders')).rows[0].n;
    const res = await as.admin.post('/api/purchase-orders', {
      po_number: 'PO-ATOMIC',
      customer_id: customer.id,
      lines: [
        { product_id: bottle.id, order_quantity: 10 },
        { product_id: '00000000-0000-4000-8000-000000000000', order_quantity: 5 },
      ],
    });
    expect(res.status).toBe(400);
    expect((await sql('SELECT count(*)::int AS n FROM purchase_orders')).rows[0].n).toBe(before);
  });

  it('validates quantities, dates, lines and duplicate numbers', async () => {
    const noLines = await as.admin.post('/api/purchase-orders', { po_number: 'PO-X', customer_id: customer.id, lines: [] });
    expect(noLines.body.error.details.fields.lines).toBe('Minimal satu item PO.');
    const zero = await as.admin.post('/api/purchase-orders', {
      po_number: 'PO-Y',
      customer_id: customer.id,
      lines: [{ product_id: bottle.id, order_quantity: 0 }],
    });
    expect(zero.body.error.details.fields['lines.0.order_quantity']).toBe('Qty order harus lebih dari 0.');
    const dates = await as.admin.post('/api/purchase-orders', {
      po_number: 'PO-Z',
      customer_id: customer.id,
      po_date: today,
      expected_delivery_date: addDays(today, -1),
      lines: [{ product_id: bottle.id, order_quantity: 1 }],
    });
    expect(dates.body.error.details.fields.expected_delivery_date).toBeTruthy();

    const po = await createPo();
    const duplicate = await as.admin.post('/api/purchase-orders', {
      po_number: po.po_number,
      customer_id: customer.id,
      lines: [{ product_id: bottle.id, order_quantity: 1 }],
    });
    expect(duplicate.status).toBe(409);
    expect(duplicate.body.error.message).toBe('Nomor PO ini sudah terdaftar untuk customer tersebut.');
    const otherCustomerSameNumber = await as.admin.post('/api/purchase-orders', {
      po_number: po.po_number,
      customer_id: otherCustomer.id,
      lines: [{ product_id: bottle.id, order_quantity: 1 }],
    });
    expect(otherCustomerSameNumber.status).toBe(201);
  });

  it('computes delivered, returned and outstanding from deliveries and returns', async () => {
    const po = await createPo();
    const [bottleLine, jarLine] = po.lines;
    expect((await deliver(po, bottleLine, 400)).status).toBe(201);
    expect((await deliver(po, bottleLine, 300)).status).toBe(201);
    expect((await deliver(po, bottleLine, 200, 'SCHEDULED')).status).toBe(201);
    const cancelled = await deliver(po, jarLine, 999, 'CANCELLED');
    expect(cancelled.status).toBe(201);
    const ret = await as.admin.post('/api/returns', {
      customer_id: customer.id,
      purchase_order_id: po.id,
      po_line_id: bottleLine.id,
      product_id: bottle.id,
      return_date: today,
      quantity: 50,
      reason: 'Cacat cetak',
      status: 'RECEIVED',
    });
    expect(ret.status).toBe(201);

    const detail = (await as.viewer.get(`/api/purchase-orders/${po.id}`)).body.data;
    const lines = Object.fromEntries(detail.lines.map((line) => [line.product_code, line]));
    expect(lines['BTL-100']).toMatchObject({ delivered_quantity: 700, in_progress_quantity: 200, returned_quantity: 50, outstanding_quantity: 350 });
    expect(lines['JAR-30']).toMatchObject({ delivered_quantity: 0, outstanding_quantity: 500 });
    expect(detail).toMatchObject({ delivered_quantity: 700, returned_quantity: 50, outstanding_quantity: 850 });
    expect(detail.deliveries).toHaveLength(4);
    expect(detail.returns).toHaveLength(1);

    const outstandingOnly = await as.viewer.get(`/api/purchase-orders?customer_id=${customer.id}&has_outstanding=true`);
    expect(outstandingOnly.body.data.map((row) => row.id)).toContain(po.id);
  });

  it('warns about over-delivery but records it; the outstanding never goes below zero', async () => {
    const po = await createPo();
    const res = await deliver(po, po.lines[1], 600);
    expect(res.status).toBe(201);
    expect(res.body.meta.warnings[0]).toContain('melebihi sisa outstanding');
    const detail = (await as.viewer.get(`/api/purchase-orders/${po.id}`)).body.data;
    expect(detail.lines[1].outstanding_quantity).toBe(0);
    expect(detail.outstanding_quantity).toBe(1000);
  });

  it('derives the delivered product from the PO line and rejects a line of another PO', async () => {
    const [po1, po2] = [await createPo(), await createPo()];
    const res = await deliver(po1, po1.lines[0], 10);
    expect(res.body.data).toMatchObject({ product_id: bottle.id, product_name: 'Botol 100ml', po_number: po1.po_number });
    const wrong = await as.admin.post('/api/deliveries', {
      purchase_order_id: po1.id,
      po_line_id: po2.lines[0].id,
      delivery_date: today,
      quantity: 1,
      status: 'DELIVERED',
    });
    expect(wrong.status).toBe(400);
    expect(wrong.body.error.message).toBe('Item PO tidak ditemukan pada PO ini.');
  });

  it('enforces status rules: cancel needs a reason, blocked while deliveries are in progress', async () => {
    const po = await createPo();
    expect((await as.admin.patch(`/api/purchase-orders/${po.id}/status`, { status: 'CANCELLED' })).status).toBe(422);
    await deliver(po, po.lines[0], 10, 'ON_DELIVERY');
    const blocked = await as.admin.patch(`/api/purchase-orders/${po.id}/status`, { status: 'CANCELLED', cancel_reason: 'Batal' });
    expect(blocked.status).toBe(422);

    const clean = await createPo();
    const cancelled = await as.admin.patch(`/api/purchase-orders/${clean.id}/status`, { status: 'CANCELLED', cancel_reason: 'Customer batal' });
    expect(cancelled.body.data).toMatchObject({ status: 'CANCELLED', cancel_reason: 'Customer batal' });
    expect(cancelled.body.data.cancelled_at).not.toBeNull();
    expect((await as.admin.put(`/api/purchase-orders/${clean.id}`, { notes: 'x' })).status).toBe(422);
    expect((await deliver(clean, clean.lines[0], 1)).status).toBe(422);
    const reopened = await as.admin.patch(`/api/purchase-orders/${clean.id}/status`, { status: 'OPEN' });
    expect(reopened.body.data).toMatchObject({ status: 'OPEN', cancel_reason: null, cancelled_at: null });
  });

  it('protects lines that have deliveries and keeps at least one line', async () => {
    const po = await createPo();
    await deliver(po, po.lines[0], 5);
    expect((await as.admin.delete(`/api/po-lines/${po.lines[0].id}`)).status).toBe(422);
    expect((await as.admin.put(`/api/po-lines/${po.lines[0].id}`, { product_id: jar.id })).status).toBe(422);
    const qty = await as.admin.put(`/api/po-lines/${po.lines[0].id}`, { order_quantity: 1200 });
    expect(qty.status).toBe(200);
    expect(qty.body.data.lines[0].outstanding_quantity).toBe(1195);

    const removed = await as.admin.delete(`/api/po-lines/${po.lines[1].id}`);
    expect(removed.body.data.lines).toHaveLength(1);
    expect((await as.admin.delete(`/api/po-lines/${po.lines[0].id}`)).status).toBe(422);

    const added = await as.admin.post(`/api/purchase-orders/${po.id}/lines`, { product_id: jar.id, order_quantity: 50 });
    expect(added.status).toBe(201);
    // Numbering continues from the highest remaining line (deleted lines never had transactions).
    expect(added.body.data.lines.map((line) => line.line_no)).toEqual([1, 2]);
  });

  it('lets Marketing write only their own POs; Sales, Management and Viewer are read-only', async () => {
    const own = await createPo(as.marketing);
    expect(own.owner_user_id).toBe(as.marketing.user.id);
    expect((await as.marketing.put(`/api/purchase-orders/${own.id}`, { notes: 'Milik saya' })).status).toBe(200);
    const foreign = await createPo(as.admin);
    expect((await as.marketing.put(`/api/purchase-orders/${foreign.id}`, { notes: 'Bukan milik saya' })).status).toBe(403);
    expect((await as.marketing.get(`/api/purchase-orders/${foreign.id}`)).status).toBe(200);
    const onBehalf = await as.marketing.post('/api/purchase-orders', {
      po_number: 'PO-ON-BEHALF',
      customer_id: customer.id,
      owner_user_id: as.admin.user.id,
      lines: [{ product_id: bottle.id, order_quantity: 1 }],
    });
    expect(onBehalf.status).toBe(403);
    for (const role of ['sales', 'management', 'viewer']) {
      expect((await as[role].put(`/api/purchase-orders/${own.id}`, { notes: 'x' })).status, role).toBe(403);
    }
    const detail = (await as.marketing.get(`/api/purchase-orders/${foreign.id}`)).body.data;
    expect(detail.can_edit).toBe(false);
  });

  it('blocks deliveries for non-admin roles', async () => {
    const po = await createPo();
    const res = await as.marketing.post('/api/deliveries', {
      purchase_order_id: po.id,
      po_line_id: po.lines[0].id,
      delivery_date: today,
      quantity: 1,
      status: 'DELIVERED',
    });
    expect(res.status).toBe(403);
  });
});

describe('returns', () => {
  it('requires the PO to belong to the customer and the product to match the line', async () => {
    const po = await createPo();
    const wrongCustomer = await as.admin.post('/api/returns', {
      customer_id: otherCustomer.id,
      purchase_order_id: po.id,
      product_id: bottle.id,
      return_date: today,
      quantity: 1,
      reason: 'x',
      status: 'REPORTED',
    });
    expect(wrongCustomer.status).toBe(400);
    expect(wrongCustomer.body.error.message).toBe('PO yang dipilih bukan milik customer ini.');
    const wrongProduct = await as.admin.post('/api/returns', {
      customer_id: customer.id,
      purchase_order_id: po.id,
      po_line_id: po.lines[0].id,
      product_id: jar.id,
      return_date: today,
      quantity: 1,
      reason: 'x',
      status: 'REPORTED',
    });
    expect(wrongProduct.status).toBe(400);
    const withoutPo = await as.admin.post('/api/returns', {
      customer_id: customer.id,
      product_id: jar.id,
      return_date: today,
      quantity: 3,
      reason: 'Retak',
      status: 'RECEIVED',
    });
    expect(withoutPo.status).toBe(201);
    expect(withoutPo.body.data).toMatchObject({ customer_name: 'PT Ops Satu', po_number: null, product_name: 'Jar 30g' });
  });

  it('only counts returns whose goods came back', async () => {
    const po = await createPo();
    await deliver(po, po.lines[0], 1000);
    const reported = await as.admin.post('/api/returns', {
      customer_id: customer.id,
      purchase_order_id: po.id,
      po_line_id: po.lines[0].id,
      product_id: bottle.id,
      return_date: today,
      quantity: 100,
      reason: 'Belum dikirim balik',
      status: 'REPORTED',
    });
    let line = (await as.admin.get(`/api/purchase-orders/${po.id}`)).body.data.lines[0];
    expect(line.outstanding_quantity).toBe(0);
    await as.admin.put(`/api/returns/${reported.body.data.id}`, { status: 'RECEIVED' });
    line = (await as.admin.get(`/api/purchase-orders/${po.id}`)).body.data.lines[0];
    expect(line.outstanding_quantity).toBe(100);
  });
});

describe('finance', () => {
  it('derives payment status and hides finance from Viewer', async () => {
    const po = await createPo();
    const invoice = await as.admin.post('/api/invoices', {
      purchase_order_id: po.id,
      invoice_number: 'INV-001',
      invoice_date: addDays(today, -40),
      due_date: addDays(today, -10),
      amount: 2500000,
      paid_amount: 1000000,
    });
    expect(invoice.status).toBe(201);
    expect(invoice.body.data).toMatchObject({ payment_status: 'PARTIAL', balance: 1500000, is_overdue: true });
    const paid = await as.admin.put(`/api/invoices/${invoice.body.data.id}`, { paid_amount: 2500000, payment_date: today });
    expect(paid.body.data).toMatchObject({ payment_status: 'PAID', is_overdue: false });

    const list = await as.management.get('/api/invoices');
    expect(list.status).toBe(200);
    expect(list.body.meta.totals).toMatchObject({ amount: 2500000, paid_amount: 2500000, open_balance: 0 });

    expect((await as.viewer.get('/api/invoices')).status).toBe(403);
    const poForViewer = (await as.viewer.get(`/api/purchase-orders/${po.id}`)).body.data;
    expect(poForViewer.invoices).toBeUndefined();
    const poForSales = (await as.sales.get(`/api/purchase-orders/${po.id}`)).body.data;
    expect(poForSales.invoices).toHaveLength(1);
    expect((await as.sales.post('/api/invoices', {})).status).toBe(403);
  });

  it('records PO financial summaries next to the computed PO value', async () => {
    const po = await createPo();
    const res = await as.admin.post('/api/po-financials', { purchase_order_id: po.id, po_value: 2500000, tax_amount: 275000, total_amount: 2775000 });
    expect(res.status).toBe(201);
    expect(res.body.data).toMatchObject({ currency: 'IDR', po_value: 2500000, computed_po_value: 2500000 });
  });
});

describe('lead time and inbound maklon', () => {
  it('stores lead times per product/customer and inbound maklon receipts', async () => {
    const leadTime = await as.admin.post('/api/lead-times', { product_id: bottle.id, customer_id: customer.id, lead_time_days: 21 });
    expect(leadTime.status).toBe(201);
    expect(leadTime.body.data).toMatchObject({ product_name: 'Botol 100ml', customer_name: 'PT Ops Satu', lead_time_days: 21 });
    const neither = await as.admin.post('/api/lead-times', { lead_time_days: 5 });
    expect(neither.status).toBe(400);

    const maklon = await as.admin.post('/api/inbound-maklon', {
      customer_id: customer.id,
      item_name: 'Resin titipan',
      inbound_date: today,
      quantity: 250,
      unit: 'kg',
      document_number: 'SJM-01',
    });
    expect(maklon.status).toBe(201);
    expect(maklon.body.data).toMatchObject({ product_name: 'Resin titipan', customer_name: 'PT Ops Satu' });
    expect((await as.viewer.get('/api/inbound-maklon')).body.data).toHaveLength(1);
    expect((await as.sales.post('/api/lead-times', { product_id: bottle.id, lead_time_days: 1 })).status).toBe(403);
  });
});
