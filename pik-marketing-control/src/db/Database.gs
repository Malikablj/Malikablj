/**
 * Database lifecycle: spreadsheet resolution, setup, initialization and verification.
 *
 * initializeDatabase() is idempotent and non-destructive:
 *   1. plan  - inspect every sheet. A header that differs from the schema, data without a header, or a database
 *              schema newer than this code aborts the run BEFORE anything is written;
 *   2. apply - create missing sheets, write headers, append missing columns (only at the end), seed missing ENUMS
 *              and SETTINGS rows, then bring formats, dropdowns, notes, protection and README in line with the schema.
 * Existing data, enum labels and setting values are never overwritten. A second run changes nothing and writes no
 * audit entry.
 */

const DEFAULT_SHEET_NAME_PATTERN = /^(Sheet|Lembar)\s?\d+$/;

/** @type {GoogleAppsScript.Spreadsheet.Spreadsheet} */
var DB_SPREADSHEET_OVERRIDE_ = null;
/** @type {GoogleAppsScript.Spreadsheet.Spreadsheet} */
var DB_SPREADSHEET_CACHE_ = null;

/** @return {GoogleAppsScript.Spreadsheet.Spreadsheet} */
function getDatabaseSpreadsheet_() {
  if (DB_SPREADSHEET_OVERRIDE_) return DB_SPREADSHEET_OVERRIDE_;
  const id = getConfig_().DATABASE_SPREADSHEET_ID;
  if (!id) {
    throw appError_(ERROR_CODE.CONFIG_MISSING,
      'Script Property DATABASE_SPREADSHEET_ID belum diisi. Jalankan setupDatabase() terlebih dahulu.');
  }
  if (DB_SPREADSHEET_CACHE_ && DB_SPREADSHEET_CACHE_.getId() === id) return DB_SPREADSHEET_CACHE_;
  DB_SPREADSHEET_CACHE_ = openSpreadsheetById_(id);
  return DB_SPREADSHEET_CACHE_;
}

/** @return {GoogleAppsScript.Spreadsheet.Spreadsheet} */
function openSpreadsheetById_(id) {
  try {
    return SpreadsheetApp.openById(id);
  } catch (error) {
    throw appError_(ERROR_CODE.CONFIG_MISSING,
      'Spreadsheet database (DATABASE_SPREADSHEET_ID) tidak dapat dibuka. Periksa ID dan hak akses.', { id: id });
  }
}

/**
 * Runs `callback` with every database function bound to `spreadsheet` (used by initializer, verify and tests).
 * @param {GoogleAppsScript.Spreadsheet.Spreadsheet} spreadsheet
 */
function withDatabaseSpreadsheet_(spreadsheet, callback) {
  const previous = DB_SPREADSHEET_OVERRIDE_;
  DB_SPREADSHEET_OVERRIDE_ = spreadsheet;
  resetDbCache_();
  try {
    return callback();
  } finally {
    DB_SPREADSHEET_OVERRIDE_ = previous;
    resetDbCache_();
  }
}

// ---------------------------------------------------------------------------------------------------------------
// Public maintenance entry points (run from the Apps Script editor)
// ---------------------------------------------------------------------------------------------------------------

/** Creates the database spreadsheet when DATABASE_SPREADSHEET_ID is empty, then initializes it. */
function setupDatabase() {
  const actor = requireMaintenanceAccess_();
  const result = setupDatabase_({ actor: actor });
  Logger.log('setupDatabase: ' + JSON.stringify({
    spreadsheetId: result.spreadsheetId, created: result.created, changed: result.initialization.changed
  }));
  return result;
}

/** Creates missing sheets/columns/seed rows in the configured database. Safe to run repeatedly. */
function initializeDatabase() {
  const actor = requireMaintenanceAccess_();
  const report = initializeDatabase_({ actor: actor });
  Logger.log('initializeDatabase: ' + JSON.stringify(summarizeInitReport_(report)));
  return report;
}

/** Read-only integrity check of the configured database. */
function verifyDatabase() {
  requireMaintenanceAccess_();
  const report = verifyDatabase_({});
  Logger.log('verifyDatabase: ' + JSON.stringify({ ok: report.ok, totals: report.totals }));
  report.errors.slice(0, 50).forEach(function (issue) { Logger.log('ERROR ' + JSON.stringify(issue)); });
  report.warnings.slice(0, 50).forEach(function (issue) { Logger.log('WARNING ' + JSON.stringify(issue)); });
  return report;
}

