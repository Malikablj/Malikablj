/**
 * Generic table access on top of the schema.
 *
 * Reads: one getValues() per table per execution (cached; the cache is dropped after every write).
 * Writes: always inside the script lock, on freshly read data, validated before anything is written, then one
 * setValues() for the whole batch plus one for the matching AUDIT_LOG rows. A batch is all-or-nothing: if any
 * record is invalid nothing is written.
 *
 * Write context (server code only, never taken from client input):
 *   actor              email written to created_by/updated_by and AUDIT_LOG (default: current user)
 *   migration          true for the migration engine: may supply ids, is_legacy, lineage and *_legacy columns
 *   internal           true for trusted server services: may write 'internal' columns (e.g. last_login_at)
 *   expectedUpdatedAt  optimistic concurrency for updates: rejected with CONFLICT when the row changed meanwhile
 *   requestId          correlation id stored in AUDIT_LOG (default: new UUID)
 *   audit              'record' (default: one AUDIT_LOG entry per record) or 'summary' (one MIGRATION_RUN entry per
 *                      insert batch listing the ids; migration context only)
 *   auditNote          note stored with the audit entries
 */

const MAX_WRITE_BATCH = 2000;

var DB_CACHE_ = { tables: {}, enums: null, settings: null, checkedHeaders: {} };

function resetDbCache_() {
  DB_CACHE_ = { tables: {}, enums: null, settings: null, checkedHeaders: {} };
}

function normalizeWriteContext_(context) {
  const ctx = context || {};
  return {
    actor: ctx.actor || getActorEmail_(),
    migration: ctx.migration === true,
    internal: ctx.internal === true || ctx.migration === true,
    expectedUpdatedAt: ctx.expectedUpdatedAt || null,
    requestId: ctx.requestId || Utilities.getUuid(),
    now: ctx.now || null,
    audit: ctx.migration === true && ctx.audit === 'summary' ? 'summary' : 'record',
    auditNote: ctx.auditNote || null
  };
}

/**
 * The sheet of a table after checking that its header matches the schema (checked once per execution).
 * @return {GoogleAppsScript.Spreadsheet.Sheet}
 */
function getCheckedSheet_(table) {
  const spreadsheet = getDatabaseSpreadsheet_();
  const cacheKey = spreadsheet.getId() + ':' + table.name;
  const sheet = spreadsheet.getSheetByName(table.name);
  if (!sheet) {
    throw appError_(ERROR_CODE.SCHEMA_MISMATCH,
      'Sheet ' + table.name + ' tidak ditemukan. Jalankan initializeDatabase().', { table: table.name });
  }
  if (DB_CACHE_.checkedHeaders[cacheKey]) return sheet;
  const width = table.columns.length;
  const header = sheet.getLastColumn() >= width ? sheet.getRange(1, 1, 1, width).getValues()[0] : [];
  for (let i = 0; i < width; i++) {
    const actual = header[i] === undefined ? '' : String(header[i]).trim();
    if (actual !== table.columnNames[i]) {
      throw appError_(ERROR_CODE.SCHEMA_MISMATCH,
        'Header sheet ' + table.name + ' kolom ' + (i + 1) + ' seharusnya "' + table.columnNames[i] + '" tetapi "' +
        actual + '". Jalankan verifyDatabase().', { table: table.name, column: i + 1 });
    }
  }
  DB_CACHE_.checkedHeaders[cacheKey] = true;
  return sheet;
}

/** A row counts as empty when every cell is blank (unchecked checkboxes included). */
function isEmptySheetRow_(row) {
  for (let i = 0; i < row.length; i++) {
    if (row[i] !== '' && row[i] !== null && row[i] !== false) return false;
  }
  return true;
}

function fromCellValue_(column, value, timeZone) {
  if (value === '' || value === null || value === undefined) return null;
  if (value instanceof Date) {
    if (column.type === 'datetime') return value.toISOString();
    if (column.type === 'date') return Utilities.formatDate(value, timeZone, 'yyyy-MM-dd');
    return value; // a date typed by hand into another column: left as is, reported by validation
  }
  switch (column.type) {
    case 'boolean':
    case 'integer':
    case 'quantity':
    case 'money':
    case 'decimal':
      return value; // anything but the expected JS type is reported by validation
    default:
      return typeof value === 'string' ? value : String(value);
  }
}

