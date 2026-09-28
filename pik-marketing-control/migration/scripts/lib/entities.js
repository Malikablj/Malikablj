/**
 * Target-side definition of every migrated entity: field types, required fields, which
 * references must be resolved, and entity-specific derivations. The mapping file
 * (migration/mapping/*.mapping.js) only says which SOURCE columns feed these fields.
 *
 * Import order follows foreign-key dependencies.
 *
 * Field options:
 *   type      text | number | integer | date | datetime | time | boolean | enum
 *   required  row cannot be imported without it
 *   max       maximum text length (longer values are truncated with a warning;
 *             the original stays in source_data)
 *   min       minimum numeric value
 *   values    enum codes (with the mapping's valueMap translating source labels)
 *   default   used when the source cell is empty (a documented mapping decision)
 *   virtual   parsed for derivations only, not written as a column
 */
import {
  ACTIVITY_TYPE,
  CUSTOMER_STATUS,
  DELIVERY_STATUS,
  FOLLOW_UP_STATUS,
  LEAD_STATUS,
  PAYMENT_STATUS,
  PO_STATUS,
  PRIORITY,
  PRODUCT_STATUS,
  RETURN_STATUS,
  STOCK_TYPE,
} from '@pik/shared';
import { derivePaymentStatus } from '@pik/shared';
import { wallClockToInstant } from './values.js';

/** Lookup targets for reference resolution. */
export const TARGETS = {
  customers: {
    table: 'customers',
    label: `name || coalesce(' [' || customer_code || ']', '')`,
    code: 'customer_code',
    nameKey: 'name_key',
  },
  contacts: { table: 'contacts', label: 'name', nameKey: 'normalize_key(name)', scope: 'customer_id' },
  products: {
    table: 'products',
    label: `coalesce(product_code || ' - ', '') || name`,
    code: 'product_code',
    nameKey: 'name_key',
    extra: 'unit',
  },
  leads: { table: 'leads', label: 'name', nameKey: 'normalize_key(name)', scope: 'customer_id' },
  activities: { table: 'activities', label: 'subject' },
  purchase_orders: { table: 'purchase_orders', label: 'po_number', extra: 'customer_id' },
  po_lines: { table: 'po_lines', label: `coalesce('item ' || line_no, 'item') || ' (' || order_quantity || ')'`, extra: 'product_id' },
  users: { table: 'users', label: `name || ' <' || email || '>'` },
};

const text = (max, extra = {}) => ({ type: 'text', max, ...extra });

