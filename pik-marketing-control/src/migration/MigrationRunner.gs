/**
 * Migration runtime (Phase 03). The same code runs in Apps Script (entry points in services/MigrationService.gs) and in
 * the Node emulator rehearsal (migration/scripts/migrate.js).
 *
 * Input is a migration package built locally from the source workbook (PROFILE + MAP, migration/scripts/migrate.js):
 * plain JSON with the target records, one accounting entry per source row, expectations computed from the raw source
 * rows, the transformation log and a SHA-256 over all of it.
 *   VALIDATE  package integrity: format, schema version, SHA-256, load order, known tables/columns, complete accounting
 *   DRY RUN   plan (insert/update/skip/conflict) and full schema validation against the live database; writes nothing
 *   MIGRATE   only after a successful dry run of the same package; batches under one script lock; resumable
 *   VERIFY    counts, missing, duplicates, content, unmatched, orphans, invalid references, totals, idempotency,
 *             reconciliation of PO / delivery / return / outstanding / invoice
 * Rerunning is safe: unchanged records are skipped, records still owned by the migration are updated, and records edited
 * by users since the migration are never overwritten (a MIGRATION_CONFLICT issue is recorded instead).
 */

const MIGRATION_PACKAGE_FORMAT = 'pik-marketing-control/migration-package';
const MIGRATION_PACKAGE_FORMAT_VERSION = 1;
const MIGRATION_ACTOR = 'system:migration';
const MIGRATION_CHUNK_SIZE = 500;
const MIGRATION_TIME_BUDGET_MS = 270000; // Apps Script stops an execution after 6 minutes
const MIGRATION_LOCK_TIMEOUT_MS = 30000;
const MIGRATION_MAX_LISTED = 50;
const MIGRATION_ISSUES_TABLE = 'MIGRATION_ISSUES';
const MIGRATION_DISPOSITIONS = ['MIGRATED', 'EXCLUDED', 'ISSUE_ONLY', 'REPRESENTED', 'NOT_MIGRATED'];
const MIGRATION_CLOSED_PO_STATUSES = ['CLOSED', 'CANCELLED'];

function digestHex_(algorithm, text) {
  return Utilities.computeDigest(algorithm, text, Utilities.Charset.UTF_8).map(function (byte) {
    const value = (byte + 256) % 256;
    return (value < 16 ? '0' : '') + value.toString(16);
  }).join('');
}

/** SHA-256 of the package without its packageHash field (the builder hashes the same serialization). */
function migrationPackageHash_(pkg) {
  const copy = {};
  Object.keys(pkg).forEach(function (key) {
    if (key !== 'packageHash') copy[key] = pkg[key];
  });
  return digestHex_(Utilities.DigestAlgorithm.SHA_256, JSON.stringify(copy));
}

function countBy_(items, keyOf) {
  const counts = {};
  items.forEach(function (item) {
    const key = keyOf(item);
    counts[key] = (counts[key] || 0) + 1;
  });
  return counts;
}

/** Sum rounded to 6 decimals, which removes binary floating-point noise (same rounding as the package builder). */
function roundSum_(value) {
  return Math.round(value * 1e6) / 1e6;
}

function chunkList_(items, size) {
  const chunks = [];
  for (let i = 0; i < items.length; i += size) chunks.push(items.slice(i, i + size));
  return chunks;
}

/** What the package contains (source, mapping, counts, issues), without touching the database. */
function describeMigrationPackage_(pkg) {
  const tables = pkg.tables || {};
  const issues = tables[MIGRATION_ISSUES_TABLE] || [];
  const records = {};
  (pkg.loadOrder || []).forEach(function (name) { records[name] = Array.isArray(tables[name]) ? tables[name].length : null; });
  const expectations = pkg.expectations || {};
  return {
    packageHash: pkg.packageHash || null,
    source: pkg.source || null,
    mappingVersion: pkg.mappingVersion || null,
    schemaVersion: pkg.schemaVersion === undefined ? null : pkg.schemaVersion,
    sourceSheets: expectations.sheets || null,
    sourceRows: (pkg.accounting || []).length,
    dispositions: expectations.dispositions || null,
    records: records,
    issuesBySeverity: countBy_(issues, function (issue) { return issue.severity; }),
    issuesByType: countBy_(issues, function (issue) { return issue.issue_type; }),
    transformationsByRule: countBy_(pkg.transformations || [], function (item) { return item.table + ': ' + item.rule; }),
    structuralErrors: (pkg.structuralErrors || []).length,
    openDecisions: (pkg.decisions || []).filter(function (decision) { return decision.status === 'OPEN'; })
  };
}

// ---------------------------------------------------------------------------------------------------------------
// VALIDATE: package integrity (no database access)
// ---------------------------------------------------------------------------------------------------------------

