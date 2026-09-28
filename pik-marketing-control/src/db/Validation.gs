/**
 * Record validation against the schema. Used for every write (insert/update) and by verifyDatabase.
 *
 * Checks, in order: required, type and format, enum membership, numeric ranges, foreign keys (existence and, for
 * new references, active target), consistency between related references, table rules, and uniqueness.
 * Rows with is_legacy = TRUE are validated structurally only (required-unless-legacy, ranges and table rules are
 * skipped) so migrated values are never rejected or rewritten; see Schema.gs.
 *
 * Error codes: REQUIRED, TYPE, MAX_LENGTH, PATTERN, ENUM, ENUM_INACTIVE, MIN, MAX, REF_NOT_FOUND, REF_INACTIVE,
 * REF_MISMATCH, RULE, UNIQUE, UNKNOWN_FIELD, SYSTEM_FIELD, LEGACY_FORBIDDEN.
 */

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const URL_PATTERN = /^https?:\/\/\S+$/i;
// Leading '=' would be stored as a formula and a leading apostrophe is swallowed by Sheets: reject both.
const UNSAFE_TEXT_START_PATTERN = /^[=']/;
const ANY_ID_PATTERN = /^[A-Z]{2,4}-[0-9A-F]{10,16}$/;

/**
 * Validation environment.
 * @param {string} mode 'insert' | 'update' | 'verify'
 * @param {Object} ctx normalized write context ({ migration, internal, ... }); may be empty for verify
 * @param {Object=} previous stored record before an update
 */
function createValidationEnv_(mode, ctx, previous) {
  return {
    mode: mode,
    ctx: ctx || {},
    previous: previous || null,
    lookup: function (tableName) { return loadTable_(tableName); },
    enums: function () { return getEnumState_(); }
  };
}

function fieldError_(column, code, message) {
  return { field: column.name, code: code, message: message };
}

/** Validates one complete record. Returns a list of { field, code, message } (empty when valid). */
function validateRecord_(table, record, env) {
  const errors = [];
  const invalidFields = {};
  const strict = record.is_legacy !== true;
  table.columns.forEach(function (column) {
    const error = validateColumnValue_(column, record[column.name], env, strict);
    if (error) {
      errors.push(error);
      invalidFields[column.name] = true;
    }
  });
  validateReferences_(table, record, env, invalidFields, errors);
  validateConsistency_(table, record, env, strict, invalidFields, errors);
  if (strict) validateTableRules_(table, record, invalidFields, errors);
  return errors;
}

function validateColumnValue_(column, value, env, strict) {
  if (value === null || value === undefined || value === '') {
    if (column.required || (column.requiredUnlessLegacy && strict)) {
      return fieldError_(column, 'REQUIRED', column.label + ' wajib diisi.');
    }
    return null;
  }
  const typeError = checkValueType_(column, value);
  if (typeError) return typeError;
  if (column.enumName) {
    const enumError = checkEnumValue_(column, value, env);
    if (enumError) return enumError;
  }
  if (strict && typeof value === 'number') return checkNumericRange_(column, value);
  return null;
}

function checkValueType_(column, value) {
  const label = column.label;
  switch (column.type) {
    case 'boolean':
      return typeof value === 'boolean' ? null : fieldError_(column, 'TYPE', label + ' harus bernilai TRUE atau FALSE.');
    case 'integer':
      if (typeof value !== 'number' || !isFinite(value) || Math.floor(value) !== value) {
        return fieldError_(column, 'TYPE', label + ' harus berupa bilangan bulat.');
      }
      return null;
    case 'quantity':
    case 'money':
    case 'decimal':
      if (typeof value !== 'number' || !isFinite(value)) {
        return fieldError_(column, 'TYPE', label + ' harus berupa angka.');
      }
      if (column.type === 'quantity' && !hasAtMostDecimals_(value, 3)) {
        return fieldError_(column, 'TYPE', label + ' maksimal 3 angka desimal.');
      }
      if (column.type === 'money' && !hasAtMostDecimals_(value, 2)) {
        return fieldError_(column, 'TYPE', label + ' maksimal 2 angka desimal.');
      }
      return null;
    default:
      return checkTextValue_(column, value);
  }
}

function checkTextValue_(column, value) {
  const label = column.label;
  if (typeof value !== 'string') return fieldError_(column, 'TYPE', label + ' harus berupa teks.');
  if (!column.preserveWhitespace && value.trim() !== value) {
    return fieldError_(column, 'PATTERN', label + ' tidak boleh diawali/diakhiri spasi.');
  }
  if (column.maxLength && value.length > column.maxLength) {
    return fieldError_(column, 'MAX_LENGTH', label + ' maksimal ' + column.maxLength + ' karakter.');
  }
  if (UNSAFE_TEXT_START_PATTERN.test(value)) {
    return fieldError_(column, 'PATTERN', label + ' tidak boleh diawali karakter = atau \'.');
  }
  let valid = true;
  let expected = '';
  switch (column.type) {
    case 'id':
      valid = ANY_ID_PATTERN.test(value);
      expected = 'ID berformat PREFIX-XXXXXXXXXX';
      break;
    case 'ref':
      valid = getTableDef_(column.ref).idPattern.test(value);
      expected = 'ID ' + column.ref + ' (' + getTableDef_(column.ref).idPrefixes.join('/') + '-XXXXXXXXXX)';
      break;
    case 'email':
      valid = EMAIL_PATTERN.test(value) && value === value.toLowerCase();
      expected = 'alamat email valid dengan huruf kecil';
      break;
    case 'url':
      valid = URL_PATTERN.test(value);
      expected = 'URL yang diawali http:// atau https://';
      break;
    case 'phone':
      valid = /\d/.test(value);
      expected = 'nomor telepon yang memuat angka';
      break;
    case 'date':
      valid = isValidIsoDate_(value);
      expected = 'tanggal valid dengan format yyyy-MM-dd';
      break;
    case 'datetime':
      valid = isValidIsoDateTime_(value);
      expected = 'waktu ISO 8601 UTC, contoh 2026-09-28T02:30:00.000Z';
      break;
    case 'time':
      valid = isValidTimeOfDay_(value);
      expected = 'jam dengan format HH:mm';
      break;
    case 'json':
      try {
        JSON.parse(value);
      } catch (error) {
        valid = false;
      }
      expected = 'JSON yang valid';
      break;
    default:
      break;
  }
  if (!valid) return fieldError_(column, column.type === 'ref' || column.type === 'id' ? 'PATTERN' : 'TYPE',
    label + ' harus berupa ' + expected + '.');
  if (column.pattern && !column.pattern.test(value)) {
    return fieldError_(column, 'PATTERN', label + ' ' + (column.patternMessage || 'formatnya tidak valid') + '.');
  }
  return null;
}

/** True when the number has at most `decimals` decimal places (tolerates binary floating-point noise). */
function hasAtMostDecimals_(value, decimals) {
  const scaled = value * Math.pow(10, decimals);
  return Math.abs(scaled - Math.round(scaled)) <= 1e-6;
}

function checkEnumValue_(column, value, env) {
  const entry = env.enums().byName[column.enumName];
  if (!entry || !entry.all[value]) {
    const options = entry ? ' Pilihan: ' + entry.activeList.join(', ') + '.' : '';
    return fieldError_(column, 'ENUM', column.label + ' tidak valid: "' + value + '".' + options);
  }
  const unchanged = env.previous && env.previous[column.name] === value;
  if (!entry.active[value] && env.mode !== 'verify' && !unchanged) {
    return fieldError_(column, 'ENUM_INACTIVE', column.label + ' "' + value + '" sudah tidak aktif.');
  }
  return null;
}

function checkNumericRange_(column, value) {
  if (column.min !== null && value < column.min) {
    return fieldError_(column, 'MIN', column.label + ' tidak boleh kurang dari ' + column.min + '.');
  }
  if (column.minExclusive !== null && value <= column.minExclusive) {
    return fieldError_(column, 'MIN', column.label + ' harus lebih besar dari ' + column.minExclusive + '.');
  }
  if (column.max !== null && value > column.max) {
    return fieldError_(column, 'MAX', column.label + ' tidak boleh lebih dari ' + column.max + '.');
  }
  return null;
}

/** Foreign keys: the target must exist; a new or changed reference must point to an active record. */
function validateReferences_(table, record, env, invalidFields, errors) {
  table.columns.forEach(function (column) {
    if (!column.ref || invalidFields[column.name]) return;
    const value = record[column.name];
    if (value === null || value === undefined || value === '') return;
    const target = env.lookup(column.ref).byId[value];
    if (!target) {
      errors.push(fieldError_(column, 'REF_NOT_FOUND', column.label + ' ' + value + ' tidak ditemukan.'));
      invalidFields[column.name] = true;
      return;
    }
    const changed = !env.previous || env.previous[column.name] !== value;
    if (env.mode !== 'verify' && !env.ctx.migration && changed && target.is_active === false) {
      errors.push(fieldError_(column, 'REF_INACTIVE', column.label + ' ' + value + ' sudah diarsipkan dan tidak dapat dipilih.'));
      invalidFields[column.name] = true;
    }
  });
}

/**
 * Related references must agree, e.g. a delivery's PO line must belong to the delivery's PO and product.
 * On legacy rows an empty local value is accepted (the link was unknown in the source); a conflict is not.
 */
function validateConsistency_(table, record, env, strict, invalidFields, errors) {
  table.consistency.forEach(function (rule) {
    const refValue = record[rule.field];
    if (refValue === null || refValue === undefined || invalidFields[rule.field]) return;
    const referenced = env.lookup(rule.ref).byId[refValue];
    if (!referenced) return;
    const refColumn = table.columnByName[rule.field];
    rule.pairs.forEach(function (pair) {
      const localColumn = table.columnByName[pair[0]];
      const local = record[pair[0]];
      const remote = referenced[pair[1]];
      if (remote === null || remote === undefined || invalidFields[pair[0]]) return;
      if (local === null || local === undefined) {
        if (!strict) return;
        errors.push(fieldError_(localColumn, 'REF_MISMATCH',
          localColumn.label + ' wajib diisi ' + remote + ' sesuai ' + refColumn.label + ' ' + refValue + '.'));
        return;
      }
      if (local !== remote) {
        errors.push(fieldError_(refColumn, 'REF_MISMATCH',
          refColumn.label + ' ' + refValue + ' milik ' + localColumn.label + ' ' + remote + ', bukan ' + local + '.'));
      }
    });
  });
}

function validateTableRules_(table, record, invalidFields, errors) {
  table.rules.forEach(function (rule) {
    const result = rule.check(record);
    const list = !result ? [] : Array.isArray(result) ? result : [result];
    list.forEach(function (item) {
      if (!invalidFields[item.field]) errors.push({ field: item.field, code: 'RULE', message: item.message });
    });
  });
}

function uniqueKeyPart_(value, normalize) {
  if (value === null || value === undefined || value === '') return null;
  const text = String(value);
  if (normalize === 'lower') return text.trim().toLowerCase();
  if (normalize === 'upper') return text.trim().toUpperCase();
  if (normalize === 'document') return documentKey_(text);
  return text;
}

/** Composite key of a unique constraint, or null when the constraint does not apply (filtered or empty part). */
function uniqueKey_(constraint, record) {
  if (constraint.where && !constraint.where(record)) return null;
  const parts = [];
  for (let i = 0; i < constraint.columns.length; i++) {
    const part = uniqueKeyPart_(record[constraint.columns[i]], constraint.normalize);
    if (part === null) return null;
    parts.push(part);
  }
  return JSON.stringify(parts);
}

/**
 * Unique constraints for `candidates` against `existing` records and against each other.
 * Returns errors with `index` = position in candidates. Existing records whose id is in `excludeIds` are ignored.
 * For constraints marked legacyExempt, two legacy records may share a key (the duplicate came from the source and
 * is tracked in MIGRATION_ISSUES); any pair involving a non-legacy record is still a conflict.
 */
function validateUniqueness_(table, candidates, existing, excludeIds) {
  const errors = [];
  const excluded = excludeIds || {};
  table.unique.forEach(function (constraint) {
    const seen = {};       // key -> true for every record
    const seenStrict = {}; // key -> true for records that are not legacy
    const remember = function (record, key) {
      seen[key] = true;
      if (record.is_legacy !== true) seenStrict[key] = true;
    };
    existing.forEach(function (record) {
      if (record.id && excluded[record.id]) return;
      const key = uniqueKey_(constraint, record);
      if (key !== null) remember(record, key);
    });
    candidates.forEach(function (record, index) {
      const key = uniqueKey_(constraint, record);
      if (key === null) return;
      const legacyPair = constraint.legacyExempt && record.is_legacy === true;
      if (legacyPair ? seenStrict[key] : seen[key]) {
        const field = constraint.field || constraint.columns[constraint.columns.length - 1];
        errors.push({ index: index, field: field, code: 'UNIQUE', message: constraint.message });
      }
      remember(record, key);
    });
  });
  return errors;
}

function validationError_(table, errors) {
  const first = errors[0];
  const more = errors.length > 1 ? ' (dan ' + (errors.length - 1) + ' kesalahan lain)' : '';
  return appError_(ERROR_CODE.VALIDATION, 'Data ' + table.label + ' tidak valid: ' + first.message + more,
    { table: table.name, errors: errors });
}
