#!/usr/bin/env node
'use strict';

/**
 * Phase 03 migration pipeline: PROFILE → MAP → VALIDATE → DRY RUN → MIGRATE → VERIFY → REPORT.
 *
 * The workbook is only read (its SHA-256 is compared before and after). PROFILE, MAP and the package VALIDATE run here in
 * Node; DRY RUN, MIGRATE and VERIFY are the Apps Script entry points (src/services/MigrationService.gs), rehearsed in the
 * Apps Script emulator against a fresh, empty database. The production migration is the same package and the same code,
 * run by the operator in the Apps Script editor (docs/DEPLOYMENT.md).
 *
 * Commands:
 *   package   PROFILE + MAP + VALIDATE: writes migration-package.json (the file to upload to Drive)
 *   dry-run   package + DRY RUN in the emulator (writes nothing to the emulated database)
 *   migrate   package + DRY RUN + MIGRATE + rerun (idempotency) + VERIFY + REPORT; the load only starts when the dry run
 *             passed with no errors
 *
 * Usage:
 *   node migration/scripts/migrate.js <package|dry-run|migrate> [workbook.xlsx] [--out <dir>] [--report <file.md>]
 *                                     [--as-of YYYY-MM-DD]
 * Outputs contain PIK business data: the defaults (migration/reports/, docs/MIGRATION_REPORT.md) are ignored by git.
 */

const fs = require('node:fs');
const path = require('node:path');

const { analyzeWorkbook } = require('./lib/analyze');
const { toCsv } = require('./lib/csv');
const { buildMigrationPackage } = require('./lib/migration/build-package');
const { MAPPING_DECISIONS } = require('./lib/migration/mapping');
const { call, createRehearsal, exportDatabase, runUntilComplete, uploadPackage } = require('./lib/migration/rehearsal');
const { buildMarkdownReport, buildSummary } = require('./lib/migration/report');
const { loadTarget } = require('./lib/migration/target');
const { isValidIsoDate } = require('./lib/normalize');
const { sha256 } = require('./lib/xlsx-reader');

const MIGRATION_DIR = path.resolve(__dirname, '..');
const PROJECT_ROOT = path.resolve(MIGRATION_DIR, '..');
const DEFAULT_INPUT = path.join(MIGRATION_DIR, 'source', 'PIK_Master_Database_AppSheet.xlsx');
const DEFAULT_OUTPUT = path.join(MIGRATION_DIR, 'reports');
const DEFAULT_REPORT = path.join(PROJECT_ROOT, 'docs', 'MIGRATION_REPORT.md');
const COMMANDS = ['package', 'dry-run', 'migrate'];

const USAGE = 'Usage: node migration/scripts/migrate.js <package|dry-run|migrate> [workbook.xlsx] [--out <dir>] ' +
  '[--report <file.md>] [--as-of YYYY-MM-DD]';

function parseArgs(argv) {
  const args = { command: null, input: DEFAULT_INPUT, out: DEFAULT_OUTPUT, report: DEFAULT_REPORT, asOf: null, help: false };
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--out') args.out = path.resolve(argv[++i]);
    else if (arg === '--report') args.report = path.resolve(argv[++i]);
    else if (arg === '--as-of') args.asOf = argv[++i];
    else if (arg === '--help' || arg === '-h') args.help = true;
    else if (arg.startsWith('--')) throw new Error(`Unknown option ${arg}\n${USAGE}`);
    else if (!args.command) args.command = arg;
    else args.input = path.resolve(arg);
  }
  if (!args.help && !COMMANDS.includes(args.command)) throw new Error(`Unknown or missing command.\n${USAGE}`);
  if (args.asOf && !isValidIsoDate(args.asOf)) throw new Error('--as-of must be a valid date in YYYY-MM-DD format.');
  return args;
}

function relative(file) {
  return path.relative(process.cwd(), file) || file;
}

function writeFile(file, content, written) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, content);
  written.push(file);
}