// ---------------------------------------------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------------------------------------------

function setupDatabase_(options) {
  const opts = options || {};
  return withScriptLock_(function () {
    resetConfigCache_();
    const config = getConfig_();
    let spreadsheet;
    let created = false;
    if (config.DATABASE_SPREADSHEET_ID) {
      spreadsheet = openSpreadsheetById_(config.DATABASE_SPREADSHEET_ID);
    } else {
      spreadsheet = SpreadsheetApp.create(config.APP_NAME + ' Database');
      spreadsheet.setSpreadsheetTimeZone(config.TIMEZONE);
      if (config.DRIVE_ROOT_FOLDER_ID) {
        DriveApp.getFileById(spreadsheet.getId()).moveTo(DriveApp.getFolderById(config.DRIVE_ROOT_FOLDER_ID));
      }
      PropertiesService.getScriptProperties().setProperty('DATABASE_SPREADSHEET_ID', spreadsheet.getId());
      resetConfigCache_();
      created = true;
    }
    const initialization = initializeDatabase_({
      spreadsheet: spreadsheet,
      actor: opts.actor,
      // The default sheet of a spreadsheet created just now may be removed once empty, whatever its localized name.
      disposableSheets: created ? spreadsheet.getSheets().map(function (sheet) { return sheet.getName(); }) : []
    });
    return {
      spreadsheetId: spreadsheet.getId(),
      spreadsheetUrl: spreadsheet.getUrl(),
      created: created,
      initialization: initialization
    };
  });
}

// ---------------------------------------------------------------------------------------------------------------
// Initialization
// ---------------------------------------------------------------------------------------------------------------

/**
 * options: { spreadsheet (default: configured database), actor, tables (subset of table names; default all),
 *            disposableSheets (names of a new spreadsheet's default sheets that may be deleted while empty) }
 * Returns a report; throws SCHEMA_MISMATCH (with details.conflicts) without changing anything when the
 * existing structure cannot be reconciled safely.
 */
function initializeDatabase_(options) {
  const opts = options || {};
  const spreadsheet = opts.spreadsheet || getDatabaseSpreadsheet_();
  const tables = selectTables_(opts.tables);
  const fullSchema = !opts.tables;
  const disposableSheets = opts.disposableSheets || [];
  return withScriptLock_(function () {
    return withDatabaseSpreadsheet_(spreadsheet, function () {
      const plan = planInitialization_(spreadsheet, tables);
      if (plan.conflicts.length > 0) {
        throw appError_(ERROR_CODE.SCHEMA_MISMATCH,
          'Struktur database tidak sesuai skema; initializer dibatalkan tanpa perubahan. ' +
          plan.conflicts.map(function (conflict) { return conflict.message; }).join(' '),
          { conflicts: plan.conflicts });
      }
      return applyInitialization_(spreadsheet, plan, {
        actor: opts.actor || getActorEmail_(),
        now: nowIso_(),
        fullSchema: fullSchema,
        disposableSheets: disposableSheets
      });
    });
  });
}

function selectTables_(names) {
  if (!names) return getSchema_().tables;
  return names.map(function (name) { return getTableDef_(name); });
}

