'use strict';

/**
 * MAP + package-level VALIDATE: turns the analysed workbook (analyze.js) into a migration package.
 *
 * The package is plain JSON and is the only input of the load step (Apps Script or emulator):
 *   tables        target records per table in load order (IDs from the workbook, lineage, migration_hash)
 *   accounting    one entry per source row of every sheet: MIGRATED, EXCLUDED, REPRESENTED or NOT_MIGRATED
 *   expectations  counts, sums and non-null counts computed from the raw source rows (for verification)
 *   transformations  every value that differs from its source cell, with the rule that produced it
 *   packageHash   SHA-256 of the package content, binding dry run, migration and verification to one package
 * Nothing here writes to the workbook or to a database.
 */

const crypto = require('node:crypto');

const { SHEET_ENTITY } = require('../issues');
const { buildIndexes } = require('../matching');
const { cleanText } = require('../normalize');
const {
  DOCUMENTATION_SHEETS,
  EMPTY_TEMPLATE_SHEETS,
  ENUM_NAME_MAP,
  ENUM_VALUE_EXCEPTIONS,
  EXCLUSION_ISSUE_TYPES,
  MAPPING_DECISIONS,
  MAPPING_VERSION,
  SHEET_MAPPINGS,
  toUpperSnake,
} = require('./mapping');

const PACKAGE_FORMAT = 'pik-marketing-control/migration-package';
const PACKAGE_FORMAT_VERSION = 1;
const LOAD_ORDER = [
  'CUSTOMERS', 'PRODUCTS', 'PURCHASE_ORDERS', 'PO_LINES', 'DELIVERIES', 'RETURNS', 'STOCK', 'LEADTIME', 'INBOUND_MAKLON',
  'INVOICES_PAYMENTS', 'PO_FINANCIALS', 'MIGRATION_ISSUES',
];

// Issue types raised by the builder itself (IDs start with MIG-); Phase 01 issues keep their PRF- IDs.
const MIGRATION_ISSUE_TYPES = {
  UNMAPPED_SOURCE_VALUE: { severity: 'MEDIUM', title: 'Nilai di kolom sumber yang tidak dimigrasikan' },
  FK_NOT_FOUND: { severity: 'BLOCKER', title: 'ID rujukan tidak ditemukan' },
  ID_MISSING: { severity: 'BLOCKER', title: 'Baris sumber tanpa ID' },
  VALUE_NOT_NUMERIC: { severity: 'HIGH', title: 'Nilai bukan angka' },
  VALUE_NOT_DATE: { severity: 'HIGH', title: 'Nilai bukan tanggal' },
  VALUE_NOT_BOOLEAN: { severity: 'MEDIUM', title: 'Nilai bukan TRUE/FALSE' },
  VALUE_NOT_MAPPED: { severity: 'HIGH', title: 'Nilai tanpa padanan enum' },
  REFERENCE_UNRESOLVED: { severity: 'HIGH', title: 'Relasi kosong tanpa isu Phase 01' },
  SHEET_ROW_WITHOUT_MAPPING: { severity: 'BLOCKER', title: 'Baris di sheet tanpa pemetaan' },
  EXISTING_ISSUE_NOT_REPRESENTED: { severity: 'HIGH', title: 'Isu workbook tidak terwakili' },
  ENUM_NOT_REPRESENTED: { severity: 'MEDIUM', title: 'Nilai ENUMS workbook tidak terwakili' },
};

// How each null relation is covered by a Phase 01 issue (an uncovered null becomes REFERENCE_UNRESOLVED).
const UNMATCHED_COVERAGE = {
  PURCHASE_ORDERS: { customer_id: ['PO_CUSTOMER_MISSING'] },
  DELIVERIES: {
    purchase_order_id: ['DELIVERY_PO_UNRESOLVED'],
    product_id: ['DELIVERY_PRODUCT_UNRESOLVED', 'DELIVERY_PO_UNRESOLVED'],
    po_line_id: ['DELIVERY_PRODUCT_UNRESOLVED', 'DELIVERY_LINE_UNRESOLVED', 'DELIVERY_PO_UNRESOLVED'],
  },
  RETURNS: { product_id: ['RETURN_PRODUCT_UNRESOLVED'], po_line_id: ['RETURN_LINE_UNRESOLVED'] },
  STOCK: { product_id: ['STOCK_PRODUCT_UNRESOLVED'] },
  LEADTIME: { purchase_order_id: ['LEADTIME_PO_UNRESOLVED'], product_id: ['LEADTIME_PRODUCT_UNRESOLVED'] },
  INBOUND_MAKLON: {
    purchase_order_id: ['INBOUND_PO_UNRESOLVED'],
    product_id: ['INBOUND_COMPONENT_UNRESOLVED', 'INBOUND_ROW_INCOMPLETE'],
  },
  INVOICES_PAYMENTS: { purchase_order_id: ['INVOICE_PO_UNRESOLVED'] },
  PO_FINANCIALS: { purchase_order_id: ['POF_PO_UNRESOLVED'] },
};