export const ENTITIES = [
  {
    name: 'customers',
    table: 'customers',
    fields: {
      customer_code: text(100),
      name: text(255, { required: true }),
      industry: text(150),
      address: text(),
      phone: text(100, { phone: true }),
      email: text(255, { lowercase: true }),
      website: text(255),
      status: { type: 'enum', values: CUSTOMER_STATUS.values, default: 'ACTIVE' },
      notes: text(),
    },
    refs: {},
  },
  {
    name: 'contacts',
    table: 'contacts',
    fields: {
      name: text(150, { required: true }),
      position: text(150),
      phone: text(100, { phone: true }),
      email: text(255, { lowercase: true }),
      whatsapp: text(100, { phone: true }),
      is_primary: { type: 'boolean', default: false },
      notes: text(),
    },
    refs: { customer_id: { target: 'customers', required: true } },
    async after(record, ctx) {
      // Only one active primary contact per customer (uq_contacts_one_primary).
      if (!record.values.is_primary) return;
      const taken = await ctx.query(
        `SELECT 1 FROM contacts WHERE customer_id = $1 AND is_primary AND is_active AND legacy_key IS DISTINCT FROM $2`,
        [record.values.customer_id, record.legacyKey],
      );
      if (taken.rowCount) {
        record.values.is_primary = false;
        ctx.issue(record, 'WARNING', 'MULTIPLE_PRIMARY_CONTACTS', 'Customer ini sudah memiliki kontak utama; kontak ini diimpor sebagai kontak biasa.');
      }
    },
  },
  {
    name: 'products',
    table: 'products',
    fields: {
      product_code: text(100),
      name: text(255, { required: true }),
      category: text(150),
      description: text(),
      unit: text(50),
      lead_time_days: { type: 'integer', min: 0 },
      status: { type: 'enum', values: PRODUCT_STATUS.values, default: 'ACTIVE' },
    },
    refs: { customer_id: { target: 'customers' } },
  },
  {
    name: 'leads',
    table: 'leads',
    fields: {
      name: text(255, { required: true }),
      source: text(100),
      estimated_value: { type: 'number', min: 0 },
      status: { type: 'enum', values: LEAD_STATUS.values, default: 'NEW' },
      priority: { type: 'enum', values: PRIORITY.values },
      expected_closing_date: { type: 'date' },
      notes: text(),
      lost_reason: text(),
    },
    refs: {
      customer_id: { target: 'customers', required: true },
      contact_id: { target: 'contacts' },
      product_id: { target: 'products' },
      owner_user_id: { target: 'users', severity: 'INFO' },
    },
  },
  {
    name: 'activities',
    table: 'activities',
    fields: {
      type: { type: 'enum', values: ACTIVITY_TYPE.values, default: 'OTHER' },
      subject: text(255),
      description: text(),
      activity_at: { type: 'datetime' },
      activity_date: { type: 'date', virtual: true },
      activity_time: { type: 'time', virtual: true },
    },
    refs: {
      customer_id: { target: 'customers', required: true },
      contact_id: { target: 'contacts' },
      lead_id: { target: 'leads' },
      owner_user_id: { target: 'users', severity: 'INFO' },
    },
    async after(record, ctx) {
      const v = record.values;
      if (!v.activity_at && v.activity_date) {
        v.activity_at = wallClockToInstant(v.activity_date, v.activity_time, ctx.timeZone);
      }
      if (!v.activity_at) {
        record.fatal = { type: 'MISSING_REQUIRED', message: 'Tanggal aktivitas kosong atau tidak valid.' };
        return;
      }
      if (!v.subject) {
        // Documented derivation: subject from the first line of the description, else the type label.
        const firstLine = v.description?.split(/\r?\n/)[0]?.trim();
        v.subject = firstLine ? firstLine.slice(0, 255) : ACTIVITY_TYPE.labels[v.type] ?? 'Aktivitas';
        ctx.issue(record, 'INFO', 'DERIVED_VALUE', `Judul aktivitas kosong; diisi "${v.subject}".`);
      }
    },
  },
  {
    name: 'follow_ups',
    table: 'follow_ups',
    fields: {
      follow_up_date: { type: 'date', required: true },
      follow_up_time: { type: 'time' },
      priority: { type: 'enum', values: PRIORITY.values },
      status: { type: 'enum', values: FOLLOW_UP_STATUS.values, default: 'PLANNED' },
      notes: text(),
    },
    refs: {
      customer_id: { target: 'customers', required: true },
      lead_id: { target: 'leads' },
      activity_id: { target: 'activities' },
      owner_user_id: { target: 'users', severity: 'INFO' },
    },
  },
  {
    name: 'purchase_orders',
    table: 'purchase_orders',
    fields: {
      po_number: text(150, { required: true }),
      po_date: { type: 'date' },
      expected_delivery_date: { type: 'date' },
      status: { type: 'enum', values: PO_STATUS.values, default: 'OPEN' },
      notes: text(),
    },
    refs: {
      customer_id: { target: 'customers', required: true },
      owner_user_id: { target: 'users', severity: 'INFO' },
    },
  },
  {
    name: 'po_lines',
    table: 'po_lines',
    fields: {
      line_no: { type: 'integer', min: 0 },
      item_name: text(255),
      order_quantity: { type: 'number', required: true, min: 0 },
      unit: text(50),
      unit_price: { type: 'number', min: 0 },
      notes: text(),
    },
    refs: {
      purchase_order_id: { target: 'purchase_orders', required: true },
      product_id: { target: 'products', fallbackToItemName: true },
    },
  },
  {
    name: 'deliveries',
    table: 'deliveries',
    fields: {
      item_name: text(255),
      delivery_date: { type: 'date' },
      quantity: { type: 'number', required: true, min: 0 },
      status: { type: 'enum', values: DELIVERY_STATUS.values, default: 'DELIVERED' },
      delivery_number: text(100),
      notes: text(),
    },
    refs: {
      purchase_order_id: { target: 'purchase_orders', required: true },
      product_id: { target: 'products', fallbackToItemName: true },
      po_line_id: { target: 'po_lines', unallocatedIsInfo: true },
    },
    after: alignLineAndProduct,
  },
  {
    name: 'returns',
    table: 'returns',
    fields: {
      item_name: text(255),
      return_date: { type: 'date' },
      quantity: { type: 'number', required: true, min: 0 },
      reason: text(),
      status: { type: 'enum', values: RETURN_STATUS.values, default: 'RECEIVED' },
      return_number: text(100),
      notes: text(),
    },
    refs: {
      customer_id: { target: 'customers' },
      purchase_order_id: { target: 'purchase_orders' },
      product_id: { target: 'products', fallbackToItemName: true },
      po_line_id: { target: 'po_lines', unallocatedIsInfo: true },
    },
    async after(record, ctx) {
      const v = record.values;
      const po = record.resolved.purchase_order_id;
      if (po) {
        if (!v.customer_id) v.customer_id = po.customer_id;
        else if (v.customer_id !== po.customer_id) {
          record.fatal = { type: 'REFERENCE_CONFLICT', message: 'Customer pada baris retur berbeda dengan customer pada PO.' };
          return;
        }
      }
      if (!v.customer_id) {
        record.fatal = { type: 'UNRESOLVED_REFERENCE', message: 'Customer retur tidak dapat ditentukan (dari kolom customer maupun PO).' };
        return;
      }
      await alignLineAndProduct(record, ctx);
    },
  },
  {
    name: 'stock',
    table: 'stock',
    fields: {
      item_name: text(255),
      stock_type: { type: 'enum', values: STOCK_TYPE.values },
      quantity: { type: 'number', min: 0 },
      warehouse: text(150),
      stock_date: { type: 'date' },
      notes: text(),
    },
    refs: { product_id: { target: 'products', fallbackToItemName: true } },
    // One source row may hold several stock types in separate columns
    // (mapping.quantityColumns = { FG: 'Stock FG', WIP: 'Stock WIP', ... }).
    expand: 'stockTypes',
    async after(record) {
      if (!record.values.stock_type) {
        record.fatal = { type: 'MISSING_REQUIRED', message: 'Tipe stok (FG/WIP/Ready/Reserved) kosong atau tidak dikenal.' };
      } else if (record.values.quantity === null || record.values.quantity === undefined) {
        record.fatal = { type: 'MISSING_REQUIRED', message: 'Qty stok kosong.' };
      }
    },
  },
  {
    name: 'leadtime',
    table: 'leadtime',
    fields: {
      item_name: text(255),
      lead_time_days: { type: 'integer', required: true, min: 0 },
      notes: text(),
    },
    refs: {
      product_id: { target: 'products', fallbackToItemName: true },
      customer_id: { target: 'customers' },
    },
    async after(record) {
      const v = record.values;
      if (!v.product_id && !v.customer_id && !v.item_name) {
        record.fatal = { type: 'MISSING_REQUIRED', message: 'Lead time tidak terkait produk maupun customer.' };
      }
    },
  },
  {
    name: 'inbound_maklon',
    table: 'inbound_maklon',
    fields: {
      item_name: text(255),
      inbound_date: { type: 'date' },
      quantity: { type: 'number', min: 0 },
      unit: text(50),
      document_number: text(100),
      notes: text(),
    },
    refs: {
      customer_id: { target: 'customers' },
      purchase_order_id: { target: 'purchase_orders' },
      product_id: { target: 'products', fallbackToItemName: true },
    },
  },
  {
    name: 'invoices_payments',
    table: 'invoices_payments',
    fields: {
      invoice_number: text(150),
      invoice_date: { type: 'date' },
      due_date: { type: 'date' },
      amount: { type: 'number', min: 0, default: 0 },
      paid_amount: { type: 'number', min: 0, default: 0 },
      payment_status: { type: 'enum', values: PAYMENT_STATUS.values },
      payment_date: { type: 'date' },
      notes: text(),
    },
    refs: { purchase_order_id: { target: 'purchase_orders', required: true } },
    async after(record, ctx) {
      // Payment status is derived from the amounts; a contradicting source status is reported.
      const v = record.values;
      const sourceStatus = v.payment_status;
      const derived = derivePaymentStatus({
        amount: v.amount,
        paidAmount: v.paid_amount,
        cancelled: sourceStatus === 'CANCELLED',
      });
      if (sourceStatus && sourceStatus !== derived) {
        ctx.issue(
          record,
          'WARNING',
          'INCONSISTENT_VALUE',
          `Status pembayaran di sumber "${sourceStatus}" tidak sesuai nilai (tagihan ${v.amount}, dibayar ${v.paid_amount}); disimpan sebagai "${derived}".`,
        );
      }
      v.payment_status = derived;
    },
  },
  {
    name: 'po_financials',
    table: 'po_financials',
    fields: {
      currency: text(10, { default: 'IDR' }),
      po_value: { type: 'number' },
      tax_amount: { type: 'number' },
      total_amount: { type: 'number' },
      invoiced_amount: { type: 'number' },
      paid_amount: { type: 'number' },
      outstanding_amount: { type: 'number' },
      notes: text(),
    },
    refs: { purchase_order_id: { target: 'purchase_orders', required: true } },
  },
];

