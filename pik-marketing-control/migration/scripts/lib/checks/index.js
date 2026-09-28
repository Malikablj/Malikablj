'use strict';

/** Runs every domain check against the parsed workbook and returns statistics + issues. */

const { createIssueCollector } = require('../issues');
const { buildIndexes } = require('../matching');
const { cleanText } = require('../normalize');
const checkDates = require('./dates');
const checkFinance = require('./finance');
const checkMasterData = require('./master-data');
const checkOperations = require('./operations');
const checkOrders = require('./orders');
const checkWorkbook = require('./workbook');

const REQUIRED_SHEETS = [
  'README',
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
  'MIGRATION_ISSUES',
  'ENUMS',
  'APPSHEET_CONFIG',
  'APPSHEET_FORMULAS',
  'MIGRATION_SUMMARY',
];

/** README is a key/value sheet (column A = key, column B = value) with a title row. */
function readmeEntries(table) {
  const [keyColumn, valueColumn] = table.header.map((column) => column.name);
  const entries = { _title: cleanText(table.header[0]?.cellText) };
  for (const record of table.records) entries[cleanText(record[keyColumn])] = cleanText(record[valueColumn]);
  return entries;
}

/** Maps raw SourceFile values (some truncated) to the full file names listed in README "Sources". */
function sourceResolver(readme) {
  const canonical = (readme.Sources || '').split(';').map(cleanText).filter(Boolean);
  const cache = new Map();
  return {
    canonical,
    resolve(raw) {
      const text = cleanText(raw);
      if (!text) return null;
      if (!cache.has(text)) {
        const matches = canonical.filter((name) => name === text || name.startsWith(text));
        cache.set(text, matches.length === 1 ? matches[0] : null);
      }
      return cache.get(text);
    },
  };
}

function runChecks({ workbook, tables, profiles, asOf }) {
  const missing = REQUIRED_SHEETS.filter((name) => !tables[name]);
  if (missing.length) throw new Error(`Workbook is missing expected sheets: ${missing.join(', ')}`);

  const readme = readmeEntries(tables.README);
  const ctx = {
    workbook,
    tables,
    profiles,
    asOf,
    issues: createIssueCollector(),
    stats: {},
    readme,
    sources: sourceResolver(readme),
    index: buildIndexes(tables),
  };
  ctx.customerName = (id) => ctx.index.customerById.get(id)?.CustomerName ?? null;
  ctx.lineage = (sheetName, record) => {
    const idColumn = tables[sheetName].header[0].name;
    const hasLineage = 'SourceFile' in record;
    return {
      workbook_sheet: sheetName,
      workbook_row: record._row,
      record_id: record[idColumn] ?? '',
      source_file: hasLineage ? (ctx.sources.resolve(record.SourceFile) ?? cleanText(record.SourceFile) ?? '') : '',
      source_sheet: hasLineage ? (cleanText(record.SourceSheet) ?? '') : '',
      legacy_row: hasLineage ? (record.LegacyRow ?? '') : '',
    };
  };

  for (const check of [checkWorkbook, checkMasterData, checkOrders, checkDates, checkOperations, checkFinance]) check(ctx);

  return { readme, sources: ctx.sources.canonical, stats: ctx.stats, issues: ctx.issues.list() };
}

module.exports = { runChecks };
