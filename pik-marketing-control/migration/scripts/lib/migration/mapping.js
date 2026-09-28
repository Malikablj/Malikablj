'use strict';

/**
 * Sheet-by-sheet mapping from PIK_Master_Database_AppSheet.xlsx to the database schema (docs/MIGRATION_MAPPING.md §5,
 * decisions D3–D14). Each entry lists every source column: either read by a field mapper (fields) or documented as not
 * migrated with a guard (ignore). The builder refuses a sheet whose columns are not all accounted for.
 */

const { normalizeCode } = require('../normalize');
const { findPoCandidates } = require('../matching');
const { bool, date, derived, documentNumber, enumOf, guard, id, money, number, original, read, ref, text } = require('./fields');

// Bump when a mapping rule changes (recorded in the package and the report). Records whose mapped values change get a
// new migration_hash and are updated on the next run; unchanged records are skipped.
const MAPPING_VERSION = '2026-09-28.2';

const CUSTOMER_STATUS = { Active: 'ACTIVE', Inactive: 'INACTIVE', Potential: 'POTENTIAL', Dormant: 'DORMANT' };
// Hold/On Hold -> ON_HOLD is the D4 default (reversible: the original text stays in status_legacy).
const PO_STATUS = {
  Open: 'OPEN', 'On Process': 'ON_PROCESS', 'On Proses': 'ON_PROCESS', Process: 'ON_PROCESS', Partial: 'PARTIAL',
  Closed: 'CLOSED', Cancel: 'CANCELLED', Cancelled: 'CANCELLED', Hold: 'ON_HOLD', 'On Hold': 'ON_HOLD',
};
const STOCK_TYPE = { FG: 'FG', WIP: 'WIP' };
const STOCK_STATUS = { Ready: 'READY', Reserved: 'RESERVED' };
const LEADTIME_STATUS = { Terkirim: 'DELIVERED', 'On Proses': 'SCHEDULED' };

// Source rows that are not business data (headers, totals, empty rows) are excluded from business tables. They are
// detected by the Phase 01 checks and each keeps its issue in MIGRATION_ISSUES with the full original row.
const EXCLUSION_ISSUE_TYPES = {
  STOCK_HEADER_ROW: 'baris header legacy, bukan data stok',
  LEADTIME_SUMMARY_ROW: 'baris total, bukan jadwal (nilainya dipakai sebagai checksum)',
  DELIVERY_EMPTY_ROW: 'baris tanpa tanggal, qty, SJ, dan tujuan',
};

const lineage = () => ({
  source_file: text('SourceFile'),
  source_sheet: text('SourceSheet'),
  legacy_row: number('LegacyRow', { integer: true }),
});

/** R-3: a legacy PO number that matches exactly one PO number character for character (after trim). */
function poByExactNumber(column) {
  return {
    sources: [column],
    derived: true, // a lookup, not a copy of its source cell
    map(row, ctx) {
      const raw = read(row, column);
      if (raw === null) return { value: null };
      const found = findPoCandidates(ctx.index, raw);
      if (found.method === 'po-number-exact' && found.pos.length === 1) {
        return { value: found.pos[0].POID, rule: 'R-3 nomor PO persis dan unik', from: raw };
      }
      return { value: null };
    },
  };
}

/** R-3: a component code that matches the code of exactly one product (brackets/spaces/case ignored). */
function productByExactCode(column) {
  return {
    sources: [column],
    derived: true, // a lookup, not a copy of its source cell
    map(row, ctx) {
      const raw = read(row, column);
      const code = normalizeCode(raw);
      if (!code) return { value: null };
      const products = ctx.index.productsByCode.get(code) || [];
      if (products.length === 1) return { value: products[0].ProductID, rule: 'R-3 kode komponen persis dan unik', from: raw };
      return { value: null };
    },
  };
}