/**
 * Keeps delivery/return line and product consistent:
 * - line known, product unknown  -> product taken from the line
 * - product known, line unknown  -> line matched by (PO, product) when exactly one exists
 * - both known but different     -> keep the line, report the mismatch
 */
async function alignLineAndProduct(record, ctx) {
  const v = record.values;
  const line = record.resolved.po_line_id;
  if (line) {
    if (!v.product_id && line.product_id) {
      v.product_id = line.product_id;
      v.item_name = null;
    } else if (v.product_id && line.product_id && v.product_id !== line.product_id) {
      ctx.issue(record, 'WARNING', 'REFERENCE_CONFLICT', 'Produk pada baris berbeda dengan produk pada item PO; produk item PO yang dipakai.');
      v.product_id = line.product_id;
    }
    return;
  }
  if (v.purchase_order_id && v.product_id) {
    const { rows } = await ctx.query(
      `SELECT id, product_id FROM po_lines WHERE purchase_order_id = $1 AND product_id = $2`,
      [v.purchase_order_id, v.product_id],
    );
    if (rows.length === 1) {
      v.po_line_id = rows[0].id;
    } else if (rows.length > 1) {
      ctx.issue(
        record,
        'WARNING',
        'AMBIGUOUS_REFERENCE',
        'PO memiliki lebih dari satu item dengan produk ini; baris tidak dialokasikan ke item tertentu.',
        rows.map((row) => row.id).join(', '),
      );
    }
  }
  if (!v.po_line_id && v.purchase_order_id) {
    ctx.issue(record, 'INFO', 'UNALLOCATED_TO_LINE', 'Tidak terhubung ke item PO; dihitung di tingkat PO sebagai "belum teralokasi".');
  }
}

export function entityByName(name) {
  return ENTITIES.find((entity) => entity.name === name);
}
