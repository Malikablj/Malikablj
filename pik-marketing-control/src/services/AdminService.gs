/**
 * Administration (Admin only): users and roles, application settings, enum values and migration issues.
 *
 * Users: sign-in is by Google account, so a user is an email plus a role. Guards keep the application administrable:
 * an Admin cannot archive their own account or change their own email, and the last active Admin can be neither
 * archived nor given another role.
 * Settings: non-system keys only; values are checked by type (Settings.gs) and by the ranges in SETTING_RULES.
 * Enums: labels, order and descriptions are editable; seed values stay active (code depends on them); new values only
 * for enums marked extensible (Enums.gs). ENUMS is a system table, so it is written here, under the script lock and
 * audited as SETTING_UPDATE, and the sheet dropdowns of the columns that use the enum are refreshed.
 * Migration issues: resolution status and note; resolved_by/resolved_at are stamped by the server.
 */

const USER_FIELDS = ['name', 'email', 'role', 'phone'];

// ---------------------------------------------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------------------------------------------

function userForList_(record) {
  return Object.assign({}, record, { role_label: enumLabel_('USER_ROLE', record.role) });
}

function listUsers_(input) {
  return runListQuery_(loadTable_('USERS').records.map(userForList_), input, {
    searchFields: ['name', 'email', 'phone', 'role_label'],
    filters: { role: eqFilter_('role') },
    sortFields: ['name', 'email', 'role', 'last_login_at', 'updated_at'],
    defaultSort: { field: 'name', direction: 'asc' }
  });
}

function otherActiveAdmins_(excludeId) {
  return loadTable_('USERS').records.filter(function (record) {
    return record.role === ROLE.ADMIN && record.is_active !== false && record.id !== excludeId;
  });
}

function lastAdminError_(field) {
  return appError_(ERROR_CODE.VALIDATION, 'Harus ada minimal satu Admin aktif. Tetapkan Admin lain terlebih dahulu.',
    { errors: [{ field: field, code: 'RULE', message: 'Admin aktif terakhir.' }] });
}

function createUser_(input, user) {
  return userForList_(createRecord_('USERS', input, USER_FIELDS, user));
}

