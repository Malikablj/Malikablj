'use strict';

/** Workbook-level checks: structure, IDs, ID references, lineage, existing issues, enums, summary. */

const { cleanText } = require('../normalize');
const { countBy, groupBy } = require('../matching');

const BUSINESS_SHEETS = [
  'CUSTOMERS',
  'CONTACTS',
  'PRODUCTS',
  'PURCHASE_ORDERS',
  'PO_LINES',
  'DELIVERIES',
  'RETURNS',
  'STOCK',
  'LEADTIME',
  'INBOUND_MAKLON',
  'INVOICES_PAYMENTS',
  'PO_FINANCIALS',
  'LEADS',
  'ACTIVITIES',
  'FOLLOW_UP',
  'USERS',
];

const ID_REFERENCES = [
  ['PURCHASE_ORDERS', 'CustomerID', 'CUSTOMERS', 'CustomerID'],
  ['PO_LINES', 'POID', 'PURCHASE_ORDERS', 'POID'],
  ['PO_LINES', 'ProductID', 'PRODUCTS', 'ProductID'],
  ['DELIVERIES', 'POID', 'PURCHASE_ORDERS', 'POID'],
  ['DELIVERIES', 'POLineID', 'PO_LINES', 'POLineID'],
  ['DELIVERIES', 'ProductID', 'PRODUCTS', 'ProductID'],
  ['RETURNS', 'POID', 'PURCHASE_ORDERS', 'POID'],
  ['RETURNS', 'ProductID', 'PRODUCTS', 'ProductID'],
  ['STOCK', 'ProductID', 'PRODUCTS', 'ProductID'],
  ['LEADTIME', 'POID', 'PURCHASE_ORDERS', 'POID'],
  ['LEADTIME', 'ProductID', 'PRODUCTS', 'ProductID'],
  ['INVOICES_PAYMENTS', 'POID', 'PURCHASE_ORDERS', 'POID'],
  ['PO_FINANCIALS', 'POID', 'PURCHASE_ORDERS', 'POID'],
  ['CONTACTS', 'CustomerID', 'CUSTOMERS', 'CustomerID'],
  ['LEADS', 'CustomerID', 'CUSTOMERS', 'CustomerID'],
  ['ACTIVITIES', 'CustomerID', 'CUSTOMERS', 'CustomerID'],
  ['ACTIVITIES', 'LeadID', 'LEADS', 'LeadID'],
  ['FOLLOW_UP', 'CustomerID', 'CUSTOMERS', 'CustomerID'],
  ['FOLLOW_UP', 'LeadID', 'LEADS', 'LeadID'],
  ['MIGRATION_ISSUES', 'RecordID', 'DELIVERIES', 'DeliveryID'],
];

// Enumerations defined by the technical specification.
const SPEC_ENUMS = {
  CustomerStatus: ['ACTIVE', 'INACTIVE', 'POTENTIAL', 'DORMANT'],
  LeadStatus: ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTATION', 'NEGOTIATION', 'WON', 'LOST', 'DORMANT'],
  ActivityType: [
    'WhatsApp',
    'Phone Call',
    'Email',
    'Meeting',
    'Visit',
    'Quotation',
    'Sample',
    'Presentation',
    'Follow Up',
    'Complaint',
    'Other',
  ],
  POStatus: ['OPEN', 'ON_PROCESS', 'PARTIAL', 'CLOSED', 'CANCELLED'],
  FollowUpStatus: ['PLANNED', 'DONE', 'RESCHEDULE', 'CANCELLED', 'OVERDUE'],
  DeliveryStatus: ['SCHEDULED', 'ON_DELIVERY', 'DELIVERED', 'DELAYED', 'CANCELLED'],
  UserRole: ['ADMIN', 'MARKETING', 'SALES', 'MANAGEMENT', 'VIEWER'],
};

const enumKey = (value) => (cleanText(value) ?? '').toUpperCase().replace(/[^A-Z0-9]+/g, '_');

