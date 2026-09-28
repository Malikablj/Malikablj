'use strict';

/**
 * Runs the real setup flow (setupDatabase -> verifyDatabase -> initializeDatabase again -> runDatabaseSelfTest) in the
 * Apps Script emulator and prints what the initializer produced. No Google account is involved and no business data
 * is used. Usage: npm run emulate:init
 */

const { loadGasProject } = require('../tools/gas-emulator/load-gas');

const { context, env } = loadGasProject({ env: { properties: {} } });

const setup = context.setupDatabase();
const spreadsheet = env.spreadsheets.get(setup.spreadsheetId);
const init = setup.initialization;

console.log('== setupDatabase()');
console.log(`spreadsheet baru: ${setup.created}; DATABASE_SPREADSHEET_ID tersimpan: ${env.properties.DATABASE_SPREADSHEET_ID === setup.spreadsheetId}`);
console.log(`sheet dibuat: ${init.createdSheets.length}; sheet bawaan dihapus: ${init.removedDefaultSheets.join(', ') || '-'}; ` +
  `zona waktu: ${spreadsheet.getSpreadsheetTimeZone()}`);
console.log(`nilai ENUMS di-seed: ${init.seededEnumValues}; SETTINGS di-seed: ${init.seededSettings.join(', ')}`);
console.log();
console.log('Sheet'.padEnd(20) + 'Kolom'.padStart(6) + 'Baris data'.padStart(12) + '  Header beku  Proteksi  Kolom pertama → terakhir');
const protections = context.indexDatabaseProtections_(spreadsheet);
context.withDatabaseSpreadsheet_(spreadsheet, () => {
  for (const table of context.getSchema_().tables) {
    const sheet = spreadsheet.getSheetByName(table.name);
    const header = sheet.getRange(1, 1, 1, table.columns.length).getValues()[0];
    const protectionKey = Object.keys(protections).find((key) => key.endsWith(`:${table.name}`));
    console.log(table.name.padEnd(20) + String(header.length).padStart(6) +
      String(Math.max(sheet.getLastRow() - 1, 0)).padStart(12) +
      `  ${sheet.getFrozenRows() === 1 ? 'ya' : 'TIDAK'}`.padEnd(13) +
      `  ${protectionKey ? protectionKey.split(':')[1].toLowerCase() : '-'}`.padEnd(10) +
      `  ${header[0]} → ${header[header.length - 1]}`);
  }
});

const verify = context.verifyDatabase_({ spreadsheet, includeAuditLog: true });
console.log();
console.log('== verifyDatabase()');
console.log(`ok: ${verify.ok}; error: ${verify.totals.errors}; peringatan: ${verify.totals.warnings}; ` +
  `SCHEMA_VERSION database: ${verify.schemaVersion.database}`);

const rerun = context.initializeDatabase();
console.log();
console.log('== initializeDatabase() dijalankan ulang');
console.log(`ada perubahan: ${rerun.changed}`);

const selfTest = context.runDatabaseSelfTest();
console.log();
console.log('== runDatabaseSelfTest()');
console.log(`${selfTest.passed}/${selfTest.total} lulus, ${selfTest.failed} gagal, ${selfTest.skipped} dilewati`);
for (const result of selfTest.results) console.log(`  ${result.status.padEnd(7)} ${result.name}`);

process.exitCode = verify.ok && !rerun.changed && selfTest.failed === 0 ? 0 : 1;