// How the migration handled each kind of Phase 01 issue (stored as resolution_note; status stays OPEN for review).
const HANDLING_NOTES = {
  PO_STATUS_UNMAPPED: 'Migrasi: status dipetakan ke ON_HOLD (default D4, menunggu konfirmasi); teks asli di status_legacy.',
  PO_NUMBER_PLACEHOLDER: 'Migrasi: po_number dikosongkan; teks asli di po_number_legacy (D7).',
  PO_NUMBER_MISSING: 'Migrasi: PO diimpor tanpa nomor sebagai data legacy (D7).',
  PO_DUPLICATE_NUMBER: 'Migrasi: kedua PO diimpor apa adanya sebagai data legacy; PO baru tidak boleh memakai nomor ini (D7).',
  PO_CUSTOMER_MISSING: 'Migrasi: customer_id dibiarkan kosong (data legacy, D3); kandidat tidak diterapkan.',
  DELIVERY_PO_UNRESOLVED: 'Migrasi: delivery diimpor tanpa PO (data legacy, D3); kandidat tidak diterapkan.',
  DELIVERY_PRODUCT_UNRESOLVED: 'Migrasi: delivery diimpor tanpa produk/baris PO (data legacy, D3); kandidat tidak diterapkan.',
  DELIVERY_LINE_UNRESOLVED: 'Migrasi: delivery diimpor tanpa baris PO (D3); kandidat tidak diterapkan.',
  DELIVERY_NEGATIVE_QTY: 'Migrasi: qty negatif disimpan apa adanya; status delivery dikosongkan (D9).',
  RETURN_PRODUCT_UNRESOLVED: 'Migrasi: retur diimpor tanpa produk (data legacy, D3); kandidat tidak diterapkan.',
  RETURN_LINE_UNRESOLVED: 'Migrasi: retur diimpor tanpa baris PO (D3); kandidat tidak diterapkan.',
  STOCK_PRODUCT_UNRESOLVED: 'Migrasi: stok diimpor tanpa produk (data legacy, D3); kandidat tidak diterapkan.',
  STOCK_QTY_MISSING: 'Migrasi: quantity dibiarkan kosong (tidak dihitung dari Box x QtyPerBox).',
  STOCK_DATE_UNKNOWN: 'Migrasi: stock_date dibiarkan kosong (data legacy).',
  LEADTIME_PO_UNRESOLVED: 'Migrasi: jadwal diimpor tanpa PO (D10); kandidat tidak diterapkan.',
  LEADTIME_PRODUCT_UNRESOLVED: 'Migrasi: jadwal diimpor tanpa produk (D10); kandidat tidak diterapkan.',
  INVOICE_PO_UNRESOLVED: 'Migrasi: invoice diimpor tanpa PO (data legacy, D3); kandidat tidak diterapkan.',
  POF_PO_UNRESOLVED: 'Migrasi: ringkasan keuangan diimpor tanpa PO (D11).',
  RECEIPT_NUMBER_FLOAT_FORMAT: 'Migrasi: akhiran ".0" dibuang (T-08); digit tidak berubah.',
  CUSTOMER_STATUS_DEFAULTED: 'Migrasi: status ACTIVE diimpor apa adanya (D13).',
  PO_DATE_SWAP_CANDIDATE: 'Migrasi: tanggal disimpan apa adanya, tidak dikoreksi (D6).',
  DELIVERY_DATE_SWAP_CANDIDATE: 'Migrasi: tanggal disimpan apa adanya, tidak dikoreksi (D6).',
  INVOICE_DATE_SWAP_CANDIDATE: 'Migrasi: tanggal disimpan apa adanya, tidak dikoreksi (D6).',
  POF_UNIT_PRICE_SCALE: 'Migrasi: UnitPrice disimpan apa adanya di unit_price_legacy, tidak dinormalisasi (D11).',
  PRODUCT_CODE_SHARED: 'Migrasi: produk tidak digabung (D8).',
  PRODUCT_DUPLICATE_EXACT: 'Migrasi: produk tidak digabung (D8).',
  CUSTOMER_DUPLICATE_CANDIDATE: 'Migrasi: customer tidak digabung (D8).',
  CUSTOMER_COMPOSITE_NAME: 'Migrasi: nama customer diimpor apa adanya, tidak dipecah (D8).',
  DELIVERY_DUPLICATE_CANDIDATE: 'Migrasi: kedua delivery diimpor; tidak ada penggabungan otomatis.',
  PO_SPLIT_CANDIDATE: 'Migrasi: PO tidak digabung (D7).',
  PO_NUMBER_WHITESPACE: 'Migrasi: nomor PO disimpan apa adanya; po_number_key (tanpa spasi) dipakai untuk pencarian dan keunikan.',
  PO_DATE_MISSING: 'Migrasi: po_date dibiarkan kosong (data legacy, tidak ditebak).',
  RETURN_DATE_MISSING: 'Migrasi: return_date dibiarkan kosong (data legacy, tidak ditebak).',
  INVOICE_PAYMENT_DATE_MISSING: 'Migrasi: payment_date dibiarkan kosong; status bayar diturunkan dari outstanding legacy (D11).',
  INVOICE_PAYMENT_AMOUNT_MISSING: 'Migrasi: paid_amount dibiarkan kosong; status bayar diturunkan dari outstanding legacy (D11).',
  LINE_RECONCILIATION_GAP: 'Migrasi: nilai legacy disimpan di kolom *_legacy; outstanding aplikasi dihitung dari transaksi tertaut (D3).',
  LINE_LEGACY_FORMULA_MISMATCH: 'Migrasi: nilai legacy disimpan apa adanya di kolom *_legacy; tidak dipakai untuk perhitungan.',
  LINE_OVER_DELIVERED_COMPUTED: 'Migrasi: qty disimpan apa adanya; outstanding hitung dibatasi minimal 0.',
  LINE_OVER_DELIVERED_LEGACY: 'Migrasi: nilai legacy disimpan apa adanya di kolom *_legacy.',
  PO_LINE_STATUS_MISMATCH: 'Migrasi: status asli disimpan di status_legacy; status aplikasi dihitung dari transaksi.',
  EXISTING_ISSUES_WITHOUT_LEGACY_TEXT: 'Migrasi: delivery tak tertaut diimpor tanpa relasi (D3); penautan butuh file legacy asli.',
  LEADTIME_SEMANTICS_MISMATCH: 'Migrasi: sheet diimpor sebagai jadwal pengiriman terencana (D10).',
  ENUM_MISMATCH: 'Migrasi: ENUMS database mengikuti skema aplikasi; nilai ENUMS workbook dicatat per baris di accounting.',
  POF_OUTSTANDING_SUSPECT: 'Migrasi: disimpan apa adanya sebagai referensi keuangan legacy (D11).',
  POF_TOTAL_INCL_MISSING: 'Migrasi: disimpan apa adanya sebagai referensi keuangan legacy (D11).',
  POF_TOTAL_INCONSISTENT: 'Migrasi: disimpan apa adanya sebagai referensi keuangan legacy (D11).',
  POF_UNIT_PRICE_UNVERIFIABLE: 'Migrasi: disimpan apa adanya sebagai referensi keuangan legacy (D11).',
  POF_DATE_DIFFERS_FROM_PO: 'Migrasi: disimpan apa adanya sebagai referensi keuangan legacy (D11).',
  POF_STATUS_ROUNDING: 'Migrasi: disimpan apa adanya sebagai referensi keuangan legacy (D11).',
  POF_UNDELIVERED_NEGATIVE: 'Migrasi: disimpan apa adanya sebagai referensi keuangan legacy (D11).',
  COLUMN_ALWAYS_EMPTY: 'Migrasi: informasi profil workbook; tidak ada data yang diubah.',
  EMPTY_SHEET: 'Migrasi: informasi profil workbook; tidak ada data yang diubah.',
  SUMMARY_INCONSISTENT: 'Migrasi: informasi profil workbook; tidak ada data yang diubah.',
  // Issues raised by the package builder (MIG-).
  UNMAPPED_SOURCE_VALUE: 'Migrasi: nilai kolom sumber yang tidak dimigrasikan disimpan di kolom value isu ini.',
  FK_NOT_FOUND: 'Migrasi: relasi dikosongkan karena ID rujukan tidak ada; tidak ditebak.',
  REFERENCE_UNRESOLVED: 'Migrasi: relasi dibiarkan kosong; tidak ditebak.',
  VALUE_NOT_NUMERIC: 'Migrasi: kolom target dikosongkan; nilai asli disimpan di kolom value isu ini.',
  VALUE_NOT_DATE: 'Migrasi: kolom target dikosongkan; nilai asli disimpan di kolom value isu ini.',
  VALUE_NOT_BOOLEAN: 'Migrasi: kolom target dikosongkan; nilai asli disimpan di kolom value isu ini.',
  VALUE_NOT_MAPPED: 'Migrasi: kolom target dikosongkan; nilai asli disimpan di kolom value isu ini.',
  ID_MISSING: 'Migrasi: baris tidak dimigrasikan (tanpa ID); isi baris asli disimpan di kolom value. Paket diblokir sampai diputuskan.',
  SHEET_ROW_WITHOUT_MAPPING: 'Migrasi: baris tidak dimigrasikan ke tabel bisnis; isi baris asli disimpan di kolom value.',
  EXISTING_ISSUE_NOT_REPRESENTED: 'Migrasi: isu workbook disimpan utuh di kolom value.',
  ENUM_NOT_REPRESENTED: 'Migrasi: nilai ENUMS workbook tidak ada di database; baris asli disimpan di kolom value.',
};
// Every other kind of issue: the data was imported unchanged and waits for review.
const DEFAULT_HANDLING_NOTE = 'Migrasi: data diimpor apa adanya (tidak dikoreksi otomatis); menunggu tinjauan Admin.';

