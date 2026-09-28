#!/usr/bin/env node
'use strict';

/**
 * Phase 01 profiler for PIK_Master_Database_AppSheet.xlsx. Read-only: it never
 * migrates or modifies anything, and verifies the workbook's SHA-256 before and after.
 *
 * Writes:
 *   <out>/data-profile.json              profile of every sheet/column + domain statistics
 *   <out>/phase01-migration-issues.csv   every issue found, one row per record (or per sheet/column)
 *
 * Usage:
 *   node migration/scripts/profile-workbook.js [workbook.xlsx] [--out <dir>] [--as-of YYYY-MM-DD]
 *
 * --as-of  dates after it count as "in the future"; defaults to the workbook's creation date.
 */

const fs = require('node:fs');
const path = require('node:path');

const { runChecks } = require('./lib/checks');
const { toCsv } = require('./lib/csv');
const { ISSUE_COLUMNS, ISSUE_TYPES, SEVERITY_ORDER } = require('./lib/issues');
const { isValidIsoDate } = require('./lib/normalize');
const { profileSheet } = require('./lib/profile');
const { readWorkbook, sha256, sheetToTable } = require('./lib/xlsx-reader');

const MIGRATION_DIR = path.resolve(__dirname, '..');
const DEFAULT_INPUT = path.join(MIGRATION_DIR, 'source', 'PIK_Master_Database_AppSheet.xlsx');
const DEFAULT_OUTPUT = path.join(MIGRATION_DIR, 'reports');

// Candidate natural keys tested per sheet, in addition to SourceFile+SourceSheet+LegacyRow.
const COMPOSITE_KEYS = {
  PRODUCTS: [{ columns: ['ProductName', 'Variant', 'ProductCode'], allowEmpty: true }],
  PURCHASE_ORDERS: [{ columns: ['CustomerID', 'PONumber'] }],
  PO_LINES: [{ columns: ['POID', 'ProductID'] }],
  DELIVERIES: [{ columns: ['SJNumber', 'POID', 'ProductID'] }],
  STOCK: [{ columns: ['SourceFile', 'SourceSheet', 'LegacyRow', 'StockType'] }],
  ENUMS: [{ columns: ['EnumName', 'Value'] }],
};

const USAGE = 'Usage: node migration/scripts/profile-workbook.js [workbook.xlsx] [--out <dir>] [--as-of YYYY-MM-DD]';

function parseArgs(argv) {
  const args = { input: DEFAULT_INPUT, out: DEFAULT_OUTPUT, asOf: null, help: false };
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--out') args.out = path.resolve(argv[++i]);
    else if (arg === '--as-of') args.asOf = argv[++i];
    else if (arg === '--help' || arg === '-h') args.help = true;
    else if (arg.startsWith('--')) throw new Error(`Unknown option ${arg}\n${USAGE}`);
    else args.input = path.resolve(arg);
  }
  if (args.asOf && !isValidIsoDate(args.asOf)) throw new Error('--as-of must be a valid date in YYYY-MM-DD format.');
  return args;
}

function summariseIssues(issues) {
  const bySeverity = Object.fromEntries(SEVERITY_ORDER.map((severity) => [severity, 0]));
  const byType = {};
  for (const issue of issues) {
    bySeverity[issue.severity] += 1;
    const definition = ISSUE_TYPES[issue.issue_type];
    const entry = (byType[issue.issue_type] ??= {
      title: definition.title,
      defaultSeverity: definition.severity,
      decision: definition.decision ?? null,
      count: 0,
      bySeverity: {},
      bySheet: {},
      withCandidate: 0,
    });
    entry.count += 1;
    entry.bySeverity[issue.severity] = (entry.bySeverity[issue.severity] || 0) + 1;
    entry.bySheet[issue.workbook_sheet] = (entry.bySheet[issue.workbook_sheet] || 0) + 1;
    if (issue.candidate_reference !== '') entry.withCandidate += 1;
  }
  return { total: issues.length, bySeverity, byType };
}

