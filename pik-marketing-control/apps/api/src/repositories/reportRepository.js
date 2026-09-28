/**
 * Report definitions. Each report declares its SQL, the columns that date/customer/owner/status
 * filters apply to, CSV columns and totals. Values come straight from the database views, so
 * reports always agree with the screens.
 */
import {
  ACTIVITY_TYPE,
  CUSTOMER_STATUS,
  DELIVERY_STATUS,
  FOLLOW_UP_STATE,
  LEAD_OPEN_STATUSES,
  LEAD_STATUS,
  MODULE,
  PO_OPEN_STATUSES,
  PO_STATUS,
  PRIORITY,
  STOCK_TYPE,
} from '@pik/shared';
import { query } from '../db/pool.js';
import { codeList, conditions, findPage, likePattern, sqlDate } from '../utils/sql.js';

const label = (enumDef) => (value) => enumDef.labels[value] ?? value;

export const REPORTS = {
  customers: {
    module: MODULE.CUSTOMERS,
    title: 'Laporan Customer',
    statuses: CUSTOMER_STATUS.values,
    select: `
      SELECT c.id, c.customer_code, c.name, c.industry, c.status, c.phone, c.email, c.is_active, c.created_at,
             (SELECT count(*) FROM contacts WHERE customer_id = c.id AND is_active) AS contacts,
             (SELECT count(*) FROM leads WHERE customer_id = c.id AND status IN ${codeList(LEAD_OPEN_STATUSES)}) AS active_leads,
             (SELECT coalesce(sum(estimated_value), 0) FROM leads WHERE customer_id = c.id AND status IN ${codeList(LEAD_OPEN_STATUSES)}) AS pipeline_value,
             (SELECT count(*) FROM purchase_orders WHERE customer_id = c.id AND status <> 'CANCELLED') AS purchase_orders,
             (SELECT coalesce(sum(s.total_value), 0) FROM purchase_orders po JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id
               WHERE po.customer_id = c.id AND po.status <> 'CANCELLED') AS po_value,
             (SELECT coalesce(sum(s.outstanding_quantity), 0) FROM purchase_orders po JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id
               WHERE po.customer_id = c.id AND po.status IN ${codeList(PO_OPEN_STATUSES)}) AS outstanding_quantity,
             (SELECT max(activity_at) FROM activities WHERE customer_id = c.id) AS last_activity_at`,
    from: 'FROM customers c',
    dateColumn: { column: 'c.created_at', timestamp: true },
    customerColumn: 'c.id',
    statusColumn: 'c.status',
    search: ['c.name', 'c.customer_code', 'c.industry'],
    order: 'c.name ASC, c.id',
    totals: 'count(*) AS count',
    columns: [
      { key: 'customer_code', header: 'Kode' },
      { key: 'name', header: 'Nama Customer' },
      { key: 'industry', header: 'Industri' },
      { key: 'status', header: 'Status', format: label(CUSTOMER_STATUS) },
      { key: 'contacts', header: 'Kontak' },
      { key: 'active_leads', header: 'Lead Aktif' },
      { key: 'pipeline_value', header: 'Nilai Pipeline' },
      { key: 'purchase_orders', header: 'Jumlah PO' },
      { key: 'po_value', header: 'Nilai PO' },
      { key: 'outstanding_quantity', header: 'Outstanding Qty' },
      { key: 'last_activity_at', header: 'Aktivitas Terakhir', type: 'timestamp' },
    ],
  },
  leads: {
    module: MODULE.LEADS,
    title: 'Laporan Lead',
    statuses: LEAD_STATUS.values,
    select: `
      SELECT l.id, l.name, c.name AS customer_name, l.status, l.priority, l.source, l.estimated_value,
             l.expected_closing_date, u.name AS owner_name, l.created_at, l.closed_at, l.lost_reason`,
    from: 'FROM leads l JOIN customers c ON c.id = l.customer_id LEFT JOIN users u ON u.id = l.owner_user_id',
    dateColumn: { column: 'l.created_at', timestamp: true },
    customerColumn: 'l.customer_id',
    ownerColumn: 'l.owner_user_id',
    statusColumn: 'l.status',
    search: ['l.name', 'c.name'],
    order: 'l.created_at DESC, l.id',
    totals: `count(*) AS count, coalesce(sum(l.estimated_value), 0) AS total_value,
             coalesce(sum(l.estimated_value) FILTER (WHERE l.status = 'WON'), 0) AS won_value,
             count(*) FILTER (WHERE l.status = 'WON') AS won, count(*) FILTER (WHERE l.status = 'LOST') AS lost`,
    columns: [
      { key: 'name', header: 'Lead' },
      { key: 'customer_name', header: 'Customer' },
      { key: 'status', header: 'Status', format: label(LEAD_STATUS) },
      { key: 'priority', header: 'Prioritas', format: label(PRIORITY) },
      { key: 'source', header: 'Sumber' },
      { key: 'estimated_value', header: 'Estimasi Nilai' },
      { key: 'expected_closing_date', header: 'Perkiraan Closing' },
      { key: 'owner_name', header: 'PIC' },
      { key: 'created_at', header: 'Dibuat', type: 'timestamp' },
      { key: 'closed_at', header: 'Ditutup', type: 'timestamp' },
      { key: 'lost_reason', header: 'Alasan Lost' },
    ],
  },
  activities: {
    module: MODULE.ACTIVITIES,
    title: 'Laporan Aktivitas',
    statuses: ACTIVITY_TYPE.values,
    select: `
      SELECT a.id, a.activity_at, a.type, a.subject, a.description, c.name AS customer_name,
             ct.name AS contact_name, l.name AS lead_name, u.name AS owner_name`,
    from: `FROM activities a JOIN customers c ON c.id = a.customer_id LEFT JOIN contacts ct ON ct.id = a.contact_id
           LEFT JOIN leads l ON l.id = a.lead_id LEFT JOIN users u ON u.id = a.owner_user_id`,
    dateColumn: { column: 'a.activity_at', timestamp: true },
    customerColumn: 'a.customer_id',
    ownerColumn: 'a.owner_user_id',
    statusColumn: 'a.type',
    search: ['a.subject', 'a.description', 'c.name'],
    order: 'a.activity_at DESC, a.id',
    totals: 'count(*) AS count, count(DISTINCT a.customer_id) AS customers',
    columns: [
      { key: 'activity_at', header: 'Waktu', type: 'timestamp' },
      { key: 'type', header: 'Jenis', format: label(ACTIVITY_TYPE) },
      { key: 'subject', header: 'Judul' },
      { key: 'customer_name', header: 'Customer' },
      { key: 'contact_name', header: 'Kontak' },
      { key: 'lead_name', header: 'Lead' },
      { key: 'owner_name', header: 'PIC' },
      { key: 'description', header: 'Deskripsi' },
    ],
  },
  'follow-ups': {
    module: MODULE.FOLLOW_UPS,
    title: 'Laporan Follow Up',
    statuses: FOLLOW_UP_STATE.values,
    select: (today) => `
      SELECT f.id, f.follow_up_date, f.follow_up_time, follow_up_state(f.follow_up_date, f.status, ${sqlDate(today)}) AS state,
             f.status, f.priority, c.name AS customer_name, l.name AS lead_name, u.name AS owner_name, f.notes, f.completed_at`,
    from: 'FROM follow_ups f JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id LEFT JOIN users u ON u.id = f.owner_user_id',
    dateColumn: { column: 'f.follow_up_date' },
    customerColumn: 'f.customer_id',
    ownerColumn: 'f.owner_user_id',
    statusColumn: (today) => `follow_up_state(f.follow_up_date, f.status, ${sqlDate(today)})`,
    search: ['f.notes', 'c.name', 'l.name'],
    order: 'f.follow_up_date ASC, f.id',
    totals: (today) => `count(*) AS count,
             count(*) FILTER (WHERE follow_up_state(f.follow_up_date, f.status, ${sqlDate(today)}) = 'OVERDUE') AS overdue,
             count(*) FILTER (WHERE f.status = 'DONE') AS done`,
    columns: [
      { key: 'follow_up_date', header: 'Tanggal' },
      { key: 'follow_up_time', header: 'Jam' },
      { key: 'state', header: 'Kondisi', format: label(FOLLOW_UP_STATE) },
      { key: 'priority', header: 'Prioritas', format: label(PRIORITY) },
      { key: 'customer_name', header: 'Customer' },
      { key: 'lead_name', header: 'Lead' },
      { key: 'owner_name', header: 'PIC' },
      { key: 'notes', header: 'Catatan' },
      { key: 'completed_at', header: 'Selesai', type: 'timestamp' },
    ],
  },
  'purchase-orders': {
    module: MODULE.PURCHASE_ORDERS,
    title: 'Laporan Purchase Order',
    statuses: PO_STATUS.values,
    select: `
      SELECT po.id, po.po_number, c.name AS customer_name, po.po_date, po.expected_delivery_date, po.status,
             u.name AS owner_name, s.ordered_quantity, s.delivered_quantity, s.returned_quantity,
             s.outstanding_quantity, s.total_value`,
    from: `FROM purchase_orders po JOIN customers c ON c.id = po.customer_id
           JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id LEFT JOIN users u ON u.id = po.owner_user_id`,
    dateColumn: { column: 'po.po_date' },
    customerColumn: 'po.customer_id',
    ownerColumn: 'po.owner_user_id',
    statusColumn: 'po.status',
    search: ['po.po_number', 'c.name'],
    order: 'po.po_date DESC NULLS LAST, po.po_number, po.id',
    totals: `count(*) AS count, coalesce(sum(s.total_value), 0) AS total_value,
             count(*) FILTER (WHERE s.outstanding_quantity > 0 AND po.status <> 'CANCELLED') AS with_outstanding`,
    columns: [
      { key: 'po_number', header: 'No PO' },
      { key: 'customer_name', header: 'Customer' },
      { key: 'po_date', header: 'Tanggal PO' },
      { key: 'expected_delivery_date', header: 'Target Kirim' },
      { key: 'status', header: 'Status', format: label(PO_STATUS) },
      { key: 'owner_name', header: 'PIC' },
      { key: 'ordered_quantity', header: 'Qty Order' },
      { key: 'delivered_quantity', header: 'Qty Terkirim' },
      { key: 'returned_quantity', header: 'Qty Retur' },
      { key: 'outstanding_quantity', header: 'Outstanding' },
      { key: 'total_value', header: 'Nilai PO' },
    ],
  },
  deliveries: {
    module: MODULE.DELIVERIES,
    title: 'Laporan Pengiriman',
    statuses: DELIVERY_STATUS.values,
    select: `
      SELECT d.id, d.delivery_date, d.delivery_number, po.po_number, c.name AS customer_name,
             coalesce(p.name, d.item_name) AS product_name, p.product_code, d.quantity,
             coalesce(l.unit, p.unit) AS unit, d.status`,
    from: `FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id JOIN customers c ON c.id = po.customer_id
           LEFT JOIN products p ON p.id = d.product_id LEFT JOIN po_lines l ON l.id = d.po_line_id`,
    dateColumn: { column: 'd.delivery_date' },
    customerColumn: 'po.customer_id',
    ownerColumn: 'po.owner_user_id',
    statusColumn: 'd.status',
    search: ['d.delivery_number', 'po.po_number', 'c.name', 'p.name', 'd.item_name'],
    order: 'd.delivery_date DESC NULLS LAST, d.id',
    totals: `count(*) AS count, count(*) FILTER (WHERE d.status = 'DELIVERED') AS delivered,
             count(*) FILTER (WHERE d.status = 'DELAYED') AS delayed`,
    columns: [
      { key: 'delivery_date', header: 'Tanggal' },
      { key: 'delivery_number', header: 'No Surat Jalan' },
      { key: 'po_number', header: 'No PO' },
      { key: 'customer_name', header: 'Customer' },
      { key: 'product_code', header: 'Kode Produk' },
      { key: 'product_name', header: 'Produk' },
      { key: 'quantity', header: 'Qty' },
      { key: 'unit', header: 'Satuan' },
      { key: 'status', header: 'Status', format: label(DELIVERY_STATUS) },
    ],
  },
  stock: {
    module: MODULE.STOCK,
    title: 'Laporan Stok',
    statuses: STOCK_TYPE.values,
    select: `
      SELECT s.id, p.product_code, coalesce(p.name, s.item_name) AS product_name, p.category, s.stock_type,
             s.warehouse, s.quantity, p.unit, s.stock_date`,
    from: 'FROM v_stock_current s LEFT JOIN products p ON p.id = s.product_id',
    statusColumn: 's.stock_type',
    search: ['p.name', 'p.product_code', 's.item_name', 's.warehouse'],
    order: 'product_name ASC, s.stock_type, s.id',
    totals: 'count(*) AS count, count(DISTINCT s.product_id) AS products',
    columns: [
      { key: 'product_code', header: 'Kode Produk' },
      { key: 'product_name', header: 'Produk' },
      { key: 'category', header: 'Kategori' },
      { key: 'stock_type', header: 'Tipe Stok', format: label(STOCK_TYPE) },
      { key: 'warehouse', header: 'Gudang' },
      { key: 'quantity', header: 'Qty' },
      { key: 'unit', header: 'Satuan' },
      { key: 'stock_date', header: 'Tanggal Stok' },
    ],
  },
};