/** A relation that is not set: null, or not produced at all because the source sheet has no such column. */
function isEmpty(value) {
  return value === null || value === undefined;
}

function sha256Hex(text) {
  return crypto.createHash('sha256').update(text).digest('hex');
}

function shortHash(text) {
  return crypto.createHash('sha1').update(text).digest('hex').slice(0, 10).toUpperCase();
}

/** Source row values in header order (the part of a row that identifies its content). */
function rawRow(table, row) {
  const values = {};
  for (const column of table.header) values[column.name] = row[column.name] === undefined ? null : row[column.name];
  return values;
}

function importRef(fileName, sheet, rowNumber) {
  return rowNumber ? `${fileName}#${sheet}!${rowNumber}` : `${fileName}#${sheet}`;
}

/**
 * @param {object} analysis result of analyzeWorkbook()
 * @param {{ schema: object, enumDefinitions: object[] }} target database schema and enum seed (from the Apps Script code)
 */
function buildMigrationPackage(analysis, target) {
  const { tables, file } = analysis;
  const fileName = file.name;
  const profilerIssues = analysis.result.issues;
  const index = buildIndexes(tables);

  // Phase 01 issues per source row: by record ID, or by workbook row for a row without an ID. Sheet-level issues
  // (no record, no row) belong to no row.
  const rowKey = (sheet, recordId, workbookRow) => (recordId ? `${sheet}|${recordId}` : workbookRow ? `${sheet}|#${workbookRow}` : null);
  const issuesByRecord = new Map();
  for (const issue of profilerIssues) {
    const key = rowKey(issue.workbook_sheet, cleanText(issue.record_id), Number(issue.workbook_row) || null);
    if (!key) continue;
    if (!issuesByRecord.has(key)) issuesByRecord.set(key, []);
    issuesByRecord.get(key).push(issue);
  }
  const issuesOfRow = (mapping, row) => issuesByRecord.get(rowKey(mapping.sheet, row[mapping.idColumn] || null, row._row)) || [];
  const exclusionOf = (mapping, row) => issuesOfRow(mapping, row).find((issue) => EXCLUSION_ISSUE_TYPES[issue.issue_type]);

  // IDs that will exist in the database. Defensive: no table refers to a sheet with excluded rows today, but a reference
  // to a row that is not migrated must become an unknown ID (FK_NOT_FOUND), never a dangling relation.
  const idSets = {};
  for (const mapping of SHEET_MAPPINGS) {
    idSets[mapping.sheet] = new Set(tables[mapping.sheet].records.filter((row) => !exclusionOf(mapping, row))
      .map((row) => row[mapping.idColumn]).filter(Boolean));
  }

  const out = {
    tables: Object.fromEntries(LOAD_ORDER.map((name) => [name, []])),
    accounting: [],
    transformations: [],
    migrationIssues: new Map(),
    exclusionRows: new Map(), // PRF issue id -> original row
    structural: [],
  };

  const addMigrationIssue = (type, fields) => {
    const definition = MIGRATION_ISSUE_TYPES[type];
    if (!definition) throw new Error(`Jenis isu migrasi tidak dikenal: ${type}`);
    // A row without an ID is identified by its workbook row, so two such rows never share one issue.
    const subject = fields.record_id || (fields.workbook_row ? `#${fields.workbook_row}` : '');
    const key = [type, fields.workbook_sheet || '', subject, fields.field || ''].join('|');
    const issueId = `MIG-${shortHash(key)}`;
    if (!out.migrationIssues.has(issueId)) {
      out.migrationIssues.set(issueId, {
        issue_id: issueId,
        severity: fields.severity || definition.severity,
        issue_type: type,
        entity_type: SHEET_ENTITY[fields.workbook_sheet] || fields.workbook_sheet || '',
        record_id: fields.record_id || '',
        field: fields.field || '',
        value: fields.value === null || fields.value === undefined ? '' : typeof fields.value === 'string' ? fields.value : JSON.stringify(fields.value),
        description: fields.description || definition.title,
        candidate_reference: '',
        candidate_method: '',
        evidence: fields.evidence || '',
        decision_ref: fields.decision_ref || '',
        existing_issue_id: '',
        source_file: fields.source_file || '',
        source_sheet: fields.source_sheet || '',
        legacy_row: fields.legacy_row === undefined ? '' : fields.legacy_row,
        workbook_sheet: fields.workbook_sheet || '',
        workbook_row: fields.workbook_row || '',
        resolution_status: 'OPEN',
      });
    }
    return issueId;
  };

  const schemaTable = (name) => {
    const table = target.schema.byName[name];
    if (!table) throw new Error(`Tabel target ${name} tidak ada di skema.`);
    return table;
  };

  for (const mapping of SHEET_MAPPINGS) {
    const source = tables[mapping.sheet];
    const targetTable = schemaTable(mapping.table);
    checkMappingCoverage(mapping, source, targetTable);

    // One ID on several source rows: which row is the record cannot be decided without guessing.
    const rowsById = new Map();
    for (const row of source.records) {
      const id = row[mapping.idColumn];
      if (id) rowsById.set(id, (rowsById.get(id) || []).concat(row._row));
    }
    for (const [id, rowNumbers] of rowsById) {
      if (rowNumbers.length > 1) {
        out.structural.push({ sheet: mapping.sheet, row: rowNumbers[0], message: `ID ${id} dipakai ${rowNumbers.length} baris (${rowNumbers.join(', ')}).` });
      }
    }

    for (const row of source.records) {
      const recordId = row[mapping.idColumn] || null;
      const rowIssues = issuesOfRow(mapping, row);
      const lineageFields = {
        workbook_sheet: mapping.sheet,
        workbook_row: row._row,
        record_id: recordId || '',
        source_file: cleanText(row.SourceFile) || '',
        source_sheet: cleanText(row.SourceSheet) || '',
        legacy_row: row.LegacyRow === undefined || row.LegacyRow === null ? '' : row.LegacyRow,
      };

      const exclusion = exclusionOf(mapping, row);
      if (exclusion) {
        out.exclusionRows.set(exclusion.issue_id, rawRow(source, row));
        out.accounting.push({
          sheet: mapping.sheet, row: row._row, sourceId: recordId, disposition: 'EXCLUDED', table: null, recordId: null,
          issueIds: [exclusion.issue_id], reason: EXCLUSION_ISSUE_TYPES[exclusion.issue_type],
        });
        continue;
      }

      const rowIssueIds = [];
      const rowMigrationIssues = [];
      const ctx = {
        index,
        idExists: (sheet, value) => idSets[sheet].has(value),
        hasIssue: (type) => rowIssues.some((issue) => issue.issue_type === type),
        hasIssueWithExistingId: () => rowIssues.some((issue) => cleanText(issue.existing_issue_id)),
        issue: (type, fields) => {
          rowMigrationIssues.push({ type, field: fields.field || '' });
          rowIssueIds.push(addMigrationIssue(type, { ...lineageFields, ...fields }));
        },
      };

      const record = {};
      for (const [column, mapper] of Object.entries(mapping.fields)) {
        const result = mapper.map(row, ctx);
        record[column] = result.value === undefined ? null : result.value;
        if (result.rule) {
          out.transformations.push({
            table: mapping.table, id: recordId, column, source: mapper.sources.join('+') || '(turunan)',
            rule: result.rule, from: result.from === undefined ? null : result.from, to: record[column],
          });
        }
      }
      for (const [column, check] of Object.entries(mapping.ignore)) {
        const value = row[column] === undefined || row[column] === '' ? null : row[column];
        if (!check.check(value, row, ctx)) {
          ctx.issue('UNMAPPED_SOURCE_VALUE', {
            field: column,
            value,
            description: `Kolom ${column} tidak dimigrasikan (${check.reason}), tetapi baris ini berisi nilai yang tidak sesuai ` +
              'asumsi tersebut. Nilai asli disimpan di isu ini.',
          });
        }
      }
      if (!record.id) {
        // No stable ID can be derived without guessing: the full row is kept in its issue and the package is blocked
        // (structural error) until the row is given an ID in the source or explicitly excluded.
        const issueId = addMigrationIssue('ID_MISSING', {
          ...lineageFields, field: mapping.idColumn, value: rawRow(source, row),
          description: `Baris tanpa ${mapping.idColumn} tidak dapat dimigrasikan; isi baris asli disimpan di kolom value.`,
        });
        out.structural.push({ sheet: mapping.sheet, row: row._row, message: `Baris tanpa ${mapping.idColumn} (isu ${issueId}).` });
        out.accounting.push({
          sheet: mapping.sheet, row: row._row, sourceId: null, disposition: 'ISSUE_ONLY', table: null, recordId: null,
          issueIds: [...new Set([issueId, ...rowIssues.map((issue) => issue.issue_id), ...rowIssueIds])], reason: 'baris tanpa ID',
        });
        continue;
      }

      const coverage = UNMATCHED_COVERAGE[mapping.table] || {};
      for (const [column, types] of Object.entries(coverage)) {
        if (!isEmpty(record[column])) continue;
        const sources = mapping.fields[column] ? mapping.fields[column].sources : [];
        const covered = rowIssues.some((issue) => types.includes(issue.issue_type)) ||
          rowMigrationIssues.some((issue) => issue.type === 'FK_NOT_FOUND' && sources.includes(issue.field));
        if (!covered) {
          ctx.issue('REFERENCE_UNRESOLVED', {
            field: column,
            description: `${column} kosong setelah pemetaan dan tidak ada isu Phase 01 yang menjelaskannya.`,
          });
        }
      }

      record.is_legacy = true;
      record.import_ref = importRef(fileName, mapping.sheet, row._row);
      // Source row + mapped record: a changed source row or a mapping change that alters the record re-migrates it.
      record.migration_hash = sha256Hex(JSON.stringify([mapping.sheet, rawRow(source, row), record]));
      out.tables[mapping.table].push(record);
      out.accounting.push({
        sheet: mapping.sheet, row: row._row, sourceId: recordId, disposition: 'MIGRATED', table: mapping.table, recordId,
        issueIds: [...new Set(rowIssues.map((issue) => issue.issue_id).concat(rowIssueIds))], reason: null,
      });
    }
  }

  accountOtherSheets(analysis, target, out, addMigrationIssue);

  const allIssues = profilerIssues.concat([...out.migrationIssues.values()]);
  out.tables.MIGRATION_ISSUES = allIssues.map((issue) => toIssueRecord(issue, fileName, out.exclusionRows));

  const pkg = {
    format: PACKAGE_FORMAT,
    formatVersion: PACKAGE_FORMAT_VERSION,
    mappingVersion: MAPPING_VERSION,
    schemaVersion: target.schema.version,
    source: { file: fileName, sha256: file.sha256, sizeBytes: file.sizeBytes, asOf: analysis.asOf },
    decisions: MAPPING_DECISIONS.map(({ id, status, topic, applied }) => ({ id, status, topic, applied })),
    loadOrder: LOAD_ORDER,
    tables: out.tables,
    accounting: out.accounting,
    expectations: computeExpectations(analysis, out),
    transformations: out.transformations,
    structuralErrors: out.structural,
  };
  pkg.packageHash = sha256Hex(JSON.stringify(pkg));
  return pkg;
}

