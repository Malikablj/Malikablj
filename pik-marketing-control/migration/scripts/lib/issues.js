'use strict';

/**
 * Catalogue of data issues detected while profiling, plus a collector that assigns
 * deterministic IDs so re-running the profiler yields the same issue IDs.
 *
 * Severity:
 *   BLOCKER  record cannot be imported under the target constraints without a decision/resolution
 *   HIGH     affects business figures (outstanding, dates, money) or leaves a relationship unresolved
 *   MEDIUM   data quality to review before or after import
 *   LOW      cosmetic or normalisation-only
 *   INFO     observation that shapes the design; nothing to fix per record
 *
 * decision refers to the decision register in docs/IMPLEMENTATION_PLAN.md.
 */

const crypto = require('node:crypto');

const SEVERITY_ORDER = ['BLOCKER', 'HIGH', 'MEDIUM', 'LOW', 'INFO'];

const ISSUE_TYPES = {
  // Workbook structure and lineage
  EMPTY_SHEET: { severity: 'INFO', title: 'Sheet target kosong (hanya header)' },
  COLUMN_ALWAYS_EMPTY: { severity: 'INFO', title: 'Kolom selalu kosong' },
  HEADER_TABLE_MISMATCH: { severity: 'HIGH', title: 'Header sheet berbeda dengan definisi Excel table' },
  CELLS_OUTSIDE_HEADER: { severity: 'MEDIUM', title: 'Ada nilai di luar kolom header' },
  ID_DUPLICATE: { severity: 'BLOCKER', title: 'ID duplikat' },
  ID_FORMAT_UNEXPECTED: { severity: 'LOW', title: 'Format ID tidak sesuai pola PREFIX-HEX' },
  FK_ORPHAN: { severity: 'BLOCKER', title: 'Referensi ID ke record yang tidak ada' },
  LINEAGE_MISSING: { severity: 'MEDIUM', title: 'Sheet tanpa lineage legacy (SourceFile/SourceSheet/LegacyRow)' },
  LINEAGE_NOT_UNIQUE: { severity: 'MEDIUM', title: 'Kombinasi SourceFile+SourceSheet+LegacyRow tidak unik' },
  SOURCE_FILE_NAME_VARIANT: { severity: 'LOW', title: 'Nama SourceFile terpotong/berbeda dari daftar README' },
  SOURCE_FILE_UNRECOGNISED: { severity: 'HIGH', title: 'SourceFile tidak dikenali di README' },
  EXISTING_ISSUES_WITHOUT_LEGACY_TEXT: {
    severity: 'HIGH',
    title: 'MIGRATION_ISSUES workbook tidak menyimpan teks PO/produk legacy',
    decision: 'D3',
  },
  EXISTING_ISSUES_OUT_OF_SYNC: { severity: 'HIGH', title: 'MIGRATION_ISSUES workbook tidak sinkron dengan DELIVERIES' },
  SUMMARY_INCONSISTENT: { severity: 'INFO', title: 'MIGRATION_SUMMARY tidak konsisten' },
  ENUM_MISMATCH: { severity: 'LOW', title: 'Nilai enum workbook berbeda dengan spesifikasi' },
  PO_REFERENCE_MISMATCH: { severity: 'HIGH', title: 'PONumberLegacy berbeda dengan nomor PO yang ditautkan' },
  DATE_AMBIGUOUS_DAY_MONTH: {
    severity: 'INFO',
    title: 'Tanggal ambigu hari/bulan tanpa bukti pembanding',
    decision: 'D6',
  },

  // Master data
  CUSTOMER_DUPLICATE_CANDIDATE: { severity: 'MEDIUM', title: 'Calon duplikat customer', decision: 'D8' },
  CUSTOMER_COMPOSITE_NAME: { severity: 'MEDIUM', title: 'Nama customer menggabungkan dua entitas', decision: 'D8' },
  CUSTOMER_STATUS_DEFAULTED: { severity: 'MEDIUM', title: 'Status customer hasil default impor', decision: 'D13' },
  PRODUCT_CODE_SHARED: { severity: 'MEDIUM', title: 'Satu kode produk dipakai beberapa record produk', decision: 'D8' },
  PRODUCT_DUPLICATE_EXACT: { severity: 'MEDIUM', title: 'Produk identik (nama+varian+kode) tercatat ganda', decision: 'D8' },
  PRODUCT_CODE_IN_NAME: { severity: 'LOW', title: 'Kode produk hanya ada di dalam nama' },

  // Purchase orders and lines
  PO_NUMBER_MISSING: { severity: 'BLOCKER', title: 'PO tanpa nomor', decision: 'D7' },
  PO_NUMBER_PLACEHOLDER: { severity: 'BLOCKER', title: 'Nomor PO berupa teks pengganti', decision: 'D7' },
  PO_NUMBER_WHITESPACE: { severity: 'LOW', title: 'Nomor PO mengandung spasi' },
  PO_DUPLICATE_NUMBER: { severity: 'BLOCKER', title: 'Nomor PO ganda untuk customer yang sama', decision: 'D7' },
  PO_NUMBER_SHARED_ACROSS_CUSTOMERS: { severity: 'MEDIUM', title: 'Nomor PO sama dipakai customer berbeda' },
  PO_CUSTOMER_MISSING: { severity: 'BLOCKER', title: 'PO tanpa customer', decision: 'D3' },
  PO_DATE_MISSING: { severity: 'MEDIUM', title: 'PO tanpa tanggal' },
  PO_STATUS_UNMAPPED: { severity: 'BLOCKER', title: 'Status PO tidak ada padanannya di enum target', decision: 'D4' },
  PO_PAYMENT_TERM_INVALID: { severity: 'MEDIUM', title: 'PaymentTerm berisi status, bukan termin' },
  PO_SPLIT_CANDIDATE: { severity: 'MEDIUM', title: 'Beberapa PO tanpa nomor mungkin satu PO', decision: 'D7' },
  PO_WITHOUT_LINES: { severity: 'HIGH', title: 'PO tanpa PO line' },
  PO_LINE_STATUS_MISMATCH: { severity: 'LOW', title: 'Status header PO berbeda dengan status line' },
  LINE_OVER_DELIVERED_LEGACY: { severity: 'MEDIUM', title: 'Outstanding legacy negatif (kirim melebihi order)' },
  LINE_LEGACY_FORMULA_MISMATCH: { severity: 'MEDIUM', title: 'Outstanding legacy tidak sesuai rumus' },
  LINE_RECONCILIATION_GAP: {
    severity: 'HIGH',
    title: 'Delivered legacy berbeda dengan jumlah delivery tertaut',
    decision: 'D3',
  },
  LINE_OVER_DELIVERED_COMPUTED: { severity: 'MEDIUM', title: 'Delivery tertaut melebihi qty order' },
  LINE_PRODUCT_NAME_DIFFERS: { severity: 'LOW', title: 'Nama produk legacy di line berbeda dengan master produk' },

  // Deliveries and returns
  DELIVERY_PO_UNRESOLVED: { severity: 'BLOCKER', title: 'Delivery tanpa PO (PO NOT FOUND)', decision: 'D3' },
  DELIVERY_PRODUCT_UNRESOLVED: { severity: 'BLOCKER', title: 'Delivery tanpa produk/line', decision: 'D3' },
  DELIVERY_LINE_UNRESOLVED: { severity: 'HIGH', title: 'Delivery dengan produk tetapi tanpa PO line', decision: 'D3' },
  DELIVERY_LINK_INCONSISTENT: { severity: 'BLOCKER', title: 'PO/produk delivery tidak sama dengan PO line-nya' },
  DELIVERY_EMPTY_ROW: { severity: 'MEDIUM', title: 'Baris delivery tanpa isi' },
  DELIVERY_NEGATIVE_QTY: { severity: 'HIGH', title: 'Qty delivery negatif (retur dicatat sebagai delivery)', decision: 'D9' },
  DELIVERY_SJ_MISSING: { severity: 'LOW', title: 'Delivery tanpa nomor SJ' },
  DELIVERY_DATE_MISSING: { severity: 'MEDIUM', title: 'Delivery tanpa tanggal' },
  DELIVERY_SJ_DATE_CONFLICT: { severity: 'MEDIUM', title: 'Satu nomor SJ memiliki beberapa tanggal' },
  DELIVERY_SJ_DESTINATION_CONFLICT: { severity: 'LOW', title: 'Satu nomor SJ memiliki beberapa tujuan' },
  DELIVERY_NOTE_REVIEW: { severity: 'LOW', title: 'Kolom Note berisi nomor dokumen' },
  DELIVERY_SJ_NUMBER_NONSTANDARD: { severity: 'LOW', title: 'Nomor SJ PIK-SJ tidak 5 digit' },
  DELIVERY_DUPLICATE_CANDIDATE: { severity: 'MEDIUM', title: 'Baris delivery identik (kemungkinan entri ganda)' },
  RETURN_PRODUCT_UNRESOLVED: { severity: 'BLOCKER', title: 'Retur tanpa produk', decision: 'D3' },
  RETURN_LINE_UNRESOLVED: { severity: 'MEDIUM', title: 'Retur belum tertaut ke PO line', decision: 'D3' },
  RETURN_DATE_MISSING: { severity: 'MEDIUM', title: 'Retur tanpa tanggal' },

  // Dates
  PO_DATE_SWAP_CANDIDATE: { severity: 'HIGH', title: 'Tanggal PO kemungkinan tertukar hari/bulan', decision: 'D6' },
  PO_DATE_NUMBER_MISMATCH: { severity: 'MEDIUM', title: 'Tanggal PO tidak cocok dengan bulan di nomor PO', decision: 'D6' },
  PO_DATE_FUTURE: { severity: 'HIGH', title: 'Tanggal PO setelah workbook dibuat', decision: 'D6' },
  DELIVERY_DATE_SWAP_CANDIDATE: {
    severity: 'HIGH',
    title: 'Tanggal delivery kemungkinan tertukar hari/bulan',
    decision: 'D6',
  },
  DELIVERY_DATE_OUT_OF_SEQUENCE: { severity: 'MEDIUM', title: 'Tanggal delivery tidak urut dengan nomor SJ', decision: 'D6' },
  DELIVERY_DATE_NUMBER_MISMATCH: {
    severity: 'MEDIUM',
    title: 'Tanggal delivery tidak cocok dengan bulan di nomor SJ',
    decision: 'D6',
  },
  DELIVERY_DATE_FUTURE: { severity: 'HIGH', title: 'Tanggal delivery setelah workbook dibuat', decision: 'D6' },
  INVOICE_DATE_SWAP_CANDIDATE: { severity: 'HIGH', title: 'Tanggal invoice kemungkinan tertukar hari/bulan', decision: 'D6' },
  INVOICE_DATE_NUMBER_MISMATCH: {
    severity: 'MEDIUM',
    title: 'Tanggal invoice tidak cocok dengan bulan di nomor invoice',
    decision: 'D6',
  },
  POF_DATE_DIFFERS_FROM_PO: { severity: 'MEDIUM', title: 'Tanggal PO di PO_FINANCIALS berbeda dengan PURCHASE_ORDERS', decision: 'D6' },

  // Stock, lead time, inbound to maklon
  STOCK_HEADER_ROW: { severity: 'MEDIUM', title: 'Baris header legacy ikut terimpor sebagai stok' },
  STOCK_PRODUCT_UNRESOLVED: { severity: 'BLOCKER', title: 'Stok tanpa produk', decision: 'D3' },
  STOCK_QTY_MISSING: { severity: 'MEDIUM', title: 'Stok tanpa quantity' },
  STOCK_QTY_INCONSISTENT: { severity: 'MEDIUM', title: 'Box x QtyPerBox tidak sama dengan Quantity' },
  STOCK_DATE_UNKNOWN: { severity: 'MEDIUM', title: 'Tanggal snapshot stok tidak diketahui' },
  LEADTIME_SUMMARY_ROW: { severity: 'MEDIUM', title: 'Baris total ikut terimpor sebagai data', decision: 'D10' },
  LEADTIME_PO_UNRESOLVED: { severity: 'MEDIUM', title: 'Jadwal lead time tanpa PO', decision: 'D10' },
  LEADTIME_PRODUCT_UNRESOLVED: { severity: 'MEDIUM', title: 'Jadwal lead time tanpa produk', decision: 'D10' },
  LEADTIME_SEMANTICS_MISMATCH: { severity: 'MEDIUM', title: 'Isi LEADTIME berbeda dengan skema target', decision: 'D10' },
  INBOUND_ROW_INCOMPLETE: { severity: 'MEDIUM', title: 'Inbound maklon tanpa kode komponen dan qty' },
  INBOUND_PO_UNRESOLVED: { severity: 'MEDIUM', title: 'Inbound maklon tidak cocok dengan PO' },
  INBOUND_COMPONENT_UNRESOLVED: { severity: 'MEDIUM', title: 'Kode komponen inbound tidak cocok dengan produk' },
  INBOUND_ATTACHMENT_NOT_FILE: { severity: 'LOW', title: 'Kolom Attachment berisi nomor SJ, bukan file' },

  // Invoices, payments and PO financial summary
  INVOICE_PO_UNRESOLVED: { severity: 'BLOCKER', title: 'Invoice tanpa PO', decision: 'D3' },
  INVOICE_PAYMENT_DATE_MISSING: { severity: 'MEDIUM', title: 'Pembayaran tanpa tanggal bayar', decision: 'D11' },
  INVOICE_PAYMENT_AMOUNT_MISSING: { severity: 'LOW', title: 'Invoice tanpa nilai pembayaran' },
  INVOICE_OUTSTANDING_INCONSISTENT: { severity: 'MEDIUM', title: 'InvoiceOutstanding tidak sama dengan Invoice - Payment' },
  INVOICE_PAYMENT_BEFORE_INVOICE: { severity: 'MEDIUM', title: 'Tanggal bayar sebelum tanggal invoice', decision: 'D6' },
  RECEIPT_NUMBER_FLOAT_FORMAT: { severity: 'LOW', title: 'Nomor bukti bayar tersimpan sebagai float (".0")' },
  POF_PO_UNRESOLVED: { severity: 'MEDIUM', title: 'Ringkasan finansial tanpa PO', decision: 'D11' },
  POF_UNIT_PRICE_SCALE: { severity: 'HIGH', title: 'UnitPrice dalam satuan ribuan rupiah', decision: 'D11' },
  POF_UNIT_PRICE_UNVERIFIABLE: { severity: 'MEDIUM', title: 'UnitPrice tidak bisa diverifikasi', decision: 'D11' },
  POF_TOTAL_INCONSISTENT: { severity: 'MEDIUM', title: 'TotalOrderAmount tidak sama dengan Qty x UnitPrice', decision: 'D11' },
  POF_PPN_INCONSISTENT: { severity: 'MEDIUM', title: 'PPN bukan 11%/12% dari total', decision: 'D11' },
  POF_TOTAL_INCL_INCONSISTENT: { severity: 'MEDIUM', title: 'TotalInclPPN tidak sama dengan Total + PPN', decision: 'D11' },
  POF_TOTAL_INCL_MISSING: { severity: 'MEDIUM', title: 'TotalInclPPN kosong/nol', decision: 'D11' },
  POF_UNDELIVERED_NEGATIVE: { severity: 'LOW', title: 'UndeliveredQuantity negatif' },
  POF_OUTSTANDING_SUSPECT: { severity: 'MEDIUM', title: 'Outstanding keuangan tidak wajar', decision: 'D11' },
  POF_STATUS_ROUNDING: { severity: 'LOW', title: 'BELUM LUNAS hanya karena selisih pembulatan', decision: 'D11' },
  POF_STATUS_INCONSISTENT: { severity: 'MEDIUM', title: 'Status LUNAS tetapi masih ada outstanding', decision: 'D11' },
};