const resolve = (value, today) => (typeof value === 'function' ? value(today) : value);

function buildWhere(report, filters, { today, timeZone }) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(${report.search.map((column) => `${column} ILIKE ${p}`).join(' OR ')})`);
  }
  if (report.dateColumn && (filters.from || filters.to)) {
    const { column, timestamp } = report.dateColumn;
    const expression = timestamp ? `(${column} AT TIME ZONE ${w.param(timeZone)})::date` : column;
    if (filters.from) w.add(`${expression} >= ${w.param(filters.from)}`);
    if (filters.to) w.add(`${expression} <= ${w.param(filters.to)}`);
  }
  if (report.customerColumn && filters.customer_id) w.add(`${report.customerColumn} = ${w.param(filters.customer_id)}`);
  if (report.ownerColumn && filters.owner_user_id) w.add(`${report.ownerColumn} = ${w.param(filters.owner_user_id)}`);
  if (filters.status?.length) w.add(`${resolve(report.statusColumn, today)} = ANY(${w.param(filters.status)})`);
  return w;
}

export async function page(report, filters, context) {
  const w = buildWhere(report, filters, context);
  const [result, totals] = await Promise.all([
    findPage({
      select: resolve(report.select, context.today),
      from: report.from,
      where: w.sql(),
      params: w.params,
      order: report.order,
      page: filters.page,
      pageSize: filters.page_size,
    }),
    query(`SELECT ${resolve(report.totals, context.today)} ${report.from} ${w.sql()}`, w.params),
  ]);
  return { rows: result.rows, meta: { ...result.meta, totals: totals.rows[0] } };
}

/** All matching rows (for CSV), capped at `limit`; `truncated` says whether rows were left out. */
export async function all(report, filters, context, limit) {
  const w = buildWhere(report, filters, context);
  const { rows } = await query(
    `${resolve(report.select, context.today)} ${report.from} ${w.sql()} ORDER BY ${report.order} LIMIT ${Number(limit) + 1}`,
    w.params,
  );
  return { rows: rows.slice(0, limit), truncated: rows.length > limit };
}