function rowToRecord_(table, row, timeZone) {
  const record = {};
  for (let i = 0; i < table.columns.length; i++) {
    record[table.columns[i].name] = fromCellValue_(table.columns[i], row[i], timeZone);
  }
  return record;
}

function recordToRow_(table, record) {
  return table.columns.map(function (column) {
    const value = record[column.name];
    return value === null || value === undefined ? '' : value;
  });
}

function copyRecord_(record) {
  return record ? Object.assign({}, record) : null;
}

/**
 * All rows of a table, cached per execution: { table, sheet, records, rowNumbers, byId, duplicateIds,
 * placeholderRows (rows holding nothing but unchecked checkboxes), timeZone }. Records in the cache must not be mutated.
 */
function loadTable_(tableName) {
  const cached = DB_CACHE_.tables[tableName];
  if (cached) return cached;
  const table = getTableDef_(tableName);
  const sheet = getCheckedSheet_(table);
  const timeZone = sheet.getParent().getSpreadsheetTimeZone();
  const lastRow = sheet.getLastRow();
  const values = lastRow > 1 ? sheet.getRange(2, 1, lastRow - 1, table.columns.length).getValues() : [];
  const state = {
    table: table, sheet: sheet, records: [], rowNumbers: [], byId: {}, duplicateIds: [], placeholderRows: 0,
    timeZone: timeZone
  };
  for (let i = 0; i < values.length; i++) {
    if (isEmptySheetRow_(values[i])) {
      if (values[i].indexOf(false) !== -1) state.placeholderRows++;
      continue;
    }
    const record = rowToRecord_(table, values[i], timeZone);
    state.records.push(record);
    state.rowNumbers.push(i + 2);
    if (table.hasId && typeof record.id === 'string') {
      if (state.byId[record.id]) state.duplicateIds.push(record.id);
      else state.byId[record.id] = record;
    }
  }
  DB_CACHE_.tables[tableName] = state;
  return state;
}

function findRecordIndex_(state, id) {
  for (let i = 0; i < state.records.length; i++) {
    if (state.records[i].id === id) return i;
  }
  return -1;
}

// ---------------------------------------------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------------------------------------------

function dbFindById_(tableName, id) {
  return copyRecord_(loadTable_(tableName).byId[id] || null);
}

/**
 * Lists records with optional filtering, sorting and paging.
 * options: { where: {field: value}, filter: function(record), includeInactive, sortBy, sortDirection ('asc'|'desc'),
 *            offset, limit } -> { items, total, offset, limit }
 */
function dbList_(tableName, options) {
  const opts = options || {};
  const state = loadTable_(tableName);
  const where = opts.where || {};
  const whereKeys = Object.keys(where);
  let records = state.records.filter(function (record) {
    if (state.table.softDelete && !opts.includeInactive && record.is_active === false) return false;
    for (let i = 0; i < whereKeys.length; i++) {
      if (record[whereKeys[i]] !== where[whereKeys[i]]) return false;
    }
    return opts.filter ? Boolean(opts.filter(record)) : true;
  });
  if (opts.sortBy) {
    const direction = opts.sortDirection === 'desc' ? -1 : 1;
    const field = opts.sortBy;
    records = records.slice().sort(function (a, b) { return compareValues_(a[field], b[field]) * direction; });
  }
  const maxPageSize = getSetting_('MAX_PAGE_SIZE', 100);
  const limit = Math.max(1, Math.min(Number(opts.limit) || getSetting_('DEFAULT_PAGE_SIZE', 25), maxPageSize));
  const offset = Math.max(0, Number(opts.offset) || 0);
  return {
    items: records.slice(offset, offset + limit).map(copyRecord_),
    total: records.length,
    offset: offset,
    limit: limit
  };
}

