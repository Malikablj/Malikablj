'use strict';

/**
 * Field mappers and source-column guards used by the sheet mappings (mapping.js).
 *
 * A mapper declares the source columns it reads and maps one source row to one target value:
 *   map(row, ctx) -> { value, rule?, from? }      rule/from are recorded in the transformation log
 * Problems never throw for data reasons: the mapper returns value null and calls ctx.issue(...) so the record is still
 * migrated and the original value is preserved in MIGRATION_ISSUES.
 *
 * A guard documents why a source column is not migrated and checks that assumption on every row; a row that breaks
 * the assumption produces an UNMAPPED_SOURCE_VALUE issue carrying the value, so nothing is dropped silently.
 */

const { cleanText, isValidIsoDate, stripFloatSuffix } = require('../normalize');

const NULL = Object.freeze({ value: null });

function read(row, column) {
  if (!Object.prototype.hasOwnProperty.call(row, column)) throw new Error(`Kolom sumber "${column}" tidak ada.`);
  const value = row[column];
  return value === undefined || value === '' ? null : value;
}

/** T-01/T-02: trimmed text; whitespace-only -> null; inner whitespace kept. */
function text(column) {
  return {
    sources: [column],
    map(row) {
      const raw = read(row, column);
      if (raw === null) return NULL;
      const asText = typeof raw === 'string' ? raw : String(raw);
      const trimmed = asText.trim();
      if (trimmed === '') return { value: null, rule: 'T-02 teks kosong -> NULL', from: raw };
      if (typeof raw !== 'string') return { value: trimmed, rule: `teks dari nilai ${typeof raw}`, from: raw };
      if (trimmed !== raw) return { value: trimmed, rule: 'T-01 trim spasi awal/akhir', from: raw };
      return { value: trimmed };
    },
  };
}

/** M-6: the original value, character for character (only a whitespace-only value becomes NULL). */
function original(column) {
  return {
    sources: [column],
    map(row) {
      const raw = read(row, column);
      if (raw === null) return NULL;
      const asText = typeof raw === 'string' ? raw : String(raw);
      if (asText.trim() === '') return { value: null, rule: 'T-02 teks kosong -> NULL', from: raw };
      if (typeof raw !== 'string') return { value: asText, rule: `teks dari nilai ${typeof raw}`, from: raw };
      return { value: raw };
    },
  };
}

/** ID of the record itself (R-1): kept as is. A row without an ID is handled by the package builder (ID_MISSING). */
function id(column) {
  return {
    sources: [column],
    map(row) {
      const raw = read(row, column);
      return typeof raw === 'string' && raw.trim() !== '' ? { value: raw } : NULL;
    },
  };
}

/** R-1 foreign key: an ID present in the target sheet is kept; a blank stays NULL; an unknown ID is never guessed. */
function ref(column, targetSheet) {
  return {
    sources: [column],
    reference: true, // copies an ID: the source-vs-database check counts filled relations for these columns
    map(row, ctx) {
      const raw = read(row, column);
      if (raw === null) return NULL;
      const value = String(raw).trim();
      if (!ctx.idExists(targetSheet, value)) {
        ctx.issue('FK_NOT_FOUND', {
          severity: 'BLOCKER',
          field: column,
          value,
          description: `${column} "${value}" tidak ditemukan di ${targetSheet}; relasi dikosongkan, tidak ditebak.`,
        });
        return { value: null, rule: 'R-1 ID rujukan tidak ada -> NULL', from: raw };
      }
      return { value };
    },
  };
}

function number(column, { integer = false } = {}) {
  return {
    sources: [column],
    map(row, ctx) {
      const raw = read(row, column);
      if (raw === null) return NULL;
      if (typeof raw === 'number' && Number.isFinite(raw) && (!integer || Number.isInteger(raw))) return { value: raw };
      ctx.issue('VALUE_NOT_NUMERIC', {
        field: column,
        value: raw,
        description: `${column} berisi "${raw}", bukan ${integer ? 'bilangan bulat' : 'angka'}; kolom target dikosongkan.`,
      });
      return { value: null, rule: 'nilai bukan angka -> NULL (lihat MIGRATION_ISSUES)', from: raw };
    },
  };
}

