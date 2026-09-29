/**
 * Reports (PRD §19): customers, leads, activities, purchase orders and deliveries, filtered by period, customer, PIC and
 * status. Every report returns a summary, groups for charts and the rows; `format: 'csv'` returns the rows as CSV text
 * for download (UTF-8 with BOM for Excel; cells that a spreadsheet would run as a formula are neutralised).
 *
 * params: { from, to ('yyyy-MM-dd', inclusive), customer_id, owner_user_id, status, type, format }
 */

const REPORT_ROW_LIMIT = 500;

function reportParams_(input) {
  const params = objectInput_(input);
  ['from', 'to'].forEach(function (key) {
    if (params[key] && !isValidIsoDate_(params[key])) {
      throw appError_(ERROR_CODE.VALIDATION, 'Tanggal ' + (key === 'from' ? 'awal' : 'akhir') + ' tidak valid.',
        { errors: [{ field: key, code: 'TYPE', message: 'Tanggal tidak valid.' }] });
    }
  });
  if (params.from && params.to && params.from > params.to) {
    throw appError_(ERROR_CODE.VALIDATION, 'Tanggal awal tidak boleh setelah tanggal akhir.', { errors: [{ field: 'to', code: 'RULE', message: 'Tanggal akhir sebelum tanggal awal.' }] });
  }
  return params;
}

function inPeriod_(value, params) {
  if (!params.from && !params.to) return true;
  const day = localDateOf_(value);
  if (!day) return false;
  return (!params.from || day >= params.from) && (!params.to || day <= params.to);
}

function groupCount_(records, keyOf, labelOf, valueOf) {
  const groups = {};
  records.forEach(function (record) {
    const key = keyOf(record) || '(kosong)';
    const entry = groups[key] || (groups[key] = { key: key, label: labelOf ? labelOf(key) : key, count: 0, value: 0 });
    entry.count++;
    if (valueOf) entry.value = roundQty_(entry.value + (valueOf(record) || 0));
  });
  return Object.keys(groups).map(function (key) { return groups[key]; }).sort(function (a, b) { return b.count - a.count; });
}

function enumLabel_(enumName, value) {
  const entry = getEnumState_().byName[enumName];
  return entry && entry.labels[value] ? entry.labels[value] : value;
}

/** Report result, or CSV when params.format === 'csv'. columns: [[key, header], ...] */
function reportResult_(name, params, summary, groups, rows, columns) {
  if (params.format === 'csv') {
    return { filename: 'pik-' + name + '-' + todayIso_() + '.csv', csv: toCsvText_(rows, columns), rows: rows.length };
  }
  // Only the displayed columns travel to the browser (full records would make large reports slow to load).
  const keys = columns.map(function (column) { return column[0]; }).concat(['id']);
  return {
    summary: summary, groups: groups, totalRows: rows.length,
    rows: rows.slice(0, REPORT_ROW_LIMIT).map(function (row) {
      const slim = {};
      keys.forEach(function (key) { slim[key] = row[key] === undefined ? null : row[key]; });
      return slim;
    }),
    columns: columns.map(function (column) { return { key: column[0], label: column[1] }; }),
    filters: { from: params.from || null, to: params.to || null }
  };
}