/**
 * Header state of a sheet compared with the schema.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function inspectSheetHeader_(sheet, table) {
  const lastColumn = sheet.getLastColumn();
  const lastRow = sheet.getLastRow();
  const header = lastColumn > 0
    ? sheet.getRange(1, 1, 1, lastColumn).getValues()[0].map(function (value) { return String(value).trim(); })
    : [];
  let headerLength = header.length;
  while (headerLength > 0 && header[headerLength - 1] === '') headerLength--;
  const expected = table.columnNames;
  const mismatches = [];
  for (let i = 0; i < Math.min(headerLength, expected.length); i++) {
    if (header[i] !== expected[i]) mismatches.push({ column: i + 1, expected: expected[i], actual: header[i] });
  }
  return {
    lastRow: lastRow,
    lastColumn: lastColumn,
    headerLength: headerLength,
    mismatches: mismatches,
    missingColumns: headerLength < expected.length ? expected.slice(headerLength) : [],
    extraColumns: header.slice(expected.length, headerLength)
  };
}

/** @param {GoogleAppsScript.Spreadsheet.Spreadsheet} spreadsheet */
function planInitialization_(spreadsheet, tables) {
  const plan = { items: [], conflicts: [], warnings: [] };
  tables.forEach(function (table) {
    const sheet = spreadsheet.getSheetByName(table.name);
    const item = { table: table, sheet: sheet, action: 'OK', existingColumns: 0, missingColumns: [] };
    plan.items.push(item);
    if (!sheet) {
      item.action = 'CREATE';
      item.missingColumns = table.columnNames.slice();
      return;
    }
    const state = inspectSheetHeader_(sheet, table);
    item.existingColumns = state.headerLength;
    if (state.headerLength === 0) {
      if (state.lastRow > 1) {
        plan.conflicts.push({ sheet: table.name, code: 'DATA_WITHOUT_HEADER',
          message: 'Sheet ' + table.name + ' berisi data tetapi baris header kosong.' });
      } else {
        item.action = 'WRITE_HEADER';
        item.missingColumns = table.columnNames.slice();
      }
      return;
    }
    if (state.mismatches.length > 0) {
      plan.conflicts.push({ sheet: table.name, code: 'HEADER_MISMATCH', mismatches: state.mismatches,
        message: 'Header ' + table.name + ' berbeda dari skema: ' + state.mismatches.map(function (m) {
          return 'kolom ' + m.column + ' "' + m.actual + '" (seharusnya "' + m.expected + '")';
        }).join(', ') + '.' });
      return;
    }
    if (state.missingColumns.length > 0) {
      if (state.lastColumn > state.headerLength) {
        plan.conflicts.push({ sheet: table.name, code: 'DATA_BEYOND_HEADER',
          message: 'Sheet ' + table.name + ' berisi data di kolom tanpa header; kolom baru tidak dapat ditambahkan.' });
        return;
      }
      item.action = 'APPEND_COLUMNS';
      item.missingColumns = state.missingColumns;
      return;
    }
    if (state.extraColumns.length > 0 || state.lastColumn > state.headerLength) {
      plan.warnings.push({ sheet: table.name, code: 'EXTRA_COLUMNS',
        message: 'Sheet ' + table.name + ' memiliki kolom di luar skema: ' + state.extraColumns.join(', ') + '.' });
    }
  });
  checkDatabaseSchemaVersion_(plan);
  return plan;
}

/** Aborts when the database was initialized by newer code (a higher SCHEMA_VERSION). */
function checkDatabaseSchemaVersion_(plan) {
  const settingsItem = findPlanItem_(plan, 'SETTINGS');
  if (!settingsItem || settingsItem.action !== 'OK') return;
  const version = readSettingRecord_(settingsItem.sheet, 'SCHEMA_VERSION');
  if (version && Number(version.record.value) > SCHEMA_VERSION) {
    plan.conflicts.push({ sheet: 'SETTINGS', code: 'SCHEMA_VERSION_NEWER',
      message: 'Database memakai SCHEMA_VERSION ' + version.record.value + ', lebih baru dari kode (' + SCHEMA_VERSION +
        '). Deploy kode terbaru sebelum menjalankan initializer.' });
  }
}

function findPlanItem_(plan, tableName) {
  for (let i = 0; i < plan.items.length; i++) {
    if (plan.items[i].table.name === tableName) return plan.items[i];
  }
  return null;
}

