/**
 * Products, stock records and lead-time schedules (D10: planned deliveries, not counted as delivered).
 */

const PRODUCT_FIELDS = ['product_code', 'name', 'variant', 'category', 'customer_id', 'description', 'unit', 'lead_time_days'];
const STOCK_FIELDS = ['product_id', 'stock_type', 'status', 'quantity', 'box_count', 'qty_per_box', 'warehouse', 'stock_date', 'notes'];
const LEAD_TIME_FIELDS = ['purchase_order_id', 'po_line_id', 'product_id', 'customer_id', 'planned_date', 'quantity', 'status',
  'lead_time_days', 'notes'];

/** Latest stock record per product and stock type (by stock_date, then last change), computed once per execution. */
function latestStock_() {
  if (DB_CACHE_.latestStock) return DB_CACHE_.latestStock;
  const latest = {};
  loadTable_('STOCK').records.forEach(function (stock) {
    if (stock.is_active === false || !stock.product_id) return;
    const key = stock.product_id + '|' + stock.stock_type;
    const current = latest[key];
    const newer = !current || compareForSort_(stock.stock_date, current.stock_date) > 0 ||
      (stock.stock_date === current.stock_date && compareForSort_(stock.updated_at, current.updated_at) > 0);
    if (newer) latest[key] = stock;
  });
  DB_CACHE_.latestStock = latest;
  return latest;
}

function productWithFigures_(product) {
  const latest = latestStock_();
  const stockBy = function (type) {
    const record = latest[product.id + '|' + type];
    return record && typeof record.quantity === 'number' ? record.quantity : null;
  };
  let openOrder = 0;
  loadTable_('PO_LINES').records.forEach(function (line) {
    if (line.product_id !== product.id || line.is_active === false) return;
    const po = loadTable_('PURCHASE_ORDERS').byId[line.purchase_order_id];
    if (po && isOpenPurchaseOrder_(po)) openOrder += lineProgress_(line).outstanding_quantity;
  });
  return Object.assign(withNames_(product), {
    label: productLabel_(product),
    stock_fg: stockBy('FG'),
    stock_wip: stockBy('WIP'),
    open_outstanding_quantity: roundQty_(openOrder)
  });
}

function listProducts_(input) {
  const outstandingByProduct = {};
  const pos = loadTable_('PURCHASE_ORDERS').byId;
  loadTable_('PO_LINES').records.forEach(function (line) {
    if (line.is_active === false || !line.product_id) return;
    const po = pos[line.purchase_order_id];
    if (!po || !isOpenPurchaseOrder_(po)) return;
    outstandingByProduct[line.product_id] = (outstandingByProduct[line.product_id] || 0) + lineProgress_(line).outstanding_quantity;
  });
  const latest = latestStock_();
  const records = loadTable_('PRODUCTS').records.map(function (product) {
    const fg = latest[product.id + '|FG'];
    const wip = latest[product.id + '|WIP'];
    return Object.assign(withNames_(product), {
      label: productLabel_(product),
      stock_fg: fg && typeof fg.quantity === 'number' ? fg.quantity : null,
      stock_wip: wip && typeof wip.quantity === 'number' ? wip.quantity : null,
      open_outstanding_quantity: roundQty_(outstandingByProduct[product.id] || 0)
    });
  });
  return runListQuery_(records, input, {
    searchFields: ['product_code', 'name', 'variant', 'category', 'customer_name', 'description'],
    filters: {
      category: eqFilter_('category'),
      customer_id: eqFilter_('customer_id'),
      unit: eqFilter_('unit'),
      has_open_order: function (record, value) { return (record.open_outstanding_quantity > 0) === (value === true || value === 'true'); }
    },
    sortFields: ['name', 'product_code', 'category', 'customer_name', 'stock_fg', 'open_outstanding_quantity', 'updated_at'],
    defaultSort: { field: 'name', direction: 'asc' }
  });
}

function productOptions_(input) {
  return optionsFrom_(loadTable_('PRODUCTS').records, input, productLabel_, function (p) { return p.category || p.unit || null; });
}

function getProduct_(input) {
  const id = requireId_(objectInput_(input).id, 'ID produk');
  const product = findOrThrow_('PRODUCTS', id);
  const pos = loadTable_('PURCHASE_ORDERS').byId;
  const newestFirst = function (field) { return function (a, b) { return compareForSort_(b[field], a[field]); }; };
  return {
    product: productWithFigures_(product),
    stock: loadTable_('STOCK').records.filter(function (s) { return s.product_id === id; }).map(withNames_).sort(newestFirst('stock_date')),
    lines: loadTable_('PO_LINES').records.filter(function (line) { return line.product_id === id; }).map(function (line) {
      const po = pos[line.purchase_order_id];
      return Object.assign(lineWithProgress_(line), {
        po_date: po ? po.po_date : null, po_status: po ? po.status : null, customer_name: po ? nameMaps_().customer[po.customer_id] || null : null
      });
    }).sort(newestFirst('po_date')),
    leadTimes: loadTable_('LEADTIME').records.filter(function (lt) { return lt.product_id === id; }).map(withNames_).sort(newestFirst('planned_date'))
  };
}

function createProduct_(input, user) {
  return productWithFigures_(createRecord_('PRODUCTS', input, PRODUCT_FIELDS, user));
}

function updateProduct_(input, user) {
  return productWithFigures_(updateRecord_('PRODUCTS', input, PRODUCT_FIELDS, user));
}

