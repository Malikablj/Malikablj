'use strict';

/**
 * Synthetic workbook for the migration tests: the sheets and headers of the AppSheet export
 * (PIK_Master_Database_AppSheet.xlsx) filled with invented data only — no PIK business data. writeWorkbook() produces a
 * minimal valid .xlsx (uncompressed zip, inline strings, one date style) that the read-only reader parses like the real
 * file, so the whole pipeline (profile, checks, mapping, load, verification) runs on it.
 *
 * The rows cover the cases the migration must handle: a PO status without a spec value (Hold), a PO without customer,
 * a placeholder and a duplicate PO number, deliveries without PO / product / line, a negative quantity, an empty row, an
 * unknown (orphan) ID, a delivery above the ordered quantity, stock header and lead-time total rows, exact and ambiguous natural keys (R-3), invoices paid /
 * unpaid / partly paid, PO summaries LUNAS / BELUM LUNAS, whitespace to trim or to keep, money float artefacts, a float
 * receipt number, workbook issues, enum values and a template sheet that unexpectedly holds a row.
 */

const fs = require('node:fs');

const HEADERS = {
  README: ['PIK MASTER DATABASE — APPSHEET (DATA UJI)', null],
  CUSTOMERS: ['CustomerID', 'CustomerName', 'Company', 'PIC', 'Phone', 'Email', 'Industry', 'CustomerStatus', 'Source',
    'MarketingPIC', 'Notes', 'CreatedAt'],
  CONTACTS: ['ContactID', 'CustomerID', 'ContactName', 'Position', 'Phone', 'Email', 'IsPrimary', 'Notes'],
  PRODUCTS: ['ProductID', 'ProductName', 'ProductCode', 'Variant', 'Category', 'ProductionCapacityPerDay', 'Unit', 'Active',
    'Source'],
  PURCHASE_ORDERS: ['POID', 'PONumber', 'CustomerID', 'PODate', 'PaymentTerm', 'Status', 'Remark', 'SourceFile', 'SourceSheet',
    'LegacyRow'],
  PO_LINES: ['POLineID', 'POID', 'ProductID', 'ProductNameLegacy', 'VariantLegacy', 'OrderQuantity', 'DeliveredQuantitySource',
    'ReturnQuantitySource', 'OutstandingSource', 'StatusSource', 'CapacityPerDay', 'Remark', 'SourceFile', 'SourceSheet', 'LegacyRow'],
  DELIVERIES: ['DeliveryID', 'POID', 'POLineID', 'ProductID', 'DeliveryDate', 'SJNumber', 'Destination', 'DeliveredQuantity', 'Note',
    'Attachment', 'SourceFile', 'SourceSheet', 'LegacyRow', 'MigrationFlag'],
  RETURNS: ['ReturnID', 'POID', 'ProductID', 'PONumberLegacy', 'ProductLegacy', 'ReturnDate', 'SJNumber', 'Destination',
    'ReturnQuantity', 'Attachment', 'Note', 'SourceFile', 'SourceSheet', 'LegacyRow'],
  STOCK: ['StockID', 'ProductID', 'ProductLegacy', 'StockType', 'Quantity', 'Box', 'QtyPerBox', 'Status', 'SourceFile',
    'SourceSheet', 'LegacyRow'],
  LEADTIME: ['LeadTimeID', 'POID', 'ProductID', 'PONumberLegacy', 'ProductLegacy', 'Quantity', 'DeliveryDate', 'Status',
    'SourceFile', 'SourceSheet', 'LegacyRow'],
  INBOUND_MAKLON: ['InboundID', 'Vendor', 'Receiver', 'ActualInboundDate', 'SJDate', 'SJNumber', 'PONumberLegacy',
    'InternalComponentCode', 'Type', 'ComponentName', 'FactoryComponentCode', 'Quantity', 'RejectQuantity', 'TotalIn',
    'Attachment', 'Notes', 'OdooChecklist', 'SourceFile', 'SourceSheet', 'LegacyRow'],
  INVOICES_PAYMENTS: ['InvoicePaymentID', 'POID', 'PONumberLegacy', 'InvoiceDate', 'InvoiceNumber', 'InvoiceAmount',
    'PaymentAmount', 'PaymentDate', 'PaymentReceiptNumber', 'InvoiceOutstanding', 'CumulativeOutstanding', 'InvoiceAttachment',
    'PaymentAttachment', 'SourceFile', 'SourceSheet', 'LegacyRow'],
  PO_FINANCIALS: ['POFinancialID', 'POID', 'PONumberLegacy', 'Brand', 'PODate', 'ProductLegacy', 'ProductCodeLegacy',
    'OrderQuantity', 'UnitPrice', 'TotalOrderAmount', 'PPN', 'TotalInclPPN', 'DeliveredQuantity', 'UndeliveredQuantity',
    'Outstanding', 'Status', 'POAttachment', 'SourceFile', 'SourceSheet', 'LegacyRow'],
  LEADS: ['LeadID', 'DateCreated', 'CustomerID', 'CompanyName', 'PIC', 'Phone', 'Email', 'Source', 'ProductInterest',
    'EstimatedQty', 'EstimatedValue', 'LeadStatus', 'Priority', 'MarketingPIC', 'NextFollowUp', 'LastContact', 'Notes'],
  ACTIVITIES: ['ActivityID', 'CustomerID', 'LeadID', 'ActivityDate', 'ActivityType', 'PIC', 'Subject', 'Description',
    'NextAction', 'NextFollowUp', 'Attachment', 'CreatedBy'],
  FOLLOW_UP: ['FollowUpID', 'CustomerID', 'LeadID', 'PIC', 'FollowUpDate', 'FollowUpType', 'Purpose', 'Status', 'Result',
    'NextFollowUp', 'Reminder', 'Notes'],
  USERS: ['UserID', 'Name', 'Email', 'Role', 'Active'],
  MIGRATION_ISSUES: ['IssueID', 'TableName', 'RecordID', 'IssueType', 'LegacyPO', 'LegacyProduct', 'Description', 'SourceFile',
    'SourceSheet', 'LegacyRow', 'ResolutionStatus'],
  ENUMS: ['EnumName', 'Value'],
  APPSHEET_CONFIG: ['Table', 'Column', 'Setting', 'Primary', 'Recommendation', 'Reference'],
  APPSHEET_FORMULAS: ['Table/View', 'Column/Metric', 'AppSheetExpression', 'Use'],
  MIGRATION_SUMMARY: ['Source', 'Scope', 'Rows'],
};