/** Every source column must be read by a field mapper or listed in `ignore`, and every referenced column must exist. */
function checkMappingCoverage(mapping, source, targetTable) {
  const header = source.header.map((column) => column.name);
  const used = new Set(Object.values(mapping.fields).flatMap((mapper) => mapper.sources));
  const ignored = new Set(Object.keys(mapping.ignore));
  const unaccounted = header.filter((column) => !used.has(column) && !ignored.has(column));
  const unknown = [...used, ...ignored].filter((column) => !header.includes(column));
  const unknownTargets = Object.keys(mapping.fields).filter((column) => !targetTable.columnByName[column]);
  if (unaccounted.length || unknown.length || unknownTargets.length) {
    throw new Error(`Pemetaan ${mapping.sheet} tidak lengkap: kolom sumber tanpa pemetaan [${unaccounted.join(', ')}], ` +
      `kolom sumber tidak ada [${unknown.join(', ')}], kolom target tidak ada [${unknownTargets.join(', ')}].`);
  }
}

/** Accounting for sheets that are not mapped to business tables: workbook issues, ENUMS, templates, documentation. */
function accountOtherSheets(analysis, target, out, addMigrationIssue) {
  const { tables } = analysis;
  const representedBy = new Map();
  for (const issue of analysis.result.issues) {
    const existing = cleanText(issue.existing_issue_id);
    if (!existing) continue;
    if (!representedBy.has(existing)) representedBy.set(existing, []);
    representedBy.get(existing).push(issue.issue_id);
  }
  for (const row of tables.MIGRATION_ISSUES.records) {
    const ids = representedBy.get(row.IssueID) || [];
    const fields = { workbook_sheet: 'MIGRATION_ISSUES', workbook_row: row._row, record_id: row.IssueID || '' };
    const issueIds = ids.length ? ids : [addMigrationIssue('EXISTING_ISSUE_NOT_REPRESENTED', {
      ...fields, value: rawRow(tables.MIGRATION_ISSUES, row),
      description: 'Isu workbook tidak terwakili isu Phase 01; baris aslinya disimpan di kolom value.',
    })];
    out.accounting.push({
      sheet: 'MIGRATION_ISSUES', row: row._row, sourceId: row.IssueID || null, disposition: ids.length ? 'REPRESENTED' : 'ISSUE_ONLY',
      table: 'MIGRATION_ISSUES', recordId: null, issueIds, reason: ids.length ? 'diwakili isu dengan existing_issue_id' : null,
    });
  }

  const seed = new Map();
  for (const definition of target.enumDefinitions) {
    for (const value of definition.values) seed.set(`${definition.name}|${value[0]}`, value[1]);
  }
  for (const row of tables.ENUMS.records) {
    const key = `${row.EnumName}|${row.Value}`;
    const exception = ENUM_VALUE_EXCEPTIONS[key];
    const enumName = exception && exception.target ? exception.target[0] : ENUM_NAME_MAP[row.EnumName];
    const enumValue = exception && exception.target ? exception.target[1] : toUpperSnake(row.Value);
    const base = { sheet: 'ENUMS', row: row._row, sourceId: key, table: 'ENUMS', recordId: null, issueIds: [] };
    if (exception && !exception.target) {
      out.accounting.push({ ...base, disposition: 'NOT_MIGRATED', reason: exception.reason });
    } else if (enumName && seed.has(`${enumName}|${enumValue}`)) {
      out.accounting.push({ ...base, disposition: 'REPRESENTED', recordId: `${enumName}.${enumValue}`, reason: exception ? exception.reason : 'nilai bawaan ENUMS' });
    } else {
      const issueId = addMigrationIssue('ENUM_NOT_REPRESENTED', {
        workbook_sheet: 'ENUMS', workbook_row: row._row, record_id: key, value: rawRow(tables.ENUMS, row),
        description: `Nilai ENUMS workbook ${key} tidak ada padanannya di ENUMS database.`,
      });
      out.accounting.push({ ...base, disposition: 'ISSUE_ONLY', issueIds: [issueId], reason: null });
    }
  }

  for (const sheet of EMPTY_TEMPLATE_SHEETS) {
    for (const row of tables[sheet].records) {
      const issueId = addMigrationIssue('SHEET_ROW_WITHOUT_MAPPING', {
        workbook_sheet: sheet, workbook_row: row._row, record_id: String(row._row), value: rawRow(tables[sheet], row),
        description: `Sheet ${sheet} berisi data tetapi belum punya pemetaan; baris aslinya disimpan di kolom value.`,
      });
      out.accounting.push({ sheet, row: row._row, sourceId: null, disposition: 'ISSUE_ONLY', table: null, recordId: null, issueIds: [issueId], reason: null });
    }
  }

  for (const [sheet, reason] of Object.entries(DOCUMENTATION_SHEETS)) {
    for (const row of tables[sheet].records) {
      out.accounting.push({ sheet, row: row._row, sourceId: null, disposition: 'NOT_MIGRATED', table: null, recordId: null, issueIds: [], reason });
    }
  }

  const known = new Set([
    ...SHEET_MAPPINGS.map((m) => m.sheet), 'MIGRATION_ISSUES', 'ENUMS', ...EMPTY_TEMPLATE_SHEETS, ...Object.keys(DOCUMENTATION_SHEETS),
  ]);
  for (const sheet of Object.keys(tables)) {
    if (!known.has(sheet)) out.structural.push({ sheet, row: null, message: `Sheet ${sheet} tidak dikenal oleh pemetaan.` });
  }
}