function checkStructure(ctx) {
  const { tables, profiles, issues } = ctx;
  const structure = [];
  for (const [name, table] of Object.entries(tables)) {
    if (table.table) {
      const headerTexts = table.header.map((column) => cleanText(column.cellText));
      table.table.columns.forEach((columnName, i) => {
        if (cleanText(columnName) !== headerTexts[i]) {
          issues.add('HEADER_TABLE_MISMATCH', {
            workbook_sheet: name,
            field: columnName,
            value: headerTexts[i] ?? '',
            description: `Kolom ke-${i + 1} di definisi table "${columnName}" berbeda dengan header sheet "${headerTexts[i] ?? ''}".`,
          });
        }
      });
      const [, lastRow] = table.table.ref.match(/:[A-Z]+(\d+)$/) || [];
      const tableRows = Number(lastRow) - table.headerRowNumber;
      if (tableRows !== table.records.length + table.blankRows) {
        issues.add('HEADER_TABLE_MISMATCH', {
          workbook_sheet: name,
          field: '(range)',
          value: table.table.ref,
          description: `Range table mencakup ${tableRows} baris data, sheet berisi ${table.records.length} baris.`,
        });
      }
    }
    for (const record of table.records) {
      if (record._outsideHeader) {
        issues.add('CELLS_OUTSIDE_HEADER', {
          workbook_sheet: name,
          workbook_row: record._row,
          record_id: record[table.header[0].name] ?? '',
          value: record._outsideHeader.join(','),
          description: `Ada nilai di kolom ${record._outsideHeader.join(', ')} yang tidak punya header.`,
        });
      }
    }
    if (BUSINESS_SHEETS.includes(name)) {
      if (table.records.length === 0) {
        issues.add('EMPTY_SHEET', {
          workbook_sheet: name,
          description: `Sheet ${name} hanya berisi header (${table.header.length} kolom); tidak ada data untuk dimigrasi.`,
        });
      } else {
        for (const column of profiles[name].columns) {
          if (column.nonEmpty === 0) {
            issues.add('COLUMN_ALWAYS_EMPTY', {
              workbook_sheet: name,
              field: column.name,
              description: `Kolom ${column.name} kosong di seluruh ${table.records.length} baris.`,
            });
          }
        }
      }
    }
    structure.push({ sheet: name, records: table.records.length, blankRows: table.blankRows });
  }
  ctx.stats.structure = structure;
}

function checkIds(ctx) {
  const { tables, issues } = ctx;
  const idStats = {};
  const suffixSheets = new Map();
  for (const name of BUSINESS_SHEETS.concat('MIGRATION_ISSUES')) {
    const table = tables[name];
    if (!table.records.length) continue;
    const idColumn = table.header[0].name;
    const formats = new Map();
    for (const [id, records] of groupBy(table.records, (record) => record[idColumn])) {
      if (records.length > 1) {
        for (const record of records) {
          issues.add('ID_DUPLICATE', {
            ...ctx.lineage(name, record),
            field: idColumn,
            value: id,
            description: `${idColumn} ${id} dipakai ${records.length} baris.`,
          });
        }
      }
    }
    for (const record of table.records) {
      const id = String(record[idColumn] ?? '');
      const match = id.match(/^([A-Z]+)-([0-9A-F]+)$/);
      if (!match) {
        issues.add('ID_FORMAT_UNEXPECTED', {
          ...ctx.lineage(name, record),
          field: idColumn,
          value: id,
          description: `${idColumn} "${id}" tidak berpola PREFIX-HEX.`,
        });
        continue;
      }
      const format = `${match[1]}- + ${match[2].length} hex`;
      formats.set(format, (formats.get(format) || 0) + 1);
      if (!suffixSheets.has(match[2])) suffixSheets.set(match[2], new Set());
      suffixSheets.get(match[2]).add(name);
    }
    idStats[name] = { column: idColumn, formats: Object.fromEntries(formats) };
  }
  const sharedSuffixes = [...suffixSheets.values()].filter((sheets) => sheets.size > 1);
  ctx.stats.ids = {
    bySheet: idStats,
    suffixesSharedAcrossSheets: sharedSuffixes.length,
    sheetsSharingSuffixes: [...new Set(sharedSuffixes.flatMap((sheets) => [...sheets]))].sort(),
  };
}