const SOURCE_A = 'Sumber Uji A.xlsx';
const SOURCE_B = 'Sumber Uji B.xlsx';

/** A date cell (stored as an Excel serial with a date format, like the real workbook). */
const date = (iso) => ({ date: iso });
const lineage = (file, sheet, row) => ({ SourceFile: file, SourceSheet: sheet, LegacyRow: row });

/** IDs used by the synthetic rows, named for readability in the tests. */
const ID = {
  customer: { one: 'CUS-00000000A1', two: 'CUS-00000000A2', three: 'CUS-00000000A3' },
  product: { bottle: 'PRD-00000000B1', jar: 'PRD-00000000B2', tube: 'PRD-00000000B3', tubeTwin: 'PRD-00000000B4' },
  po: { open: 'PO-00000000C1', closed: 'PO-00000000C2', hold: 'PO-00000000C3', placeholder: 'PO-00000000C4',
    cancelled: 'PO-00000000C5', duplicate: 'PO-00000000C6' },
  line: { openBottle: 'POL-00000000D1', closedJar: 'POL-00000000D2', holdTube: 'POL-00000000D3',
    placeholderBottle: 'POL-00000000D4', duplicateJar: 'POL-00000000D5', openJar: 'POL-00000000D6' },
  delivery: { linked: 'DEL-00000000E1', closed: 'DEL-00000000E2', withoutPo: 'DEL-00000000E3', withoutProduct: 'DEL-00000000E4',
    negative: 'DEL-00000000E5', empty: 'DEL-00000000E6', orphan: 'DEL-00000000E7', overDelivered: 'DEL-00000000E8' },
  ret: { linked: 'RET-00000000F1', withoutProduct: 'RET-00000000F2' },
  stock: { ready: 'STK-00000000A1', withoutProduct: 'STK-00000000A2', header: 'STK-00000000A3' },
  leadtime: { planned: 'LT-00000000A1', total: 'LT-00000000A2' },
  inbound: { exact: 'INB-00000000A1', ambiguous: 'INB-00000000A2', incomplete: 'INB-00000000A3' },
  invoice: { paid: 'PAY-00000000A1', unpaid: 'PAY-00000000A2', partial: 'PAY-00000000A3' },
  financial: { paid: 'POF-00000000A1', unpaid: 'POF-00000000A2', partial: 'POF-00000000A3' },
  issue: { withoutPo: 'ISS-00000000A1', withoutProduct: 'ISS-00000000A2' },
  contact: 'CON-00000000A1',
};