/** @param {GoogleAppsScript.Spreadsheet.Spreadsheet} spreadsheet */
function applyInitialization_(spreadsheet, plan, options) {
  const report = {
    spreadsheetId: spreadsheet.getId(),
    schemaVersion: SCHEMA_VERSION,
    timeZone: null,
    createdSheets: [],
    headersWritten: [],
    appendedColumns: {},
    seededEnumValues: 0,
    seededSettings: [],
    updatedSettings: [],
    formattedSheets: [],
    validatedSheets: [],
    notedSheets: [],
    frozenSheets: [],
    protectedSheets: [],
    readmeUpdated: false,
    removedDefaultSheets: [],
    warnings: plan.warnings.slice(),
    changed: false
  };

  const timeZone = getConfig_().TIMEZONE;
  if (options.fullSchema && spreadsheet.getSpreadsheetTimeZone() !== timeZone) {
    spreadsheet.setSpreadsheetTimeZone(timeZone);
    report.timeZone = timeZone;
  }

  plan.items.forEach(function (item) {
    const table = item.table;
    const width = table.columns.length;
    if (item.action === 'CREATE') {
      item.sheet = spreadsheet.insertSheet(table.name, spreadsheet.getNumSheets());
      fitNewSheetColumns_(item.sheet, width);
      item.sheet.getRange(1, 1, 1, width).setNumberFormat(TEXT_NUMBER_FORMAT).setValues([table.columnNames]);
      report.createdSheets.push(table.name);
    } else if (item.action === 'WRITE_HEADER' || item.action === 'APPEND_COLUMNS') {
      ensureColumnCapacity_(item.sheet, width);
      const start = item.existingColumns + 1;
      item.sheet.getRange(1, start, 1, item.missingColumns.length)
        .setNumberFormat(TEXT_NUMBER_FORMAT)
        .setValues([item.missingColumns]);
      if (item.action === 'WRITE_HEADER') report.headersWritten.push(table.name);
      else report.appendedColumns[table.name] = item.missingColumns.slice();
    }
  });
  resetDbCache_();

  const enumsItem = findPlanItem_(plan, 'ENUMS');
  if (enumsItem) report.seededEnumValues = seedEnums_(enumsItem.sheet);
  resetDbCache_();
  const enumValues = getEnumValuesForValidation_();

  const protectionIndex = indexDatabaseProtections_(spreadsheet);
  plan.items.forEach(function (item) {
    const result = ensureSheetLayout_(item, enumValues, protectionIndex);
    const name = item.table.name;
    if (result.frozen) report.frozenSheets.push(name);
    if (result.notes) report.notedSheets.push(name);
    if (result.formats) report.formattedSheets.push(name);
    if (result.validations) report.validatedSheets.push(name);
    if (result.protection) report.protectedSheets.push(name);
  });

  const settingsItem = findPlanItem_(plan, 'SETTINGS');
  if (settingsItem) {
    const settings = seedSettings_(settingsItem.sheet, options);
    report.seededSettings = settings.added;
    report.updatedSettings = settings.updated;
  }

  const readmeItem = findPlanItem_(plan, 'README');
  if (readmeItem) report.readmeUpdated = writeReadme_(readmeItem.sheet);

  if (options.fullSchema) {
    report.removedDefaultSheets = removeEmptyDefaultSheets_(spreadsheet, options.disposableSheets);
    spreadsheet.getSheets().forEach(function (sheet) {
      const name = sheet.getName();
      if (!getSchema_().byName[name] && report.removedDefaultSheets.indexOf(name) === -1) {
        report.warnings.push({ sheet: name, code: 'UNKNOWN_SHEET', message: 'Sheet ' + name + ' tidak ada di skema.' });
      }
    });
  }

  report.changed = hasInitializationChanges_(report);
  resetDbCache_();
  if (report.changed && findPlanItem_(plan, 'AUDIT_LOG')) {
    writeAuditEntries_([{ action: 'DB_INIT', entityType: 'DATABASE', entityId: null, changes: summarizeInitReport_(report) }],
      { actor: options.actor, requestId: Utilities.getUuid() }, options.now);
  }
  return report;
}

/**
 * New sheets start with 26 columns; trim or extend them to the table width. The sheet is empty at this point.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function fitNewSheetColumns_(sheet, width) {
  const maxColumns = sheet.getMaxColumns();
  if (maxColumns > width) sheet.deleteColumns(width + 1, maxColumns - width);
  else if (maxColumns < width) sheet.insertColumnsAfter(maxColumns, width - maxColumns);
}

/** Frozen header, header style and notes, number formats, dropdowns and protection. Returns what changed. */
function ensureSheetLayout_(item, enumValues, protectionIndex) {
  /** @type {GoogleAppsScript.Spreadsheet.Sheet} */
  const sheet = item.sheet;
  const table = item.table;
  const width = table.columns.length;
  const structural = item.action !== 'OK';
  const result = { frozen: false, notes: false, formats: false, validations: false, protection: false };
  if (sheet.getFrozenRows() !== 1) {
    sheet.setFrozenRows(1);
    result.frozen = true;
  }
  if (structural) styleHeader_(sheet, table);
  const notes = buildHeaderNotes_(table);
  const headerRange = sheet.getRange(1, 1, 1, width);
  if (JSON.stringify(headerRange.getNotes()) !== JSON.stringify(notes)) {
    headerRange.setNotes(notes);
    result.notes = true;
  }
  const dataRows = sheet.getMaxRows() - 1;
  if (dataRows > 0) {
    if (structural || !numberFormatsMatch_(sheet, table, 2, dataRows)) {
      applyNumberFormats_(sheet, table, 2, dataRows);
      result.formats = true;
    }
    const hasValidatedColumns = table.columns.some(function (column) {
      return buildColumnValidation_(column, enumValues) !== null;
    });
    if (hasValidatedColumns && (structural || !validationsMatch_(sheet, table, dataRows, enumValues))) {
      applyColumnValidations_(sheet, table, 2, dataRows, enumValues);
      result.validations = true;
    }
  }
  result.protection = ensureTableProtection_(sheet, table, protectionIndex);
  return result;
}