function csvCell_(value) {
  if (value === null || value === undefined) return '';
  let text = typeof value === 'boolean' ? (value ? 'Ya' : 'Tidak') : String(value);
  if (/^[=+\-@\t\r]/.test(text) && !/^-?\d+(\.\d+)?$/.test(text)) text = "'" + text;
  return /[",\r\n;]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
}

function toCsvText_(rows, columns) {
  const lines = [columns.map(function (column) { return csvCell_(column[1]); }).join(',')];
  rows.forEach(function (row) {
    lines.push(columns.map(function (column) { return csvCell_(row[column[0]]); }).join(','));
  });
  return '﻿' + lines.join('\r\n') + '\r\n';
}

function reportCustomers_(input) {
  const params = reportParams_(input);
  const customers = loadTable_('CUSTOMERS').records.filter(function (c) {
    return c.is_active !== false && (!params.status || c.status === params.status) &&
      (!params.owner_user_id || c.owner_user_id === params.owner_user_id) && (!params.customer_id || c.id === params.customer_id);
  }).map(customerWithStats_);
  const activityCount = {};
  loadTable_('ACTIVITIES').records.forEach(function (a) {
    if (a.is_active !== false && a.customer_id && inPeriod_(a.activity_at, params)) activityCount[a.customer_id] = (activityCount[a.customer_id] || 0) + 1;
  });
  const rows = customers.map(function (c) {
    return Object.assign({}, c, { activities_in_period: activityCount[c.id] || 0, status_label: enumLabel_('CUSTOMER_STATUS', c.status) });
  }).sort(function (a, b) { return compareForSort_(a.name, b.name); });
  const summary = {
    total: customers.length,
    active: customers.filter(function (c) { return c.status === 'ACTIVE'; }).length,
    dormant: customers.filter(function (c) { return c.status === 'DORMANT'; }).length,
    potential: customers.filter(function (c) { return c.status === 'POTENTIAL'; }).length,
    inactive: customers.filter(function (c) { return c.status === 'INACTIVE'; }).length,
    newInPeriod: customers.filter(function (c) { return !c.is_legacy && inPeriod_(c.created_at, params); }).length
  };
  const groups = { byStatus: groupCount_(customers, function (c) { return c.status; }, function (key) { return enumLabel_('CUSTOMER_STATUS', key); }) };
  return reportResult_('customers', params, summary, groups, rows, [
    ['name', 'Customer'], ['customer_code', 'Kode'], ['status_label', 'Status'], ['industry', 'Industri'], ['owner_name', 'PIC'],
    ['contact_count', 'Contact'], ['open_leads', 'Lead terbuka'], ['activities_in_period', 'Aktivitas (periode)'],
    ['open_purchase_orders', 'PO terbuka'], ['outstanding_quantity', 'Outstanding (qty)'], ['last_activity_at', 'Aktivitas terakhir']
  ]);
}

function reportLeads_(input) {
  const params = reportParams_(input);
  const leads = namedRecords_('LEADS').filter(function (lead) {
    return lead.is_active !== false && inPeriod_(lead.created_at, params) && (!params.status || lead.status === params.status) &&
      (!params.owner_user_id || lead.owner_user_id === params.owner_user_id) && (!params.customer_id || lead.customer_id === params.customer_id);
  });
  const rows = leads.map(function (lead) {
    return Object.assign({}, lead, { status_label: enumLabel_('LEAD_STATUS', lead.status), priority_label: lead.priority ? enumLabel_('PRIORITY', lead.priority) : null });
  }).sort(function (a, b) { return compareForSort_(b.created_at, a.created_at); });
  const value = function (lead) { return lead.estimated_value || 0; };
  const summary = {
    total: leads.length,
    open: leads.filter(isOpenLead_).length,
    won: leads.filter(function (l) { return l.status === 'WON'; }).length,
    lost: leads.filter(function (l) { return l.status === 'LOST'; }).length,
    openValue: roundMoney_(leads.filter(isOpenLead_).reduce(function (sum, l) { return sum + value(l); }, 0)),
    wonValue: roundMoney_(leads.filter(function (l) { return l.status === 'WON'; }).reduce(function (sum, l) { return sum + value(l); }, 0))
  };
  const groups = {
    byStatus: groupCount_(leads, function (l) { return l.status; }, function (key) { return enumLabel_('LEAD_STATUS', key); }, value),
    byOwner: groupCount_(leads, function (l) { return l.owner_name; }, null, value)
  };
  return reportResult_('leads', params, summary, groups, rows, [
    ['name', 'Lead'], ['customer_name', 'Customer'], ['status_label', 'Status'], ['priority_label', 'Prioritas'], ['owner_name', 'PIC'],
    ['estimated_quantity', 'Estimasi qty'], ['estimated_value', 'Estimasi nilai'], ['expected_closing_date', 'Perkiraan closing'],
    ['created_at', 'Dibuat']
  ]);
}

function reportActivities_(input) {
  const params = reportParams_(input);
  const activities = namedRecords_('ACTIVITIES').filter(function (a) {
    return a.is_active !== false && inPeriod_(a.activity_at, params) && (!params.type || a.type === params.type) &&
      (!params.owner_user_id || a.owner_user_id === params.owner_user_id) && (!params.customer_id || a.customer_id === params.customer_id);
  });
  const rows = activities.map(function (a) { return Object.assign({}, a, { type_label: enumLabel_('ACTIVITY_TYPE', a.type) }); })
    .sort(function (a, b) { return compareForSort_(b.activity_at, a.activity_at); });
  const groups = {
    byOwner: groupCount_(activities, function (a) { return a.owner_name; }),
    byType: groupCount_(activities, function (a) { return a.type; }, function (key) { return enumLabel_('ACTIVITY_TYPE', key); }),
    byCustomer: groupCount_(activities, function (a) { return a.customer_name; }).slice(0, 15)
  };
  return reportResult_('activities', params, { total: activities.length, customers: groups.byCustomer.length }, groups, rows, [
    ['activity_at', 'Waktu'], ['type_label', 'Jenis'], ['subject', 'Subjek'], ['customer_name', 'Customer'], ['lead_name', 'Lead'],
    ['owner_name', 'PIC'], ['description', 'Deskripsi']
  ]);
}

function reportPurchaseOrders_(input) {
  const params = reportParams_(input);
  const pos = loadTable_('PURCHASE_ORDERS').records.filter(function (po) {
    return po.is_active !== false && inPeriod_(po.po_date, params) && (!params.status || po.status === params.status) &&
      (!params.customer_id || po.customer_id === params.customer_id) && (!params.owner_user_id || po.owner_user_id === params.owner_user_id);
  }).map(purchaseOrderWithSummary_);
  const rows = pos.map(function (po) { return Object.assign({}, po, { status_label: enumLabel_('PO_STATUS', po.status) }); })
    .sort(function (a, b) { return compareForSort_(b.po_date, a.po_date); });
  const open = pos.filter(function (po) { return po.is_open; });
  const summary = {
    total: pos.length,
    open: open.length,
    closed: pos.filter(function (po) { return po.status === 'CLOSED'; }).length,
    cancelled: pos.filter(function (po) { return po.status === 'CANCELLED'; }).length,
    orderQuantity: roundQty_(pos.reduce(function (sum, po) { return sum + po.order_quantity; }, 0)),
    deliveredQuantity: roundQty_(pos.reduce(function (sum, po) { return sum + po.delivered_quantity; }, 0)),
    outstandingQuantity: roundQty_(open.reduce(function (sum, po) { return sum + po.outstanding_quantity; }, 0))
  };
  const groups = {
    byStatus: groupCount_(pos, function (po) { return po.status; }, function (key) { return enumLabel_('PO_STATUS', key); },
      function (po) { return po.outstanding_quantity; }),
    byCustomer: groupCount_(open, function (po) { return po.customer_name; }, null, function (po) { return po.outstanding_quantity; }).slice(0, 15)
  };
  return reportResult_('purchase-orders', params, summary, groups, rows, [
    ['po_label', 'Nomor PO'], ['customer_name', 'Customer'], ['po_date', 'Tanggal PO'], ['status_label', 'Status'], ['owner_name', 'PIC'],
    ['line_count', 'Baris'], ['order_quantity', 'Qty order'], ['delivered_quantity', 'Qty terkirim'], ['returned_quantity', 'Qty retur'],
    ['outstanding_quantity', 'Outstanding'], ['expected_delivery_date', 'Target kirim']
  ]);
}

function reportDeliveries_(input) {
  const params = reportParams_(input);
  const deliveries = loadTable_('DELIVERIES').records.map(deliveryForList_).filter(function (d) {
    return d.is_active !== false && inPeriod_(d.delivery_date, params) && (!params.status || d.status === params.status) &&
      (!params.customer_id || d.customer_id === params.customer_id);
  });
  const rows = deliveries.map(function (d) {
    return Object.assign({}, d, { status_label: d.status ? enumLabel_('DELIVERY_STATUS', d.status) : '(tanpa status)' });
  }).sort(function (a, b) { return compareForSort_(b.delivery_date, a.delivery_date); });
  const qty = function (d) { return typeof d.quantity === 'number' ? d.quantity : 0; };
  const byStatus = function (status) { return deliveries.filter(function (d) { return d.status === status; }); };
  const summary = {
    total: deliveries.length,
    scheduled: byStatus('SCHEDULED').length,
    onDelivery: byStatus('ON_DELIVERY').length,
    delivered: byStatus('DELIVERED').length,
    delayed: byStatus('DELAYED').length,
    cancelled: byStatus('CANCELLED').length,
    deliveredQuantity: roundQty_(deliveries.filter(deliveryCountsAsDelivered_).reduce(function (sum, d) { return sum + qty(d); }, 0)),
    unlinked: deliveries.filter(function (d) { return !d.po_line_id; }).length
  };
  const groups = {
    byStatus: groupCount_(deliveries, function (d) { return d.status; }, function (key) { return key === '(kosong)' ? '(tanpa status)' : enumLabel_('DELIVERY_STATUS', key); }, qty),
    byCustomer: groupCount_(deliveries, function (d) { return d.customer_name; }, null, qty).slice(0, 15)
  };
  return reportResult_('deliveries', params, summary, groups, rows, [
    ['delivery_date', 'Tanggal'], ['sj_number', 'Nomor SJ'], ['po_label', 'PO'], ['customer_name', 'Customer'], ['product_label', 'Produk'],
    ['quantity', 'Qty'], ['status_label', 'Status'], ['destination', 'Tujuan']
  ]);
}