function syntheticSheets() {
  const c = ID.customer;
  const p = ID.product;
  const po = ID.po;
  const l = ID.line;
  const d = ID.delivery;
  return {
    README: [
      { key: 'Sources', value: `${SOURCE_A}; ${SOURCE_B}` },
      { key: 'Catatan', value: 'Workbook sintetis untuk pengujian; semua data karangan.' },
    ],
    CUSTOMERS: [
      { CustomerID: c.one, CustomerName: 'PT Contoh Satu', Company: 'PT Contoh Satu', CustomerStatus: 'Active', Source: 'Imported',
        Notes: '  Catatan dengan spasi  ' },
      { CustomerID: c.two, CustomerName: 'CV Contoh Dua', Company: 'CV Contoh Dua', CustomerStatus: 'Potential', Source: 'Imported' },
      { CustomerID: c.three, CustomerName: 'PT Contoh Tiga', Company: 'PT Contoh Tiga', CustomerStatus: 'Active', Source: 'Imported' },
    ],
    CONTACTS: [
      { ContactID: ID.contact, CustomerID: c.one, ContactName: 'Kontak Uji', Position: 'Purchasing', IsPrimary: true },
    ],
    PRODUCTS: [
      { ProductID: p.bottle, ProductName: 'Botol Uji 30ml', ProductCode: '[TSTBTL30]', Variant: 'Varian A', Unit: 'pcs', Active: true,
        Source: 'Imported' },
      { ProductID: p.jar, ProductName: 'Jar Uji 50gr', ProductCode: '[TSTJAR50]', Unit: 'pcs', Active: true, Source: 'Imported' },
      { ProductID: p.tube, ProductName: 'Tube Uji 100ml', ProductCode: '[TSTTUB10]', Unit: 'pcs', Active: true, Source: 'Imported' },
      { ProductID: p.tubeTwin, ProductName: 'Tube Uji 100ml Kembar', ProductCode: '[TSTTUB10]', Unit: 'pcs', Active: false,
        Source: 'Imported' },
    ],
    PURCHASE_ORDERS: [
      { POID: po.open, PONumber: 'PO/UJI/001', CustomerID: c.one, PODate: date('2025-01-10'), PaymentTerm: '30 hari',
        Status: 'On Process', ...lineage(SOURCE_A, 'PO', 5) },
      { POID: po.closed, PONumber: 'PO/UJI/002', CustomerID: c.two, PODate: date('2025-02-01'), Status: 'Closed',
        ...lineage(SOURCE_A, 'PO', 6) },
      { POID: po.hold, PONumber: 'PO/UJI/003', CustomerID: null, PODate: date('2025-03-05'), Status: 'Hold',
        ...lineage(SOURCE_A, 'PO', 7) },
      { POID: po.placeholder, PONumber: 'Tanpa Nomor', CustomerID: c.three, PODate: date('2025-04-01'), Status: 'Open',
        ...lineage(SOURCE_B, 'PO', 3) },
      { POID: po.cancelled, PONumber: 'PO/UJI/005', CustomerID: c.one, PODate: date('2025-04-15'), Status: 'cancel',
        ...lineage(SOURCE_B, 'PO', 4) },
      { POID: po.duplicate, PONumber: 'PO/UJI/005', CustomerID: c.one, PODate: date('2025-04-20'), Status: 'Open',
        ...lineage(SOURCE_B, 'PO', 5) },
    ],
    PO_LINES: [
      { POLineID: l.openBottle, POID: po.open, ProductID: p.bottle, ProductNameLegacy: ' Botol  Uji 30ml ', VariantLegacy: 'Varian A',
        OrderQuantity: 1000, DeliveredQuantitySource: 600, ReturnQuantitySource: 0, OutstandingSource: 400, StatusSource: 'On Process',
        ...lineage(SOURCE_A, 'PO', 5) },
      { POLineID: l.openJar, POID: po.open, ProductID: p.jar, ProductNameLegacy: 'Jar Uji 50gr', OrderQuantity: 50,
        DeliveredQuantitySource: 0, ReturnQuantitySource: 0, OutstandingSource: 50, StatusSource: 'On Process', ...lineage(SOURCE_A, 'PO', 6) },
      { POLineID: l.closedJar, POID: po.closed, ProductID: p.jar, ProductNameLegacy: 'Jar Uji 50gr', OrderQuantity: 500,
        DeliveredQuantitySource: 480, ReturnQuantitySource: 20, OutstandingSource: 40, StatusSource: 'Closed', ...lineage(SOURCE_A, 'PO', 7) },
      { POLineID: l.holdTube, POID: po.hold, ProductID: p.tube, ProductNameLegacy: 'Tube Uji 100ml', OrderQuantity: 200,
        DeliveredQuantitySource: 0, ReturnQuantitySource: 0, OutstandingSource: 200, StatusSource: 'Hold', ...lineage(SOURCE_A, 'PO', 8) },
      { POLineID: l.placeholderBottle, POID: po.placeholder, ProductID: p.bottle, ProductNameLegacy: 'Botol Uji 30ml',
        OrderQuantity: 100, OutstandingSource: 100, StatusSource: 'Open', ...lineage(SOURCE_B, 'PO', 3) },
      { POLineID: l.duplicateJar, POID: po.duplicate, ProductID: p.jar, ProductNameLegacy: 'Jar Uji 50gr', OrderQuantity: 300,
        OutstandingSource: 300, StatusSource: 'Open', ...lineage(SOURCE_B, 'PO', 5) },
    ],
    DELIVERIES: [
      { DeliveryID: d.linked, POID: po.open, POLineID: l.openBottle, ProductID: p.bottle, DeliveryDate: date('2025-01-20'),
        SJNumber: 'SJ/UJI/001', Destination: 'Gudang A', DeliveredQuantity: 600, ...lineage(SOURCE_A, 'Delivery', 10), MigrationFlag: 'OK' },
      { DeliveryID: d.closed, POID: po.closed, POLineID: l.closedJar, ProductID: p.jar, DeliveryDate: date('2025-02-10'),
        SJNumber: 'SJ/UJI/002', Destination: 'Gudang B', DeliveredQuantity: 500, ...lineage(SOURCE_A, 'Delivery', 11), MigrationFlag: 'OK' },
      { DeliveryID: d.withoutPo, DeliveryDate: date('2025-03-01'), SJNumber: 'SJ/UJI/003', Destination: 'Gudang A',
        DeliveredQuantity: 150, ...lineage(SOURCE_A, 'Delivery', 12), MigrationFlag: 'PO_NOT_FOUND' },
      { DeliveryID: d.withoutProduct, POID: po.open, DeliveryDate: date('2025-01-25'), SJNumber: 'SJ/UJI/004', Destination: 'Gudang A',
        DeliveredQuantity: 50, ...lineage(SOURCE_A, 'Delivery', 13), MigrationFlag: 'PRODUCT_NOT_FOUND' },
      { DeliveryID: d.negative, POID: po.closed, POLineID: l.closedJar, ProductID: p.jar, DeliveryDate: date('2025-02-15'),
        SJNumber: 'SJ/UJI/005', Destination: 'Gudang B', DeliveredQuantity: -20, Note: 'Tanda terima retur',
        ...lineage(SOURCE_A, 'Delivery', 14), MigrationFlag: 'OK' },
      { DeliveryID: d.empty, ...lineage(SOURCE_A, 'Delivery', 15), MigrationFlag: 'OK' },
      { DeliveryID: d.orphan, POID: 'PO-FFFFFFFFFF', DeliveryDate: date('2025-03-10'), SJNumber: 'SJ/UJI/007', Destination: 'Gudang C',
        DeliveredQuantity: 70, ...lineage(SOURCE_B, 'Delivery', 9), MigrationFlag: 'OK' },
      { DeliveryID: d.overDelivered, POID: po.placeholder, POLineID: l.placeholderBottle, ProductID: p.bottle,
        DeliveryDate: date('2025-04-10'), SJNumber: 'SJ/UJI/008', Destination: 'Gudang C', DeliveredQuantity: 150,
        ...lineage(SOURCE_B, 'Delivery', 10), MigrationFlag: 'OK' },
    ],
    RETURNS: [
      { ReturnID: ID.ret.linked, POID: po.closed, ProductID: p.jar, PONumberLegacy: 'PO/UJI/002', ProductLegacy: 'Jar Uji 50gr',
        ReturnDate: date('2025-02-20'), SJNumber: 'RTR/UJI/001', Destination: 'Gudang B', ReturnQuantity: 20, ...lineage(SOURCE_A, 'Retur', 3) },
      { ReturnID: ID.ret.withoutProduct, POID: po.open, PONumberLegacy: 'PO/UJI/001', ProductLegacy: 'Produk tak dikenal',
        ReturnDate: date('2025-01-30'), ReturnQuantity: 5, ...lineage(SOURCE_A, 'Retur', 4) },
    ],
    STOCK: [
      { StockID: ID.stock.ready, ProductID: p.bottle, ProductLegacy: 'Botol Uji 30ml', StockType: 'FG', Quantity: 1200, Box: 10,
        QtyPerBox: 120, Status: 'Ready', ...lineage(SOURCE_B, 'Stok', 4) },
      { StockID: ID.stock.withoutProduct, ProductLegacy: 'Barang Lama', StockType: 'WIP', Quantity: 300, Status: 'Ready',
        ...lineage(SOURCE_B, 'Stok', 5) },
      { StockID: ID.stock.header, ProductLegacy: 'Nama Barang', Status: 'Status', ...lineage(SOURCE_B, 'Stok', 3) },
    ],
    LEADTIME: [
      { LeadTimeID: ID.leadtime.planned, POID: po.open, PONumberLegacy: 'PO/UJI/001', ProductLegacy: 'Botol Uji 30ml', Quantity: 400,
        DeliveryDate: date('2025-05-01'), Status: 'On Proses', ...lineage(SOURCE_B, 'Leadtime', 4) },
      { LeadTimeID: ID.leadtime.total, PONumberLegacy: 'TOTAL', Quantity: 400, ...lineage(SOURCE_B, 'Leadtime', 5) },
    ],
    INBOUND_MAKLON: [
      { InboundID: ID.inbound.exact, Vendor: 'Vendor Uji', Receiver: 'Gudang A', ActualInboundDate: date('2025-01-05'),
        SJNumber: 'SJV/UJI/001', PONumberLegacy: 'PO/UJI/001', FactoryComponentCode: '[TSTBTL30]', Quantity: 1000, RejectQuantity: 0,
        TotalIn: 1000, ...lineage(SOURCE_B, 'Inbound', 4) },
      { InboundID: ID.inbound.ambiguous, Vendor: 'Vendor Uji', Receiver: 'Gudang A', ActualInboundDate: date('2025-04-25'),
        SJNumber: 'SJV/UJI/002', PONumberLegacy: 'PO/UJI/005', FactoryComponentCode: '[TSTTUB10]', Quantity: 500,
        ...lineage(SOURCE_B, 'Inbound', 5) },
      { InboundID: ID.inbound.incomplete, Vendor: 'Vendor Uji', Receiver: 'Gudang A', ActualInboundDate: date('2025-01-06'),
        SJNumber: 'SJV/UJI/003', PONumberLegacy: 'PO/UJI/001', ...lineage(SOURCE_B, 'Inbound', 6) },
    ],
    INVOICES_PAYMENTS: [
      { InvoicePaymentID: ID.invoice.paid, POID: po.closed, PONumberLegacy: 'PO/UJI/002', InvoiceDate: date('2025-02-12'),
        InvoiceNumber: 'PIK/II/2025/INV/001', InvoiceAmount: 1234.567, PaymentAmount: 1234.567, PaymentDate: date('2025-03-01'),
        PaymentReceiptNumber: '12345678.0', InvoiceOutstanding: 0, CumulativeOutstanding: 0, ...lineage(SOURCE_A, 'Invoice', 3) },
      { InvoicePaymentID: ID.invoice.unpaid, POID: po.open, PONumberLegacy: 'PO/UJI/001', InvoiceDate: date('2025-01-25'),
        InvoiceNumber: 'PIK/I/2025/TUM/002', InvoiceAmount: 5000, InvoiceOutstanding: 5000, CumulativeOutstanding: 5000,
        ...lineage(SOURCE_A, 'Invoice', 4) },
      { InvoicePaymentID: ID.invoice.partial, PONumberLegacy: 'PO/UJI/999', InvoiceDate: date('2025-03-01'),
        InvoiceNumber: 'PIK/III/2025/INV/003', InvoiceAmount: 800, PaymentAmount: 300, PaymentDate: date('2025-03-10'),
        InvoiceOutstanding: 500, CumulativeOutstanding: 500, ...lineage(SOURCE_B, 'Invoice', 3) },
    ],
    PO_FINANCIALS: [
      { POFinancialID: ID.financial.paid, POID: po.open, PONumberLegacy: 'PO/UJI/001', Brand: 'Merek Uji', PODate: date('2025-01-10'),
        ProductLegacy: 'Botol Uji 30ml', OrderQuantity: 1000, UnitPrice: 12.5, TotalOrderAmount: 12500, PPN: 1375, TotalInclPPN: 13875,
        DeliveredQuantity: 600, UndeliveredQuantity: 400, Outstanding: 0, Status: 'LUNAS', ...lineage(SOURCE_A, 'Keuangan', 3) },
      { POFinancialID: ID.financial.unpaid, POID: po.closed, PONumberLegacy: 'PO/UJI/002', Brand: 'Merek Uji', PODate: date('2025-02-01'),
        ProductLegacy: 'Jar Uji 50gr', OrderQuantity: 500, UnitPrice: 10, TotalOrderAmount: 5000, PPN: 550, TotalInclPPN: 5550,
        DeliveredQuantity: 500, UndeliveredQuantity: 0, Outstanding: 5550, Status: 'BELUM LUNAS', ...lineage(SOURCE_A, 'Keuangan', 4) },
      { POFinancialID: ID.financial.partial, POID: po.duplicate, PONumberLegacy: 'PO/UJI/005', Brand: 'Merek Uji',
        PODate: date('2025-04-20'), ProductLegacy: 'Jar Uji 50gr', OrderQuantity: 300, UnitPrice: 10, TotalOrderAmount: 3000, PPN: 330,
        TotalInclPPN: 3330, DeliveredQuantity: 0, UndeliveredQuantity: 300, Outstanding: 1000, Status: 'BELUM LUNAS',
        ...lineage(SOURCE_B, 'Keuangan', 3) },
    ],
    LEADS: [],
    ACTIVITIES: [],
    FOLLOW_UP: [],
    USERS: [],
    MIGRATION_ISSUES: [
      { IssueID: ID.issue.withoutPo, TableName: 'DELIVERIES', RecordID: d.withoutPo, IssueType: 'PO_NOT_FOUND',
        Description: 'PO tidak ditemukan saat konversi', ...lineage(SOURCE_A, 'Delivery', 12), ResolutionStatus: 'Open' },
      { IssueID: ID.issue.withoutProduct, TableName: 'DELIVERIES', RecordID: d.withoutProduct, IssueType: 'PRODUCT_NOT_FOUND',
        Description: 'Produk tidak ditemukan saat konversi', ...lineage(SOURCE_A, 'Delivery', 13), ResolutionStatus: 'Open' },
    ],
    ENUMS: [
      { EnumName: 'CustomerStatus', Value: 'Active' },
      { EnumName: 'CustomerStatus', Value: 'Potential' },
      { EnumName: 'POStatus', Value: 'Open' },
      { EnumName: 'FollowUpStatus', Value: 'Overdue' },
      { EnumName: 'StockType', Value: 'FG' },
      { EnumName: 'StockType', Value: 'Ready' },
      { EnumName: 'Priority', Value: 'Urgent' },
    ],
    APPSHEET_CONFIG: [{ Table: 'CUSTOMERS', Column: 'CustomerID', Setting: 'Key', Primary: 'Yes', Recommendation: 'UNIQUEID()' }],
    APPSHEET_FORMULAS: [{ 'Table/View': 'PO_LINES', 'Column/Metric': 'OutstandingQuantity',
      AppSheetExpression: 'MAX(0,[OrderQuantity]-[DeliveredQuantity]+[ReturnQuantity])', Use: 'Virtual column' }],
    MIGRATION_SUMMARY: [{ Source: SOURCE_A, Scope: 'PO + Delivery', Rows: 20 }, { Source: 'TOTAL', Scope: '', Rows: 20 }],
  };
}