function printSummary(report, outputs) {
  const pad = (value, width) => String(value).padEnd(width);
  console.log(`Workbook  ${report.input.name} (${report.input.sizeBytes} bytes, sha256 ${report.input.sha256.slice(0, 16)}…, unchanged)`);
  console.log(`As-of     ${report.asOf} (${report.asOfSource})\n`);
  console.log(`${pad('Sheet', 20)}${pad('Rows', 7)}${pad('Cols', 6)}Empty columns`);
  for (const sheet of report.sheets) {
    console.log(`${pad(sheet.sheet, 20)}${pad(sheet.rows, 7)}${pad(sheet.columnCount, 6)}${sheet.emptyColumns.join(', ') || '-'}`);
  }
  const { issueSummary } = report;
  console.log(`\nIssues: ${issueSummary.total} (${SEVERITY_ORDER.map((s) => `${s} ${issueSummary.bySeverity[s]}`).join(', ')})`);
  for (const [type, entry] of Object.entries(issueSummary.byType).sort((a, b) => b[1].count - a[1].count)) {
    console.log(`  ${pad(entry.count, 5)}${pad(entry.defaultSeverity, 8)}${type}`);
  }
  console.log(`\nWrote ${path.relative(process.cwd(), outputs.profile)}\nWrote ${path.relative(process.cwd(), outputs.issues)}`);
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help) {
    console.log(USAGE);
    return;
  }
  if (!fs.existsSync(args.input)) {
    throw new Error(`Workbook not found: ${args.input}\nPlace it in migration/source/ or pass its path.\n${USAGE}`);
  }
  const outputs = {
    profile: path.join(args.out, 'data-profile.json'),
    issues: path.join(args.out, 'phase01-migration-issues.csv'),
  };
  if (Object.values(outputs).some((file) => path.resolve(file) === args.input)) {
    throw new Error('Refusing to write over the source workbook.');
  }

  const hashBefore = sha256(fs.readFileSync(args.input));
  const workbook = readWorkbook(args.input);
  const createdDate = (workbook.documentProperties.created || '').slice(0, 10);
  const asOf = args.asOf ?? (isValidIsoDate(createdDate) ? createdDate : new Date().toISOString().slice(0, 10));

  const tables = {};
  const profiles = {};
  for (const sheet of workbook.sheets) {
    tables[sheet.name] = sheetToTable(sheet, workbook);
    profiles[sheet.name] = profileSheet(tables[sheet.name], sheet, { asOf, compositeKeys: COMPOSITE_KEYS[sheet.name] });
  }
  const result = runChecks({ workbook, tables, profiles, asOf });
  if (sha256(fs.readFileSync(args.input)) !== hashBefore) {
    throw new Error('The source workbook changed while it was being profiled; results discarded.');
  }

  const report = {
    generatedBy: 'migration/scripts/profile-workbook.js',
    generatedAt: new Date().toISOString(),
    asOf,
    asOfSource: args.asOf ? '--as-of' : isValidIsoDate(createdDate) ? 'tanggal pembuatan workbook' : 'hari ini',
    input: {
      ...workbook.file,
      sha256Unchanged: true,
      date1904: workbook.date1904,
      workbookProtectionElement: workbook.workbookProtection,
      definedNames: workbook.definedNames,
      calculation: workbook.calculation,
      sharedStrings: workbook.sharedStringCount,
      documentProperties: workbook.documentProperties,
      zipParts: workbook.parts.length,
    },
    readme: result.readme,
    sourceFiles: result.sources,
    sheets: Object.values(profiles),
    stats: result.stats,
    issueSummary: summariseIssues(result.issues),
  };

  fs.mkdirSync(args.out, { recursive: true });
  fs.writeFileSync(outputs.profile, `${JSON.stringify(report, null, 2)}\n`);
  fs.writeFileSync(outputs.issues, toCsv(result.issues, ISSUE_COLUMNS));
  printSummary(report, outputs);
}

try {
  main();
} catch (error) {
  console.error(`profile-workbook: ${error.message}`);
  process.exitCode = 1;
}
