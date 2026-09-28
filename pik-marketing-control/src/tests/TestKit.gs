/**
 * Minimal test harness shared by two runners:
 *   - Node (tests/database.test.js) runs the cases against the Apps Script emulator;
 *   - runDatabaseSelfTest() runs the same cases in real Apps Script against temporary spreadsheets that are moved
 *     to the trash afterwards. The configured production database and Script Properties are never touched.
 */

const SELF_TEST_ACTOR = 'self-test@example.com';
const SELF_TEST_TIME_BUDGET_MS = 300000;

/**
 * Runs the database test suite in Apps Script. Optional `filter`: only run cases whose name starts with it
 * (e.g. 'init', 'validasi'). Returns { total, passed, failed, skipped, durationMs, results }.
 */
function runDatabaseSelfTest(filter) {
  requireMaintenanceAccess_();
  const prefix = typeof filter === 'string' ? filter : '';
  const cases = getDatabaseTestCases_().filter(function (testCase) { return testCase.name.indexOf(prefix) === 0; });
  const runner = createTestRunner_();
  const started = Date.now();
  const results = [];
  try {
    cases.forEach(function (testCase) {
      if (Date.now() - started > SELF_TEST_TIME_BUDGET_MS) {
        results.push({ name: testCase.name, status: 'SKIPPED', ms: 0, error: 'Batas waktu eksekusi; jalankan dengan filter.' });
        return;
      }
      const result = runTestCase_(runner, testCase);
      results.push(result);
      Logger.log(result.status + ' ' + result.name + ' (' + result.ms + ' ms)' + (result.error ? ' — ' + result.error : ''));
    });
  } finally {
    runner.cleanup();
  }
  const summary = summarizeTestResults_(results, Date.now() - started);
  Logger.log('Self-test: ' + summary.passed + '/' + summary.total + ' lulus, ' + summary.failed + ' gagal, ' +
    summary.skipped + ' dilewati.');
  return summary;
}

function summarizeTestResults_(results, durationMs) {
  const count = function (status) { return results.filter(function (r) { return r.status === status; }).length; };
  return {
    total: results.length,
    passed: count('PASSED'),
    failed: count('FAILED'),
    skipped: count('SKIPPED'),
    durationMs: durationMs,
    results: results
  };
}

/** Creates temporary spreadsheets on demand and trashes them in cleanup(). */
function createTestRunner_() {
  const createdIds = [];
  let shared = null;
  const runner = {
    newSpreadsheet: function (label) {
      const spreadsheet = SpreadsheetApp.create('PIK DB self-test ' + label + ' ' + nowIso_());
      createdIds.push(spreadsheet.getId());
      return spreadsheet;
    },
    /** One fully initialized database shared by the cases that only add their own records. */
    sharedDatabase: function () {
      if (!shared) {
        const spreadsheet = runner.newSpreadsheet('shared');
        const initReport = initializeDatabase_({
          spreadsheet: spreadsheet,
          actor: SELF_TEST_ACTOR,
          disposableSheets: spreadsheet.getSheets().map(function (sheet) { return sheet.getName(); })
        });
        shared = { spreadsheet: spreadsheet, initReport: initReport };
      }
      return shared;
    },
    cleanup: function () {
      createdIds.forEach(function (id) {
        try {
          DriveApp.getFileById(id).setTrashed(true);
        } catch (error) {
          Logger.log('Tidak dapat memindahkan spreadsheet uji ' + id + ' ke trash: ' + error);
        }
      });
      createdIds.length = 0;
      shared = null;
    }
  };
  return runner;
}

function runTestCase_(runner, testCase) {
  const started = Date.now();
  try {
    testCase.run(createTestHelpers_(runner));
    return { name: testCase.name, status: 'PASSED', ms: Date.now() - started, error: null };
  } catch (error) {
    return { name: testCase.name, status: 'FAILED', ms: Date.now() - started, error: describeTestError_(error) };
  } finally {
    setClockForTesting_(null);
    setIdRandomSourceForTesting_(null);
    resetDbCache_();
  }
}

function createTestHelpers_(runner) {
  return {
    shared: function () { return runner.sharedDatabase(); },
    fresh: function (label) { return runner.newSpreadsheet(label); },
    /** Runs `callback` with the database functions bound to the shared test database. */
    onShared: function (callback) { return withDatabaseSpreadsheet_(runner.sharedDatabase().spreadsheet, callback); }
  };
}

function describeTestError_(error) {
  if (!error) return 'Unknown error';
  let text = error.message || String(error);
  if (error.code) text += ' [' + error.code + ']';
  if (error.details) {
    const details = JSON.stringify(error.details);
    text += ' ' + (details.length > 800 ? details.slice(0, 800) + '…' : details);
  }
  return text;
}

// ---------------------------------------------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------------------------------------------

function assertTrue_(value, message) {
  if (!value) throw new Error('Assertion failed: ' + (message || 'expected truthy value'));
}

function assertEqual_(actual, expected, message) {
  if (actual !== expected) {
    throw new Error((message ? message + ': ' : '') + 'expected ' + JSON.stringify(expected) + ' but got ' +
      JSON.stringify(actual));
  }
}

function assertDeepEqual_(actual, expected, message) {
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a !== e) {
    throw new Error((message ? message + ': ' : '') + 'expected ' + truncateForMessage_(e) + ' but got ' +
      truncateForMessage_(a));
  }
}

function assertMatch_(value, pattern, message) {
  if (typeof value !== 'string' || !pattern.test(value)) {
    throw new Error((message ? message + ': ' : '') + JSON.stringify(value) + ' does not match ' + pattern);
  }
}

function truncateForMessage_(text) {
  return text && text.length > 600 ? text.slice(0, 600) + '…' : text;
}

/** Runs `callback`, expects an AppError with `code`, returns the error. */
function expectError_(callback, code, message) {
  try {
    callback();
  } catch (error) {
    if (!isAppError_(error) || error.code !== code) {
      throw new Error((message ? message + ': ' : '') + 'expected error ' + code + ' but got ' + describeTestError_(error));
    }
    return error;
  }
  throw new Error((message ? message + ': ' : '') + 'expected error ' + code + ' but nothing was thrown');
}

/**
 * Runs `callback`, expects a VALIDATION_ERROR containing every expected { field, code } (index optional).
 * Returns the validation errors.
 */
function expectValidation_(callback, expected, message) {
  const error = expectError_(callback, ERROR_CODE.VALIDATION, message);
  const errors = (error.details && error.details.errors) || [];
  (Array.isArray(expected) ? expected : [expected]).forEach(function (item) {
    const found = errors.some(function (candidate) {
      return candidate.field === item.field && candidate.code === item.code &&
        (item.index === undefined || candidate.index === item.index);
    });
    if (!found) {
      throw new Error((message ? message + ': ' : '') + 'expected validation error ' + JSON.stringify(item) +
        ' but got ' + truncateForMessage_(JSON.stringify(errors)));
    }
  });
  return errors;
}