function checkMigrationPackage_(pkg) {
  const errors = [];
  const fail = function (code, message, details) {
    errors.push({ code: code, message: message, details: details === undefined ? null : details });
  };
  const done = function () {
    return { ok: errors.length === 0, errorCount: errors.length, errors: errors.slice(0, MIGRATION_MAX_LISTED) };
  };
  if (!pkg || typeof pkg !== 'object' || Array.isArray(pkg)) {
    fail('PACKAGE_INVALID', 'Paket migrasi tidak valid.');
    return done();
  }
  if (pkg.format !== MIGRATION_PACKAGE_FORMAT || pkg.formatVersion !== MIGRATION_PACKAGE_FORMAT_VERSION) {
    fail('PACKAGE_FORMAT', 'Format paket tidak dikenal: ' + pkg.format + ' v' + pkg.formatVersion + '.');
    return done();
  }
  if (!pkg.packageHash || migrationPackageHash_(pkg) !== pkg.packageHash) {
    fail('PACKAGE_HASH', 'Isi paket tidak cocok dengan packageHash (paket rusak atau diubah setelah dibuat).');
  }
  if (pkg.schemaVersion !== SCHEMA_VERSION) {
    fail('PACKAGE_SCHEMA_VERSION', 'Paket dibuat untuk skema v' + pkg.schemaVersion + ', kode memakai v' + SCHEMA_VERSION + '.');
  }
  if (!pkg.source || typeof pkg.source.file !== 'string' || pkg.source.file === '') {
    fail('PACKAGE_SOURCE', 'Paket tidak mencatat file sumber.');
  }
  if (Array.isArray(pkg.structuralErrors) && pkg.structuralErrors.length > 0) {
    fail('PACKAGE_STRUCTURAL', pkg.structuralErrors.length + ' error struktural dari pembuatan paket.', pkg.structuralErrors.slice(0, 20));
  }
  const loadOrder = Array.isArray(pkg.loadOrder) ? pkg.loadOrder : [];
  const tables = pkg.tables && typeof pkg.tables === 'object' ? pkg.tables : {};
  if (loadOrder.indexOf(MIGRATION_ISSUES_TABLE) === -1) fail('PACKAGE_TABLE', 'Tabel ' + MIGRATION_ISSUES_TABLE + ' tidak ada di loadOrder.');
  Object.keys(tables).forEach(function (name) {
    if (loadOrder.indexOf(name) === -1) fail('PACKAGE_TABLE', 'Tabel ' + name + ' tidak ada di loadOrder.');
  });
  const recordUses = {};
  loadOrder.forEach(function (name, position) {
    const table = getSchema_().byName[name];
    if (loadOrder.indexOf(name) !== position) {
      fail('PACKAGE_LOAD_ORDER', 'Tabel ' + name + ' muncul lebih dari sekali di loadOrder.');
      return;
    }
    if (!table || table.kind !== TABLE_KIND.DATA || !table.hasId) {
      fail('PACKAGE_TABLE', 'Tabel ' + name + ' tidak dikenal atau bukan tabel data.');
      return;
    }
    table.columns.forEach(function (column) {
      if (column.ref && loadOrder.indexOf(column.ref) > position) {
        fail('PACKAGE_LOAD_ORDER', name + ' dimuat sebelum ' + column.ref + ' yang dirujuknya.');
      }
    });
    const records = tables[name];
    if (!Array.isArray(records)) {
      fail('PACKAGE_TABLE', 'Tabel ' + name + ' tidak berisi daftar record.');
      return;
    }
    const unknown = {};
    const ids = {};
    records.forEach(function (record) {
      if (!record || typeof record !== 'object' || Array.isArray(record)) {
        fail('PACKAGE_RECORD', 'Record tidak valid di ' + name + '.');
        return;
      }
      Object.keys(record).forEach(function (column) {
        if (!table.columnByName[column]) unknown[column] = true;
      });
      if (typeof record.id !== 'string' || record.id === '') {
        fail('PACKAGE_RECORD_ID', 'Record tanpa ID di ' + name + '.');
      } else if (ids[record.id]) {
        fail('PACKAGE_DUPLICATE_ID', 'ID ' + record.id + ' muncul lebih dari sekali di ' + name + '.');
      } else {
        ids[record.id] = true;
        recordUses[name + '|' + record.id] = 0;
      }
    });
    if (Object.keys(unknown).length > 0) {
      fail('PACKAGE_COLUMN', 'Kolom tidak dikenal di ' + name + ': ' + Object.keys(unknown).join(', ') + '.');
    }
    const expected = pkg.expectations && pkg.expectations.counts && pkg.expectations.counts[name];
    if (expected && expected.expectedRecords !== records.length) {
      fail('PACKAGE_COUNT', name + ': ' + records.length + ' record, diharapkan ' + expected.expectedRecords + '.');
    }
  });
  const expectations = pkg.expectations;
  const expectationParts = ['counts', 'sums', 'nonNull', 'unmatched', 'sheets', 'dispositions', 'issueTypes'];
  if (!expectations || typeof expectations !== 'object' || expectationParts.some(function (part) {
    return !expectations[part] || typeof expectations[part] !== 'object';
  })) {
    fail('PACKAGE_EXPECTATIONS', 'Paket tidak memuat ekspektasi lengkap (' + expectationParts.join(', ') + ').');
    return done();
  }
  if (!Array.isArray(pkg.accounting) || pkg.accounting.length === 0) {
    fail('PACKAGE_ACCOUNTING', 'Paket tidak memuat accounting baris sumber.');
    return done();
  }
  checkMigrationAccounting_(pkg, tables, recordUses, fail);
  return done();
}

