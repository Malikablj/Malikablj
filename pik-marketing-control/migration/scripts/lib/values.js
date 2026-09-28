/**
 * Source value parsers. Each returns { value } or { value: null, problem } where problem is
 * { type, message }. Parsers never guess: ambiguous inputs (e.g. "03/04/2026" without a
 * declared date format, "1.000" without a declared number format) are reported as problems.
 */

const MONTHS = {
  jan: 1, januari: 1, january: 1,
  feb: 2, februari: 2, february: 2, peb: 2, pebruari: 2,
  mar: 3, maret: 3, march: 3,
  apr: 4, april: 4,
  mei: 5, may: 5,
  jun: 6, juni: 6, june: 6,
  jul: 7, juli: 7, july: 7,
  agu: 8, agt: 8, agustus: 8, aug: 8, august: 8,
  sep: 9, sept: 9, september: 9,
  okt: 10, oktober: 10, oct: 10, october: 10,
  nov: 11, nop: 11, november: 11, nopember: 11,
  des: 12, desember: 12, dec: 12, december: 12,
};

const TRUE_WORDS = new Set(['true', 'yes', 'y', 'ya', '1', 'x', 'v', '✓', 'aktif', 'active']);
const FALSE_WORDS = new Set(['false', 'no', 'n', 'tidak', 't', '0', '-', 'nonaktif', 'inactive']);

const ok = (value) => ({ value });
const fail = (type, message) => ({ value: null, problem: { type, message } });

/** Same normalization as SQL normalize_key(): lower case, non-alphanumerics collapsed. */
export function normalizeKey(value) {
  if (value === null || value === undefined) return null;
  const normalized = String(value).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  return normalized === '' ? null : normalized;
}

function pad(number, length = 2) {
  return String(number).padStart(length, '0');
}

function isoDate(year, month, day) {
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
  if (year < 1900 || year > 2200) return null;
  return `${year}-${pad(month)}-${pad(day)}`;
}

function excelSerialToDate(serial) {
  // 25569 = days between 1899-12-30 (Excel epoch) and 1970-01-01.
  return new Date(Math.round((serial - 25569) * 86_400_000));
}

export function parseText(raw) {
  if (raw === null || raw === undefined) return ok(null);
  if (raw instanceof Date) return ok(raw.toISOString().slice(0, 10));
  const text = String(raw).replace(/\u00a0/g, ' ').trim();
  return ok(text === '' ? null : text);
}

/**
 * @param {'en'|'id'|null} format decimal convention for numbers stored as text:
 *   'en' = 1,234.50   'id' = 1.234,50   null = only plain numbers ("1234.5") are accepted
 */
export function parseNumber(raw, { format = null } = {}) {
  if (raw === null || raw === undefined || raw === '') return ok(null);
  if (typeof raw === 'number') return Number.isFinite(raw) ? ok(raw) : fail('INVALID_NUMBER', 'Angka tidak valid.');
  if (typeof raw === 'boolean' || raw instanceof Date) return fail('INVALID_NUMBER', `"${raw}" bukan angka.`);
  let text = String(raw).replace(/\u00a0/g, ' ').trim();
  if (text === '' || text === '-') return ok(null);
  text = text.replace(/^(rp\.?|idr)\s*/i, '').replace(/\s+/g, '');
  const negative = /^\(.*\)$/.test(text);
  if (negative) text = text.slice(1, -1);

  // "12.500" is 12.5 in English notation but 12500 in Indonesian: only plain when format is 'en'.
  const thousandsLike = /^-?\d{1,3}(\.\d{3})+$/.test(text);
  if (/^-?\d+(\.\d+)?$/.test(text) && !(thousandsLike && format !== 'en')) {
    const value = Number(text);
    return ok(negative ? -value : value);
  }
  let normalized = null;
  if (format === 'en' && /^-?\d{1,3}(,\d{3})*(\.\d+)?$/.test(text)) normalized = text.replace(/,/g, '');
  if (format === 'id' && /^-?\d{1,3}(\.\d{3})*(,\d+)?$/.test(text)) normalized = text.replace(/\./g, '').replace(',', '.');
  if (format === 'id' && /^-?\d+,\d+$/.test(text)) normalized = text.replace(',', '.');
  if (normalized === null) {
    return fail(
      format ? 'INVALID_NUMBER' : 'AMBIGUOUS_NUMBER',
      format
        ? `"${raw}" bukan angka dengan format ${format}.`
        : `"${raw}" adalah angka dalam bentuk teks dengan pemisah ribuan/desimal; tentukan numberFormat di mapping.`,
    );
  }
  const value = Number(normalized);
  return Number.isFinite(value) ? ok(negative ? -value : value) : fail('INVALID_NUMBER', `"${raw}" bukan angka.`);
}