/** Sort order with empty values last. */
function compareValues_(a, b) {
  const aEmpty = a === null || a === undefined;
  const bEmpty = b === null || b === undefined;
  if (aEmpty || bEmpty) return aEmpty === bEmpty ? 0 : aEmpty ? 1 : -1;
  if (typeof a === 'string' && typeof b === 'string') return a.localeCompare(b);
  return a < b ? -1 : a > b ? 1 : 0;
}

// ---------------------------------------------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------------------------------------------

function assertTableWritable_(table, ctx, operation) {
  if (table.kind !== TABLE_KIND.DATA) {
    throw appError_(ERROR_CODE.READ_ONLY, 'Tabel ' + table.name + ' dikelola sistem dan tidak dapat ditulis langsung.');
  }
  if (table.migrationOnly && !ctx.migration) {
    throw appError_(ERROR_CODE.READ_ONLY, table.label + ' adalah data referensi legacy (read-only).');
  }
  if (operation === 'insert' && table.insertAccess === WRITABLE.MIGRATION && !ctx.migration) {
    throw appError_(ERROR_CODE.READ_ONLY, table.label + ' hanya dapat dibuat oleh proses migrasi.');
  }
}

function canWriteColumn_(column, ctx, operation) {
  switch (column.writable) {
    case WRITABLE.USER:
      return true;
    case WRITABLE.INTERNAL:
      return ctx.internal;
    case WRITABLE.MIGRATION:
      return ctx.migration;
    case WRITABLE.ARCHIVE:
      return ctx.migration; // users archive/restore through dbArchive_/dbRestore_; the migration copies the source value
    case WRITABLE.AUTO:
      return column.name === 'id' && operation === 'insert' && ctx.migration;
    default:
      return false;
  }
}

function writeAccessError_(column, operation) {
  if (column.writable === WRITABLE.MIGRATION) {
    return fieldError_(column, 'LEGACY_FORBIDDEN', column.label + ' hanya dapat diisi oleh proses migrasi data legacy.');
  }
  if (column.writable === WRITABLE.ARCHIVE) {
    return fieldError_(column, 'SYSTEM_FIELD', 'Gunakan fungsi arsip/pulihkan untuk mengubah ' + column.label + '.');
  }
  if (column.name === 'id' && operation === 'update') {
    return fieldError_(column, 'SYSTEM_FIELD', 'ID tidak dapat diubah.');
  }
  return fieldError_(column, 'SYSTEM_FIELD', column.label + ' diisi otomatis oleh sistem.');
}

/**
 * Converts client-style input to the stored representation: trims text (empty -> null), lower-cases emails,
 * formats Date objects, and parses unambiguous numeric/boolean strings. Anything else is left for validation.
 */
function normalizeInputValue_(column, value, timeZone) {
  if (value === undefined || value === null) return value;
  if (value instanceof Date) {
    if (isNaN(value.getTime())) return value;
    if (column.type === 'date') return Utilities.formatDate(value, timeZone, 'yyyy-MM-dd');
    if (column.type === 'datetime') return value.toISOString();
    return value;
  }
  if (typeof value !== 'string') return value;
  const text = value.trim();
  if (text === '') return null;
  if (column.preserveWhitespace) return value;
  switch (column.type) {
    case 'integer':
    case 'quantity':
    case 'money':
    case 'decimal':
      return parseNumericInput_(text);
    case 'boolean':
      if (/^true$/i.test(text)) return true;
      if (/^false$/i.test(text)) return false;
      return text;
    case 'email':
      return text.toLowerCase();
    default:
      return text;
  }
}

/**
 * Number from text written without thousand separators, '.' as decimal point ('1500', '12.5', '-3').
 * Text that looks like Indonesian thousand grouping ('1.500', '12.345.678') or uses ',' is ambiguous and is left
 * as text so validation rejects it instead of silently reading 1.500 as one and a half.
 */
function parseNumericInput_(text) {
  if (!/^-?\d+(\.\d+)?$/.test(text)) return text;
  if (/^-?\d{1,3}(\.\d{3})+$/.test(text)) return text;
  return Number(text);
}