/** Every source row has exactly one accounting entry, and every entry points at a record or an issue in the package. */
function checkMigrationAccounting_(pkg, tables, recordUses, fail) {
  const accounting = pkg.accounting;
  const issueIds = {};
  (Array.isArray(tables[MIGRATION_ISSUES_TABLE]) ? tables[MIGRATION_ISSUES_TABLE] : []).forEach(function (issue) {
    if (issue && typeof issue.id === 'string') issueIds[issue.id] = true;
  });
  const rowsPerSheet = {};
  const seenRows = {};
  accounting.forEach(function (entry) {
    const where = entry.sheet + '!' + entry.row;
    rowsPerSheet[entry.sheet] = (rowsPerSheet[entry.sheet] || 0) + 1;
    if (seenRows[where]) fail('PACKAGE_ACCOUNTING', 'Baris ' + where + ' tercatat lebih dari sekali.');
    seenRows[where] = true;
    if (MIGRATION_DISPOSITIONS.indexOf(entry.disposition) === -1) {
      fail('PACKAGE_ACCOUNTING', 'Disposisi tidak dikenal untuk ' + where + ': ' + entry.disposition + '.');
      return;
    }
    const linked = Array.isArray(entry.issueIds) ? entry.issueIds : [];
    const missingIssue = linked.filter(function (id) { return !issueIds[id]; });
    if (missingIssue.length > 0) fail('PACKAGE_ACCOUNTING', 'Isu ' + missingIssue.join(', ') + ' untuk ' + where + ' tidak ada di paket.');
    if (entry.disposition === 'MIGRATED') {
      const key = entry.table + '|' + entry.recordId;
      if (recordUses[key] === undefined) fail('PACKAGE_ACCOUNTING', 'Baris ' + where + ' tidak punya record ' + key + '.');
      else recordUses[key]++;
    } else if (entry.disposition === 'EXCLUDED' || entry.disposition === 'ISSUE_ONLY' ||
      (entry.disposition === 'REPRESENTED' && entry.table === MIGRATION_ISSUES_TABLE)) {
      if (linked.length === 0) fail('PACKAGE_ACCOUNTING', 'Baris ' + where + ' (' + entry.disposition + ') tanpa isu.');
    }
  });
  Object.keys(recordUses).forEach(function (key) {
    if (key.indexOf(MIGRATION_ISSUES_TABLE + '|') === 0) return;
    if (recordUses[key] !== 1) fail('PACKAGE_ACCOUNTING', 'Record ' + key + ' tercatat ' + recordUses[key] + ' kali di accounting.');
  });
  const sheets = pkg.expectations.sheets;
  Object.keys(sheets).forEach(function (sheet) {
    if ((rowsPerSheet[sheet] || 0) !== sheets[sheet]) {
      fail('PACKAGE_ACCOUNTING', 'Sheet ' + sheet + ': ' + (rowsPerSheet[sheet] || 0) + ' baris tercatat, sumber ' + sheets[sheet] + '.');
    }
  });
  Object.keys(rowsPerSheet).forEach(function (sheet) {
    if (sheets[sheet] === undefined) fail('PACKAGE_ACCOUNTING', 'Sheet ' + sheet + ' tidak ada di ringkasan sumber.');
  });
}

// ---------------------------------------------------------------------------------------------------------------
// DRY RUN: plan + validation, no writes
// ---------------------------------------------------------------------------------------------------------------

function migrationValuesEqual_(a, b) {
  const aEmpty = a === null || a === undefined || a === '';
  const bEmpty = b === null || b === undefined || b === '';
  if (aEmpty || bEmpty) return aEmpty === bEmpty;
  if (typeof a === 'number' && typeof b === 'number') return Math.abs(a - b) < 1e-9;
  return a === b;
}

/** Package columns whose stored value differs. */
function migrationDifferences_(record, stored) {
  return Object.keys(record).filter(function (column) { return !migrationValuesEqual_(record[column], stored[column]); });
}

/**
 * What loading `records` into a table would do: insert new IDs, skip unchanged records, update records the migration
 * still owns, and leave records edited by users alone (a conflict on tables with migration_hash; on MIGRATION_ISSUES the
 * Admin's review is simply kept).
 */
function planMigrationTable_(tableName, records) {
  const state = loadTable_(tableName);
  const hashed = Boolean(state.table.columnByName.migration_hash);
  const plan = { table: tableName, inserts: [], updates: [], skips: 0, kept: 0, conflicts: [] };
  records.forEach(function (record) {
    const existing = state.byId[record.id];
    if (!existing) {
      plan.inserts.push(record);
      return;
    }
    const unchanged = hashed ? existing.migration_hash === record.migration_hash : migrationDifferences_(record, existing).length === 0;
    if (unchanged) plan.skips++;
    else if (existing.updated_by === MIGRATION_ACTOR) plan.updates.push({ record: record, existing: existing });
    else if (hashed) plan.conflicts.push({ record: record, existing: existing });
    else plan.kept++;
  });
  return plan;
}

function migrationInput_(table, record, now) {
  const input = Object.assign({}, record);
  if (table.columnByName.migrated_at) input.migrated_at = now;
  return input;
}

/** Patch for an update: every package value except the id. */
function migrationUpdatePatch_(table, record, now) {
  const patch = {};
  Object.keys(record).forEach(function (column) {
    if (column !== 'id') patch[column] = record[column];
  });
  if (table.columnByName.migrated_at) patch.migrated_at = now;
  return patch;
}

function locatedError_(tableName, id, error) {
  return { table: tableName, id: id || null, field: error.field || null, code: error.code, message: error.message };
}

/** Validates every planned insert/update against the schema, as if all were written. Returns located errors. */
function validateMigrationPlans_(plans, ctx) {
  const errors = [];
  const prepared = {};
  plans.forEach(function (plan) {
    const table = getTableDef_(plan.table);
    const state = loadTable_(plan.table);
    const usedIds = new Set(Object.keys(state.byId).concat(state.duplicateIds));
    const entry = { byId: {}, inserts: [], updates: [], previous: {} };
    plan.inserts.forEach(function (record) {
      const result = prepareInsertRecord_(table, migrationInput_(table, record, ctx.now), ctx, usedIds, ctx.now, state.timeZone);
      result.errors.forEach(function (error) { errors.push(locatedError_(plan.table, record.id, error)); });
      entry.byId[result.record.id] = result.record;
      entry.inserts.push(result.record);
    });
    plan.updates.forEach(function (item) {
      const patched = applyPatch_(table, item.existing, migrationUpdatePatch_(table, item.record, ctx.now), ctx, state.timeZone);
      patched.errors.forEach(function (error) { errors.push(locatedError_(plan.table, item.record.id, error)); });
      const next = patched.record;
      if (table.hasAudit) {
        next.updated_at = ctx.now;
        next.updated_by = ctx.actor;
      }
      entry.byId[next.id] = next;
      entry.updates.push(next);
      entry.previous[next.id] = item.existing;
    });
    prepared[plan.table] = entry;
  });

  // References resolve against the database plus everything the plans would write (built once per table).
  const merged = {};
  const lookup = function (tableName) {
    if (!merged[tableName]) {
      const state = loadTable_(tableName);
      const extra = prepared[tableName];
      merged[tableName] = extra ? { byId: Object.assign({}, state.byId, extra.byId), records: state.records } : state;
    }
    return merged[tableName];
  };
  plans.forEach(function (plan) {
    const table = getTableDef_(plan.table);
    const entry = prepared[plan.table];
    entry.inserts.forEach(function (record) {
      const env = { mode: 'insert', ctx: ctx, previous: null, lookup: lookup, enums: getEnumState_ };
      validateRecord_(table, record, env).forEach(function (error) { errors.push(locatedError_(plan.table, record.id, error)); });
    });
    entry.updates.forEach(function (record) {
      const env = { mode: 'update', ctx: ctx, previous: entry.previous[record.id], lookup: lookup, enums: getEnumState_ };
      validateRecord_(table, record, env).forEach(function (error) { errors.push(locatedError_(plan.table, record.id, error)); });
    });
    const candidates = entry.inserts.concat(entry.updates);
    validateUniqueness_(table, candidates, loadTable_(plan.table).records, idSet_(Object.keys(entry.previous)))
      .forEach(function (error) { errors.push(locatedError_(plan.table, candidates[error.index].id, error)); });
  });
  return errors;
}