function checkIdReferences(ctx) {
  const { tables, issues } = ctx;
  const references = [];
  for (const [fromSheet, fromColumn, toSheet, toColumn] of ID_REFERENCES) {
    const targetIds = new Set(tables[toSheet].records.map((record) => record[toColumn]));
    const records = tables[fromSheet].records;
    const filled = records.filter((record) => cleanText(record[fromColumn]));
    const orphans = filled.filter((record) => !targetIds.has(record[fromColumn]));
    for (const record of orphans) {
      issues.add('FK_ORPHAN', {
        ...ctx.lineage(fromSheet, record),
        field: fromColumn,
        value: record[fromColumn],
        description: `${fromColumn} ${record[fromColumn]} tidak ditemukan di ${toSheet}.${toColumn}.`,
      });
    }
    references.push({
      from: `${fromSheet}.${fromColumn}`,
      to: `${toSheet}.${toColumn}`,
      rows: records.length,
      filled: filled.length,
      empty: records.length - filled.length,
      matched: filled.length - orphans.length,
      orphans: orphans.length,
    });
  }
  ctx.stats.idReferences = references;
}

function checkLineage(ctx) {
  const { tables, issues, sources } = ctx;
  const lineage = {};
  for (const name of BUSINESS_SHEETS) {
    const table = tables[name];
    if (!table.records.length) continue;
    const columns = table.header.map((column) => column.name);
    const hasLineage = ['SourceFile', 'SourceSheet', 'LegacyRow'].every((column) => columns.includes(column));
    if (!hasLineage) {
      issues.add('LINEAGE_MISSING', {
        workbook_sheet: name,
        description:
          `Sheet ${name} tidak punya kolom SourceFile/SourceSheet/LegacyRow; asal baris legacy tidak bisa ditelusuri ` +
          'per record (record ini hasil deduplikasi saat konversi AppSheet).',
      });
      lineage[name] = { hasLineage: false };
      continue;
    }
    const rawFiles = new Map();
    for (const record of table.records) {
      const raw = cleanText(record.SourceFile);
      rawFiles.set(raw, (rawFiles.get(raw) || 0) + 1);
    }
    const files = [...rawFiles].map(([raw, count]) => ({ raw, canonical: sources.resolve(raw), rows: count }));
    for (const file of files) {
      if (!file.canonical) {
        issues.add('SOURCE_FILE_UNRECOGNISED', {
          workbook_sheet: name,
          field: 'SourceFile',
          value: file.raw ?? '',
          description: `SourceFile "${file.raw}" (${file.rows} baris) tidak cocok dengan daftar Sources di README.`,
        });
      } else if (file.canonical !== file.raw) {
        issues.add('SOURCE_FILE_NAME_VARIANT', {
          workbook_sheet: name,
          field: 'SourceFile',
          value: file.raw,
          candidate_reference: file.canonical,
          candidate_method: 'prefix-of-readme-source',
          description: `SourceFile "${file.raw}" (${file.rows} baris) adalah potongan nama file di README.`,
        });
      }
    }
    const groups = groupBy(table.records, (record) =>
      [sources.resolve(record.SourceFile) ?? record.SourceFile, cleanText(record.SourceSheet), record.LegacyRow].join('|'),
    );
    let duplicateRecords = 0;
    for (const records of groups.values()) {
      if (records.length < 2) continue;
      duplicateRecords += records.length;
      for (const record of records) {
        const others = records.filter((other) => other !== record).map((other) => other[table.header[0].name]);
        issues.add('LINEAGE_NOT_UNIQUE', {
          ...ctx.lineage(name, record),
          field: 'SourceFile+SourceSheet+LegacyRow',
          candidate_reference: others.join('; '),
          candidate_method: 'same-lineage',
          description: `Baris legacy ${record.LegacyRow} di "${record.SourceSheet}" menghasilkan ${records.length} record.`,
        });
      }
    }
    lineage[name] = {
      hasLineage: true,
      files,
      sourceSheets: countBy(table.records, (record) => cleanText(record.SourceSheet)),
      duplicateRecords,
    };
  }
  ctx.stats.lineage = lineage;
}

