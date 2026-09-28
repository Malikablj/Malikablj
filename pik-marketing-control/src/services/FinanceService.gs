/**
 * Invoices and payments (finance module: Admin RW, Management R, other roles no access — D5 default).
 *
 * payment_status is always derived from the amounts (Calculations.gs: invoicePaymentStatus_) and never taken from the
 * client; outstanding and overdue are computed on read.
 */

const INVOICE_FIELDS = ['purchase_order_id', 'invoice_number', 'invoice_type', 'invoice_date', 'due_date', 'amount', 'paid_amount',
  'payment_date', 'payment_receipt_number', 'invoice_attachment_url', 'payment_attachment_url', 'notes'];

function invoiceForList_(invoice) {
  const today = todayIso_();
  return Object.assign(withCustomerViaPo_(withNames_(invoice)), {
    outstanding_amount: invoiceOutstanding_(invoice),
    is_overdue: invoiceIsOverdue_(invoice, today)
  });
}

function listInvoices_(input) {
  const records = loadTable_('INVOICES_PAYMENTS').records.map(invoiceForList_);
  return runListQuery_(records, input, {
    searchFields: ['invoice_number', 'po_label', 'customer_name', 'payment_receipt_number', 'notes', 'po_number_legacy'],
    filters: {
      payment_status: eqFilter_('payment_status'),
      invoice_type: eqFilter_('invoice_type'),
      purchase_order_id: eqFilter_('purchase_order_id'),
      customer_id: eqFilter_('customer_id'),
      overdue: function (record, value) { return record.is_overdue === (value === true || value === 'true'); },
      invoice_date: dateRangeFilter_('invoice_date'),
      due_date: dateRangeFilter_('due_date')
    },
    sortFields: ['invoice_date', 'invoice_number', 'due_date', 'amount', 'paid_amount', 'outstanding_amount', 'payment_status',
      'customer_name', 'updated_at'],
    defaultSort: { field: 'invoice_date', direction: 'desc' }
  });
}

function getInvoice_(input) {
  const id = requireId_(objectInput_(input).id, 'ID invoice');
  const invoice = findOrThrow_('INVOICES_PAYMENTS', id);
  const po = poOf_(invoice);
  return { invoice: invoiceForList_(invoice), purchaseOrder: po ? purchaseOrderWithSummary_(po) : null };
}

/** The invoice type follows the number when not chosen (PIK/<month>/<year>/<type>/<no>), and the status the amounts. */
function withDerivedInvoiceFields_(data, current) {
  const merged = Object.assign({}, current || {}, data);
  const derived = {};
  if (!merged.invoice_type && typeof merged.invoice_number === 'string') {
    const segment = (merged.invoice_number.split('/')[3] || '').trim().toUpperCase();
    if (segment === 'INV' || segment === 'TUM') derived.invoice_type = segment;
  }
  const amount = normalizeMoneyInput_(merged.amount);
  const paid = normalizeMoneyInput_(merged.paid_amount);
  if (typeof amount === 'number') derived.payment_status = invoicePaymentStatus_(amount, typeof paid === 'number' ? paid : 0);
  return Object.assign({}, data, derived);
}

function normalizeMoneyInput_(value) {
  const number = numericInput_(value);
  return typeof number === 'number' ? number : null;
}

function createInvoice_(input, user) {
  const data = withDerivedInvoiceFields_(Object.assign({ paid_amount: 0 },
    pickFields_(objectInput_(objectInput_(input).data, 'Data'), INVOICE_FIELDS)), null);
  return invoiceForList_(dbInsert_('INVOICES_PAYMENTS', [data], userContext_(user))[0]);
}

function updateInvoice_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  return withScriptLock_(function () {
    const current = findOrThrow_('INVOICES_PAYMENTS', id);
    const data = withDerivedInvoiceFields_(pickFields_(objectInput_(params.data, 'Data'), INVOICE_FIELDS), current);
    return invoiceForList_(dbUpdate_('INVOICES_PAYMENTS', id, data, userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
  });
}

/** Records the total paid so far (not an increment), the payment date and the receipt. */
function recordInvoicePayment_(input, user) {
  const params = objectInput_(input);
  return updateInvoice_({
    id: params.id, expectedUpdatedAt: params.expectedUpdatedAt,
    data: pickFields_(params, ['paid_amount', 'payment_date', 'payment_receipt_number', 'payment_attachment_url'])
  }, user);
}

function archiveInvoice_(input, user) {
  return invoiceForList_(setRecordActive_('INVOICES_PAYMENTS', requireId_(objectInput_(input).id), false, userContext_(user)));
}

function restoreInvoice_(input, user) {
  return invoiceForList_(setRecordActive_('INVOICES_PAYMENTS', requireId_(objectInput_(input).id), true, userContext_(user)));
}