function summarizePlans_(plans) {
  const summary = {};
  plans.forEach(function (plan) {
    summary[plan.table] = {
      insert: plan.inserts.length, update: plan.updates.length, skip: plan.skips, keptUserEdits: plan.kept,
      conflict: plan.conflicts.length
    };
  });
  return summary;
}

/** DRY RUN: package integrity, database health, plan, and validation of every record. Writes nothing. */
function dryRunMigrationPackage_(pkg) {
  const startedAt = nowIso_();
  const report = {
    kind: 'DRY_RUN',
    startedAt: startedAt,
    packageHash: pkg && pkg.packageHash ? pkg.packageHash : null,
    source: pkg && pkg.source ? pkg.source : null,
    ok: false,
    integrity: checkMigrationPackage_(pkg),
    database: null,
    plan: {},
    errorCount: 0,
    errorsByCode: {},
    errors: [],
    warnings: []
  };
  if (!report.integrity.ok) return report;
  report.package = describeMigrationPackage_(pkg);
  const database = verifyDatabase_({ spreadsheet: getDatabaseSpreadsheet_() });
  report.database = {
    ok: database.ok, schemaVersion: database.schemaVersion, rows: database.totals.rows, errors: database.totals.errors,
    warnings: database.totals.warnings, firstErrors: database.errors.slice(0, 20)
  };
  if (!database.ok) return report;
  resetDbCache_();
  const ctx = normalizeWriteContext_({ actor: MIGRATION_ACTOR, migration: true, now: startedAt });
  const plans = pkg.loadOrder.map(function (name) { return planMigrationTable_(name, pkg.tables[name]); });
  const errors = validateMigrationPlans_(plans, ctx);
  report.plan = summarizePlans_(plans);
  plans.forEach(function (plan) {
    if (plan.conflicts.length > 0) {
      report.warnings.push({ code: 'MIGRATION_CONFLICT', table: plan.table, count: plan.conflicts.length,
        message: plan.conflicts.length + ' record ' + plan.table + ' sudah diubah pengguna; tidak akan ditimpa (dicatat sebagai isu).' });
    }
  });
  report.errorCount = errors.length;
  report.errorsByCode = countBy_(errors, function (error) { return error.table + ' ' + error.code + (error.field ? ':' + error.field : ''); });
  report.errors = errors.slice(0, MIGRATION_MAX_LISTED);
  report.ok = errors.length === 0;
  report.finishedAt = nowIso_();
  return report;
}

// ---------------------------------------------------------------------------------------------------------------
// MIGRATE
// ---------------------------------------------------------------------------------------------------------------

/**
 * Loads the package. options:
 *   dryRun       result (or stored marker) of a dry run of this package: required, must be ok
 *   operator     email recorded in the audit note (default: current user)
 *   timeBudgetMs / maxChunks  stop between batches (default 270 s / no limit); running again continues where it stopped
 * The dry run is repeated under the script lock before anything is written, so the plan cannot go stale.
 */
function runMigrationPackage_(pkg, options) {
  const opts = options || {};
  const marker = opts.dryRun;
  if (!pkg || !pkg.packageHash || !marker || marker.ok !== true || marker.packageHash !== pkg.packageHash) {
    throw appError_(ERROR_CODE.FORBIDDEN,
      'Migrasi nyata hanya dapat dijalankan setelah dry run paket yang sama selesai tanpa error. Jalankan dryRunMigration() dulu.');
  }
  const budget = {
    startedMs: Date.now(),
    timeMs: typeof opts.timeBudgetMs === 'number' ? opts.timeBudgetMs : MIGRATION_TIME_BUDGET_MS,
    chunksLeft: typeof opts.maxChunks === 'number' ? opts.maxChunks : -1
  };
  const operator = opts.operator || getActorEmail_();
  const result = withScriptLock_(function () {
    const recheck = dryRunMigrationPackage_(pkg);
    if (!recheck.ok) {
      throw appError_(ERROR_CODE.VALIDATION, 'Dry run ulang sebelum migrasi menemukan masalah; tidak ada data yang ditulis.', {
        integrity: recheck.integrity, database: recheck.database, errorCount: recheck.errorCount, errors: recheck.errors
      });
    }
    return applyMigrationPackage_(pkg, operator, budget);
  }, MIGRATION_LOCK_TIMEOUT_MS);
  if (result.completed) result.verification = verifyMigrationPackage_(pkg);
  return result;
}