/**
 * Reads a table's rows straight from a sheet (header already checked by the plan).
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function readSheetEntries_(sheet, table) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return [];
  const timeZone = sheet.getParent().getSpreadsheetTimeZone();
  const values = sheet.getRange(2, 1, lastRow - 1, table.columns.length).getValues();
  const entries = [];
  values.forEach(function (row, index) {
    if (!isEmptySheetRow_(row)) entries.push({ rowNumber: index + 2, record: rowToRecord_(table, row, timeZone) });
  });
  return entries;
}

/** @param {GoogleAppsScript.Spreadsheet.Sheet} sheet */
function readSettingRecord_(sheet, key) {
  const entries = readSheetEntries_(sheet, getTableDef_('SETTINGS'));
  for (let i = 0; i < entries.length; i++) {
    if (entries[i].record.key === key) return entries[i];
  }
  return null;
}

/**
 * Appends the seed values that are missing. Existing rows (labels, order, active flag) are left untouched.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function seedEnums_(sheet) {
  const table = getTableDef_('ENUMS');
  const present = {};
  readSheetEntries_(sheet, table).forEach(function (entry) {
    present[entry.record.enum_name + '|' + entry.record.enum_value] = true;
  });
  const rows = buildEnumSeedRecords_()
    .filter(function (record) { return !present[record.enum_name + '|' + record.enum_value]; })
    .map(function (record) { return recordToRow_(table, record); });
  appendRowsToSheet_(sheet, table, rows);
  return rows.length;
}

/**
 * Appends missing settings and raises SCHEMA_VERSION after an upgrade. Existing values are otherwise kept.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function seedSettings_(sheet, options) {
  const table = getTableDef_('SETTINGS');
  const byKey = {};
  readSheetEntries_(sheet, table).forEach(function (entry) { byKey[entry.record.key] = entry; });
  const added = [];
  const updated = [];
  const rows = [];
  getSettingDefinitions_().forEach(function (definition) {
    if (byKey[definition.key]) return;
    rows.push(recordToRow_(table, {
      key: definition.key,
      value: definition.key === 'DB_INITIALIZED_AT' ? options.now : definition.value,
      value_type: definition.type,
      description: definition.description,
      is_system: definition.system === true,
      updated_at: options.now,
      updated_by: options.actor
    }));
    added.push(definition.key);
  });
  const version = byKey.SCHEMA_VERSION;
  if (version && Number(version.record.value) < SCHEMA_VERSION) {
    const record = Object.assign({}, version.record, {
      value: String(SCHEMA_VERSION), updated_at: options.now, updated_by: options.actor
    });
    applyNumberFormats_(sheet, table, version.rowNumber, 1);
    sheet.getRange(version.rowNumber, 1, 1, table.columns.length).setValues([recordToRow_(table, record)]);
    updated.push('SCHEMA_VERSION');
  }
  appendRowsToSheet_(sheet, table, rows);
  return { added: added, updated: updated };
}

function buildReadmeRows_() {
  const schema = getSchema_();
  const rows = [
    ['APP', 'PIK Marketing Control — database Google Sheets'],
    ['SCHEMA_VERSION', String(schema.version)],
    ['DIKELOLA_OLEH', 'Sheet ini dibuat ulang oleh initializeDatabase(); perubahan manual di sheet ini akan ditimpa.'],
    ['ATURAN_STRUKTUR', 'Jangan menyisipkan, menghapus, mengganti nama, atau mengubah urutan sheet/kolom secara manual. ' +
      'Kolom baru hanya ditambahkan di ujung kanan oleh initializeDatabase().'],
    ['ATURAN_ID', 'ID permanen berformat PREFIX-XXXXXXXXXX (heksadesimal huruf besar; AUDIT_LOG: AUD- dengan 16 digit). ' +
      'ID tidak pernah diambil dari nomor baris dan tidak boleh diubah.'],
    ['ATURAN_HAPUS', 'Tidak ada penghapusan permanen: record diarsipkan dengan is_active = FALSE.'],
    ['ATURAN_TIPE', 'Tanggal disimpan sebagai teks yyyy-MM-dd, waktu sebagai teks ISO 8601 UTC, angka sebagai number, ' +
      'TRUE/FALSE sebagai checkbox. Kolom teks berformat Plain text.'],
    ['ATURAN_LEGACY', 'is_legacy = TRUE menandai record hasil migrasi. Lineage (source_file, source_sheet, legacy_row, ' +
      'import_ref) dan kolom *_legacy hanya diisi oleh proses migrasi.'],
    ['ATURAN_TULIS', 'Semua perubahan data melalui aplikasi (validasi, LockService, AUDIT_LOG). Edit manual hanya untuk ' +
      'perbaikan darurat dan harus diperiksa dengan verifyDatabase().']
  ];
  schema.tables.forEach(function (table) {
    const id = table.hasId ? ' ID: ' + table.idPrefixes.join('/') + '-…' : '';
    rows.push(['TABEL ' + table.name, '[' + table.group + '] ' + table.description + id]);
  });
  return rows;
}

/**
 * Rewrites README content when it differs from the schema. Returns true when it was rewritten.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function writeReadme_(sheet) {
  const table = getTableDef_('README');
  const desired = buildReadmeRows_();
  const lastRow = sheet.getLastRow();
  const current = lastRow > 1
    ? sheet.getRange(2, 1, lastRow - 1, 2).getValues().map(function (row) { return [String(row[0]), String(row[1])]; })
    : [];
  if (JSON.stringify(current) === JSON.stringify(desired)) return false;
  if (lastRow > 1) sheet.getRange(2, 1, lastRow - 1, 2).clearContent();
  appendRowsToSheet_(sheet, table, desired);
  return true;
}

/**
 * Deletes the empty default sheet of a new spreadsheet ("Sheet1", or a name listed in `disposableNames`).
 * Sheets with any content are never deleted.
 * @param {GoogleAppsScript.Spreadsheet.Spreadsheet} spreadsheet
 * @param {string[]} disposableNames
 */