const SHEET_MAPPINGS = [
  {
    sheet: 'CUSTOMERS',
    table: 'CUSTOMERS',
    idColumn: 'CustomerID',
    fields: {
      id: id('CustomerID'),
      name: text('CustomerName'),
      industry: text('Industry'),
      phone: text('Phone'),
      email: text('Email'),
      status: enumOf('CustomerStatus', CUSTOMER_STATUS, 'T-06 status customer (D13)'),
      notes: text('Notes'),
    },
    ignore: {
      Company: guard.sameAs('CustomerName', 'identik dengan CustomerName'),
      PIC: guard.empty('kosong; contact person akan dibuat di CONTACTS'),
      Source: guard.constant('Imported', 'konstan "Imported"; asal dicatat di import_ref'),
      MarketingPIC: guard.empty('USERS kosong; nama PIC tidak dipetakan ke user tanpa data user'),
      CreatedAt: guard.empty('kosong'),
    },
  },
  {
    sheet: 'PRODUCTS',
    table: 'PRODUCTS',
    idColumn: 'ProductID',
    fields: {
      id: id('ProductID'),
      product_code: text('ProductCode'),
      name: text('ProductName'),
      variant: text('Variant'),
      category: text('Category'),
      unit: text('Unit'),
      is_active: bool('Active'),
    },
    ignore: {
      ProductionCapacityPerDay: guard.empty('kosong'),
      Source: guard.constant('Imported', 'konstan "Imported"; asal dicatat di import_ref'),
    },
  },
  {
    sheet: 'PURCHASE_ORDERS',
    table: 'PURCHASE_ORDERS',
    idColumn: 'POID',
    fields: {
      id: id('POID'),
      po_number: {
        sources: ['PONumber'],
        map(row, ctx) {
          if (ctx.hasIssue('PO_NUMBER_PLACEHOLDER')) {
            return { value: null, rule: 'D7 teks pengganti nomor PO -> NULL (teks asli di po_number_legacy)', from: row.PONumber };
          }
          return text('PONumber').map(row);
        },
      },
      po_number_legacy: original('PONumber'),
      customer_id: ref('CustomerID', 'CUSTOMERS'),
      po_date: date('PODate'),
      status: enumOf('Status', PO_STATUS, 'T-06 status PO'),
      status_legacy: original('Status'),
      payment_term: text('PaymentTerm'),
      notes: text('Remark'),
      ...lineage(),
    },
    ignore: {},
  },
  {
    sheet: 'PO_LINES',
    table: 'PO_LINES',
    idColumn: 'POLineID',
    fields: {
      id: id('POLineID'),
      purchase_order_id: ref('POID', 'PURCHASE_ORDERS'),
      product_id: ref('ProductID', 'PRODUCTS'),
      order_quantity: number('OrderQuantity'),
      notes: text('Remark'),
      product_name_legacy: original('ProductNameLegacy'),
      variant_legacy: original('VariantLegacy'),
      delivered_qty_legacy: number('DeliveredQuantitySource'),
      returned_qty_legacy: number('ReturnQuantitySource'),
      outstanding_qty_legacy: number('OutstandingSource'),
      status_legacy: original('StatusSource'),
      ...lineage(),
    },
    ignore: { CapacityPerDay: guard.empty('kosong') },
  },
  {
    sheet: 'DELIVERIES',
    table: 'DELIVERIES',
    idColumn: 'DeliveryID',
    fields: {
      id: id('DeliveryID'),
      purchase_order_id: ref('POID', 'PURCHASE_ORDERS'),
      po_line_id: ref('POLineID', 'PO_LINES'),
      product_id: ref('ProductID', 'PRODUCTS'),
      delivery_date: date('DeliveryDate'),
      quantity: number('DeliveredQuantity'),
      status: derived(['DeliveredQuantity'],
        'status DELIVERED: baris sumber adalah pengiriman ber-SJ yang sudah terjadi (qty > 0); qty <= 0 tanpa status (D9)',
        (row) => (typeof row.DeliveredQuantity === 'number' && row.DeliveredQuantity > 0 ? 'DELIVERED' : null)),
      sj_number: text('SJNumber'),
      destination: text('Destination'),
      attachment_url: text('Attachment'),
      notes: text('Note'),
      ...lineage(),
    },
    ignore: {
      MigrationFlag: guard.keptElsewhere(
        'flag konversi AppSheet; setiap flag selain OK diwakili isu DELIVERY_* dengan existing_issue_id',
        (value, row, ctx) => value === null || value === 'OK' || ctx.hasIssueWithExistingId(),
      ),
    },
  },
  {
    sheet: 'RETURNS',
    table: 'RETURNS',
    idColumn: 'ReturnID',
    fields: {
      id: id('ReturnID'),
      purchase_order_id: ref('POID', 'PURCHASE_ORDERS'),
      product_id: ref('ProductID', 'PRODUCTS'),
      return_date: date('ReturnDate'),
      quantity: number('ReturnQuantity'),
      sj_number: text('SJNumber'),
      destination: text('Destination'),
      attachment_url: text('Attachment'),
      notes: text('Note'),
      po_number_legacy: original('PONumberLegacy'),
      product_legacy: original('ProductLegacy'),
      ...lineage(),
    },
    ignore: {},
  },
  {
    sheet: 'STOCK',
    table: 'STOCK',
    idColumn: 'StockID',
    fields: {
      id: id('StockID'),
      product_id: ref('ProductID', 'PRODUCTS'),
      stock_type: enumOf('StockType', STOCK_TYPE, 'T-06 jenis stok'),
      status: enumOf('Status', STOCK_STATUS, 'T-06 status stok (Ready -> READY)'),
      quantity: number('Quantity'),
      box_count: number('Box'),
      qty_per_box: number('QtyPerBox'),
      product_legacy: original('ProductLegacy'),
      status_legacy: original('Status'),
      ...lineage(),
    },
    ignore: {},
  },
  {
    sheet: 'LEADTIME',
    table: 'LEADTIME',
    idColumn: 'LeadTimeID',
    fields: {
      id: id('LeadTimeID'),
      purchase_order_id: ref('POID', 'PURCHASE_ORDERS'),
      product_id: ref('ProductID', 'PRODUCTS'),
      planned_date: date('DeliveryDate'),
      quantity: number('Quantity'),
      status: enumOf('Status', LEADTIME_STATUS, 'T-06 status jadwal (D10)'),
      status_legacy: original('Status'),
      po_number_legacy: original('PONumberLegacy'),
      product_legacy: original('ProductLegacy'),
      ...lineage(),
    },
    ignore: {},
  },
  {
    sheet: 'INBOUND_MAKLON',
    table: 'INBOUND_MAKLON',
    idColumn: 'InboundID',
    fields: {
      id: id('InboundID'),
      purchase_order_id: poByExactNumber('PONumberLegacy'),
      product_id: productByExactCode('FactoryComponentCode'),
      vendor: text('Vendor'),
      receiver: text('Receiver'),
      inbound_date: date('ActualInboundDate'),
      sj_date: date('SJDate'),
      sj_number: text('SJNumber'),
      internal_component_code: text('InternalComponentCode'),
      component_type: text('Type'),
      component_name: text('ComponentName'),
      factory_component_code: text('FactoryComponentCode'),
      quantity: number('Quantity'),
      reject_quantity: number('RejectQuantity'),
      total_in: number('TotalIn'),
      attachment: text('Attachment'),
      odoo_checklist: text('OdooChecklist'),
      notes: text('Notes'),
      po_number_legacy: original('PONumberLegacy'),
      ...lineage(),
    },
    ignore: {},
  },
  {
    sheet: 'INVOICES_PAYMENTS',
    table: 'INVOICES_PAYMENTS',
    idColumn: 'InvoicePaymentID',
    fields: {
      id: id('InvoicePaymentID'),
      purchase_order_id: ref('POID', 'PURCHASE_ORDERS'),
      invoice_number: text('InvoiceNumber'),
      invoice_type: derived(['InvoiceNumber'], 'jenis invoice dari segmen ke-4 nomor (PIK/<bulan>/<tahun>/<jenis>/<nomor>)',
        (row) => {
          const segment = String(row.InvoiceNumber || '').split('/')[3];
          const type = segment ? segment.trim().toUpperCase() : '';
          return type === 'INV' || type === 'TUM' ? type : null;
        }),
      invoice_date: date('InvoiceDate'),
      amount: money('InvoiceAmount'),
      paid_amount: money('PaymentAmount'),
      payment_date: date('PaymentDate'),
      payment_receipt_number: documentNumber('PaymentReceiptNumber'),
      payment_status: derived(['InvoiceOutstanding', 'PaymentAmount'],
        'status bayar dari InvoiceOutstanding dan PaymentAmount (D11): 0 -> PAID; >0 dengan pembayaran -> PARTIAL; >0 tanpa pembayaran -> UNPAID',
        (row, ctx) => {
          const outstanding = row.InvoiceOutstanding;
          if (typeof outstanding !== 'number') {
            ctx.issue('VALUE_NOT_NUMERIC', { field: 'InvoiceOutstanding', value: outstanding, description: 'Status bayar tidak dapat diturunkan.' });
            return null;
          }
          if (outstanding <= 0) return 'PAID';
          return typeof row.PaymentAmount === 'number' && row.PaymentAmount > 0 ? 'PARTIAL' : 'UNPAID';
        }),
      invoice_attachment_url: text('InvoiceAttachment'),
      payment_attachment_url: text('PaymentAttachment'),
      po_number_legacy: original('PONumberLegacy'),
      outstanding_legacy: money('InvoiceOutstanding'),
      ...lineage(),
    },
    ignore: {
      CumulativeOutstanding: guard.keptElsewhere('akumulasi outstanding per PO; nilai turunan yang dapat dihitung ulang dari invoice'),
    },
  },
  {
    sheet: 'PO_FINANCIALS',
    table: 'PO_FINANCIALS',
    idColumn: 'POFinancialID',
    fields: {
      id: id('POFinancialID'),
      purchase_order_id: ref('POID', 'PURCHASE_ORDERS'),
      brand: text('Brand'),
      po_date: date('PODate'),
      order_quantity: number('OrderQuantity'),
      total_order_amount: money('TotalOrderAmount'),
      ppn_amount: money('PPN'),
      total_incl_ppn: money('TotalInclPPN'),
      // D11: the label says paid or not; the legacy amounts tell UNPAID from PARTIAL ("BELUM LUNAS" covers both).
      payment_status: derived(['Status', 'Outstanding', 'TotalInclPPN'],
        'status bayar ringkasan PO (D11): LUNAS -> PAID; BELUM LUNAS -> UNPAID bila Outstanding >= TotalInclPPN, ' +
          'PARTIAL bila 0 < Outstanding < TotalInclPPN',
        (row, ctx) => {
          const label = row.Status === null || row.Status === undefined ? '' : String(row.Status).trim().toUpperCase();
          if (label === '') return null;
          if (label === 'LUNAS') return 'PAID';
          if (label === 'BELUM LUNAS' && typeof row.Outstanding === 'number' && typeof row.TotalInclPPN === 'number' &&
            row.TotalInclPPN > 0 && row.Outstanding > 0) {
            return row.Outstanding >= row.TotalInclPPN ? 'UNPAID' : 'PARTIAL';
          }
          ctx.issue('VALUE_NOT_MAPPED', {
            field: 'Status',
            value: row.Status,
            description: label === 'BELUM LUNAS'
              ? 'BELUM LUNAS tetapi Outstanding/TotalInclPPN tidak menunjukkan UNPAID atau PARTIAL; payment_status dikosongkan.'
              : `Nilai "${row.Status}" pada Status tidak ada di tabel pemetaan; payment_status dikosongkan.`,
          });
          return null;
        }),
      attachment_url: text('POAttachment'),
      po_number_legacy: original('PONumberLegacy'),
      product_legacy: original('ProductLegacy'),
      product_code_legacy: original('ProductCodeLegacy'),
      unit_price_legacy: number('UnitPrice'),
      delivered_qty_legacy: number('DeliveredQuantity'),
      undelivered_qty_legacy: number('UndeliveredQuantity'),
      outstanding_amount_legacy: number('Outstanding'),
      status_legacy: original('Status'),
      ...lineage(),
    },
    ignore: {},
  },
];

