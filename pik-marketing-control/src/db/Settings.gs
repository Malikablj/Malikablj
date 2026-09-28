/**
 * Application settings stored in the SETTINGS sheet.
 *
 * Deployment configuration and secrets live in Script Properties (Config.gs); SETTINGS holds values an Admin may
 * change at runtime plus a few system values maintained by the initializer (is_system = TRUE). Seeding only adds
 * missing keys: existing values are never overwritten.
 */

const SETTING_PARSERS = Object.freeze({
  STRING: function (text) { return { ok: true, value: text }; },
  INTEGER: function (text) {
    return /^-?\d+$/.test(text) ? { ok: true, value: Number(text) } : { ok: false };
  },
  NUMBER: function (text) {
    return /^-?\d+(\.\d+)?$/.test(text) ? { ok: true, value: Number(text) } : { ok: false };
  },
  BOOLEAN: function (text) {
    const upper = text.toUpperCase();
    return upper === 'TRUE' || upper === 'FALSE' ? { ok: true, value: upper === 'TRUE' } : { ok: false };
  },
  DATE: function (text) { return isValidIsoDate_(text) ? { ok: true, value: text } : { ok: false }; },
  DATETIME: function (text) { return isValidIsoDateTime_(text) ? { ok: true, value: text } : { ok: false }; },
  JSON: function (text) {
    try {
      return { ok: true, value: JSON.parse(text) };
    } catch (error) {
      return { ok: false };
    }
  }
});

function getSettingDefinitions_() {
  return [
    { key: 'COMPANY_NAME', type: 'STRING', value: 'PT Permata Indo Kemas', description: 'Nama perusahaan.' },
    { key: 'CURRENCY', type: 'STRING', value: 'IDR', description: 'Kode mata uang nilai transaksi.' },
    { key: 'DEFAULT_PAGE_SIZE', type: 'INTEGER', value: '25', description: 'Jumlah baris per halaman bawaan.' },
    { key: 'MAX_PAGE_SIZE', type: 'INTEGER', value: '100', description: 'Batas jumlah baris per halaman.' },
    {
      key: 'SCHEMA_VERSION', type: 'INTEGER', value: String(SCHEMA_VERSION), system: true,
      description: 'Versi skema database. Dikelola initializeDatabase().'
    },
    {
      key: 'DB_INITIALIZED_AT', type: 'DATETIME', value: null, system: true,
      description: 'Waktu database pertama kali diinisialisasi.'
    }
  ];
}

/** Parses a stored setting value by its type. Returns { ok, value }. Empty text parses to null. */
function parseSettingValue_(text, type) {
  if (text === null || text === undefined || String(text).trim() === '') return { ok: true, value: null };
  const parser = SETTING_PARSERS[type];
  if (!parser) return { ok: false };
  return parser(String(text).trim());
}

/** All settings, cached per execution: { KEY: { value, raw, type, isSystem, valid } }. */
function getSettings_() {
  if (DB_CACHE_.settings) return DB_CACHE_.settings;
  const settings = {};
  loadTable_('SETTINGS').records.forEach(function (record) {
    if (!record.key) return;
    const parsed = parseSettingValue_(record.value, record.value_type);
    settings[record.key] = {
      value: parsed.ok ? parsed.value : null,
      raw: record.value,
      type: record.value_type,
      isSystem: record.is_system === true,
      valid: parsed.ok
    };
  });
  DB_CACHE_.settings = settings;
  return settings;
}

/** Parsed value of a setting, or `fallback` when it is missing, empty or invalid. */
function getSetting_(key, fallback) {
  const setting = getSettings_()[key];
  if (!setting || !setting.valid || setting.value === null) return fallback === undefined ? null : fallback;
  return setting.value;
}

/**
 * Changes one setting. System settings (is_system = TRUE) require context.internal. Audited as SETTING_UPDATE.
 */
function updateSetting_(key, value, context) {
  const ctx = normalizeWriteContext_(context);
  const table = getTableDef_('SETTINGS');
  return withScriptLock_(function () {
    resetDbCache_();
    const state = loadTable_('SETTINGS');
    let index = -1;
    for (let i = 0; i < state.records.length; i++) {
      if (state.records[i].key === key) {
        index = i;
        break;
      }
    }
    if (index === -1) throw appError_(ERROR_CODE.NOT_FOUND, 'Setting ' + key + ' tidak ditemukan.');
    const current = state.records[index];
    if (current.is_system === true && !ctx.internal) {
      throw appError_(ERROR_CODE.FORBIDDEN, 'Setting ' + key + ' dikelola sistem dan tidak dapat diubah manual.');
    }
    const text = value === null || value === undefined ? null : String(value).trim();
    const parsed = parseSettingValue_(text, current.value_type);
    if (!parsed.ok) {
      throw appError_(ERROR_CODE.VALIDATION, 'Nilai setting ' + key + ' harus bertipe ' + current.value_type + '.',
        { errors: [{ field: 'value', code: 'TYPE', message: 'Nilai tidak sesuai tipe ' + current.value_type + '.' }] });
    }
    const now = ctx.now || nowIso_();
    const updated = Object.assign({}, current, { value: text === '' ? null : text, updated_at: now, updated_by: ctx.actor });
    const errors = validateRecord_(table, updated, createValidationEnv_('update', ctx, current));
    if (errors.length) throw validationError_(table, errors);
    if (updated.value === current.value) return copyRecord_(current);
    writeRecordRow_(state, state.rowNumbers[index], updated);
    writeAuditEntries_([{
      action: 'SETTING_UPDATE', entityType: 'SETTINGS', entityId: key,
      changes: { value: [current.value, updated.value] }
    }], ctx, now);
    resetDbCache_();
    return copyRecord_(updated);
  });
}
