'use strict';

/**
 * Runs the shared database test suite (src/tests/DatabaseTests.gs) against the Apps Script emulator.
 * The same cases run in real Apps Script through runDatabaseSelfTest().
 */

const assert = require('node:assert/strict');
const { after, test } = require('node:test');

const { loadGasProject } = require('./helpers/load-gas');

const { context } = loadGasProject();
const runner = context.createTestRunner_();

for (const testCase of context.getDatabaseTestCases_()) {
  test(testCase.name, () => {
    const result = context.runTestCase_(runner, testCase);
    assert.equal(result.status, 'PASSED', result.error || '');
  });
}

after(() => runner.cleanup());
