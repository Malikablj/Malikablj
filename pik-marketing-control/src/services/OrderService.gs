/**
 * Purchase orders, PO lines, deliveries, returns and inbound (maklon).
 *
 * Quantities delivered, returned and outstanding are derived from the transactions (Calculations.gs), never typed in.
 * New deliveries always reference a PO line, so the PO and the product come from the line (they cannot disagree).
 * Returns reference a PO line when there is one, otherwise a product (and optionally a PO). A quantity above what the
 * line still allows is not silently accepted: the server answers OVER_QUANTITY and the user must confirm explicitly.
 * Legacy rows (is_legacy) keep their values; linking a legacy delivery to a PO line is how an Admin resolves it (D3).
 */

const PURCHASE_ORDER_FIELDS = ['po_number', 'customer_id', 'po_date', 'expected_delivery_date', 'status', 'payment_term',
  'owner_user_id', 'notes'];
const PO_LINE_FIELDS = ['purchase_order_id', 'product_id', 'order_quantity', 'unit', 'unit_price', 'notes'];
const DELIVERY_FIELDS = ['po_line_id', 'delivery_date', 'quantity', 'status', 'sj_number', 'destination', 'attachment_url', 'notes'];
const RETURN_FIELDS = ['purchase_order_id', 'po_line_id', 'product_id', 'return_date', 'quantity', 'reason', 'status',
  'sj_number', 'destination', 'attachment_url', 'notes'];
const PO_DETAIL_LIST_LIMIT = 200;
const QUANTITY_EPSILON = 0.0005;

function purchaseOrderWithSummary_(po) {
  return Object.assign(withNames_(po), purchaseOrderSummary_(po.id), { po_label: purchaseOrderLabel_(po), is_open: isOpenPurchaseOrder_(po) });
}

function poOf_(record) {
  return record.purchase_order_id ? loadTable_('PURCHASE_ORDERS').byId[record.purchase_order_id] || null : null;
}

/** Customer of a transaction through its PO (deliveries and returns have no customer column). */
function withCustomerViaPo_(record) {
  const po = poOf_(record);
  const names = nameMaps_();
  return Object.assign(record, {
    customer_id: record.customer_id || (po ? po.customer_id : null),
    customer_name: record.customer_id ? names.customer[record.customer_id] || null
      : po && po.customer_id ? names.customer[po.customer_id] || null : null
  });
}

function deliveryForList_(delivery) {
  return Object.assign(withCustomerViaPo_(withNames_(delivery)), {
    counts_as_delivered: deliveryCountsAsDelivered_(delivery),
    is_linked: Boolean(delivery.po_line_id)
  });
}

function returnForList_(item) {
  return Object.assign(withCustomerViaPo_(withNames_(item)), { is_linked: Boolean(item.po_line_id) });
}

/** A quantity outside what the line allows: the client may confirm and resend with confirmOverQuantity = true. */
function overQuantityError_(message, available, field) {
  return appError_(ERROR_CODE.VALIDATION, message, {
    confirmable: true, available: available,
    errors: [{ field: field || 'quantity', code: 'OVER_QUANTITY', message: message }]
  });
}

// ---------------------------------------------------------------------------------------------------------------
// Purchase orders
// ---------------------------------------------------------------------------------------------------------------

function listPurchaseOrders_(input) {
  const records = loadTable_('PURCHASE_ORDERS').records.map(purchaseOrderWithSummary_);
  return runListQuery_(records, input, {
    searchFields: ['po_number', 'po_number_legacy', 'customer_name', 'owner_name', 'notes', 'payment_term'],
    filters: {
      status: eqFilter_('status'),
      customer_id: eqFilter_('customer_id'),
      owner_user_id: eqFilter_('owner_user_id'),
      fulfillment: eqFilter_('fulfillment'),
      open: function (record, value) { return record.is_open === (value === true || value === 'true'); },
      is_legacy: boolFilter_('is_legacy'),
      po_date: dateRangeFilter_('po_date')
    },
    sortFields: {
      po_date: function (r) { return r.po_date; },
      po_number: function (r) { return r.po_label; },
      customer_name: function (r) { return r.customer_name; },
      status: function (r) { return r.status; },
      order_quantity: function (r) { return r.order_quantity; },
      outstanding_quantity: function (r) { return r.outstanding_quantity; },
      expected_delivery_date: function (r) { return r.expected_delivery_date; },
      updated_at: function (r) { return r.updated_at; }
    },
    defaultSort: { field: 'po_date', direction: 'desc' }
  });
}