function toIssueRecord(issue, fileName, exclusionRows) {
  const text = (value) => {
    const cleaned = value === null || value === undefined ? '' : String(value).trim();
    return cleaned === '' ? null : cleaned;
  };
  const excludedRow = exclusionRows.get(issue.issue_id);
  const record = {
    id: issue.issue_id,
    severity: issue.severity,
    issue_type: issue.issue_type,
    entity_type: text(issue.entity_type),
    record_id: text(issue.record_id),
    field: text(issue.field),
    value: text(issue.value),
    description: text(issue.description),
    candidate_reference: text(issue.candidate_reference),
    candidate_method: text(issue.candidate_method),
    evidence: text(issue.evidence),
    decision_ref: text(issue.decision_ref),
    existing_issue_id: text(issue.existing_issue_id),
    source_file: text(issue.source_file),
    source_sheet: text(issue.source_sheet),
    legacy_row: issue.legacy_row === '' || issue.legacy_row === null || issue.legacy_row === undefined ? null : Number(issue.legacy_row),
    import_ref: issue.workbook_sheet ? importRef(fileName, issue.workbook_sheet, issue.workbook_row) : null,
    resolution_status: 'OPEN',
    resolution_note: HANDLING_NOTES[issue.issue_type] || DEFAULT_HANDLING_NOTE,
  };
  if (excludedRow) {
    // The complete original row is kept with the issue because the row itself is not migrated to a business table.
    record.evidence = text([record.evidence, record.value ? `Nilai ${record.field || 'kolom'}: ${record.value}` : null].filter(Boolean).join(' | '));
    record.value = JSON.stringify(excludedRow);
    record.resolution_status = 'EXCLUDED';
    record.resolution_note = `Migrasi: baris tidak dimigrasikan ke tabel bisnis (${EXCLUSION_ISSUE_TYPES[issue.issue_type]}). ` +
      'Baris asli lengkap disimpan di kolom value (MIGRATION_MAPPING §6).';
  }
  return record;
}

