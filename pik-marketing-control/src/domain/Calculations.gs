/**
 * Derived business values. They are computed from the transactions whenever they are read and never stored or typed
 * in, so they cannot drift from the data (Technical Specification §7):
 *
 *   PO line  delivered   = Σ quantity of its active deliveries with status DELIVERED, or without status (legacy
 *                          corrections, D9). SCHEDULED / ON_DELIVERY / DELAYED are counted separately as "scheduled";
 *                          CANCELLED and archived deliveries are not counted.
 *            returned    = Σ quantity of its active returns that are not CANCELLED
 *            outstanding = MAX(0, order_quantity − delivered + returned)
 *   PO       totals over its active lines; fulfillment NO_LINES / NOT_STARTED / PARTIAL / COMPLETE;
 *            deliveries linked to the PO but not to a line (legacy, D3) are reported separately, never guessed
 *   Follow-up due state for open statuses (PLANNED, RESCHEDULE): OVERDUE (date < today), TODAY, UPCOMING (D14)
 *   Invoice  payment status from amount and paid amount; overdue when the due date has passed and something is unpaid
 * "Today" is the application time zone's date (SETTINGS/Script Property TIMEZONE, default Asia/Jakarta).
 */

const OPEN_LEAD_STATUSES = ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTATION', 'NEGOTIATION'];
const CLOSED_PO_STATUSES = ['CLOSED', 'CANCELLED'];
const OPEN_FOLLOW_UP_STATUSES = ['PLANNED', 'RESCHEDULE'];
const PENDING_DELIVERY_STATUSES = ['SCHEDULED', 'ON_DELIVERY', 'DELAYED'];

function roundQty_(value) {
  return Math.round(value * 1000) / 1000;
}

function roundMoney_(value) {
  return Math.round(value * 100) / 100;
}

function isOpenLead_(lead) {
  return lead.is_active !== false && OPEN_LEAD_STATUSES.indexOf(lead.status) !== -1;
}

function isOpenPurchaseOrder_(po) {
  return po.is_active !== false && CLOSED_PO_STATUSES.indexOf(po.status) === -1;
}

function deliveryCountsAsDelivered_(delivery) {
  return delivery.is_active !== false && typeof delivery.quantity === 'number' &&
    (delivery.status === 'DELIVERED' || !delivery.status);
}

function deliveryIsPending_(delivery) {
  return delivery.is_active !== false && typeof delivery.quantity === 'number' &&
    PENDING_DELIVERY_STATUSES.indexOf(delivery.status) !== -1;
}

function returnCounts_(item) {
  return item.is_active !== false && typeof item.quantity === 'number' && item.status !== 'CANCELLED';
}

/** Sums of deliveries/returns per PO line and per PO, computed once per execution. */
function progressIndex_() {
  if (DB_CACHE_.progress) return DB_CACHE_.progress;
  const index = { delivered: {}, scheduled: {}, returned: {}, unlinkedDelivered: {}, unlinkedReturned: {} };
  const add = function (map, key, quantity) { map[key] = (map[key] || 0) + quantity; };
  loadTable_('DELIVERIES').records.forEach(function (delivery) {
    if (deliveryCountsAsDelivered_(delivery)) {
      if (delivery.po_line_id) add(index.delivered, delivery.po_line_id, delivery.quantity);
      else if (delivery.purchase_order_id) add(index.unlinkedDelivered, delivery.purchase_order_id, delivery.quantity);
    } else if (deliveryIsPending_(delivery) && delivery.po_line_id) {
      add(index.scheduled, delivery.po_line_id, delivery.quantity);
    }
  });
  loadTable_('RETURNS').records.forEach(function (item) {
    if (!returnCounts_(item)) return;
    if (item.po_line_id) add(index.returned, item.po_line_id, item.quantity);
    else if (item.purchase_order_id) add(index.unlinkedReturned, item.purchase_order_id, item.quantity);
  });
  DB_CACHE_.progress = index;
  return index;
}

/** Delivered / returned / outstanding / scheduled quantities of one PO line. */
function lineProgress_(line) {
  const index = progressIndex_();
  const order = typeof line.order_quantity === 'number' ? line.order_quantity : 0;
  const delivered = roundQty_(index.delivered[line.id] || 0);
  const returned = roundQty_(index.returned[line.id] || 0);
  return {
    order_quantity: order,
    delivered_quantity: delivered,
    returned_quantity: returned,
    outstanding_quantity: Math.max(0, roundQty_(order - delivered + returned)),
    scheduled_quantity: roundQty_(index.scheduled[line.id] || 0),
    over_delivered_quantity: Math.max(0, roundQty_(delivered - returned - order))
  };
}