function removeEmptyDefaultSheets_(spreadsheet, disposableNames) {
  const removed = [];
  const disposable = disposableNames || [];
  spreadsheet.getSheets().forEach(function (sheet) {
    const name = sheet.getName();
    if (getSchema_().byName[name]) return;
    if (!DEFAULT_SHEET_NAME_PATTERN.test(name) && disposable.indexOf(name) === -1) return;
    if (sheet.getLastRow() !== 0 || sheet.getLastColumn() !== 0) return;
    if (spreadsheet.getSheets().length <= 1) return;
    spreadsheet.deleteSheet(sheet);
    removed.push(name);
  });
  return removed;
}

function hasInitializationChanges_(report) {
  return Boolean(report.timeZone) ||
    report.createdSheets.length > 0 ||
    report.headersWritten.length > 0 ||
    Object.keys(report.appendedColumns).length > 0 ||
    report.seededEnumValues > 0 ||
    report.seededSettings.length > 0 ||
    report.updatedSettings.length > 0 ||
    report.formattedSheets.length > 0 ||
    report.validatedSheets.length > 0 ||
    report.notedSheets.length > 0 ||
    report.frozenSheets.length > 0 ||
    report.protectedSheets.length > 0 ||
    report.readmeUpdated ||
    report.removedDefaultSheets.length > 0;
}

function summarizeInitReport_(report) {
  return {
    schemaVersion: report.schemaVersion,
    changed: report.changed,
    timeZone: report.timeZone,
    createdSheets: report.createdSheets,
    headersWritten: report.headersWritten,
    appendedColumns: report.appendedColumns,
    seededEnumValues: report.seededEnumValues,
    seededSettings: report.seededSettings,
    updatedSettings: report.updatedSettings,
    formattedSheets: report.formattedSheets.length,
    validatedSheets: report.validatedSheets.length,
    protectedSheets: report.protectedSheets.length,
    readmeUpdated: report.readmeUpdated,
    removedDefaultSheets: report.removedDefaultSheets,
    warnings: report.warnings.length
  };
}

// ---------------------------------------------------------------------------------------------------------------
// Verification (read-only)
// ---------------------------------------------------------------------------------------------------------------

/**
 * Checks structure (sheets, headers), seeds (ENUMS, SETTINGS, SCHEMA_VERSION) and every data row (types, required
 * values, enums, IDs, foreign keys, consistency, uniqueness, rules). Writes nothing.
 * options: { spreadsheet, maxIssuesPerTable (default 50), includeAuditLog (default false) }
 */