/** Counts and totals computed from the raw source rows only, so a mapping error cannot hide in the check. */
function computeExpectations(analysis, out) {
  const { tables } = analysis;
  const excluded = new Set(out.accounting.filter((entry) => entry.disposition === 'EXCLUDED').map((entry) => `${entry.sheet}|${entry.row}`));
  const counts = {};
  const sums = {};
  const nonNull = {};
  for (const mapping of SHEET_MAPPINGS) {
    const source = tables[mapping.sheet];
    const rows = source.records.filter((row) => !excluded.has(`${mapping.sheet}|${row._row}`));
    counts[mapping.table] = { sourceRows: source.records.length, excludedRows: source.records.length - rows.length, expectedRecords: rows.length };
    for (const [column, mapper] of Object.entries(mapping.fields)) {
      if (mapper.derived || mapper.sources.length !== 1) continue;
      const sourceColumn = mapper.sources[0];
      const values = rows.map((row) => row[sourceColumn]);
      const key = `${mapping.table}.${column}`;
      if (values.some((value) => typeof value === 'number')) {
        sums[key] = { source: `${mapping.sheet}.${sourceColumn}`, sum: sumExact(values.filter((value) => typeof value === 'number')) };
      }
      if (mapper.reference) {
        // Every ID in the source is kept, except an unknown ID, which is left empty with an FK_NOT_FOUND issue.
        const sourceCount = values.filter((value) => value !== null && value !== '').length;
        const unknownIds = [...out.migrationIssues.values()].filter((issue) => issue.issue_type === 'FK_NOT_FOUND' &&
          issue.workbook_sheet === mapping.sheet && issue.field === sourceColumn).length;
        nonNull[key] = { source: `${mapping.sheet}.${sourceColumn}`, sourceCount, unknownIds, count: sourceCount - unknownIds };
      }
    }
  }
  const issueTypes = {};
  for (const record of out.tables.MIGRATION_ISSUES || []) issueTypes[record.issue_type] = (issueTypes[record.issue_type] || 0) + 1;
  const unmatched = {};
  for (const [tableName, columns] of Object.entries(UNMATCHED_COVERAGE)) {
    for (const [column, issueTypes] of Object.entries(columns)) {
      const count = (out.tables[tableName] || []).filter((record) => isEmpty(record[column])).length;
      // A Phase 01 issue explains the empty relation, or the builder's own: an unknown ID (FK_NOT_FOUND) or none found.
      unmatched[`${tableName}.${column}`] = { count, issueTypes: [...issueTypes, 'FK_NOT_FOUND', 'REFERENCE_UNRESOLVED'] };
    }
  }
  const dispositions = {};
  for (const entry of out.accounting) dispositions[entry.disposition] = (dispositions[entry.disposition] || 0) + 1;
  const sheets = {};
  for (const [name, table] of Object.entries(tables)) sheets[name] = table.records.length;
  return { counts, sums, nonNull, unmatched, sheets, dispositions, issueTypes };
}

/** Sum rounded to 6 decimals, which removes binary floating-point noise from quantities and rupiah amounts. */
function sumExact(values) {
  return Math.round(values.reduce((total, value) => total + value, 0) * 1e6) / 1e6;
}

module.exports = { LOAD_ORDER, MIGRATION_ISSUE_TYPES, PACKAGE_FORMAT, PACKAGE_FORMAT_VERSION, buildMigrationPackage };