// Workbook sheet -> target entity (logical schema in docs/MIGRATION_MAPPING.md).
const SHEET_ENTITY = {
  README: '(workbook)',
  CUSTOMERS: 'customers',
  CONTACTS: 'contacts',
  PRODUCTS: 'products',
  PURCHASE_ORDERS: 'purchase_orders',
  PO_LINES: 'po_lines',
  DELIVERIES: 'deliveries',
  RETURNS: 'returns',
  STOCK: 'stock',
  LEADTIME: 'leadtime',
  INBOUND_MAKLON: 'inbound_maklon',
  INVOICES_PAYMENTS: 'invoices_payments',
  PO_FINANCIALS: 'po_financials',
  LEADS: 'leads',
  ACTIVITIES: 'activities',
  FOLLOW_UP: 'follow_ups',
  USERS: 'users',
  MIGRATION_ISSUES: 'migration_issues',
  ENUMS: 'enums',
  APPSHEET_CONFIG: '(workbook)',
  APPSHEET_FORMULAS: '(workbook)',
  MIGRATION_SUMMARY: '(workbook)',
};

const ISSUE_COLUMNS = [
  'issue_id',
  'severity',
  'issue_type',
  'entity_type',
  'record_id',
  'field',
  'value',
  'description',
  'candidate_reference',
  'candidate_method',
  'evidence',
  'decision_ref',
  'existing_issue_id',
  'source_file',
  'source_sheet',
  'legacy_row',
  'workbook_sheet',
  'workbook_row',
  'resolution_status',
];