/** T-04 money: at most 2 decimals; binary float artefacts are rounded and logged. */
function money(column) {
  const base = number(column);
  return {
    sources: [column],
    map(row, ctx) {
      const result = base.map(row, ctx);
      if (typeof result.value !== 'number') return result;
      const rounded = Math.round(result.value * 100) / 100;
      if (rounded !== result.value) return { value: rounded, rule: 'T-04 uang dibulatkan 2 desimal', from: result.value };
      return result;
    },
  };
}

/** T-03: date cells arrive as 'yyyy-MM-dd' from the reader; anything else is not guessed. */
function date(column) {
  return {
    sources: [column],
    map(row, ctx) {
      const raw = read(row, column);
      if (raw === null) return NULL;
      if (typeof raw === 'string' && isValidIsoDate(raw)) return { value: raw };
      ctx.issue('VALUE_NOT_DATE', {
        field: column,
        value: raw,
        description: `${column} berisi "${raw}", bukan tanggal; kolom target dikosongkan (tidak ditebak).`,
      });
      return { value: null, rule: 'nilai bukan tanggal -> NULL (lihat MIGRATION_ISSUES)', from: raw };
    },
  };
}

function bool(column) {
  return {
    sources: [column],
    map(row, ctx) {
      const raw = read(row, column);
      if (raw === null) return NULL;
      if (typeof raw === 'boolean') return { value: raw };
      ctx.issue('VALUE_NOT_BOOLEAN', { field: column, value: raw, description: `${column} berisi "${raw}", bukan TRUE/FALSE.` });
      return { value: null, rule: 'nilai bukan boolean -> NULL', from: raw };
    },
  };
}

/** T-06: enum value from a fixed table (keys compared trimmed and case-insensitively). Unknown text is not forced. */
function enumOf(column, table, rule) {
  const lookup = new Map(Object.entries(table).map(([key, value]) => [key.trim().toLowerCase(), value]));
  return {
    sources: [column],
    map(row, ctx) {
      const raw = read(row, column);
      if (raw === null) return NULL;
      const mapped = lookup.get(String(raw).trim().toLowerCase());
      if (!mapped) {
        ctx.issue('VALUE_NOT_MAPPED', {
          field: column,
          value: raw,
          description: `Nilai "${raw}" pada ${column} tidak ada di tabel pemetaan; kolom target dikosongkan.`,
        });
        return { value: null, rule: `${rule}: nilai tanpa padanan -> NULL`, from: raw };
      }
      return mapped === raw ? { value: mapped } : { value: mapped, rule, from: raw };
    },
  };
}

/** T-08: long numeric identifiers stored as floats lose a trailing ".0"; digits are not changed. */
function documentNumber(column) {
  return {
    sources: [column],
    map(row) {
      const base = text(column).map(row);
      if (base.value === null) return base;
      const stripped = stripFloatSuffix(base.value);
      if (stripped !== base.value) return { value: stripped, rule: 'T-08 akhiran ".0" dibuang', from: read(row, column) };
      return base;
    },
  };
}

/** A value that does not come from a single source cell (derivation), always logged with the source values it used. */
function derived(sources, rule, compute) {
  return {
    sources,
    derived: true, // not a copy of its source cell: excluded from the source-vs-database totals
    map(row, ctx) {
      const value = compute(row, ctx);
      if (value === null || value === undefined) return NULL;
      const from = sources.length === 1 ? read(row, sources[0])
        : Object.fromEntries(sources.map((column) => [column, read(row, column)]));
      return { value, rule, from };
    },
  };
}

// ----------------------------------------------------------------------------------------------------------------
// Guards for source columns that are not migrated
// ----------------------------------------------------------------------------------------------------------------

const guard = {
  /** The column is expected to be empty in every row. */
  empty: (reason) => ({ reason, check: (value) => value === null }),
  /** The column holds one known constant. */
  constant: (expected, reason) => ({ reason, check: (value) => value === null || value === expected }),
  /** The column repeats another column of the same row. */
  sameAs: (other, reason) => ({
    reason,
    check: (value, row) => value === null || cleanText(value) === cleanText(row[other]),
  }),
  /** Any value is accepted because the information is kept elsewhere (the reason says where). */
  keptElsewhere: (reason, check) => ({ reason, check: check || (() => true) }),
};

module.exports = { bool, date, derived, documentNumber, enumOf, guard, id, money, number, original, read, ref, text };