export function parseInteger(raw, options) {
  const result = parseNumber(raw, options);
  if (result.problem || result.value === null) return result;
  if (!Number.isInteger(result.value)) return fail('INVALID_NUMBER', `"${raw}" bukan bilangan bulat.`);
  return result;
}

/**
 * Dates become "YYYY-MM-DD".
 * @param {'DD/MM/YYYY'|'MM/DD/YYYY'|null} format order for numeric text dates; ISO and
 *   month-name dates are always accepted. Without a format, day/month order must be
 *   unambiguous (one part > 12) or the value is reported.
 */
export function parseDate(raw, { format = null } = {}) {
  if (raw === null || raw === undefined || raw === '') return ok(null);
  if (raw instanceof Date) {
    if (Number.isNaN(raw.getTime())) return fail('INVALID_DATE', 'Tanggal tidak valid.');
    return ok(isoDate(raw.getUTCFullYear(), raw.getUTCMonth() + 1, raw.getUTCDate()));
  }
  if (typeof raw === 'number') {
    if (raw > 20_000 && raw < 80_000) {
      const date = excelSerialToDate(raw);
      return ok(isoDate(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate()));
    }
    return fail('INVALID_DATE', `Angka ${raw} bukan tanggal.`);
  }
  const text = String(raw).trim();
  if (text === '' || text === '-') return ok(null);

  let match = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[ T].*)?$/.exec(text);
  if (match) {
    const value = isoDate(Number(match[1]), Number(match[2]), Number(match[3]));
    return value ? ok(value) : fail('INVALID_DATE', `"${text}" bukan tanggal yang valid.`);
  }

  match = /^(\d{1,2})[\s\-/.]+([A-Za-z]+)\.?[\s\-/.,]+(\d{2,4})(?:\s.*)?$/.exec(text);
  if (match) {
    const month = MONTHS[match[2].toLowerCase()];
    let year = Number(match[3]);
    if (year < 100) year += 2000;
    const value = month ? isoDate(year, month, Number(match[1])) : null;
    return value ? ok(value) : fail('INVALID_DATE', `"${text}" bukan tanggal yang valid.`);
  }

  match = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})(?:\s.*)?$/.exec(text);
  if (match) {
    const first = Number(match[1]);
    const second = Number(match[2]);
    let year = Number(match[3]);
    if (year < 100) year += 2000;
    let order = format;
    if (!order) {
      if (first > 12 && second <= 12) order = 'DD/MM/YYYY';
      else if (second > 12 && first <= 12) order = 'MM/DD/YYYY';
      else if (first === second) order = 'DD/MM/YYYY';
      else {
        return fail(
          'AMBIGUOUS_DATE',
          `"${text}" bisa berarti DD/MM atau MM/DD; tentukan dateFormat di mapping.`,
        );
      }
    }
    const value = order === 'MM/DD/YYYY' ? isoDate(year, first, second) : isoDate(year, second, first);
    return value ? ok(value) : fail('INVALID_DATE', `"${text}" bukan tanggal yang valid (${order}).`);
  }
  return fail('INVALID_DATE', `"${text}" tidak dikenali sebagai tanggal.`);
}