function checkExistingIssues(ctx) {
  const { tables, issues } = ctx;
  const existing = tables.MIGRATION_ISSUES.records;
  const flagged = tables.DELIVERIES.records.filter((record) => cleanText(record.MigrationFlag) !== 'OK');
  const byRecord = new Map(existing.map((issue) => [issue.RecordID, issue]));
  ctx.existingIssueByRecord = byRecord;

  const missing = flagged.filter((delivery) => !byRecord.has(delivery.DeliveryID));
  const typeMismatch = flagged.filter(
    (delivery) => byRecord.has(delivery.DeliveryID) && byRecord.get(delivery.DeliveryID).IssueType !== delivery.MigrationFlag,
  );
  const flaggedIds = new Set(flagged.map((delivery) => delivery.DeliveryID));
  const extra = existing.filter((issue) => !flaggedIds.has(issue.RecordID));
  for (const [label, rows] of [
    ['tanpa entri MIGRATION_ISSUES', missing],
    ['IssueType berbeda dengan MigrationFlag', typeMismatch],
  ]) {
    for (const delivery of rows) {
      issues.add('EXISTING_ISSUES_OUT_OF_SYNC', {
        ...ctx.lineage('DELIVERIES', delivery),
        description: `Delivery ber-flag ${delivery.MigrationFlag} ${label}.`,
      });
    }
  }
  for (const issue of extra) {
    issues.add('EXISTING_ISSUES_OUT_OF_SYNC', {
      workbook_sheet: 'MIGRATION_ISSUES',
      workbook_row: issue._row,
      record_id: issue.IssueID,
      description: `Issue ${issue.IssueID} merujuk ${issue.RecordID} yang flag-nya OK.`,
    });
  }
  const withLegacyText = existing.filter((issue) => cleanText(issue.LegacyPO) || cleanText(issue.LegacyProduct));
  if (existing.length && withLegacyText.length === 0) {
    issues.add('EXISTING_ISSUES_WITHOUT_LEGACY_TEXT', {
      workbook_sheet: 'MIGRATION_ISSUES',
      field: 'LegacyPO, LegacyProduct',
      description:
        `Kolom LegacyPO dan LegacyProduct kosong di ${existing.length} issue, dan DELIVERIES tidak menyimpan teks PO/produk ` +
        'asli. Delivery tak tertaut tidak bisa dicocokkan ulang dari workbook ini saja; perlu file legacy asli ' +
        'atau konfirmasi manual.',
    });
  }
  const valuesOf = (column) => countBy(existing, (issue) => cleanText(issue[column]));
  ctx.stats.existingIssues = {
    rows: existing.length,
    byType: valuesOf('IssueType'),
    byResolution: valuesOf('ResolutionStatus'),
    flaggedDeliveries: flagged.length,
    deliveriesWithoutIssue: missing.length,
    typeMismatches: typeMismatch.length,
    issuesForOkDeliveries: extra.length,
    withLegacyText: withLegacyText.length,
    descriptions: valuesOf('Description'),
  };
}

function checkEnums(ctx) {
  const { tables, issues } = ctx;
  const workbookEnums = new Map();
  for (const record of tables.ENUMS.records) {
    const name = cleanText(record.EnumName);
    if (!workbookEnums.has(name)) workbookEnums.set(name, []);
    workbookEnums.get(name).push(cleanText(record.Value));
  }
  const comparison = [];
  for (const name of new Set([...workbookEnums.keys(), ...Object.keys(SPEC_ENUMS)])) {
    const workbookValues = workbookEnums.get(name) || [];
    const specValues = SPEC_ENUMS[name] || [];
    const workbookKeys = new Set(workbookValues.map(enumKey));
    const specKeys = new Set(specValues.map(enumKey));
    const onlyWorkbook = workbookValues.filter((value) => !specKeys.has(enumKey(value)));
    const onlySpec = specValues.filter((value) => !workbookKeys.has(enumKey(value)));
    const entry = {
      enum: name,
      workbook: workbookValues,
      spec: specValues,
      onlyInWorkbook: specValues.length ? onlyWorkbook : [],
      onlyInSpec: workbookValues.length ? onlySpec : [],
      inWorkbookOnly: specValues.length === 0,
      inSpecOnly: workbookValues.length === 0,
      sameValuesDifferentFormat:
        specValues.length > 0 &&
        workbookValues.length > 0 &&
        onlyWorkbook.length === 0 &&
        onlySpec.length === 0 &&
        workbookValues.some((value, i) => value !== specValues[i]),
    };
    comparison.push(entry);
    if (entry.onlyInWorkbook.length || entry.onlyInSpec.length || entry.inWorkbookOnly || entry.inSpecOnly) {
      issues.add('ENUM_MISMATCH', {
        workbook_sheet: 'ENUMS',
        field: name,
        value: workbookValues.join(' | '),
        candidate_reference: specValues.join(' | '),
        description: entry.inWorkbookOnly
          ? `Enum ${name} ada di workbook tetapi tidak didefinisikan di spesifikasi.`
          : entry.inSpecOnly
            ? `Enum ${name} ada di spesifikasi tetapi tidak ada di workbook.`
            : `Nilai berbeda. Hanya di workbook: ${entry.onlyInWorkbook.join(', ') || '-'}; hanya di spesifikasi: ${
                entry.onlyInSpec.join(', ') || '-'
              }.`,
      });
    }
  }
  ctx.stats.enums = comparison;
}

