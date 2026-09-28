'use strict';

/**
 * Rehearsal of the Apps Script migration in the emulator. It calls the same public entry points the operator runs in
 * the Apps Script editor (setupDatabase, profileSourceWorkbook, validateMigrationMapping, dryRunMigration,
 * runMigration, verifyMigration) against an in-memory spreadsheet and an in-memory Drive. Nothing leaves this process.
 */

const { loadGasProject } = require('../../../../tools/gas-emulator/load-gas');

const REHEARSAL_OPERATOR = 'operator@rehearsal.local';

/** Values from the script realm as plain JSON data. */
function plain(value) {
  return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

/** A fresh emulated Apps Script project with an initialized, empty database (setupDatabase()). */
function createRehearsal(options = {}) {
  const operator = options.operator || REHEARSAL_OPERATOR;
  const project = loadGasProject({ env: { activeUserEmail: operator, effectiveUserEmail: operator, ...(options.env || {}) } });
  const { context, env } = project;
  if (options.now) context.setClockForTesting_(options.now);
  const setup = plain(context.setupDatabase());
  return { project, context, env, setup, operator };
}

/** Puts the package file into the emulated Drive and points MIGRATION_PACKAGE_FILE_ID at it (as the operator does). */
function uploadPackage(rehearsal, packageText) {
  const fileId = rehearsal.env.addDriveFile('migration-package.json', packageText);
  rehearsal.env.properties.MIGRATION_PACKAGE_FILE_ID = fileId;
  rehearsal.context.resetConfigCache_();
  return fileId;
}

function spreadsheetWrites(env) {
  return env.calls.filter((call) => call.mutating).length;
}

/** Calls a public entry point; returns { result } or { error } (AppErrors carry code, message and details). */
function call(rehearsal, name) {
  rehearsal.env.resetCalls();
  try {
    return { result: plain(rehearsal.context[name]()), spreadsheetWrites: spreadsheetWrites(rehearsal.env) };
  } catch (error) {
    return {
      error: { name: error.name, code: error.code || null, message: error.message, details: plain(error.details) || null },
      spreadsheetWrites: spreadsheetWrites(rehearsal.env),
    };
  }
}

/** runMigration() until it reports completed (it stops between batches at the Apps Script time limit). */
function runUntilComplete(rehearsal, maxRuns = 10) {
  const runs = [];
  for (let i = 0; i < maxRuns; i++) {
    const outcome = call(rehearsal, 'runMigration');
    runs.push(outcome);
    if (outcome.error || outcome.result.completed) return runs;
  }
  return runs;
}

/** Every sheet of the emulated database as rows of cell values (header first). */
function exportDatabase(rehearsal) {
  const spreadsheet = rehearsal.context.getDatabaseSpreadsheet_();
  const sheets = {};
  for (const sheet of spreadsheet.getSheets()) {
    sheets[sheet.getName()] = sheet.getLastRow() > 0 ? plain(sheet.getDataRange().getValues()) : [];
  }
  return { spreadsheetId: spreadsheet.getId(), sheets };
}

module.exports = { REHEARSAL_OPERATOR, call, createRehearsal, exportDatabase, plain, runUntilComplete, uploadPackage };