/** Writes the plan table by table in load order (callers hold the script lock). */
function applyMigrationPackage_(pkg, operator, budget) {
  const now = nowIso_();
  const ctx = {
    actor: MIGRATION_ACTOR, migration: true, now: now, audit: 'summary',
    auditNote: 'Migrasi paket ' + pkg.packageHash.slice(0, 12) + ' dari ' + pkg.source.file + ' (operator ' + operator + ')'
  };
  const result = {
    kind: 'MIGRATE', startedAt: now, packageHash: pkg.packageHash, source: pkg.source, operator: operator,
    completed: false, stoppedAt: null, tables: {}
  };
  const mayContinue = function () {
    if (budget.chunksLeft === 0 || Date.now() - budget.startedMs >= budget.timeMs) return false;
    if (budget.chunksLeft > 0) budget.chunksLeft--;
    return true;
  };
  for (let t = 0; t < pkg.loadOrder.length; t++) {
    const tableName = pkg.loadOrder[t];
    const table = getTableDef_(tableName);
    resetDbCache_();
    const plan = planMigrationTable_(tableName, pkg.tables[tableName]);
    const counts = {
      inserted: 0, updated: 0, skipped: plan.skips, keptUserEdits: plan.kept, conflicts: plan.conflicts.length, conflictIssues: 0
    };
    result.tables[tableName] = counts;
    const insertChunks = chunkList_(plan.inserts, MIGRATION_CHUNK_SIZE);
    for (let i = 0; i < insertChunks.length; i++) {
      if (!mayContinue()) {
        result.stoppedAt = tableName;
        return result;
      }
      dbInsert_(tableName, insertChunks[i].map(function (record) { return migrationInput_(table, record, now); }), ctx);
      counts.inserted += insertChunks[i].length;
    }
    const updateChunks = chunkList_(plan.updates, MIGRATION_CHUNK_SIZE);
    for (let i = 0; i < updateChunks.length; i++) {
      if (!mayContinue()) {
        result.stoppedAt = tableName;
        return result;
      }
      dbUpdateMany_(tableName, updateChunks[i].map(function (item) {
        return { id: item.record.id, patch: migrationUpdatePatch_(table, item.record, now) };
      }), ctx);
      counts.updated += updateChunks[i].length;
    }
    if (plan.conflicts.length > 0) counts.conflictIssues = recordMigrationConflicts_(tableName, plan.conflicts, ctx);
  }
  result.completed = true;
  result.finishedAt = nowIso_();
  return result;
}

/** A record edited by a user since the migration is not overwritten; the newer source values are kept in an issue. */
function recordMigrationConflicts_(tableName, conflicts, ctx) {
  resetDbCache_();
  const existingIssues = loadTable_(MIGRATION_ISSUES_TABLE).byId;
  const issues = [];
  conflicts.forEach(function (item) {
    const key = ['MIGRATION_CONFLICT', tableName, item.record.id, item.record.migration_hash].join('|');
    const id = 'MIG-' + digestHex_(Utilities.DigestAlgorithm.SHA_1, key).slice(0, 10).toUpperCase();
    if (existingIssues[id]) return;
    issues.push({
      id: id, severity: 'HIGH', issue_type: 'MIGRATION_CONFLICT', entity_type: tableName.toLowerCase(),
      record_id: item.record.id,
      field: migrationDifferences_(item.record, item.existing).join(', ').slice(0, 100) || null,
      value: JSON.stringify(item.record).slice(0, 5000),
      description: 'Data sumber berubah setelah record ini diubah pengguna (' + item.existing.updated_by + '). Record tidak ' +
        'ditimpa; nilai sumber terbaru disimpan di kolom Nilai untuk ditinjau.',
      import_ref: item.record.import_ref || null,
      resolution_status: 'OPEN'
    });
  });
  if (issues.length > 0) dbInsert_(MIGRATION_ISSUES_TABLE, issues, ctx);
  return issues.length;
}

// ---------------------------------------------------------------------------------------------------------------
// VERIFY (read-only)
// ---------------------------------------------------------------------------------------------------------------

/** Verifies the database against the package and the source totals it carries. */
function verifyMigrationPackage_(pkg) {
  const report = {
    kind: 'VERIFY', verifiedAt: nowIso_(), packageHash: pkg && pkg.packageHash ? pkg.packageHash : null,
    source: pkg && pkg.source ? pkg.source : null, ok: false, checks: [], reconciliation: null
  };
  const add = function (name, passed, details, informational) {
    report.checks.push({ name: name, status: informational ? 'INFO' : passed ? 'PASS' : 'FAIL', details: details });
  };
  const integrity = checkMigrationPackage_(pkg);
  add('Integritas paket (format, SHA-256, skema, urutan muat, accounting)', integrity.ok, integrity);
  if (!integrity.ok) return report;

  const database = verifyDatabase_({ spreadsheet: getDatabaseSpreadsheet_(), includeAuditLog: true, maxIssuesPerTable: 20 });
  add('verifyDatabase: tipe, wajib, enum, record yatim (orphan), referensi tidak valid, konsistensi relasi, keunikan',
    database.ok, {
      rows: database.totals.rows, errors: database.totals.errors, warnings: database.totals.warnings,
      byCode: countBy_(database.errors, function (error) { return error.sheet + ' ' + error.code; }),
      firstErrors: database.errors.slice(0, 20)
    });
  resetDbCache_();

  // Records edited by users after the migration (linking a delivery, resolving an issue, ...) are expected. Content checks
  // use what the migration wrote for them (asMigrated); their current values are counted, reconciled and in AUDIT_LOG.
  const dbById = {};
  const asMigrated = {};
  pkg.loadOrder.forEach(function (name) {
    const state = loadTable_(name);
    dbById[name] = state.byId;
    asMigrated[name] = verifyMigrationTable_(pkg, name, state, add);
  });
  verifyMigrationAccounting_(pkg, dbById, asMigrated, add);
  verifyMigrationTotals_(pkg, asMigrated, add);

  const rerun = summarizePlans_(pkg.loadOrder.map(function (name) { return planMigrationTable_(name, pkg.tables[name]); }));
  const pending = Object.keys(rerun).filter(function (name) { return rerun[name].insert + rerun[name].update > 0; });
  add('Idempoten: menjalankan migrasi ulang tidak menulis apa pun', pending.length === 0, { pendingTables: pending, plan: rerun });

  report.reconciliation = reconcileMigration_(pkg, dbById);
  add('Rekonsiliasi PO / delivery / retur / outstanding / invoice (informasi, data legacy tidak lengkap — D3)', true,
    report.reconciliation.summary, true);
  report.ok = report.checks.every(function (check) { return check.status !== 'FAIL'; });
  report.summary = {
    pass: report.checks.filter(function (check) { return check.status === 'PASS'; }).length,
    fail: report.checks.filter(function (check) { return check.status === 'FAIL'; }).length,
    info: report.checks.filter(function (check) { return check.status === 'INFO'; }).length
  };
  return report;
}