function printStep(name, ok, detail) {
  console.log(`${name.padEnd(10)} ${ok ? 'OK   ' : 'GAGAL'} ${detail}`);
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help) {
    console.log(USAGE);
    return;
  }
  const outputs = { package: path.join(args.out, 'migration-package.json') };
  for (const file of [outputs.package, args.report]) {
    if (path.resolve(file) === path.resolve(args.input)) throw new Error('Refusing to write over the source workbook.');
  }

  // PROFILE + MAP + VALIDATE (package level)
  const analysis = analyzeWorkbook(args.input, { asOf: args.asOf });
  const target = loadTarget();
  const pkg = buildMigrationPackage(analysis, target);
  const packageText = JSON.stringify(pkg);
  const written = [];
  writeFile(outputs.package, packageText, written);
  printStep('PROFILE', true, `${analysis.file.name}: ${Object.keys(analysis.tables).length} sheet, ` +
    `${pkg.accounting.length} baris data, ${analysis.result.issues.length} isu profil; SHA-256 tidak berubah`);
  printStep('MAP', pkg.structuralErrors.length === 0, `${pkg.transformations.length} transformasi tercatat; ` +
    `${pkg.structuralErrors.length} error struktural; disposisi ${JSON.stringify(pkg.expectations.dispositions)}`);
  for (const error of pkg.structuralErrors.slice(0, 20)) console.log(`           STRUKTURAL ${error.sheet}!${error.row || '-'}: ${error.message}`);

  const rehearsal = createRehearsal();
  uploadPackage(rehearsal, packageText);
  const steps = { mode: 'emulator' };
  steps.profile = call(rehearsal, 'profileSourceWorkbook');
  steps.validate = call(rehearsal, 'validateMigrationMapping');
  const integrity = steps.validate.result ? steps.validate.result.integrity : null;
  printStep('VALIDATE', Boolean(integrity && integrity.ok), steps.validate.error ? steps.validate.error.message
    : `integritas paket ${integrity.errorCount} error; ${(pkg.tables.MIGRATION_ISSUES || []).length} isu migrasi`);
  for (const error of integrity ? integrity.errors.slice(0, 20) : []) console.log(`           ${error.code}: ${error.message}`);
  if (args.command === 'package' || !integrity || !integrity.ok) {
    console.log(`\nWrote ${relative(outputs.package)}`);
    if (!integrity || !integrity.ok) process.exitCode = 1;
    return;
  }

  // DRY RUN
  steps.dryRun = call(rehearsal, 'dryRunMigration');
  const dry = steps.dryRun.result;
  printStep('DRY RUN', Boolean(dry && dry.ok), steps.dryRun.error ? steps.dryRun.error.message
    : `${dry.errorCount} error validasi, ${steps.dryRun.spreadsheetWrites} penulisan spreadsheet; ` +
      `rencana insert ${Object.values(dry.plan).reduce((total, plan) => total + plan.insert, 0)}`);
  if (dry) for (const error of dry.errors.slice(0, 20)) console.log(`           ${JSON.stringify(error)}`);
  outputs.dryRun = path.join(args.out, 'migration-dry-run.json');
  writeFile(outputs.dryRun, `${JSON.stringify(steps.dryRun, null, 2)}\n`, written);

  if (args.command === 'migrate' && dry && dry.ok) {
    // MIGRATE, rerun, VERIFY
    steps.migrate = runUntilComplete(rehearsal);
    const last = steps.migrate[steps.migrate.length - 1];
    printStep('MIGRATE', Boolean(last.result && last.result.completed), last.error ? last.error.message
      : `${steps.migrate.length} kali jalan; ${Object.entries(last.result.tables).map(([name, c]) => `${name} +${c.inserted}`).join(', ')}`);
    steps.rerun = call(rehearsal, 'runMigration');
    printStep('RERUN', Boolean(steps.rerun.result && steps.rerun.spreadsheetWrites === 0),
      steps.rerun.error ? steps.rerun.error.message : `${steps.rerun.spreadsheetWrites} penulisan spreadsheet (idempoten bila 0)`);
    steps.verify = call(rehearsal, 'verifyMigration');
    const verify = steps.verify.result;
    printStep('VERIFY', Boolean(verify && verify.ok), steps.verify.error ? steps.verify.error.message
      : `${verify.summary.pass} lulus, ${verify.summary.fail} gagal, ${verify.summary.info} informasi`);
    if (verify) for (const check of verify.checks.filter((c) => c.status === 'FAIL')) console.log(`           FAIL ${check.name}`);

    outputs.run = path.join(args.out, 'migration-run.json');
    writeFile(outputs.run, `${JSON.stringify({ runs: steps.migrate, rerun: steps.rerun }, null, 2)}\n`, written);
    outputs.verify = path.join(args.out, 'migration-verify.json');
    writeFile(outputs.verify, `${JSON.stringify(steps.verify, null, 2)}\n`, written);
    writeDatabaseExports(rehearsal, args.out, written);
    if (verify && verify.reconciliation) {
      writeFile(path.join(args.out, 'outstanding-reconciliation.csv'), toCsv(verify.reconciliation.outstandingByPo,
        ['purchase_order_id', 'status', 'lines', 'computed', 'legacy', 'difference', 'linesWithoutLegacy', 'hasUnlinkedTransactions']), written);
    }
  }

  const asText = (value) => (value !== null && typeof value === 'object' ? JSON.stringify(value) : value);
  writeFile(path.join(args.out, 'migration-transformations.csv'), toCsv(pkg.transformations.map((item) => ({
    ...item, from: asText(item.from), to: asText(item.to),
  })), ['table', 'id', 'column', 'source', 'rule', 'from', 'to']), written);
  writeFile(path.join(args.out, 'migration-accounting.csv'), toCsv(pkg.accounting.map((entry) => ({
    ...entry, issueIds: entry.issueIds.join(' '),
  })), ['sheet', 'row', 'sourceId', 'disposition', 'table', 'recordId', 'issueIds', 'reason']), written);

  const generatedAt = new Date().toISOString();
  const summary = buildSummary({ pkg, analysis, steps, generatedAt });
  outputs.summary = path.join(args.out, 'migration-summary.json');
  writeFile(outputs.summary, `${JSON.stringify(summary, null, 2)}\n`, written);

  if (args.command === 'migrate') {
    const databaseFiles = written.filter((file) => path.basename(path.dirname(file)) === 'database');
    const list = written.filter((file) => !databaseFiles.includes(file)).concat(args.report)
      .map((file) => ({ path: path.relative(PROJECT_ROOT, file), description: describeOutput(file) }));
    if (databaseFiles.length) {
      list.push({ path: `${path.relative(PROJECT_ROOT, path.dirname(databaseFiles[0]))}/*.csv`,
        description: `Isi ${databaseFiles.length} sheet database hasil gladi migrasi (seperti di Google Sheets)` });
    }
    const markdown = buildMarkdownReport({
      pkg, analysis, steps, generatedAt, decisions: MAPPING_DECISIONS,
      outputs: { package: path.relative(PROJECT_ROOT, outputs.package), list },
    });
    writeFile(args.report, markdown, written);
  }
  if (sha256Changed(analysis, args.input)) throw new Error('The source workbook changed during the run; discard the outputs.');

  console.log('');
  for (const file of written) console.log(`Wrote ${relative(file)}`);
  const verify = steps.verify && steps.verify.result;
  const failed = !dry || !dry.ok || (args.command === 'migrate' && (!verify || !verify.ok || steps.rerun.spreadsheetWrites !== 0));
  const open = MAPPING_DECISIONS.filter((decision) => decision.status === 'OPEN');
  if (open.length) console.log(`\nDECISION REQUIRED (default diterapkan, menunggu konfirmasi): ${open.map((d) => `${d.id} ${d.topic}`).join('; ')}`);
  if (failed) process.exitCode = 1;
}