// ---------------------------------------------------------------------------------------------------------------
// Minimal .xlsx writer (uncompressed zip)
// ---------------------------------------------------------------------------------------------------------------

const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(buffer) {
  let crc = -1;
  for (const byte of buffer) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  return (crc ^ -1) >>> 0;
}

function zipStored(files) {
  const parts = [];
  const directory = [];
  let offset = 0;
  for (const file of files) {
    const name = Buffer.from(file.name, 'utf8');
    const data = Buffer.from(file.content, 'utf8');
    const crc = crc32(data);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6); // UTF-8 names
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(data.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(name.length, 26);
    parts.push(local, name, data);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(data.length, 20);
    central.writeUInt32LE(data.length, 24);
    central.writeUInt16LE(name.length, 28);
    central.writeUInt32LE(offset, 42);
    directory.push(central, name);
    offset += local.length + name.length + data.length;
  }
  const directoryBuffer = Buffer.concat(directory);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(files.length, 8);
  end.writeUInt16LE(files.length, 10);
  end.writeUInt32LE(directoryBuffer.length, 12);
  end.writeUInt32LE(offset, 16);
  return Buffer.concat([...parts, directoryBuffer, end]);
}

const escapeXml = (text) => String(text).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[char]);

function columnLetters(number) {
  let letters = '';
  for (let n = number; n > 0; n = Math.floor((n - 1) / 26)) letters = String.fromCharCode(65 + ((n - 1) % 26)) + letters;
  return letters;
}

