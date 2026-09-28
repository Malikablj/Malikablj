'use strict';

/**
 * The target database definition (schema, schema version, enum seed) read from the Apps Script sources through the
 * emulator, so the package builder and the Apps Script runtime share one source of truth.
 */

const { loadGasProject } = require('../../../../tools/gas-emulator/load-gas');

function loadTarget(project = loadGasProject()) {
  const schema = project.run('getSchema_()');
  return {
    schema: { version: project.run('SCHEMA_VERSION'), byName: schema.byName, tables: schema.tables },
    enumDefinitions: project.run('getEnumDefinitions_()'),
  };
}

module.exports = { loadTarget };