function verifyDatabase_(options) {
  const opts = options || {};
  const spreadsheet = opts.spreadsheet || getDatabaseSpreadsheet_();
  const limit = opts.maxIssuesPerTable || 50;
  return withDatabaseSpreadsheet_(spreadsheet, function () {
    const report = {
      ok: false,
      spreadsheetId: spreadsheet.getId(),
      verifiedAt: nowIso_(),
      schemaVersion: { code: SCHEMA_VERSION, database: null },
      tables: {},
      errors: [],
      warnings: [],
      totals: { rows: 0, errors: 0, warnings: 0 }
    };
    const collector = createIssueCollector_(report, limit);
    const healthy = {};

    getSchema_().tables.forEach(function (table) {
      report.tables[table.name] = { rows: 0, errors: 0, warnings: 0 };
      const sheet = spreadsheet.getSheetByName(table.name);
      if (!sheet) {
        collector.error(table.name, { code: 'SHEET_MISSING', message: 'Sheet ' + table.name + ' tidak ditemukan.' });
        return;
      }
      const state = inspectSheetHeader_(sheet, table);
      if (state.mismatches.length > 0 || state.headerLength < table.columns.length) {
        collector.error(table.name, { code: 'HEADER_MISMATCH', message: 'Header ' + table.name + ' tidak sesuai skema.',
          details: { mismatches: state.mismatches, missingColumns: state.missingColumns } });
        return;
      }
      if (state.extraColumns.length > 0) {
        collector.warning(table.name, { code: 'EXTRA_COLUMNS', message: 'Kolom di luar skema: ' + state.extraColumns.join(', ') });
      }
      if (sheet.getFrozenRows() !== 1) {
        collector.warning(table.name, { code: 'HEADER_NOT_FROZEN', message: 'Baris header tidak dibekukan.' });
      }
      healthy[table.name] = true;
    });
    spreadsheet.getSheets().forEach(function (sheet) {
      if (!getSchema_().byName[sheet.getName()]) {
        collector.warning(sheet.getName(), { code: 'UNKNOWN_SHEET', message: 'Sheet tidak ada di skema.' });
      }
    });

    if (healthy.ENUMS) verifyEnumSeeds_(collector);
    if (healthy.SETTINGS) verifySettingSeeds_(collector, report);

    getSchema_().tables.forEach(function (table) {
      if (!healthy[table.name] || table.kind === TABLE_KIND.DOC) return;
      if (table.name === 'AUDIT_LOG' && !opts.includeAuditLog) {
        report.tables.AUDIT_LOG.rows = Math.max(getCheckedSheet_(table).getLastRow() - 1, 0);
        return;
      }
      verifyTableRows_(table, collector, report, healthy);
    });

    Object.keys(report.tables).forEach(function (name) { report.totals.rows += report.tables[name].rows; });
    report.ok = report.totals.errors === 0;
    return report;
  });
}

function createIssueCollector_(report, limit) {
  function add(kind, sheetName, issue) {
    const tableEntry = report.tables[sheetName];
    if (tableEntry) tableEntry[kind === 'error' ? 'errors' : 'warnings']++;
    report.totals[kind === 'error' ? 'errors' : 'warnings']++;
    const list = kind === 'error' ? report.errors : report.warnings;
    const stored = list.filter(function (existing) { return existing.sheet === sheetName; }).length;
    if (stored < limit) list.push(Object.assign({ sheet: sheetName }, issue));
  }
  return {
    error: function (sheetName, issue) { add('error', sheetName, issue); },
    warning: function (sheetName, issue) { add('warning', sheetName, issue); }
  };
}