function updateUser_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const data = pickFields_(objectInput_(params.data, 'Data'), USER_FIELDS);
  return withScriptLock_(function () {
    resetDbCache_();
    const current = findOrThrow_('USERS', id);
    if (id === user.id && data.email !== undefined &&
        String(data.email || '').trim().toLowerCase() !== String(current.email || '').toLowerCase()) {
      throw appError_(ERROR_CODE.VALIDATION, 'Email akun Anda sendiri tidak dapat diubah. Minta Admin lain untuk mengubahnya.',
        { errors: [{ field: 'email', code: 'RULE', message: 'Email akun sendiri tidak dapat diubah.' }] });
    }
    if (current.role === ROLE.ADMIN && current.is_active !== false && data.role !== undefined && data.role !== ROLE.ADMIN &&
        otherActiveAdmins_(id).length === 0) {
      throw lastAdminError_('role');
    }
    return userForList_(dbUpdate_('USERS', id, data, userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
  });
}

function archiveUser_(input, user) {
  const id = requireId_(objectInput_(input).id);
  return withScriptLock_(function () {
    resetDbCache_();
    const current = findOrThrow_('USERS', id);
    if (id === user.id) {
      throw appError_(ERROR_CODE.VALIDATION, 'Anda tidak dapat menonaktifkan akun sendiri.',
        { errors: [{ field: 'id', code: 'RULE', message: 'Akun sendiri.' }] });
    }
    if (current.role === ROLE.ADMIN && current.is_active !== false && otherActiveAdmins_(id).length === 0) throw lastAdminError_('id');
    return userForList_(setRecordActive_('USERS', id, false, userContext_(user)));
  });
}

function restoreUser_(input, user) {
  return userForList_(setRecordActive_('USERS', requireId_(objectInput_(input).id), true, userContext_(user)));
}

// ---------------------------------------------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------------------------------------------

/** Extra checks per key on the parsed value; returns an error message or null. */
const SETTING_RULES = Object.freeze({
  COMPANY_NAME: function (value) { return value ? null : 'Nama perusahaan wajib diisi.'; },
  CURRENCY: function (value) { return /^[A-Z]{3}$/.test(value || '') ? null : 'Kode mata uang harus 3 huruf kapital (mis. IDR).'; },
  DEFAULT_PAGE_SIZE: function (value) {
    const max = getSetting_('MAX_PAGE_SIZE', 100);
    return value >= 5 && value <= max ? null : 'Baris per halaman harus antara 5 dan ' + max + '.';
  },
  MAX_PAGE_SIZE: function (value) {
    const current = getSetting_('DEFAULT_PAGE_SIZE', 25);
    return value >= Math.max(10, current) && value <= 500 ? null :
      'Batas baris per halaman harus antara ' + Math.max(10, current) + ' dan 500.';
  }
});

function settingForClient_(record) {
  const parsed = parseSettingValue_(record.value, record.value_type);
  return {
    key: record.key, value: record.value, parsed_value: parsed.ok ? parsed.value : null, value_type: record.value_type,
    description: record.description, is_system: record.is_system === true, valid: parsed.ok,
    updated_at: record.updated_at, updated_by: record.updated_by
  };
}

function listSettings_() {
  const config = getConfig_();
  return {
    items: loadTable_('SETTINGS').records.filter(function (record) { return record.key; }).map(settingForClient_)
      .sort(function (a, b) { return Number(a.is_system) - Number(b.is_system) || compareForSort_(a.key, b.key); }),
    config: { appName: config.APP_NAME, timeZone: config.TIMEZONE, schemaVersion: SCHEMA_VERSION }
  };
}

function updateSettingValue_(input, user) {
  const params = objectInput_(input);
  const key = String(params.key || '');
  const setting = getSettings_()[key];
  if (!setting) throw appError_(ERROR_CODE.NOT_FOUND, 'Setting ' + key + ' tidak ditemukan.');
  const text = params.value === null || params.value === undefined ? null : String(params.value).trim();
  const parsed = parseSettingValue_(text, setting.type);
  const rule = SETTING_RULES[key];
  const message = parsed.ok && rule ? rule(parsed.value) : null;
  if (message) {
    throw appError_(ERROR_CODE.VALIDATION, message, { errors: [{ field: 'value', code: 'RULE', message: message }] });
  }
  const updated = updateSetting_(key, text, userContext_(user));
  return settingForClient_(updated);
}

// ---------------------------------------------------------------------------------------------------------------
// Enum values
// ---------------------------------------------------------------------------------------------------------------

function isSeedEnumValue_(enumName, value) {
  const definition = getEnumDefinition_(enumName);
  return Boolean(definition) && definition.values.some(function (entry) { return entry[0] === value; });
}

function enumUsage_(enumName) {
  const usage = [];
  getSchema_().tables.forEach(function (table) {
    table.columns.forEach(function (column) {
      if (column.type === 'enum' && column.enumName === enumName) usage.push(table.label + ' · ' + column.label);
    });
  });
  return usage;
}

/** Every enum with its values: { items: [{ name, description, extensible, usage, values: [...] }] }. */
function listEnumValues_() {
  const state = getEnumState_();
  return {
    items: getEnumDefinitions_().map(function (definition) {
      const entry = state.byName[definition.name] || { items: [] };
      return {
        name: definition.name, description: definition.description, extensible: definition.extensible === true,
        usage: enumUsage_(definition.name),
        values: entry.items.map(function (item) {
          return {
            enum_value: item.enum_value, label: item.label, sort_order: item.sort_order, is_active: item.is_active === true,
            description: item.description, is_system: isSeedEnumValue_(definition.name, item.enum_value)
          };
        })
      };
    })
  };
}

function requireEnumDefinition_(enumName) {
  const definition = getEnumDefinition_(String(enumName || ''));
  if (!definition) throw appError_(ERROR_CODE.NOT_FOUND, 'Enum ' + enumName + ' tidak dikenal.');
  return definition;
}

/** New value for an extensible enum. Input: { enum_name, enum_value, label, sort_order, description }. */
function createEnumValue_(input, user) {
  const params = objectInput_(input);
  const definition = requireEnumDefinition_(params.enum_name);
  if (!definition.extensible) {
    throw appError_(ERROR_CODE.VALIDATION, 'Nilai ' + definition.name + ' dipakai logika aplikasi dan tidak dapat ditambah.');
  }
  return withScriptLock_(function () {
    resetDbCache_();
    const state = loadTable_('ENUMS');
    const siblings = state.records.filter(function (record) { return record.enum_name === definition.name; });
    const maxOrder = siblings.reduce(function (max, record) {
      return typeof record.sort_order === 'number' && record.sort_order > max ? record.sort_order : max;
    }, 0);
    const table = state.table;
    const record = {};
    table.columns.forEach(function (column) { record[column.name] = null; });
    const value = typeof params.enum_value === 'string' ? params.enum_value.trim().toUpperCase().replace(/[\s-]+/g, '_') : params.enum_value;
    Object.assign(record, {
      enum_name: definition.name,
      enum_value: value,
      label: normalizeInputValue_(table.columnByName.label, params.label, state.timeZone),
      sort_order: params.sort_order === undefined || params.sort_order === null || params.sort_order === '' ? maxOrder + 10 :
        normalizeInputValue_(table.columnByName.sort_order, params.sort_order, state.timeZone),
      is_active: true,
      description: normalizeInputValue_(table.columnByName.description, params.description, state.timeZone)
    });
    const ctx = normalizeWriteContext_(userContext_(user));
    const errors = validateRecord_(table, record, createValidationEnv_('insert', ctx, null))
      .concat(validateUniqueness_(table, [record], state.records, null));
    if (errors.length) throw validationError_(table, errors);
    const now = nowIso_();
    appendRowsToSheet_(state.sheet, table, [recordToRow_(table, record)]);
    writeAuditEntries_([{
      action: 'SETTING_UPDATE', entityType: 'ENUMS', entityId: definition.name + '.' + record.enum_value,
      changes: { created: compactRecord_(record) }
    }], ctx, now);
    resetDbCache_();
    refreshEnumDropdowns_(definition.name);
    return record;
  });
}

/** Changes label, sort order, description or active flag. Input: { enum_name, enum_value, label, sort_order, is_active, description }. */
function updateEnumValue_(input, user) {
  const params = objectInput_(input);
  const definition = requireEnumDefinition_(params.enum_name);
  const enumValue = String(params.enum_value || '');
  return withScriptLock_(function () {
    resetDbCache_();
    const state = loadTable_('ENUMS');
    let index = -1;
    for (let i = 0; i < state.records.length; i++) {
      if (state.records[i].enum_name === definition.name && state.records[i].enum_value === enumValue) {
        index = i;
        break;
      }
    }
    if (index === -1) throw appError_(ERROR_CODE.NOT_FOUND, 'Nilai ' + definition.name + '.' + enumValue + ' tidak ditemukan.');
    const table = state.table;
    const current = state.records[index];
    const next = Object.assign({}, current);
    ['label', 'sort_order', 'is_active', 'description'].forEach(function (field) {
      if (params[field] !== undefined) next[field] = normalizeInputValue_(table.columnByName[field], params[field], state.timeZone);
    });
    if (next.is_active !== true && isSeedEnumValue_(definition.name, enumValue)) {
      throw appError_(ERROR_CODE.VALIDATION, 'Nilai sistem ' + definition.name + '.' + enumValue + ' tidak dapat dinonaktifkan.',
        { errors: [{ field: 'is_active', code: 'RULE', message: 'Nilai sistem harus tetap aktif.' }] });
    }
    const ctx = normalizeWriteContext_(userContext_(user));
    const errors = validateRecord_(table, next, createValidationEnv_('update', ctx, current));
    if (errors.length) throw validationError_(table, errors);
    const changes = diffRecords_(table, current, next);
    if (Object.keys(changes).length === 0) return copyRecord_(current);
    writeRecordRow_(state, state.rowNumbers[index], next);
    writeAuditEntries_([{
      action: 'SETTING_UPDATE', entityType: 'ENUMS', entityId: definition.name + '.' + enumValue, changes: changes
    }], ctx, nowIso_());
    resetDbCache_();
    if (changes.is_active) refreshEnumDropdowns_(definition.name);
    return copyRecord_(next);
  });
}

/** Re-applies the sheet dropdown of every data column that uses the enum, so the sheets list the active values. */
function refreshEnumDropdowns_(enumName) {
  const enumValues = getEnumValuesForValidation_();
  const spreadsheet = getDatabaseSpreadsheet_();
  getSchema_().tables.forEach(function (table) {
    if (table.kind !== TABLE_KIND.DATA) return;
    table.columns.forEach(function (column, index) {
      if (column.type !== 'enum' || column.enumName !== enumName) return;
      const sheet = spreadsheet.getSheetByName(table.name);
      if (!sheet || sheet.getMaxRows() < 2) return;
      const rule = buildColumnValidation_(column, enumValues);
      const range = sheet.getRange(2, index + 1, sheet.getMaxRows() - 1, 1);
      if (rule) range.setDataValidation(rule);
      else range.clearDataValidations();
    });
  });
}

// ---------------------------------------------------------------------------------------------------------------
// Migration issues
// ---------------------------------------------------------------------------------------------------------------

const ISSUE_SEVERITY_RANK = Object.freeze({ BLOCKER: 0, HIGH: 1, MEDIUM: 2, LOW: 3, INFO: 4 });

function listMigrationIssues_(input) {
  const records = loadTable_('MIGRATION_ISSUES').records;
  const listed = runListQuery_(records, input, {
    searchFields: ['issue_type', 'entity_type', 'record_id', 'field', 'value', 'description', 'candidate_reference',
      'source_sheet', 'import_ref', 'resolution_note', 'decision_ref'],
    filters: {
      severity: eqFilter_('severity'),
      resolution_status: eqFilter_('resolution_status'),
      entity_type: eqFilter_('entity_type'),
      issue_type: eqFilter_('issue_type'),
      record_id: eqFilter_('record_id')
    },
    sortFields: {
      severity: function (record) {
        const rank = ISSUE_SEVERITY_RANK[record.severity];
        return rank === undefined ? 9 : rank;
      },
      issue_type: function (record) { return record.issue_type; },
      entity_type: function (record) { return record.entity_type; },
      resolution_status: function (record) { return record.resolution_status; },
      legacy_row: function (record) { return record.legacy_row; },
      updated_at: function (record) { return record.updated_at; }
    },
    defaultSort: { field: 'severity', direction: 'asc' }
  });
  const counts = { bySeverity: {}, byStatus: {}, byType: {}, open: 0, total: records.length };
  records.forEach(function (record) {
    counts.byStatus[record.resolution_status] = (counts.byStatus[record.resolution_status] || 0) + 1;
    if (record.resolution_status !== 'OPEN') return;
    counts.open++;
    counts.bySeverity[record.severity] = (counts.bySeverity[record.severity] || 0) + 1;
    counts.byType[record.issue_type] = (counts.byType[record.issue_type] || 0) + 1;
  });
  return Object.assign(listed, { counts: counts });
}

/** Input: { id, resolution_status, resolution_note, expectedUpdatedAt }. A note is required unless reopening. */
function resolveMigrationIssue_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const status = String(params.resolution_status || '');
  const note = typeof params.resolution_note === 'string' ? params.resolution_note.trim() : '';
  if (status !== 'OPEN' && note === '') {
    throw appError_(ERROR_CODE.VALIDATION, 'Catatan penyelesaian wajib diisi.',
      { errors: [{ field: 'resolution_note', code: 'REQUIRED', message: 'Catatan penyelesaian wajib diisi.' }] });
  }
  const reopen = status === 'OPEN';
  const patch = {
    resolution_status: status,
    resolution_note: note || null,
    resolved_by: reopen ? null : user.email,
    resolved_at: reopen ? null : nowIso_()
  };
  return dbUpdate_('MIGRATION_ISSUES', id, patch, userContext_(user, {
    internal: true, expectedUpdatedAt: params.expectedUpdatedAt || null
  }));
}