// Template sheets of the workbook that hold no rows. A row appearing here would have no mapping: it is reported.
const EMPTY_TEMPLATE_SHEETS = ['CONTACTS', 'LEADS', 'ACTIVITIES', 'FOLLOW_UP', 'USERS'];

// Documentation sheets of the workbook (MIGRATION_MAPPING §5.17): read by the profiler, not migrated as data.
const DOCUMENTATION_SHEETS = {
  README: 'dokumentasi workbook (dibaca profiler untuk daftar file sumber)',
  APPSHEET_CONFIG: 'konfigurasi AppSheet (dokumentasi)',
  APPSHEET_FORMULAS: 'rumus AppSheet; logikanya diimplementasikan ulang sebagai aturan bisnis',
  MIGRATION_SUMMARY: 'ringkasan konversi AppSheet (dokumentasi)',
};

// Workbook ENUMS (AppSheet names, Title Case values) -> database enum (UPPER_SNAKE). null = intentionally not stored.
const ENUM_NAME_MAP = {
  CustomerStatus: 'CUSTOMER_STATUS',
  LeadStatus: 'LEAD_STATUS',
  Priority: 'PRIORITY',
  ActivityType: 'ACTIVITY_TYPE',
  POStatus: 'PO_STATUS',
  FollowUpStatus: 'FOLLOW_UP_STATUS',
  UserRole: 'USER_ROLE',
  StockType: 'STOCK_TYPE',
};
const ENUM_VALUE_EXCEPTIONS = {
  'FollowUpStatus|Overdue': { target: null, reason: 'OVERDUE dihitung dari tanggal dan status, tidak disimpan (D14)' },
  'StockType|Ready': { target: ['STOCK_STATUS', 'READY'], reason: 'di data dipakai sebagai status stok (STOCK_STATUS)' },
  'StockType|Reserved': { target: ['STOCK_STATUS', 'RESERVED'], reason: 'status stok (STOCK_STATUS)' },
};