function checkSummary(ctx) {
  const { tables, issues, sources } = ctx;
  const perSource = {};
  for (const name of BUSINESS_SHEETS) {
    for (const record of tables[name].records) {
      if (!('SourceFile' in record)) continue;
      const source = sources.resolve(record.SourceFile) ?? cleanText(record.SourceFile) ?? '(kosong)';
      perSource[source] ??= {};
      perSource[source][name] = (perSource[source][name] || 0) + 1;
    }
  }
  const rows = tables.MIGRATION_SUMMARY.records.map((record) => {
    const source = cleanText(record.Source);
    const stated = Number(record.Rows);
    const counts = perSource[source] || null;
    const lineRows = counts ? (counts.PO_LINES || 0) + (counts.DELIVERIES || 0) : null;
    const allRows = counts ? Object.values(counts).reduce((a, b) => a + b, 0) : null;
    return { source, scope: cleanText(record.Scope), statedRows: stated, poLinesPlusDeliveries: lineRows, allSheetRows: allRows, counts };
  });
  const components = rows.filter((row) => row.source !== 'TOTAL');
  const total = rows.find((row) => row.source === 'TOTAL');
  const sumOfComponents = components.reduce((sum, row) => sum + row.statedRows, 0);
  for (const row of components) {
    if (row.statedRows !== row.poLinesPlusDeliveries && row.statedRows !== row.allSheetRows) {
      issues.add('SUMMARY_INCONSISTENT', {
        workbook_sheet: 'MIGRATION_SUMMARY',
        field: row.source,
        value: row.statedRows,
        description:
          `MIGRATION_SUMMARY menyebut ${row.statedRows} baris; workbook berisi ${row.poLinesPlusDeliveries} baris ` +
          `PO_LINES+DELIVERIES dan ${row.allSheetRows} baris di semua sheet untuk sumber ini.`,
      });
    }
  }
  if (total && total.statedRows !== sumOfComponents) {
    issues.add('SUMMARY_INCONSISTENT', {
      workbook_sheet: 'MIGRATION_SUMMARY',
      field: 'TOTAL',
      value: total.statedRows,
      description: `Baris TOTAL = ${total.statedRows}, padahal jumlah baris per sumber = ${sumOfComponents}.`,
    });
  }
  ctx.stats.summary = { rows, sumOfComponents, perSource };
}

function describeAppSheetSetup(ctx) {
  const { tables } = ctx;
  ctx.stats.appsheet = {
    config: tables.APPSHEET_CONFIG.records.map((record) => ({
      table: record.Table,
      column: record.Column,
      setting: record.Setting,
      primary: record.Primary,
      recommendation: record.Recommendation,
    })),
    formulas: tables.APPSHEET_FORMULAS.records.map((record) => ({
      target: record['Table/View'],
      metric: record['Column/Metric'],
      expression: record.AppSheetExpression,
      use: record.Use,
    })),
  };
}

module.exports = function checkWorkbook(ctx) {
  checkStructure(ctx);
  checkIds(ctx);
  checkIdReferences(ctx);
  checkLineage(ctx);
  checkExistingIssues(ctx);
  checkEnums(ctx);
  checkSummary(ctx);
  describeAppSheetSetup(ctx);
};