/**
 * One table: every package record present with the same content, no duplicate import_ref, no unexpected legacy rows.
 * Returns the records by id as the migration wrote them (package values for records edited by users since).
 */
function verifyMigrationTable_(pkg, name, state, add) {
  const table = state.table;
  const records = pkg.tables[name];
  const missing = [];
  const mismatched = [];
  const asMigrated = {};
  let editedByUsers = 0;
  records.forEach(function (record) {
    const stored = state.byId[record.id];
    if (!stored) {
      missing.push(record.id);
      return;
    }
    asMigrated[record.id] = stored;
    const differing = migrationDifferences_(record, stored);
    if (differing.length === 0) return;
    if (stored.updated_by !== MIGRATION_ACTOR) {
      editedByUsers++;
      asMigrated[record.id] = Object.assign({}, stored, record);
    } else {
      mismatched.push({ id: record.id, columns: differing });
    }
  });
  const duplicateImportRefs = [];
  if (table.columnByName.import_ref && name !== MIGRATION_ISSUES_TABLE) {
    const seen = {};
    state.records.forEach(function (stored) {
      if (!stored.import_ref) return;
      if (seen[stored.import_ref]) duplicateImportRefs.push(stored.import_ref);
      seen[stored.import_ref] = true;
    });
  }
  const packageIds = idSet_(records.map(function (record) { return record.id; }));
  const prefix = pkg.source.file + '#';
  const unexpected = table.columnByName.is_legacy ? state.records.filter(function (stored) {
    return stored.is_legacy === true && typeof stored.import_ref === 'string' && stored.import_ref.indexOf(prefix) === 0 &&
      !packageIds[stored.id];
  }).map(function (stored) { return stored.id; }) : [];
  add('Record ' + name + ': jumlah sumber vs termigrasi, hilang, duplikat, isi',
    missing.length === 0 && mismatched.length === 0 && duplicateImportRefs.length === 0 && unexpected.length === 0, {
      expected: records.length, found: records.length - missing.length, missing: missing.length,
      missingIds: missing.slice(0, MIGRATION_MAX_LISTED), contentMismatches: mismatched.length,
      mismatchExamples: mismatched.slice(0, MIGRATION_MAX_LISTED), duplicateImportRefs: duplicateImportRefs.slice(0, MIGRATION_MAX_LISTED),
      unexpectedLegacyRecords: unexpected.slice(0, MIGRATION_MAX_LISTED), editedByUsers: editedByUsers,
      rowsInTable: state.records.length
    });
  return asMigrated;
}

function verifyMigrationAccounting_(pkg, dbById, asMigrated, add) {
  const issues = dbById[MIGRATION_ISSUES_TABLE];
  const enums = getEnumState_().byName;
  const problems = [];
  pkg.accounting.forEach(function (entry) {
    let ok = true;
    if (entry.disposition === 'MIGRATED') {
      ok = Boolean(dbById[entry.table] && dbById[entry.table][entry.recordId]);
    } else if (entry.disposition === 'REPRESENTED' && entry.table === 'ENUMS') {
      const parts = String(entry.recordId).split('.');
      ok = Boolean(enums[parts[0]] && enums[parts[0]].all[parts[1]]);
    }
    if (ok && entry.issueIds && entry.issueIds.length > 0) ok = entry.issueIds.every(function (id) { return Boolean(issues[id]); });
    if (!ok) problems.push(entry.sheet + '!' + entry.row + ' ' + entry.disposition);
  });
  add('Setiap baris sumber tercatat: dimigrasikan, dikecualikan + isu, hanya isu, diwakili, atau dokumentasi', problems.length === 0, {
    sourceRows: pkg.accounting.length,
    dispositions: countBy_(pkg.accounting, function (entry) { return entry.disposition; }),
    unaccounted: problems.length, examples: problems.slice(0, MIGRATION_MAX_LISTED)
  });

  const issueTypesByRecord = {};
  Object.keys(issues).forEach(function (id) {
    const issue = issues[id];
    if (!issue.record_id) return;
    (issueTypesByRecord[issue.record_id] || (issueTypesByRecord[issue.record_id] = {}))[issue.issue_type] = true;
  });
  const unmatched = {};
  const failed = [];
  const expectedUnmatched = (pkg.expectations && pkg.expectations.unmatched) || {};
  Object.keys(expectedUnmatched).forEach(function (key) {
    const expectation = expectedUnmatched[key];
    const parts = key.split('.');
    const empty = pkg.tables[parts[0]].filter(function (record) {
      const stored = asMigrated[parts[0]][record.id];
      return stored && (stored[parts[1]] === null || stored[parts[1]] === undefined);
    });
    const withoutIssue = empty.filter(function (record) {
      const types = issueTypesByRecord[record.id] || {};
      return !expectation.issueTypes.some(function (type) { return types[type]; });
    });
    unmatched[key] = { expected: expectation.count, found: empty.length, withoutIssue: withoutIssue.length,
      examples: withoutIssue.slice(0, 10).map(function (record) { return record.id; }) };
    if (empty.length !== expectation.count || withoutIssue.length > 0) failed.push(key);
  });
  add('Relasi tidak cocok (unmatched): jumlah sesuai paket dan setiap relasi kosong dijelaskan isu', failed.length === 0,
    { failed: failed, relations: unmatched });

  const expectedTypes = (pkg.expectations && pkg.expectations.issueTypes) || {};
  const storedTypes = countBy_(pkg.tables[MIGRATION_ISSUES_TABLE].filter(function (issue) { return Boolean(issues[issue.id]); }),
    function (issue) { return issues[issue.id].issue_type; });
  const typeMismatch = Object.keys(expectedTypes).filter(function (type) { return expectedTypes[type] !== storedTypes[type]; });
  add('MIGRATION_ISSUES: semua isu paket tersimpan, per jenis', typeMismatch.length === 0, {
    expected: pkg.tables[MIGRATION_ISSUES_TABLE].length, byType: storedTypes, mismatchedTypes: typeMismatch
  });
}