function dateSerial(iso) {
  const [year, month, day] = iso.split('-').map(Number);
  return (Date.UTC(year, month - 1, day) - Date.UTC(1899, 11, 30)) / 86400000;
}

function cellXml(ref, value, keepEmpty) {
  if (value === null || value === undefined) return keepEmpty ? `<c r="${ref}"/>` : '';
  if (typeof value === 'boolean') return `<c r="${ref}" t="b"><v>${value ? 1 : 0}</v></c>`;
  if (typeof value === 'number') return `<c r="${ref}"><v>${value}</v></c>`;
  if (typeof value === 'object' && value.date) return `<c r="${ref}" s="1"><v>${dateSerial(value.date)}</v></c>`;
  return `<c r="${ref}" t="inlineStr"><is><t xml:space="preserve">${escapeXml(value)}</t></is></c>`;
}

function sheetXml(name, rows) {
  const header = HEADERS[name];
  const body = [];
  // Header row: every column present (an empty header cell still defines the column, as in the README sheet).
  body.push(`<row r="1">${header.map((title, i) => cellXml(`${columnLetters(i + 1)}1`, title, true)).join('')}</row>`);
  rows.forEach((row, index) => {
    const number = index + 2;
    const values = name === 'README' ? [row.key, row.value] : header.map((column) => row[column]);
    body.push(`<row r="${number}">${values.map((value, i) => cellXml(`${columnLetters(i + 1)}${number}`, value)).join('')}</row>`);
  });
  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
    `<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${body.join('')}</sheetData></worksheet>`;
}

