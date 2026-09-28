'use strict';

/**
 * PROFILE step shared by the Phase 01 profiler and the migration package builder: reads the workbook read-only,
 * profiles every sheet and runs the domain checks. The workbook's SHA-256 is compared before and after reading;
 * any change aborts, so the source is provably untouched.
 */

const fs = require('node:fs');
const path = require('node:path');

const { runChecks } = require('./checks');
const { isValidIsoDate } = require('./normalize');
const { profileSheet } = require('./profile');
const { readWorkbook, sha256, sheetToTable } = require('./xlsx-reader');

// Candidate natural keys tested per sheet, in addition to SourceFile+SourceSheet+LegacyRow.
const COMPOSITE_KEYS = {
  PRODUCTS: [{ columns: ['ProductName', 'Variant', 'ProductCode'], allowEmpty: true }],
  PURCHASE_ORDERS: [{ columns: ['CustomerID', 'PONumber'] }],
  PO_LINES: [{ columns: ['POID', 'ProductID'] }],
  DELIVERIES: [{ columns: ['SJNumber', 'POID', 'ProductID'] }],
  STOCK: [{ columns: ['SourceFile', 'SourceSheet', 'LegacyRow', 'StockType'] }],
  ENUMS: [{ columns: ['EnumName', 'Value'] }],
};

/**
 * @param {string} inputPath workbook path
 * @param {{ asOf?: string }} options asOf: dates after it count as "in the future" (default: workbook creation date)
 */
function analyzeWorkbook(inputPath, options = {}) {
  if (!fs.existsSync(inputPath)) throw new Error(`Workbook not found: ${inputPath}`);
  const hashBefore = sha256(fs.readFileSync(inputPath));
  const workbook = readWorkbook(inputPath);
  const createdDate = (workbook.documentProperties.created || '').slice(0, 10);
  const asOf = options.asOf || (isValidIsoDate(createdDate) ? createdDate : new Date().toISOString().slice(0, 10));
  const asOfSource = options.asOf ? '--as-of' : isValidIsoDate(createdDate) ? 'tanggal pembuatan workbook' : 'hari ini';

  const tables = {};
  const profiles = {};
  for (const sheet of workbook.sheets) {
    tables[sheet.name] = sheetToTable(sheet, workbook);
    profiles[sheet.name] = profileSheet(tables[sheet.name], sheet, { asOf, compositeKeys: COMPOSITE_KEYS[sheet.name] });
  }
  const result = runChecks({ workbook, tables, profiles, asOf });
  if (sha256(fs.readFileSync(inputPath)) !== hashBefore) {
    throw new Error('The source workbook changed while it was being read; results discarded.');
  }
  return {
    file: { ...workbook.file, name: path.basename(inputPath), sha256: hashBefore },
    workbook,
    tables,
    profiles,
    result,
    asOf,
    asOfSource,
  };
}

module.exports = { COMPOSITE_KEYS, analyzeWorkbook };