function sumStoredColumn_(pkg, asMigrated, tableName, column) {
  let total = 0;
  pkg.tables[tableName].forEach(function (record) {
    const stored = asMigrated[tableName][record.id];
    if (stored && typeof stored[column] === 'number') total += stored[column];
  });
  return roundSum_(total);
}

function verifyMigrationTotals_(pkg, asMigrated, add) {
  const expectations = pkg.expectations || {};
  const sums = {};
  const failedSums = [];
  Object.keys(expectations.sums || {}).forEach(function (key) {
    const parts = key.split('.');
    const found = sumStoredColumn_(pkg, asMigrated, parts[0], parts[1]);
    const expected = expectations.sums[key].sum;
    sums[key] = { source: expectations.sums[key].source, expected: expected, found: found };
    // Money is rounded to 2 decimals per record (T-04); the tolerance covers that rounding, nothing more.
    const tolerance = getTableDef_(parts[0]).columnByName[parts[1]].type === 'money' ? 0.005 * pkg.tables[parts[0]].length + 1e-6 : 1e-6;
    if (Math.abs(found - expected) > tolerance) failedSums.push(key);
  });
  add('Total numerik sumber = database (qty PO, delivery, retur, outstanding, stok, invoice/pembayaran, ringkasan keuangan)',
    failedSums.length === 0, { failed: failedSums, sums: sums });

  const counts = {};
  const failedCounts = [];
  Object.keys(expectations.nonNull || {}).forEach(function (key) {
    const parts = key.split('.');
    const found = pkg.tables[parts[0]].filter(function (record) {
      const stored = asMigrated[parts[0]][record.id];
      return stored && stored[parts[1]] !== null && stored[parts[1]] !== undefined;
    }).length;
    const expectation = expectations.nonNull[key];
    counts[key] = {
      source: expectation.source, sourceIds: expectation.sourceCount, unknownIds: expectation.unknownIds, expected: expectation.count,
      found: found
    };
    if (found !== expectation.count) failedCounts.push(key);
  });
  add('Relasi terisi = ID rujukan di sumber, kecuali ID yang tidak ada (FK_NOT_FOUND): tidak ada relasi hilang atau ditambahkan',
    failedCounts.length === 0, { failed: failedCounts, counts: counts });
}