function purchaseOrderOptions_(input) {
  const params = objectInput_(input);
  const names = nameMaps_();
  const records = loadTable_('PURCHASE_ORDERS').records.filter(function (po) {
    return (!params.customer_id || po.customer_id === params.customer_id) &&
      (params.openOnly !== true || isOpenPurchaseOrder_(po) || po.id === params.include);
  });
  return optionsFrom_(records, params, purchaseOrderLabel_, function (po) { return names.customer[po.customer_id] || null; });
}

/** Lines of open POs for delivery/return/lead-time forms, with what is still outstanding. */
function poLineOptions_(input) {
  const params = objectInput_(input);
  const pos = loadTable_('PURCHASE_ORDERS').byId;
  const names = nameMaps_();
  const records = loadTable_('PO_LINES').records.filter(function (line) {
    const po = pos[line.purchase_order_id];
    if (params.purchase_order_id && line.purchase_order_id !== params.purchase_order_id) return false;
    if (params.product_id && line.product_id !== params.product_id) return false;
    return line.id === params.include || (po && isOpenPurchaseOrder_(po)) || params.includeClosed === true;
  });
  const result = optionsFrom_(records, params, function (line) {
    return (names.purchaseOrder[line.purchase_order_id] || '?') + ' · ' + (names.product[line.product_id] || '?');
  }, function (line) {
    const progress = lineProgress_(line);
    return 'Order ' + progress.order_quantity + ' · terkirim ' + progress.delivered_quantity + ' · outstanding ' + progress.outstanding_quantity;
  });
  result.items = result.items.map(function (item) {
    const line = loadTable_('PO_LINES').byId[item.id];
    return Object.assign(item, lineProgress_(line), {
      purchase_order_id: line.purchase_order_id, product_id: line.product_id, unit: line.unit || null
    });
  });
  return result;
}

function getPurchaseOrder_(input, user) {
  const id = requireId_(objectInput_(input).id, 'ID PO');
  const po = findOrThrow_('PURCHASE_ORDERS', id);
  const ofPo = function (tableName) {
    return loadTable_(tableName).records.filter(function (record) { return record.purchase_order_id === id; });
  };
  const newestFirst = function (field) {
    return function (a, b) { return compareForSort_(b[field], a[field]); };
  };
  const detail = {
    purchaseOrder: purchaseOrderWithSummary_(po),
    lines: ofPo('PO_LINES').map(lineWithProgress_).sort(function (a, b) {
      return Number(a.is_active === false) - Number(b.is_active === false) || compareForSort_(a.created_at, b.created_at);
    }),
    deliveries: ofPo('DELIVERIES').map(deliveryForList_).sort(newestFirst('delivery_date')).slice(0, PO_DETAIL_LIST_LIMIT),
    returns: ofPo('RETURNS').map(returnForList_).sort(newestFirst('return_date')),
    leadTimes: can_(user, 'leadTime', ACCESS.READ) ? ofPo('LEADTIME').map(withNames_).sort(newestFirst('planned_date')) : null,
    inbound: can_(user, 'inbound', ACCESS.READ) ? ofPo('INBOUND_MAKLON').map(withNames_).sort(newestFirst('inbound_date')) : null,
    invoices: null,
    financials: null
  };
  if (can_(user, 'finance', ACCESS.READ)) {
    detail.invoices = ofPo('INVOICES_PAYMENTS').map(invoiceForList_).sort(newestFirst('invoice_date'));
    detail.financials = ofPo('PO_FINANCIALS').map(withNames_);
  }
  return detail;
}

/** Header and lines in one unit: both are validated before anything is written. */
function createPurchaseOrder_(input, user) {
  const params = objectInput_(input);
  const header = Object.assign({ owner_user_id: user.id, status: 'OPEN', po_date: todayIso_() },
    pickFields_(objectInput_(params.data, 'Data'), PURCHASE_ORDER_FIELDS));
  const lines = Array.isArray(params.lines) ? params.lines : [];
  const results = dbInsertUnit_([
    { table: 'PURCHASE_ORDERS', inputs: [header] },
    {
      table: 'PO_LINES',
      inputs: function (previous) {
        return lines.map(function (line) {
          return withDefaultUnit_(Object.assign(pickFields_(objectInput_(line, 'Baris PO'), PO_LINE_FIELDS), {
            purchase_order_id: previous[0][0].id
          }));
        });
      }
    }
  ], userContext_(user));
  return purchaseOrderWithSummary_(results[0][0]);
}