/** PO line with its progress and display names. */
function lineWithProgress_(line) {
  return Object.assign(withNames_(line), lineProgress_(line), {
    line_value: typeof line.unit_price === 'number' && typeof line.order_quantity === 'number'
      ? roundMoney_(line.unit_price * line.order_quantity) : null
  });
}

/** Totals per PO id over its active lines, computed once per execution. */
function purchaseOrderSummaries_() {
  if (DB_CACHE_.poSummaries) return DB_CACHE_.poSummaries;
  const index = progressIndex_();
  const summaries = {};
  loadTable_('PO_LINES').records.forEach(function (line) {
    if (line.is_active === false || !line.purchase_order_id) return;
    const summary = summaries[line.purchase_order_id] || (summaries[line.purchase_order_id] = emptyPoSummary_());
    const progress = lineProgress_(line);
    summary.line_count++;
    summary.order_quantity += progress.order_quantity;
    summary.delivered_quantity += progress.delivered_quantity;
    summary.returned_quantity += progress.returned_quantity;
    summary.outstanding_quantity += progress.outstanding_quantity;
    summary.scheduled_quantity += progress.scheduled_quantity;
    if (typeof line.unit_price === 'number' && typeof line.order_quantity === 'number') {
      summary.order_value += line.unit_price * line.order_quantity;
      summary.priced_lines++;
    }
  });
  Object.keys(index.unlinkedDelivered).concat(Object.keys(index.unlinkedReturned)).forEach(function (poId) {
    if (!summaries[poId]) summaries[poId] = emptyPoSummary_();
  });
  Object.keys(summaries).forEach(function (poId) {
    const summary = summaries[poId];
    ['order_quantity', 'delivered_quantity', 'returned_quantity', 'outstanding_quantity', 'scheduled_quantity'].forEach(function (key) {
      summary[key] = roundQty_(summary[key]);
    });
    summary.order_value = summary.priced_lines > 0 ? roundMoney_(summary.order_value) : null;
    summary.unlinked_delivered_quantity = roundQty_(index.unlinkedDelivered[poId] || 0);
    summary.unlinked_returned_quantity = roundQty_(index.unlinkedReturned[poId] || 0);
    summary.fulfillment = summary.line_count === 0 ? 'NO_LINES'
      : summary.outstanding_quantity === 0 ? 'COMPLETE'
        : summary.delivered_quantity > 0 ? 'PARTIAL' : 'NOT_STARTED';
  });
  DB_CACHE_.poSummaries = summaries;
  return summaries;
}

function emptyPoSummary_() {
  return {
    line_count: 0, order_quantity: 0, delivered_quantity: 0, returned_quantity: 0, outstanding_quantity: 0,
    scheduled_quantity: 0, order_value: 0, priced_lines: 0, unlinked_delivered_quantity: 0, unlinked_returned_quantity: 0,
    fulfillment: 'NO_LINES'
  };
}

function purchaseOrderSummary_(poId) {
  return purchaseOrderSummaries_()[poId] || Object.assign(emptyPoSummary_(), { order_value: null });
}

/** OVERDUE / TODAY / UPCOMING for open follow-ups; DONE / CANCELLED otherwise (D14: OVERDUE is never stored). */
function followUpDueState_(followUp, today) {
  if (followUp.status === 'DONE') return 'DONE';
  if (followUp.status === 'CANCELLED') return 'CANCELLED';
  if (!followUp.follow_up_date) return 'UPCOMING';
  if (followUp.follow_up_date < today) return 'OVERDUE';
  if (followUp.follow_up_date === today) return 'TODAY';
  return 'UPCOMING';
}

function isOpenFollowUp_(followUp) {
  return followUp.is_active !== false && OPEN_FOLLOW_UP_STATUSES.indexOf(followUp.status) !== -1;
}

/** UNPAID / PARTIAL / PAID from the amounts (the stored payment_status always follows this rule). */
function invoicePaymentStatus_(amount, paid) {
  const total = typeof amount === 'number' ? amount : 0;
  const received = typeof paid === 'number' ? paid : 0;
  if (received >= total) return 'PAID';
  return received > 0 ? 'PARTIAL' : 'UNPAID';
}

function invoiceOutstanding_(invoice) {
  const total = typeof invoice.amount === 'number' ? invoice.amount : 0;
  const received = typeof invoice.paid_amount === 'number' ? invoice.paid_amount : 0;
  return Math.max(0, roundMoney_(total - received));
}

function invoiceIsOverdue_(invoice, today) {
  return invoice.is_active !== false && Boolean(invoice.due_date) && invoice.due_date < today && invoiceOutstanding_(invoice) > 0;
}