function archiveProduct_(input, user) {
  return archiveRecord_('PRODUCTS', input, user);
}

function restoreProduct_(input, user) {
  return restoreRecord_('PRODUCTS', input, user);
}

// ---------------------------------------------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------------------------------------------

function listStock_(input) {
  return runListQuery_(namedRecords_('STOCK'), input, {
    searchFields: ['product_label', 'warehouse', 'notes', 'product_legacy', 'status_legacy'],
    filters: {
      product_id: eqFilter_('product_id'),
      stock_type: eqFilter_('stock_type'),
      status: eqFilter_('status'),
      warehouse: eqFilter_('warehouse'),
      linked: function (record, value) { return Boolean(record.product_id) === (value === true || value === 'true'); },
      stock_date: dateRangeFilter_('stock_date')
    },
    sortFields: ['stock_date', 'product_label', 'stock_type', 'status', 'quantity', 'warehouse', 'updated_at'],
    defaultSort: { field: 'stock_date', direction: 'desc' }
  });
}

/** Stock overview: latest record per product and type, with totals per type. */
function summarizeStock_(input) {
  const params = objectInput_(input);
  const latest = latestStock_();
  const names = nameMaps_();
  const rows = Object.keys(latest).map(function (key) {
    const record = latest[key];
    return {
      product_id: record.product_id, product_label: names.product[record.product_id] || null, stock_type: record.stock_type,
      status: record.status, quantity: record.quantity, warehouse: record.warehouse, stock_date: record.stock_date,
      updated_at: record.updated_at, stock_id: record.id
    };
  });
  const listed = runListQuery_(rows, { search: params.search, filters: params.filters, sort: params.sort, page: params.page, pageSize: params.pageSize }, {
    searchFields: ['product_label', 'warehouse'],
    filters: { stock_type: eqFilter_('stock_type'), status: eqFilter_('status'), warehouse: eqFilter_('warehouse') },
    sortFields: ['product_label', 'stock_type', 'quantity', 'stock_date', 'warehouse'],
    defaultSort: { field: 'product_label', direction: 'asc' }
  });
  const totals = {};
  rows.forEach(function (row) {
    const entry = totals[row.stock_type] || (totals[row.stock_type] = { products: 0, quantity: 0 });
    entry.products++;
    entry.quantity = roundQty_(entry.quantity + (typeof row.quantity === 'number' ? row.quantity : 0));
  });
  const unlinked = loadTable_('STOCK').records.filter(function (s) { return s.is_active !== false && !s.product_id; }).length;
  return Object.assign(listed, { totals: totals, unlinkedRecords: unlinked });
}

function createStock_(input, user) {
  return createRecord_('STOCK', input, STOCK_FIELDS, user, { stock_date: todayIso_(), stock_type: 'FG' });
}

function updateStock_(input, user) {
  return updateRecord_('STOCK', input, STOCK_FIELDS, user);
}

function archiveStock_(input, user) {
  return archiveRecord_('STOCK', input, user);
}

function restoreStock_(input, user) {
  return restoreRecord_('STOCK', input, user);
}

// ---------------------------------------------------------------------------------------------------------------
// Lead time schedules
// ---------------------------------------------------------------------------------------------------------------

function listLeadTimes_(input) {
  return runListQuery_(namedRecords_('LEADTIME'), input, {
    searchFields: ['po_label', 'product_label', 'customer_name', 'notes', 'po_number_legacy', 'product_legacy'],
    filters: {
      purchase_order_id: eqFilter_('purchase_order_id'),
      product_id: eqFilter_('product_id'),
      customer_id: eqFilter_('customer_id'),
      status: eqFilter_('status'),
      planned_date: dateRangeFilter_('planned_date')
    },
    sortFields: ['planned_date', 'quantity', 'status', 'po_label', 'product_label', 'customer_name', 'updated_at'],
    defaultSort: { field: 'planned_date', direction: 'asc' }
  });
}

/** A PO line fills the PO and product; the PO fills the customer. */
function leadTimeRelations_(data) {
  const record = Object.assign({}, data);
  if (record.po_line_id) {
    const line = findOrThrow_('PO_LINES', record.po_line_id);
    record.purchase_order_id = line.purchase_order_id;
    record.product_id = line.product_id;
  }
  if (record.purchase_order_id && !record.customer_id) {
    const po = loadTable_('PURCHASE_ORDERS').byId[record.purchase_order_id];
    if (po && po.customer_id) record.customer_id = po.customer_id;
  }
  return record;
}

function createLeadTime_(input, user) {
  const data = leadTimeRelations_(Object.assign({ status: 'SCHEDULED' },
    pickFields_(objectInput_(objectInput_(input).data, 'Data'), LEAD_TIME_FIELDS)));
  return withNames_(dbInsert_('LEADTIME', [data], userContext_(user))[0]);
}

function updateLeadTime_(input, user) {
  const params = objectInput_(input);
  const data = leadTimeRelations_(pickFields_(objectInput_(params.data, 'Data'), LEAD_TIME_FIELDS));
  return withNames_(dbUpdate_('LEADTIME', requireId_(params.id), data,
    userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
}

function archiveLeadTime_(input, user) {
  return archiveRecord_('LEADTIME', input, user);
}

function restoreLeadTime_(input, user) {
  return restoreRecord_('LEADTIME', input, user);
}