function withDefaultUnit_(line) {
  if (line.unit || !line.product_id) return line;
  const product = loadTable_('PRODUCTS').byId[line.product_id];
  return product && product.unit ? Object.assign({}, line, { unit: product.unit }) : line;
}

function updatePurchaseOrder_(input, user) {
  return purchaseOrderWithSummary_(updateRecord_('PURCHASE_ORDERS', input, PURCHASE_ORDER_FIELDS, user));
}

function setPurchaseOrderStatus_(input, user) {
  const params = objectInput_(input);
  return updatePurchaseOrder_({ id: params.id, data: { status: params.status }, expectedUpdatedAt: params.expectedUpdatedAt }, user);
}

/** A PO with active deliveries or returns is not archived (they would lose their PO); cancel it instead. */
function archivePurchaseOrder_(input, user) {
  const id = requireId_(objectInput_(input).id);
  return withScriptLock_(function () {
    const inUse = function (record) { return record.purchase_order_id === id && record.is_active !== false; };
    const deliveries = loadTable_('DELIVERIES').records.filter(inUse).length;
    const returns = loadTable_('RETURNS').records.filter(inUse).length;
    if (deliveries + returns > 0) {
      throw appError_(ERROR_CODE.VALIDATION, 'PO masih memiliki ' + deliveries + ' delivery dan ' + returns + ' retur aktif. ' +
        'Ubah status PO menjadi Cancelled, atau arsipkan transaksinya lebih dulu.');
    }
    return purchaseOrderWithSummary_(setRecordActive_('PURCHASE_ORDERS', id, false, userContext_(user)));
  });
}

function restorePurchaseOrder_(input, user) {
  return purchaseOrderWithSummary_(setRecordActive_('PURCHASE_ORDERS', requireId_(objectInput_(input).id), true, userContext_(user)));
}

// ---------------------------------------------------------------------------------------------------------------
// PO lines
// ---------------------------------------------------------------------------------------------------------------

function activeTransactionsOfLine_(lineId) {
  const active = function (record) { return record.po_line_id === lineId && record.is_active !== false; };
  return {
    deliveries: loadTable_('DELIVERIES').records.filter(active),
    returns: loadTable_('RETURNS').records.filter(active)
  };
}

function createPoLine_(input, user) {
  const data = withDefaultUnit_(pickFields_(objectInput_(objectInput_(input).data, 'Data'), PO_LINE_FIELDS));
  return lineWithProgress_(dbInsert_('PO_LINES', [data], userContext_(user))[0]);
}