function applyDerivedColumns_(table, record) {
  table.columns.forEach(function (column) {
    if (column.derive) record[column.name] = column.derive(record);
  });
}

function prepareInsertRecord_(table, input, ctx, usedIds, now, timeZone) {
  const errors = [];
  const record = {};
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    return { record: record, errors: [{ field: null, code: 'TYPE', message: 'Data ' + table.label + ' tidak valid.' }] };
  }
  Object.keys(input).forEach(function (field) {
    const column = table.columnByName[field];
    if (!column) {
      errors.push({ field: field, code: 'UNKNOWN_FIELD', message: 'Kolom "' + field + '" tidak dikenal pada ' + table.label + '.' });
    } else if (!canWriteColumn_(column, ctx, 'insert') && input[field] !== undefined) {
      errors.push(writeAccessError_(column, 'insert'));
    }
  });
  table.columns.forEach(function (column) {
    let value;
    if (Object.prototype.hasOwnProperty.call(input, column.name) && canWriteColumn_(column, ctx, 'insert')) {
      value = normalizeInputValue_(column, input[column.name], timeZone);
    }
    if (value === undefined) value = column.defaultValue === undefined ? null : column.defaultValue;
    record[column.name] = value;
  });
  if (table.hasId) {
    if (record.id === null) {
      record.id = generateId_(table.idPrefix, usedIds, table.idHexLength);
    } else if (typeof record.id === 'string' && usedIds.has(record.id)) {
      errors.push({ field: 'id', code: 'UNIQUE', message: 'ID ' + record.id + ' sudah dipakai.' });
    } else if (typeof record.id === 'string') {
      usedIds.add(record.id);
    }
  }
  applyDerivedColumns_(table, record);
  if (table.hasAudit) {
    record.created_at = now;
    record.created_by = ctx.actor;
    record.updated_at = now;
    record.updated_by = ctx.actor;
  }
  return { record: record, errors: errors };
}

function withRecordIndex_(error, index) {
  return Object.assign({ index: index }, error);
}

/**
 * Prepares and validates an insert batch against the current table (under the caller's lock). Throws the validation
 * error when any record is invalid; returns { table, state, records, now } otherwise. Nothing is written.
 */
function prepareInsertBatch_(tableName, inputs, ctx) {
  const table = getTableDef_(tableName);
  assertTableWritable_(table, ctx, 'insert');
  const list = Array.isArray(inputs) ? inputs : [inputs];
  if (list.length > MAX_WRITE_BATCH) {
    throw appError_(ERROR_CODE.VALIDATION, 'Maksimal ' + MAX_WRITE_BATCH + ' record per penyimpanan.');
  }
  const state = loadTable_(tableName);
  const now = ctx.now || nowIso_();
  const usedIds = new Set(Object.keys(state.byId).concat(state.duplicateIds));
  const errors = [];
  const records = list.map(function (input, index) {
    const prepared = prepareInsertRecord_(table, input, ctx, usedIds, now, state.timeZone);
    prepared.errors.forEach(function (error) { errors.push(withRecordIndex_(error, index)); });
    return prepared.record;
  });
  if (errors.length === 0) {
    const env = createValidationEnv_('insert', ctx, null);
    records.forEach(function (record, index) {
      validateRecord_(table, record, env).forEach(function (error) { errors.push(withRecordIndex_(error, index)); });
    });
    validateUniqueness_(table, records, state.records, null).forEach(function (error) { errors.push(error); });
  }
  if (errors.length > 0) throw validationError_(table, errors);
  return { table: table, state: state, records: records, now: now };
}

/**
 * Validates an insert without writing it (same checks as dbInsert_). Services call it before a multi-step change so a
 * record that would be rejected stops the whole change before its first write.
 */
function dbValidateInsert_(tableName, inputs, context) {
  const ctx = normalizeWriteContext_(context);
  return withScriptLock_(function () {
    prepareInsertBatch_(tableName, inputs, ctx);
    return true;
  });
}

/**
 * Inserts one record or a batch (all-or-nothing). Returns the stored records (with generated ids and timestamps).
 */
