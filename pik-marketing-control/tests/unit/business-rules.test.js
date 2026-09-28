/**
 * Business rules: the SQL functions/views in database/views.sql (the single implementation
 * of outstanding quantity and follow-up state) and the shared workflow rules.
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import {
  accessLevel,
  canRead,
  canWrite,
  canWriteRecord,
  checkLeadTransition,
  checkPoTransition,
  derivePaymentStatus,
} from '@pik/shared';
import { closeDb, sql, truncateAll } from '../helpers/db.js';
import {
  insertCustomer,
  insertDelivery,
  insertProduct,
  insertPurchaseOrder,
  insertReturn,
} from '../helpers/factories.js';

beforeAll(truncateAll);
afterAll(closeDb);

async function followUpState(date, status, today) {
  const { rows } = await sql('SELECT follow_up_state($1::date, $2, $3::date) AS state', [date, status, today]);
  return rows[0].state;
}

async function outstanding(ordered, delivered, returned) {
  const { rows } = await sql('SELECT outstanding_quantity($1, $2, $3) AS value', [ordered, delivered, returned]);
  return rows[0].value;
}

describe('follow_up_state()', () => {
  const today = '2026-09-28';

  it('is OVERDUE when the date is before today and the follow-up is still open', async () => {
    expect(await followUpState('2026-09-27', 'PLANNED', today)).toBe('OVERDUE');
    expect(await followUpState('2026-01-01', 'RESCHEDULE', today)).toBe('OVERDUE');
    expect(await followUpState('2026-09-20', 'OVERDUE', today)).toBe('OVERDUE');
  });

  it('is TODAY when the date is today and the follow-up is still open', async () => {
    expect(await followUpState(today, 'PLANNED', today)).toBe('TODAY');
    expect(await followUpState(today, 'RESCHEDULE', today)).toBe('TODAY');
  });

  it('is UPCOMING for future open follow-ups, even when a legacy status says OVERDUE', async () => {
    expect(await followUpState('2026-09-29', 'PLANNED', today)).toBe('UPCOMING');
    expect(await followUpState('2026-10-15', 'OVERDUE', today)).toBe('UPCOMING');
  });

  it('never reports DONE or CANCELLED follow-ups as overdue or today', async () => {
    expect(await followUpState('2026-09-01', 'DONE', today)).toBe('DONE');
    expect(await followUpState(today, 'DONE', today)).toBe('DONE');
    expect(await followUpState('2026-09-01', 'CANCELLED', today)).toBe('CANCELLED');
  });
});

describe('outstanding_quantity()', () => {
  it('is order - delivered + returned', async () => {
    expect(await outstanding(100, 40, 0)).toBe(60);
    expect(await outstanding(100, 100, 10)).toBe(10);
    expect(await outstanding(1000.5, 0.5, 0)).toBe(1000);
  });

  it('never goes below zero (over-delivery)', async () => {
    expect(await outstanding(100, 120, 0)).toBe(0);
    expect(await outstanding(100, 130, 20)).toBe(0);
  });

  it('treats missing delivered/returned as zero', async () => {
    expect(await outstanding(50, null, null)).toBe(50);
  });
});

describe('PO fulfillment views', () => {
  it('counts only DELIVERED deliveries and RECEIVED/RESOLVED returns, per line', async () => {
    const customer = await insertCustomer();
    const [a, b] = [await insertProduct(), await insertProduct()];
    const po = await insertPurchaseOrder(customer.id, {
      lines: [
        { product_id: a.id, order_quantity: 1000, unit_price: 1500 },
        { product_id: b.id, order_quantity: 500, unit_price: 2000 },
      ],
    });
    const [lineA, lineB] = po.lines;

    await insertDelivery(po, lineA, { quantity: 400, status: 'DELIVERED' });
    await insertDelivery(po, lineA, { quantity: 300, status: 'DELIVERED' });
    await insertDelivery(po, lineA, { quantity: 200, status: 'SCHEDULED' }); // not yet delivered
    await insertDelivery(po, lineA, { quantity: 999, status: 'CANCELLED' }); // never counts
    await insertReturn(po, lineA, { quantity: 50, status: 'RECEIVED' });
    await insertReturn(po, lineA, { quantity: 70, status: 'REPORTED' }); // goods not back yet
    await insertDelivery(po, lineB, { quantity: 600, status: 'DELIVERED' }); // over-delivered

    const lines = await sql(
      `SELECT po_line_id, delivered_quantity, in_progress_quantity, returned_quantity, outstanding_quantity
       FROM v_po_line_fulfillment WHERE purchase_order_id = $1`,
      [po.id],
    );
    const byLine = Object.fromEntries(lines.rows.map((row) => [row.po_line_id, row]));
    expect(byLine[lineA.id]).toMatchObject({
      delivered_quantity: 700,
      in_progress_quantity: 200,
      returned_quantity: 50,
      outstanding_quantity: 350, // 1000 - 700 + 50
    });
    expect(byLine[lineB.id]).toMatchObject({ delivered_quantity: 600, outstanding_quantity: 0 });

    const summary = await sql('SELECT * FROM v_purchase_order_summary WHERE purchase_order_id = $1', [po.id]);
    expect(summary.rows[0]).toMatchObject({
      line_count: 2,
      ordered_quantity: 1500,
      delivered_quantity: 1300,
      returned_quantity: 50,
      // Sum of line outstanding: line B's over-delivery must not hide line A's shortage.
      outstanding_quantity: 350,
      total_value: 1000 * 1500 + 500 * 2000,
      unallocated_delivered_quantity: 0,
    });
  });

  it('reports deliveries without a PO line separately instead of guessing the line', async () => {
    const customer = await insertCustomer();
    const product = await insertProduct();
    const po = await insertPurchaseOrder(customer.id, { lines: [{ product_id: product.id, order_quantity: 100 }] });
    await insertDelivery(po, po.lines[0], { quantity: 30, lineless: true });

    const summary = await sql('SELECT * FROM v_purchase_order_summary WHERE purchase_order_id = $1', [po.id]);
    expect(summary.rows[0]).toMatchObject({
      outstanding_quantity: 100,
      unallocated_delivered_quantity: 30,
    });
  });

  it('returns a summary row with zero totals for a PO without lines', async () => {
    const customer = await insertCustomer();
    const po = await insertPurchaseOrder(customer.id);
    const summary = await sql('SELECT * FROM v_purchase_order_summary WHERE purchase_order_id = $1', [po.id]);
    expect(summary.rows[0]).toMatchObject({ line_count: 0, ordered_quantity: 0, outstanding_quantity: 0, total_value: null });
  });
});

describe('lead status transitions', () => {
  it('allows moving between pipeline stages in any direction', () => {
    expect(checkLeadTransition({ from: 'NEW', to: 'QUOTATION', role: 'SALES' })).toEqual({ ok: true });
    expect(checkLeadTransition({ from: 'NEGOTIATION', to: 'QUALIFIED', role: 'SALES' })).toEqual({ ok: true });
    expect(checkLeadTransition({ from: 'DORMANT', to: 'CONTACTED', role: 'MARKETING' })).toEqual({ ok: true });
    expect(checkLeadTransition({ from: 'LOST', to: 'NEW', role: 'SALES' })).toEqual({ ok: true });
  });

  it('requires a reason to mark a lead LOST', () => {
    expect(checkLeadTransition({ from: 'QUOTATION', to: 'LOST', role: 'SALES' }).ok).toBe(false);
    expect(checkLeadTransition({ from: 'QUOTATION', to: 'LOST', role: 'SALES', lostReason: '  ' }).ok).toBe(false);
    expect(checkLeadTransition({ from: 'QUOTATION', to: 'LOST', role: 'SALES', lostReason: 'Harga' }).ok).toBe(true);
  });

  it('only lets an Admin reopen a WON lead', () => {
    expect(checkLeadTransition({ from: 'WON', to: 'NEGOTIATION', role: 'SALES' }).ok).toBe(false);
    expect(checkLeadTransition({ from: 'WON', to: 'NEGOTIATION', role: 'ADMIN' }).ok).toBe(true);
  });

  it('rejects unknown statuses', () => {
    expect(checkLeadTransition({ from: 'NEW', to: 'CLOSED', role: 'ADMIN' }).ok).toBe(false);
  });
});

describe('purchase order status transitions', () => {
  it('moves freely between active statuses', () => {
    expect(checkPoTransition({ from: 'OPEN', to: 'ON_PROCESS', role: 'MARKETING' }).ok).toBe(true);
    expect(checkPoTransition({ from: 'PARTIAL', to: 'CLOSED', role: 'MARKETING' }).ok).toBe(true);
    expect(checkPoTransition({ from: 'CLOSED', to: 'PARTIAL', role: 'ADMIN' }).ok).toBe(true);
  });

  it('requires a reason to cancel, and only an Admin can reopen a cancelled PO', () => {
    expect(checkPoTransition({ from: 'OPEN', to: 'CANCELLED', role: 'ADMIN' }).ok).toBe(false);
    expect(checkPoTransition({ from: 'OPEN', to: 'CANCELLED', role: 'ADMIN', cancelReason: 'Batal customer' }).ok).toBe(true);
    expect(checkPoTransition({ from: 'CANCELLED', to: 'OPEN', role: 'MARKETING' }).ok).toBe(false);
    expect(checkPoTransition({ from: 'CANCELLED', to: 'CLOSED', role: 'ADMIN' }).ok).toBe(false);
    expect(checkPoTransition({ from: 'CANCELLED', to: 'OPEN', role: 'ADMIN' }).ok).toBe(true);
  });
});

describe('payment status', () => {
  it('derives status from amount and paid amount', () => {
    expect(derivePaymentStatus({ amount: 1000, paidAmount: 0 })).toBe('UNPAID');
    expect(derivePaymentStatus({ amount: 1000, paidAmount: 400 })).toBe('PARTIAL');
    expect(derivePaymentStatus({ amount: 1000, paidAmount: 1000 })).toBe('PAID');
    expect(derivePaymentStatus({ amount: 1000, paidAmount: 400, cancelled: true })).toBe('CANCELLED');
  });
});

describe('authorization matrix', () => {
  it('matches the specification for CRM, PO and admin modules', () => {
    expect(accessLevel('SALES', 'CUSTOMERS')).toBe('RW');
    expect(accessLevel('MANAGEMENT', 'LEADS')).toBe('R');
    expect(accessLevel('MARKETING', 'PURCHASE_ORDERS')).toBe('OWN');
    expect(accessLevel('SALES', 'PURCHASE_ORDERS')).toBe('R');
    expect(canWrite('MARKETING', 'DELIVERIES')).toBe(false);
    expect(canWrite('ADMIN', 'DELIVERIES')).toBe(true);
    expect(canRead('VIEWER', 'USERS')).toBe(false);
    expect(canRead('VIEWER', 'FINANCE')).toBe(false);
    expect(canRead('VIEWER', 'CUSTOMERS')).toBe(true);
  });

  it('limits OWN access to records the user owns', () => {
    const marketing = { id: 'u1', role: 'MARKETING' };
    expect(canWriteRecord(marketing, 'PURCHASE_ORDERS', { owner_user_id: 'u1' })).toBe(true);
    expect(canWriteRecord(marketing, 'PURCHASE_ORDERS', { owner_user_id: 'u2' })).toBe(false);
    expect(canWriteRecord({ id: 'a', role: 'ADMIN' }, 'PURCHASE_ORDERS', { owner_user_id: 'u2' })).toBe(true);
    expect(canWriteRecord({ id: 's', role: 'SALES' }, 'PURCHASE_ORDERS', { owner_user_id: 's' })).toBe(false);
  });
});