/** Business reconciliation of the migrated data (informational: legacy links are known to be incomplete, D3). */
function reconcileMigration_(pkg, dbById) {
  const pick = function (tableName) {
    return pkg.tables[tableName].map(function (record) { return dbById[tableName][record.id]; }).filter(Boolean);
  };
  const sum = function (items, column) {
    return roundSum_(items.reduce(function (total, item) { return total + (typeof item[column] === 'number' ? item[column] : 0); }, 0));
  };
  const lines = pick('PO_LINES');
  const deliveries = pick('DELIVERIES');
  const returns = pick('RETURNS');
  const invoices = pick('INVOICES_PAYMENTS');
  const orders = pick('PURCHASE_ORDERS');
  const financials = pick('PO_FINANCIALS');

  const byLine = function (items) {
    const totals = {};
    items.forEach(function (item) {
      if (item.po_line_id && typeof item.quantity === 'number') totals[item.po_line_id] = (totals[item.po_line_id] || 0) + item.quantity;
    });
    return totals;
  };
  const deliveredByLine = byLine(deliveries);
  const returnedByLine = byLine(returns);
  const unlinkedByPo = {};
  deliveries.concat(returns).forEach(function (item) {
    if (item.purchase_order_id && !item.po_line_id) unlinkedByPo[item.purchase_order_id] = true;
  });
  const outstanding = { legacyEmpty: 0, equal: 0, different: 0, differentWithUnlinked: 0, computedTotal: 0, legacyTotal: 0 };
  const open = { lines: 0, computedTotal: 0, legacyTotal: 0, purchaseOrders: {} };
  const statusByPo = {};
  orders.forEach(function (po) { statusByPo[po.id] = po.status; });
  const examples = [];
  const perPo = {};
  lines.forEach(function (line) {
    const delivered = deliveredByLine[line.id] || 0;
    const returned = returnedByLine[line.id] || 0;
    const computed = Math.max(0, line.order_quantity - delivered + returned);
    const legacy = typeof line.outstanding_qty_legacy === 'number' ? line.outstanding_qty_legacy : null;
    const po = perPo[line.purchase_order_id] || (perPo[line.purchase_order_id] = {
      status: statusByPo[line.purchase_order_id] || null, lines: 0, computed: 0, legacy: 0, legacyEmpty: 0
    });
    po.lines++;
    po.computed += computed;
    outstanding.computedTotal += computed;
    if (MIGRATION_CLOSED_PO_STATUSES.indexOf(po.status) === -1) {
      open.lines++;
      open.computedTotal += computed;
      open.legacyTotal += legacy === null ? 0 : legacy;
      open.purchaseOrders[line.purchase_order_id] = true;
    }
    if (legacy === null) {
      outstanding.legacyEmpty++;
      po.legacyEmpty++;
      return;
    }
    po.legacy += legacy;
    outstanding.legacyTotal += legacy;
    if (Math.abs(computed - line.outstanding_qty_legacy) < 1e-9) {
      outstanding.equal++;
      return;
    }
    outstanding.different++;
    if (unlinkedByPo[line.purchase_order_id]) outstanding.differentWithUnlinked++;
    if (examples.length < 20) {
      examples.push({
        po_line_id: line.id, purchase_order_id: line.purchase_order_id, order: line.order_quantity, deliveredLinked: delivered,
        returnedLinked: returned, computed: computed, legacy: line.outstanding_qty_legacy,
        poHasUnlinkedTransactions: Boolean(unlinkedByPo[line.purchase_order_id])
      });
    }
  });
  const poOutstanding = Object.keys(perPo).map(function (poId) {
    const po = perPo[poId];
    return {
      purchase_order_id: poId, status: po.status, lines: po.lines, computed: roundSum_(po.computed), legacy: roundSum_(po.legacy),
      linesWithoutLegacy: po.legacyEmpty, difference: roundSum_(po.computed - po.legacy),
      hasUnlinkedTransactions: Boolean(unlinkedByPo[poId])
    };
  });
  const openPoIds = Object.keys(open.purchaseOrders);

  const linesByPo = countBy_(lines, function (line) { return line.purchase_order_id; });
  const invoicesByStatus = {};
  invoices.forEach(function (invoice) {
    const key = invoice.payment_status || '(kosong)';
    const entry = invoicesByStatus[key] || (invoicesByStatus[key] = { count: 0, amount: 0, paid: 0, outstandingLegacy: 0 });
    entry.count++;
    entry.amount = roundSum_(entry.amount + (invoice.amount || 0));
    entry.paid = roundSum_(entry.paid + (invoice.paid_amount || 0));
    entry.outstandingLegacy = roundSum_(entry.outstandingLegacy + (invoice.outstanding_legacy || 0));
  });
  const invoiceAmount = sum(invoices, 'amount');
  const invoicePaid = sum(invoices, 'paid_amount');
  const summary = {
    purchaseOrders: {
      count: orders.length, withoutCustomer: orders.filter(function (po) { return !po.customer_id; }).length,
      withoutLines: orders.filter(function (po) { return !linesByPo[po.id]; }).length,
      lines: lines.length, orderQuantity: sum(lines, 'order_quantity')
    },
    deliveries: {
      count: deliveries.length, quantity: sum(deliveries, 'quantity'),
      linkedToLine: deliveries.filter(function (d) { return Boolean(d.po_line_id); }).length,
      quantityLinkedToLine: roundSum_(Object.keys(deliveredByLine).reduce(function (t, k) { return t + deliveredByLine[k]; }, 0)),
      withoutPo: deliveries.filter(function (d) { return !d.purchase_order_id; }).length,
      negativeQuantity: deliveries.filter(function (d) { return typeof d.quantity === 'number' && d.quantity < 0; }).length
    },
    returns: {
      count: returns.length, quantity: sum(returns, 'quantity'),
      linkedToLine: returns.filter(function (r) { return Boolean(r.po_line_id); }).length,
      withoutPo: returns.filter(function (r) { return !r.purchase_order_id; }).length
    },
    outstanding: {
      formula: 'MAX(0, qty order - qty kirim tertaut + qty retur tertaut) per baris PO (rumus AppSheet)',
      lines: lines.length, legacyEmpty: outstanding.legacyEmpty, equalToLegacy: outstanding.equal,
      differentFromLegacy: outstanding.different, differentOnPoWithUnlinkedTransactions: outstanding.differentWithUnlinked,
      computedTotal: roundSum_(outstanding.computedTotal), legacyTotal: roundSum_(outstanding.legacyTotal),
      purchaseOrdersDifferent: poOutstanding.filter(function (po) { return Math.abs(po.difference) > 1e-9; }).length,
      openPurchaseOrders: {
        scope: 'PO berstatus selain ' + MIGRATION_CLOSED_PO_STATUSES.join('/') + ' (yang tampil sebagai outstanding di aplikasi)',
        purchaseOrders: openPoIds.length, lines: open.lines, computedTotal: roundSum_(open.computedTotal),
        legacyTotal: roundSum_(open.legacyTotal),
        purchaseOrdersDifferent: poOutstanding.filter(function (po) {
          return open.purchaseOrders[po.purchase_order_id] && Math.abs(po.difference) > 1e-9;
        }).length
      }
    },
    invoices: {
      count: invoices.length, amount: invoiceAmount, paid: invoicePaid, amountMinusPaid: roundSum_(invoiceAmount - invoicePaid),
      outstandingLegacy: sum(invoices, 'outstanding_legacy'), withoutPo: invoices.filter(function (i) { return !i.purchase_order_id; }).length,
      byPaymentStatus: invoicesByStatus
    },
    poFinancials: {
      count: financials.length, totalOrderAmount: sum(financials, 'total_order_amount'), ppn: sum(financials, 'ppn_amount'),
      totalInclPpn: sum(financials, 'total_incl_ppn'), outstandingAmountLegacy: sum(financials, 'outstanding_amount_legacy'),
      withoutPo: financials.filter(function (f) { return !f.purchase_order_id; }).length
    }
  };
  return { summary: summary, outstandingExamples: examples, outstandingByPo: poOutstanding };
}