function dbInsert_(tableName, inputs, context) {
  const ctx = normalizeWriteContext_(context);
  const table = getTableDef_(tableName);
  assertTableWritable_(table, ctx, 'insert');
  const list = Array.isArray(inputs) ? inputs : [inputs];
  if (list.length === 0) return [];
  return withScriptLock_(function () {
    resetDbCache_();
    const batch = prepareInsertBatch_(tableName, list, ctx);
    const state = batch.state;
    const records = batch.records;
    const now = batch.now;

    appendRowsToSheet_(state.sheet, table, records.map(function (record) { return recordToRow_(table, record); }));
    if (ctx.audit === 'summary') {
      writeAuditEntries_([{
        action: 'MIGRATION_RUN', entityType: table.name, entityId: null, note: ctx.auditNote,
        changes: { operation: 'insert', count: records.length, ids: records.map(function (record) { return record.id; }) }
      }], ctx, now);
    } else {
      writeAuditEntries_(records.map(function (record) {
        return { action: 'CREATE', entityType: table.name, entityId: record.id, changes: compactRecord_(record), note: ctx.auditNote };
      }), ctx, now);
    }
    resetDbCache_();
    return records.map(copyRecord_);
  });
}

/**
 * Updates one record with a partial patch. Returns the stored record. A patch that changes nothing writes nothing.
 */
function dbUpdate_(tableName, id, patch, context) {
  const ctx = normalizeWriteContext_(context);
  const table = getTableDef_(tableName);
  assertTableWritable_(table, ctx, 'update');
  if (!patch || typeof patch !== 'object' || Array.isArray(patch)) {
    throw appError_(ERROR_CODE.VALIDATION, 'Data perubahan ' + table.label + ' tidak valid.');
  }
  return withScriptLock_(function () {
    resetDbCache_();
    const state = loadTable_(tableName);
    const index = findRecordIndex_(state, id);
    if (index === -1) throw appError_(ERROR_CODE.NOT_FOUND, table.label + ' ' + id + ' tidak ditemukan.');
    const current = state.records[index];
    if (ctx.expectedUpdatedAt && current.updated_at !== ctx.expectedUpdatedAt) {
      throw appError_(ERROR_CODE.CONFLICT, 'Data ' + table.label + ' telah diubah pengguna lain. Muat ulang lalu coba lagi.',
        { id: id, updatedAt: current.updated_at });
    }
    const patched = applyPatch_(table, current, patch, ctx, state.timeZone);
    if (patched.errors.length > 0) throw validationError_(table, patched.errors);
    const next = patched.record;
    const changes = diffRecords_(table, current, next);
    if (Object.keys(changes).length === 0) return copyRecord_(current);

    const now = ctx.now || nowIso_();
    if (table.hasAudit) {
      next.updated_at = now;
      next.updated_by = ctx.actor;
    }
    const validationErrors = validateRecord_(table, next, createValidationEnv_('update', ctx, current))
      .concat(validateUniqueness_(table, [next], state.records, idSet_([id])));
    if (validationErrors.length > 0) throw validationError_(table, validationErrors);

    writeRecordRow_(state, state.rowNumbers[index], next);
    writeAuditEntries_([{ action: 'UPDATE', entityType: table.name, entityId: id, changes: changes, note: ctx.auditNote }], ctx, now);
    resetDbCache_();
    return copyRecord_(next);
  });
}

/**
 * Updates several records of one table in a single batch (all-or-nothing): updates = [{ id, patch }]. One read of the
 * table, validation of every record before anything is written, rows written in contiguous blocks and one AUDIT_LOG
 * entry per changed record. Patches that change nothing write nothing. Returns the stored records in input order.
 */