// Business decisions this mapping depends on (docs/IMPLEMENTATION_PLAN.md §2). OPEN: the recommended default is applied
// and every affected record stays traceable, but the owner has not confirmed it yet.
const MAPPING_DECISIONS = [
  {
    id: 'D3', status: 'OPEN', topic: 'Record legacy dengan relasi wajib kosong + outstanding PO legacy',
    applied: 'A — diimpor sebagai legacy (is_legacy = TRUE) dengan relasi kosong dan isu penjelas; outstanding aplikasi ' +
      'dihitung dari transaksi tertaut; nilai legacy disimpan di kolom *_legacy sebagai pembanding.',
    alternatives: 'B — outstanding PO legacy memakai nilai legacy sebagai saldo awal (cut-off): data migrasi tidak berubah, ' +
      'hanya perhitungan di modul PO (Phase 04). C — record berelasi kosong tidak masuk tabel bisnis: pemetaan mengecualikannya ' +
      '(record yang sudah termigrasi diarsipkan), datanya tetap di MIGRATION_ISSUES.',
  },
  {
    id: 'D4', status: 'OPEN', topic: 'Status PO "Hold" / "On Hold"',
    applied: 'A — dipetakan ke ON_HOLD; teks asli tetap di status_legacy.',
    alternatives: 'B — dipetakan ke ON_PROCESS (informasi "ditahan" hilang; teks asli tetap di status_legacy).',
  },
  { id: 'D6', status: 'DEFAULT', topic: 'Tanggal hari/bulan tertukar', applied: 'tidak dikoreksi; kandidat dicatat sebagai isu.' },
  { id: 'D7', status: 'DEFAULT', topic: 'Nomor PO kosong/pengganti/ganda', applied: 'diimpor apa adanya; teks pengganti -> po_number kosong; tidak digabung.' },
  { id: 'D8', status: 'DEFAULT', topic: 'Duplikat customer/produk', applied: 'tidak digabung otomatis.' },
  { id: 'D9', status: 'DEFAULT', topic: 'Delivery qty negatif', applied: 'diimpor apa adanya tanpa status; ditandai isu.' },
  { id: 'D10', status: 'DEFAULT', topic: 'Makna LEADTIME', applied: 'jadwal pengiriman terencana; status asli di status_legacy.' },
  { id: 'D11', status: 'DEFAULT', topic: 'Keuangan legacy', applied: 'referensi; status bayar diturunkan dari outstanding legacy.' },
  { id: 'D12', status: 'DEFAULT', topic: 'ID', applied: 'ID workbook dipakai apa adanya (stabil, bukan nomor baris).' },
  { id: 'D13', status: 'DEFAULT', topic: 'Status customer', applied: 'ACTIVE diimpor apa adanya + isu penanda.' },
  { id: 'D14', status: 'DEFAULT', topic: 'Follow-up OVERDUE', applied: 'tidak disimpan sebagai nilai enum (dihitung).' },
];

/** 'Phone Call' -> 'PHONE_CALL', 'WhatsApp' -> 'WHATSAPP'. */
function toUpperSnake(value) {
  return String(value).trim().replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '').toUpperCase();
}

module.exports = {
  DOCUMENTATION_SHEETS,
  EMPTY_TEMPLATE_SHEETS,
  ENUM_NAME_MAP,
  ENUM_VALUE_EXCEPTIONS,
  EXCLUSION_ISSUE_TYPES,
  MAPPING_DECISIONS,
  MAPPING_VERSION,
  SHEET_MAPPINGS,
  toUpperSnake,
};