/** Times become "HH:MM:SS". Accepts Excel time cells, day fractions and "HH:MM"/"HH.MM" text. */
export function parseTime(raw) {
  if (raw === null || raw === undefined || raw === '') return ok(null);
  if (raw instanceof Date) {
    return ok(`${pad(raw.getUTCHours())}:${pad(raw.getUTCMinutes())}:${pad(raw.getUTCSeconds())}`);
  }
  if (typeof raw === 'number' && raw >= 0 && raw < 1) {
    const seconds = Math.round(raw * 86_400);
    return ok(`${pad(Math.floor(seconds / 3600))}:${pad(Math.floor((seconds % 3600) / 60))}:${pad(seconds % 60)}`);
  }
  const match = /^(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?$/.exec(String(raw).trim());
  if (match && Number(match[1]) < 24 && Number(match[2]) < 60 && Number(match[3] ?? 0) < 60) {
    return ok(`${pad(Number(match[1]))}:${match[2]}:${match[3] ?? '00'}`);
  }
  return fail('INVALID_TIME', `"${raw}" tidak dikenali sebagai jam.`);
}

/** Offset (minutes) of `timeZone` from UTC at the given instant. */
function timeZoneOffsetMinutes(date, timeZone) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(date);
  const get = (type) => Number(parts.find((part) => part.type === type).value);
  const asUtc = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second'));
  return (asUtc - date.getTime()) / 60_000;
}

/** Interprets a wall-clock date/time (as typed in Excel) in `timeZone` and returns an ISO instant. */
export function wallClockToInstant(isoDateValue, timeValue, timeZone) {
  const [year, month, day] = isoDateValue.split('-').map(Number);
  const [hour, minute, second] = (timeValue || '00:00:00').split(':').map(Number);
  const guess = Date.UTC(year, month - 1, day, hour, minute, second);
  const offset = timeZoneOffsetMinutes(new Date(guess), timeZone);
  return new Date(guess - offset * 60_000).toISOString();
}

/**
 * Date-times become ISO instants. Excel stores wall-clock values without a timezone, so the
 * value is interpreted in the business timezone (APP_TIMEZONE).
 */
export function parseDateTime(raw, { format = null, timeZone }) {
  if (raw === null || raw === undefined || raw === '') return ok(null);
  if (raw instanceof Date) {
    if (Number.isNaN(raw.getTime())) return fail('INVALID_DATE', 'Tanggal tidak valid.');
    const date = isoDate(raw.getUTCFullYear(), raw.getUTCMonth() + 1, raw.getUTCDate());
    const time = `${pad(raw.getUTCHours())}:${pad(raw.getUTCMinutes())}:${pad(raw.getUTCSeconds())}`;
    return ok(wallClockToInstant(date, time, timeZone));
  }
  if (typeof raw === 'number') {
    const date = parseDate(Math.floor(raw));
    if (date.problem) return date;
    const time = parseTime(raw - Math.floor(raw));
    return ok(wallClockToInstant(date.value, time.value, timeZone));
  }
  const text = String(raw).trim();
  const timeMatch = /[ T](\d{1,2}[:.]\d{2}(?:[:.]\d{2})?)(?:\s*(WIB|WITA|WIT))?$/i.exec(text);
  const date = parseDate(timeMatch ? text.slice(0, timeMatch.index) : text, { format });
  if (date.problem || date.value === null) return date;
  const time = timeMatch ? parseTime(timeMatch[1]) : ok(null);
  if (time.problem) return time;
  return ok(wallClockToInstant(date.value, time.value, timeZone));
}

export function parseBoolean(raw) {
  if (raw === null || raw === undefined || raw === '') return ok(null);
  if (typeof raw === 'boolean') return ok(raw);
  if (typeof raw === 'number') return raw === 1 ? ok(true) : raw === 0 ? ok(false) : fail('INVALID_BOOLEAN', `${raw}`);
  const text = String(raw).trim().toLowerCase();
  if (TRUE_WORDS.has(text)) return ok(true);
  if (FALSE_WORDS.has(text)) return ok(false);
  return fail('INVALID_BOOLEAN', `"${raw}" bukan ya/tidak.`);
}

/**
 * Maps a source label to an enum code. `valueMap` keys are compared after normalizeKey(),
 * so "On Process", "on-process" and "ON PROCESS" all match the key "on process".
 * Values that already equal a code ("ON_PROCESS") are accepted as-is.
 */
export function parseEnum(raw, { values, valueMap = {} }) {
  const text = parseText(raw).value;
  if (text === null) return ok(null);
  const normalized = normalizeKey(text);
  for (const [label, code] of Object.entries(valueMap)) {
    if (normalizeKey(label) === normalized) return ok(code);
  }
  const asCode = text.toUpperCase().replace(/[\s-]+/g, '_');
  if (values.includes(asCode)) return ok(asCode);
  return fail('UNMAPPED_VALUE', `Nilai "${text}" belum dipetakan ke salah satu dari: ${values.join(', ')}.`);
}