function dbUpdateMany_(tableName, updates, context) {
  const ctx = normalizeWriteContext_(context);
  const table = getTableDef_(tableName);
  assertTableWritable_(table, ctx, 'update');
  if (!Array.isArray(updates)) throw appError_(ERROR_CODE.VALIDATION, 'Data perubahan ' + table.label + ' tidak valid.');
  if (updates.length === 0) return [];
  if (updates.length > MAX_WRITE_BATCH) {
    throw appError_(ERROR_CODE.VALIDATION, 'Maksimal ' + MAX_WRITE_BATCH + ' record per penyimpanan.');
  }
  return withScriptLock_(function () {
    resetDbCache_();
    const state = loadTable_(tableName);
    const now = ctx.now || nowIso_();
    const indexById = {};
    state.records.forEach(function (record, index) {
      if (typeof record.id === 'string' && indexById[record.id] === undefined) indexById[record.id] = index;
    });
    const errors = [];
    const results = [];
    const changed = [];
    const inBatch = {};
    updates.forEach(function (update, position) {
      if (!update || typeof update !== 'object' || !update.patch || typeof update.patch !== 'object' || Array.isArray(update.patch)) {
        errors.push({ index: position, field: null, code: 'TYPE', message: 'Data perubahan ' + table.label + ' tidak valid.' });
        return;
      }
      const index = indexById[update.id];
      if (index === undefined) throw appError_(ERROR_CODE.NOT_FOUND, table.label + ' ' + update.id + ' tidak ditemukan.', { index: position });
      if (inBatch[update.id]) {
        errors.push({ index: position, field: 'id', code: 'UNIQUE', message: 'ID ' + update.id + ' muncul lebih dari sekali dalam satu penyimpanan.' });
        return;
      }
      inBatch[update.id] = true;
      const current = state.records[index];
      const patched = applyPatch_(table, current, update.patch, ctx, state.timeZone);
      patched.errors.forEach(function (error) { errors.push(withRecordIndex_(error, position)); });
      if (patched.errors.length > 0) return;
      const next = patched.record;
      const changes = diffRecords_(table, current, next);
      if (Object.keys(changes).length === 0) {
        results[position] = copyRecord_(current);
        return;
      }
      if (table.hasAudit) {
        next.updated_at = now;
        next.updated_by = ctx.actor;
      }
      validateRecord_(table, next, createValidationEnv_('update', ctx, current)).forEach(function (error) {
        errors.push(withRecordIndex_(error, position));
      });
      changed.push({ position: position, rowNumber: state.rowNumbers[index], current: current, record: next, changes: changes });
      results[position] = next;
    });
    const candidates = changed.map(function (item) { return item.record; });
    validateUniqueness_(table, candidates, state.records, idSet_(candidates.map(function (record) { return record.id; })))
      .forEach(function (error) { errors.push(Object.assign({}, error, { index: changed[error.index].position })); });
    if (errors.length > 0) throw validationError_(table, errors);
    if (changed.length === 0) return results;

    writeRecordRows_(state, changed);
    writeAuditEntries_(changed.map(function (item) {
      return { action: 'UPDATE', entityType: table.name, entityId: item.record.id, changes: item.changes, note: ctx.auditNote };
    }), ctx, now);
    resetDbCache_();
    return results.map(copyRecord_);
  });
}

/** The record after applying `patch`: write access checked, input normalized, derived columns recomputed. */
function applyPatch_(table, current, patch, ctx, timeZone) {
  const errors = [];
  const next = Object.assign({}, current);
  Object.keys(patch).forEach(function (field) {
    const column = table.columnByName[field];
    if (!column) {
      errors.push({ field: field, code: 'UNKNOWN_FIELD', message: 'Kolom "' + field + '" tidak dikenal pada ' + table.label + '.' });
      return;
    }
    if (patch[field] === undefined) return;
    if (!canWriteColumn_(column, ctx, 'update')) {
      errors.push(writeAccessError_(column, 'update'));
      return;
    }
    next[field] = normalizeInputValue_(column, patch[field], timeZone);
  });
  applyDerivedColumns_(table, next);
  return { record: next, errors: errors };
}

function dbArchive_(tableName, id, context) {
  return setRecordActive_(tableName, id, false, context);
}

function dbRestore_(tableName, id, context) {
  return setRecordActive_(tableName, id, true, context);
}