function updatePoLine_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const data = pickFields_(objectInput_(params.data, 'Data'), PO_LINE_FIELDS);
  return withScriptLock_(function () {
    const current = findOrThrow_('PO_LINES', id);
    const transactions = activeTransactionsOfLine_(id);
    const used = transactions.deliveries.length + transactions.returns.length > 0;
    if (used && data.product_id && data.product_id !== current.product_id) {
      throw appError_(ERROR_CODE.VALIDATION, 'Produk baris PO tidak dapat diganti karena sudah ada delivery/retur untuk baris ini.',
        { errors: [{ field: 'product_id', code: 'IN_USE', message: 'Sudah ada delivery/retur untuk baris ini.' }] });
    }
    if (used && data.purchase_order_id && data.purchase_order_id !== current.purchase_order_id) {
      throw appError_(ERROR_CODE.VALIDATION, 'Baris PO yang sudah punya delivery/retur tidak dapat dipindah ke PO lain.',
        { errors: [{ field: 'purchase_order_id', code: 'IN_USE', message: 'Sudah ada delivery/retur untuk baris ini.' }] });
    }
    const orderQuantity = numericInput_(data.order_quantity);
    if (typeof orderQuantity === 'number' && params.confirmOverQuantity !== true) {
      const progress = lineProgress_(current);
      const netDelivered = Math.max(0, roundQty_(progress.delivered_quantity - progress.returned_quantity));
      if (orderQuantity + QUANTITY_EPSILON < netDelivered) {
        throw overQuantityError_('Qty order ' + orderQuantity + ' lebih kecil dari qty yang sudah terkirim (' + netDelivered +
          ', setelah retur). Konfirmasi bila memang demikian.', netDelivered, 'order_quantity');
      }
    }
    return lineWithProgress_(dbUpdate_('PO_LINES', id, data, userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
  });
}

function archivePoLine_(input, user) {
  const id = requireId_(objectInput_(input).id);
  return withScriptLock_(function () {
    const transactions = activeTransactionsOfLine_(id);
    if (transactions.deliveries.length + transactions.returns.length > 0) {
      throw appError_(ERROR_CODE.VALIDATION, 'Baris PO masih dipakai ' + transactions.deliveries.length + ' delivery dan ' +
        transactions.returns.length + ' retur aktif. Arsipkan transaksi tersebut lebih dulu.');
    }
    return lineWithProgress_(setRecordActive_('PO_LINES', id, false, userContext_(user)));
  });
}

function restorePoLine_(input, user) {
  return lineWithProgress_(setRecordActive_('PO_LINES', requireId_(objectInput_(input).id), true, userContext_(user)));
}

// ---------------------------------------------------------------------------------------------------------------
// Deliveries
// ---------------------------------------------------------------------------------------------------------------

function deliverySpec_() {
  return {
    searchFields: ['sj_number', 'destination', 'po_label', 'customer_name', 'product_label', 'notes'],
    filters: {
      status: function (record, value) {
        if (value === 'NONE') return !record.status;
        return Array.isArray(value) ? value.indexOf(record.status) !== -1 : record.status === value;
      },
      purchase_order_id: eqFilter_('purchase_order_id'),
      po_line_id: eqFilter_('po_line_id'),
      product_id: eqFilter_('product_id'),
      customer_id: eqFilter_('customer_id'),
      linked: function (record, value) { return record.is_linked === (value === true || value === 'true'); },
      is_legacy: boolFilter_('is_legacy'),
      delivery_date: dateRangeFilter_('delivery_date')
    },
    sortFields: ['delivery_date', 'quantity', 'sj_number', 'status', 'customer_name', 'po_label', 'product_label', 'updated_at'],
    defaultSort: { field: 'delivery_date', direction: 'desc' }
  };
}

function listDeliveries_(input) {
  return runListQuery_(loadTable_('DELIVERIES').records.map(deliveryForList_), input, deliverySpec_());
}

/** Quantity typed as text is read as the number that will be stored, so the quantity checks cannot be bypassed. */
function withNumericQuantity_(data) {
  if (data.quantity === undefined) return data;
  return Object.assign({}, data, { quantity: numericInput_(data.quantity) });
}

function requireActiveLine_(lineId) {
  if (!lineId) {
    throw appError_(ERROR_CODE.VALIDATION, 'Baris PO wajib dipilih.', { errors: [{ field: 'po_line_id', code: 'REQUIRED', message: 'Baris PO wajib dipilih.' }] });
  }
  const line = findOrThrow_('PO_LINES', lineId);
  if (line.is_active === false) {
    throw appError_(ERROR_CODE.VALIDATION, 'Baris PO ' + lineId + ' sudah diarsipkan.', { errors: [{ field: 'po_line_id', code: 'REF_INACTIVE', message: 'Baris PO sudah diarsipkan.' }] });
  }
  return line;
}

/** A delivery that counts (delivered or scheduled) must fit what the line still has outstanding, unless confirmed. */
function checkDeliveryQuantity_(line, record, previous, confirmed) {
  if (confirmed === true || typeof record.quantity !== 'number') return;
  const counts = deliveryCountsAsDelivered_(record) || deliveryIsPending_(record);
  if (!counts) return;
  const progress = lineProgress_(line);
  let available = progress.outstanding_quantity - progress.scheduled_quantity;
  if (previous && previous.po_line_id === line.id) {
    if (deliveryCountsAsDelivered_(previous)) available += previous.quantity;
    else if (deliveryIsPending_(previous)) available += previous.quantity;
  }
  available = Math.max(0, roundQty_(available));
  if (record.quantity > available + QUANTITY_EPSILON) {
    throw overQuantityError_('Qty kirim ' + record.quantity + ' melebihi sisa baris PO (' + available + '). Konfirmasi bila memang ' +
      'dikirim lebih dari pesanan.', available);
  }
}

function createDelivery_(input, user) {
  const params = objectInput_(input);
  const data = withNumericQuantity_(pickFields_(objectInput_(params.data, 'Data'), DELIVERY_FIELDS));
  return withScriptLock_(function () {
    resetDbCache_();
    const line = requireActiveLine_(data.po_line_id);
    const po = findOrThrow_('PURCHASE_ORDERS', line.purchase_order_id);
    if (!isOpenPurchaseOrder_(po)) {
      throw appError_(ERROR_CODE.VALIDATION, 'PO ' + purchaseOrderLabel_(po) + ' berstatus ' + po.status +
        '; delivery baru hanya untuk PO yang masih terbuka.');
    }
    const record = Object.assign({ status: 'DELIVERED', delivery_date: todayIso_() }, data, {
      purchase_order_id: line.purchase_order_id, product_id: line.product_id
    });
    checkDeliveryQuantity_(line, record, null, params.confirmOverQuantity);
    return deliveryForList_(dbInsert_('DELIVERIES', [record], userContext_(user))[0]);
  });
}

/**
 * Editing a delivery. Choosing a PO line takes the PO and product from that line; a line of another PO, or of another
 * product than the delivery already has, is rejected rather than silently rewriting the delivery (legacy linking, D3).
 */
function updateDelivery_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const data = withNumericQuantity_(pickFields_(objectInput_(params.data, 'Data'), DELIVERY_FIELDS));
  return withScriptLock_(function () {
    resetDbCache_();
    const current = findOrThrow_('DELIVERIES', id);
    const lineId = data.po_line_id !== undefined ? data.po_line_id : current.po_line_id;
    const patch = /** @type {Object<string, *>} */ (Object.assign({}, data));
    let line = null;
    if (lineId) {
      line = lineId === current.po_line_id ? findOrThrow_('PO_LINES', lineId) : requireActiveLine_(lineId);
      if (current.purchase_order_id && current.purchase_order_id !== line.purchase_order_id) {
        throw appError_(ERROR_CODE.VALIDATION, 'Baris PO yang dipilih milik PO lain, bukan PO delivery ini.',
          { errors: [{ field: 'po_line_id', code: 'REF_MISMATCH', message: 'Baris PO milik PO lain.' }] });
      }
      if (current.product_id && current.product_id !== line.product_id) {
        throw appError_(ERROR_CODE.VALIDATION, 'Produk baris PO berbeda dengan produk delivery ini.',
          { errors: [{ field: 'po_line_id', code: 'REF_MISMATCH', message: 'Produk baris PO berbeda.' }] });
      }
      patch.purchase_order_id = line.purchase_order_id;
      patch.product_id = line.product_id;
    } else if (!current.is_legacy) {
      throw appError_(ERROR_CODE.VALIDATION, 'Baris PO wajib dipilih.', { errors: [{ field: 'po_line_id', code: 'REQUIRED', message: 'Baris PO wajib dipilih.' }] });
    }
    if (line) checkDeliveryQuantity_(line, Object.assign({}, current, patch), current, params.confirmOverQuantity);
    return deliveryForList_(dbUpdate_('DELIVERIES', id, patch, userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
  });
}

function archiveDelivery_(input, user) {
  return deliveryForList_(setRecordActive_('DELIVERIES', requireId_(objectInput_(input).id), false, userContext_(user)));
}

function restoreDelivery_(input, user) {
  return deliveryForList_(setRecordActive_('DELIVERIES', requireId_(objectInput_(input).id), true, userContext_(user)));
}

// ---------------------------------------------------------------------------------------------------------------
// Returns
// ---------------------------------------------------------------------------------------------------------------

function listReturns_(input) {
  return runListQuery_(loadTable_('RETURNS').records.map(returnForList_), input, {
    searchFields: ['sj_number', 'reason', 'po_label', 'customer_name', 'product_label', 'notes', 'po_number_legacy', 'product_legacy'],
    filters: {
      status: eqFilter_('status'),
      purchase_order_id: eqFilter_('purchase_order_id'),
      product_id: eqFilter_('product_id'),
      customer_id: eqFilter_('customer_id'),
      linked: function (record, value) { return record.is_linked === (value === true || value === 'true'); },
      return_date: dateRangeFilter_('return_date')
    },
    sortFields: ['return_date', 'quantity', 'status', 'customer_name', 'po_label', 'product_label', 'updated_at'],
    defaultSort: { field: 'return_date', direction: 'desc' }
  });
}

/** With a PO line, PO and product come from the line; a return above what was delivered on the line needs confirmation. */
function prepareReturn_(data, current, confirmed) {
  const record = Object.assign({}, data);
  const lineId = data.po_line_id !== undefined ? data.po_line_id : current ? current.po_line_id : null;
  if (!lineId) return record;
  const line = current && lineId === current.po_line_id ? findOrThrow_('PO_LINES', lineId) : requireActiveLine_(lineId);
  const base = Object.assign({}, current || {}, data);
  if (base.purchase_order_id && base.purchase_order_id !== line.purchase_order_id) {
    throw appError_(ERROR_CODE.VALIDATION, 'Baris PO yang dipilih milik PO lain.', { errors: [{ field: 'po_line_id', code: 'REF_MISMATCH', message: 'Baris PO milik PO lain.' }] });
  }
  if (base.product_id && base.product_id !== line.product_id) {
    throw appError_(ERROR_CODE.VALIDATION, 'Produk baris PO berbeda dengan produk retur.', { errors: [{ field: 'po_line_id', code: 'REF_MISMATCH', message: 'Produk baris PO berbeda.' }] });
  }
  record.purchase_order_id = line.purchase_order_id;
  record.product_id = line.product_id;
  const quantity = typeof base.quantity === 'number' ? base.quantity : null;
  if (confirmed !== true && quantity !== null && base.status !== 'CANCELLED') {
    const progress = lineProgress_(line);
    let returnable = progress.delivered_quantity - progress.returned_quantity;
    if (current && current.po_line_id === line.id && returnCounts_(current)) returnable += current.quantity;
    returnable = Math.max(0, roundQty_(returnable));
    if (quantity > returnable + QUANTITY_EPSILON) {
      throw overQuantityError_('Qty retur ' + quantity + ' melebihi qty terkirim yang belum diretur (' + returnable + '). ' +
        'Konfirmasi bila memang demikian.', returnable);
    }
  }
  return record;
}

function createReturn_(input, user) {
  const params = objectInput_(input);
  const data = withNumericQuantity_(pickFields_(objectInput_(params.data, 'Data'), RETURN_FIELDS));
  return withScriptLock_(function () {
    resetDbCache_();
    const record = prepareReturn_(Object.assign({ status: 'OPEN', return_date: todayIso_() }, data), null, params.confirmOverQuantity);
    return returnForList_(dbInsert_('RETURNS', [record], userContext_(user))[0]);
  });
}

function updateReturn_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const data = withNumericQuantity_(pickFields_(objectInput_(params.data, 'Data'), RETURN_FIELDS));
  return withScriptLock_(function () {
    resetDbCache_();
    const current = findOrThrow_('RETURNS', id);
    const patch = prepareReturn_(data, current, params.confirmOverQuantity);
    return returnForList_(dbUpdate_('RETURNS', id, patch, userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
  });
}

function archiveReturn_(input, user) {
  return returnForList_(setRecordActive_('RETURNS', requireId_(objectInput_(input).id), false, userContext_(user)));
}

function restoreReturn_(input, user) {
  return returnForList_(setRecordActive_('RETURNS', requireId_(objectInput_(input).id), true, userContext_(user)));
}

// ---------------------------------------------------------------------------------------------------------------
// Inbound maklon (read-only in the application; maintained by the migration and Admin)
// ---------------------------------------------------------------------------------------------------------------

function listInbound_(input) {
  return runListQuery_(namedRecords_('INBOUND_MAKLON'), input, {
    searchFields: ['sj_number', 'vendor', 'receiver', 'component_name', 'factory_component_code', 'internal_component_code',
      'po_label', 'product_label', 'po_number_legacy'],
    filters: {
      purchase_order_id: eqFilter_('purchase_order_id'),
      product_id: eqFilter_('product_id'),
      inbound_date: dateRangeFilter_('inbound_date')
    },
    sortFields: ['inbound_date', 'quantity', 'vendor', 'sj_number', 'po_label'],
    defaultSort: { field: 'inbound_date', direction: 'desc' }
  });
}