/** Writes the sheets (default: syntheticSheets()) as an .xlsx file. Sheet order follows HEADERS. */
function writeWorkbook(file, sheets = syntheticSheets(), { created = '2025-06-30T00:00:00Z' } = {}) {
  const names = Object.keys(HEADERS);
  for (const name of Object.keys(sheets)) if (!HEADERS[name]) throw new Error(`Unknown synthetic sheet ${name}`);
  const main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  const rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  const files = [
    { name: '[Content_Types].xml', content: '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' +
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
      '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
      names.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('') +
      '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>' },
    { name: '_rels/.rels', content: `<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
      `<Relationship Id="rId1" Type="${rel}/officeDocument" Target="xl/workbook.xml"/>` +
      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>' },
    { name: 'docProps/core.xml', content: '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties ' +
      'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" ' +
      'xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' +
      `<dc:creator>test</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">${created}</dcterms:created></cp:coreProperties>` },
    { name: 'xl/workbook.xml', content: `<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="${main}" xmlns:r="${rel}"><sheets>` +
      names.map((name, i) => `<sheet name="${escapeXml(name)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('') + '</sheets></workbook>' },
    { name: 'xl/_rels/workbook.xml.rels', content: '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
      names.map((_, i) => `<Relationship Id="rId${i + 1}" Type="${rel}/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join('') +
      `<Relationship Id="rIdStyles" Type="${rel}/styles" Target="styles.xml"/></Relationships>` },
    { name: 'xl/styles.xml', content: `<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="${main}">` +
      '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>' },
    ...names.map((name, i) => ({ name: `xl/worksheets/sheet${i + 1}.xml`, content: sheetXml(name, sheets[name] || []) })),
  ];
  fs.writeFileSync(file, zipStored(files));
  return file;
}

module.exports = { HEADERS, ID, SOURCE_A, SOURCE_B, date, syntheticSheets, writeWorkbook };