/** Soft delete / restore. Idempotent: archiving an archived record changes nothing. */
function setRecordActive_(tableName, id, active, context) {
  const ctx = normalizeWriteContext_(context);
  const table = getTableDef_(tableName);
  assertTableWritable_(table, ctx, 'update');
  if (!table.softDelete) throw appError_(ERROR_CODE.READ_ONLY, table.label + ' tidak mendukung arsip.');
  return withScriptLock_(function () {
    resetDbCache_();
    const state = loadTable_(tableName);
    const index = findRecordIndex_(state, id);
    if (index === -1) throw appError_(ERROR_CODE.NOT_FOUND, table.label + ' ' + id + ' tidak ditemukan.');
    const current = state.records[index];
    if (current.is_active === active) return copyRecord_(current);
    const now = ctx.now || nowIso_();
    const next = Object.assign({}, current, { is_active: active });
    if (table.hasAudit) {
      next.updated_at = now;
      next.updated_by = ctx.actor;
    }
    if (active) {
      // Restoring must not create a second active primary contact or another unique conflict.
      const uniqueErrors = validateUniqueness_(table, [next], state.records, idSet_([id]));
      if (uniqueErrors.length > 0) throw validationError_(table, uniqueErrors);
    }
    writeRecordRow_(state, state.rowNumbers[index], next);
    writeAuditEntries_([{
      action: active ? 'RESTORE' : 'ARCHIVE', entityType: table.name, entityId: id,
      changes: { is_active: [current.is_active, active] }
    }], ctx, now);
    resetDbCache_();
    return copyRecord_(next);
  });
}

function idSet_(ids) {
  const set = {};
  ids.forEach(function (id) { set[id] = true; });
  return set;
}

/** { column: [before, after] } for columns that changed, ignoring updated_at/updated_by. */
function diffRecords_(table, before, after) {
  const changes = {};
  table.columns.forEach(function (column) {
    if (column.name === 'updated_at' || column.name === 'updated_by') return;
    const a = before[column.name] === undefined ? null : before[column.name];
    const b = after[column.name] === undefined ? null : after[column.name];
    if (a !== b) changes[column.name] = [a, b];
  });
  return changes;
}

/** Record without empty fields, for AUDIT_LOG. */
function compactRecord_(record) {
  const compact = {};
  Object.keys(record).forEach(function (key) {
    if (record[key] !== null && record[key] !== undefined) compact[key] = record[key];
  });
  return compact;
}

/**
 * Appends rows below the last used row: capacity, formats, then one setValues. Returns the first row number.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function appendRowsToSheet_(sheet, table, rows) {
  if (rows.length === 0) return 0;
  const startRow = Math.max(sheet.getLastRow(), 1) + 1;
  ensureRowCapacity_(sheet, table, startRow + rows.length - 1);
  applyNumberFormats_(sheet, table, startRow, rows.length);
  sheet.getRange(startRow, 1, rows.length, table.columns.length).setValues(rows);
  return startRow;
}

/** @param {{ sheet: GoogleAppsScript.Spreadsheet.Sheet, table: * }} state */
function writeRecordRow_(state, rowNumber, record) {
  writeRecordRows_(state, [{ rowNumber: rowNumber, record: record }]);
}

/**
 * Rewrites existing rows, one setValues per block of consecutive row numbers.
 * @param {{ sheet: GoogleAppsScript.Spreadsheet.Sheet, table: * }} state
 * @param {Array<{ rowNumber: number, record: Object }>} items
 */
function writeRecordRows_(state, items) {
  const sorted = items.slice().sort(function (a, b) { return a.rowNumber - b.rowNumber; });
  let start = 0;
  while (start < sorted.length) {
    let end = start;
    while (end + 1 < sorted.length && sorted[end + 1].rowNumber === sorted[end].rowNumber + 1) end++;
    const block = sorted.slice(start, end + 1);
    applyNumberFormats_(state.sheet, state.table, block[0].rowNumber, block.length);
    state.sheet.getRange(block[0].rowNumber, 1, block.length, state.table.columns.length)
      .setValues(block.map(function (item) { return recordToRow_(state.table, item.record); }));
    start = end + 1;
  }
}