function sha256Changed(analysis, input) {
  return sha256(fs.readFileSync(input)) !== analysis.file.sha256;
}

/** Every sheet of the emulated database, as it would look in Google Sheets after the migration. */
function writeDatabaseExports(rehearsal, outDir, written) {
  const database = exportDatabase(rehearsal);
  const toRecords = (rows) => {
    const header = rows[0].map(String);
    return { header, records: rows.slice(1).map((row) => Object.fromEntries(header.map((column, i) => [column, row[i]]))) };
  };
  for (const [name, rows] of Object.entries(database.sheets)) {
    if (rows.length === 0) continue;
    const { header, records } = toRecords(rows);
    writeFile(path.join(outDir, 'database', `${name}.csv`), toCsv(records, header), written);
    if (name === 'MIGRATION_ISSUES') writeFile(path.join(outDir, 'migration-issues.csv'), toCsv(records, header), written);
  }
}

function describeOutput(file) {
  const name = path.basename(file);
  if (name === 'migration-package.json') return 'Paket migrasi: diunggah ke Drive untuk migrasi produksi (Apps Script)';
  if (name === 'migration-dry-run.json') return 'Hasil dryRunMigration()';
  if (name === 'migration-run.json') return 'Hasil runMigration() dan rerun';
  if (name === 'migration-verify.json') return 'Hasil verifyMigration() lengkap, termasuk rekonsiliasi';
  if (name === 'migration-summary.json') return 'Ringkasan angka semua langkah';
  if (name === 'migration-issues.csv') return 'MIGRATION_ISSUES seperti tersimpan di database';
  if (name === 'migration-transformations.csv') return 'Log transformasi: setiap nilai yang berbeda dari sel sumber';
  if (name === 'migration-accounting.csv') return 'Disposisi setiap baris sumber';
  if (name === 'outstanding-reconciliation.csv') return 'Outstanding hitung vs legacy per PO';
  if (name === 'MIGRATION_REPORT.md') return 'Laporan migrasi (dokumen ini)';
  if (path.basename(path.dirname(file)) === 'database') return 'Isi sheet database hasil gladi migrasi';
  return '';
}

try {
  main();
} catch (error) {
  console.error(`migrate: ${error.message}`);
  process.exitCode = 1;
}