function createIssueCollector() {
  const issues = [];
  const seen = new Set();

  function add(type, fields) {
    const definition = ISSUE_TYPES[type];
    if (!definition) throw new Error(`Unknown issue type: ${type}`);
    const key = [type, fields.workbook_sheet || '', fields.record_id || '', fields.field || ''].join('|');
    if (seen.has(key)) return;
    seen.add(key);
    const hash = crypto.createHash('sha1').update(key).digest('hex').slice(0, 10).toUpperCase();
    const issue = Object.fromEntries(ISSUE_COLUMNS.map((column) => [column, '']));
    Object.assign(issue, fields, {
      issue_id: `PRF-${hash}`,
      severity: fields.severity || definition.severity,
      issue_type: type,
      entity_type: fields.entity_type || SHEET_ENTITY[fields.workbook_sheet] || '',
      decision_ref: fields.decision_ref || definition.decision || '',
      resolution_status: 'OPEN',
    });
    issues.push(issue);
  }

  function list() {
    const rank = (severity) => SEVERITY_ORDER.indexOf(severity);
    return [...issues].sort(
      (a, b) =>
        rank(a.severity) - rank(b.severity) ||
        a.issue_type.localeCompare(b.issue_type) ||
        String(a.workbook_sheet).localeCompare(String(b.workbook_sheet)) ||
        (Number(a.workbook_row) || 0) - (Number(b.workbook_row) || 0) ||
        String(a.field).localeCompare(String(b.field)),
    );
  }

  return { add, list };
}

module.exports = { ISSUE_COLUMNS, ISSUE_TYPES, SEVERITY_ORDER, SHEET_ENTITY, createIssueCollector };