function verifyTableRows_(table, collector, report, healthy) {
  const unhealthyRefs = table.columns.filter(function (column) { return column.ref && !healthy[column.ref]; });
  if (unhealthyRefs.length > 0) {
    collector.warning(table.name, { code: 'REF_NOT_CHECKED',
      message: 'Relasi ke sheet bermasalah tidak diperiksa: ' + unhealthyRefs.map(function (c) { return c.ref; }).join(', ') });
  }
  const state = loadTable_(table.name);
  report.tables[table.name].rows = state.records.length;
  if (state.placeholderRows > 0) {
    collector.warning(table.name, { code: 'PLACEHOLDER_ROWS', message: state.placeholderRows + ' baris tanpa data berisi ' +
      'checkbox FALSE (mis. dari Insert > Checkbox). Kosongkan baris tersebut agar baris baru tidak ditulis di bawahnya.' });
  }
  const derivedColumns = table.columns.filter(function (column) { return column.derive; });
  const env = createValidationEnv_('verify', {}, null);
  const originalLookup = env.lookup;
  env.lookup = function (tableName) {
    return healthy[tableName] ? originalLookup(tableName) : { byId: {}, records: [], unavailable: true };
  };
  const seenIds = {};
  state.records.forEach(function (record, index) {
    const location = { row: state.rowNumbers[index], id: record.id || null };
    if (table.hasId && typeof record.id === 'string' && record.id !== '') {
      if (seenIds[record.id]) {
        collector.error(table.name, Object.assign({ field: 'id', code: 'DUPLICATE_ID', message: 'ID ' + record.id + ' dipakai lebih dari satu baris.' }, location));
      }
      seenIds[record.id] = true;
    }
    validateRecord_(table, record, env).forEach(function (error) {
      if (error.code === 'REF_NOT_FOUND' && unhealthyRefs.some(function (c) { return c.name === error.field; })) return;
      collector.error(table.name, Object.assign({}, error, location));
    });
    derivedColumns.forEach(function (column) {
      const expected = column.derive(record);
      if ((record[column.name] === undefined ? null : record[column.name]) !== (expected === undefined ? null : expected)) {
        collector.error(table.name, Object.assign({ field: column.name, code: 'DERIVED_MISMATCH',
          message: column.label + ' seharusnya "' + expected + '" (dihitung ulang dari kolom sumber).' }, location));
      }
    });
  });
  validateUniqueness_(table, state.records, [], null).forEach(function (error) {
    collector.error(table.name, Object.assign({}, error, { row: state.rowNumbers[error.index], id: state.records[error.index].id || null }));
  });
}

function verifyEnumSeeds_(collector) {
  const state = getEnumState_();
  getEnumDefinitions_().forEach(function (definition) {
    const entry = state.byName[definition.name];
    definition.values.forEach(function (value) {
      if (!entry || !entry.all[value[0]]) {
        collector.error('ENUMS', { code: 'ENUM_SEED_MISSING', message: 'Nilai sistem ' + definition.name + '.' + value[0] + ' tidak ada.' });
      } else if (!entry.active[value[0]]) {
        collector.error('ENUMS', { code: 'ENUM_SEED_INACTIVE', message: 'Nilai sistem ' + definition.name + '.' + value[0] + ' dinonaktifkan.' });
      }
    });
    if (entry && !definition.extensible) {
      Object.keys(entry.all).forEach(function (value) {
        const known = definition.values.some(function (seed) { return seed[0] === value; });
        if (!known) {
          collector.warning('ENUMS', { code: 'ENUM_VALUE_UNKNOWN', message: 'Nilai tambahan ' + definition.name + '.' + value +
            ' belum dikenali kode aplikasi.' });
        }
      });
    }
  });
  Object.keys(state.byName).forEach(function (name) {
    if (!getEnumDefinition_(name)) {
      collector.warning('ENUMS', { code: 'ENUM_UNKNOWN', message: 'Enum ' + name + ' tidak dipakai skema.' });
    }
  });
}

function verifySettingSeeds_(collector, report) {
  const settings = getSettings_();
  getSettingDefinitions_().forEach(function (definition) {
    const setting = settings[definition.key];
    if (!setting) {
      collector.error('SETTINGS', { code: 'SETTING_MISSING', message: 'Setting ' + definition.key + ' tidak ada.' });
    } else if (setting.type !== definition.type) {
      collector.error('SETTINGS', { code: 'SETTING_TYPE', message: 'Tipe setting ' + definition.key + ' seharusnya ' + definition.type + '.' });
    }
  });
  Object.keys(settings).forEach(function (key) {
    if (!settings[key].valid) {
      collector.error('SETTINGS', { code: 'SETTING_INVALID', message: 'Nilai setting ' + key + ' tidak sesuai tipe ' + settings[key].type + '.' });
    }
  });
  const version = settings.SCHEMA_VERSION;
  report.schemaVersion.database = version && version.valid ? version.value : null;
  if (report.schemaVersion.database !== SCHEMA_VERSION) {
    collector.error('SETTINGS', { code: 'SCHEMA_VERSION', message: 'SCHEMA_VERSION database (' + report.schemaVersion.database +
      ') berbeda dari kode (' + SCHEMA_VERSION + '). Jalankan initializeDatabase().' });
  }
}
