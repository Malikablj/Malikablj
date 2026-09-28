'use strict';

/**
 * Value normalisation helpers shared by profiling and, later, the migration engine.
 * Pure functions with no Node or Apps Script APIs so they can be reused unchanged.
 *
 * The *Key() helpers build loose comparison keys for duplicate detection and
 * candidate matching only. They are never written back as data.
 */

const MS_PER_DAY = 86400000;
const EXCEL_EPOCH_1900 = Date.UTC(1899, 11, 30);
const EXCEL_EPOCH_1904 = Date.UTC(1904, 0, 1);

const ROMAN_MONTHS = { I: 1, II: 2, III: 3, IV: 4, V: 5, VI: 6, VII: 7, VIII: 8, IX: 9, X: 10, XI: 11, XII: 12 };

// Month abbreviations seen in PIK invoice numbers (Indonesian and English spellings).
const MONTH_TOKENS = {
  JAN: 1, FEB: 2, MAR: 3, APR: 4, MEI: 5, MAY: 5, JUN: 6, JUL: 7, AGU: 8, AGS: 8, AGT: 8, AGUST: 8, AUG: 8,
  SEP: 9, SEPT: 9, OKT: 10, OCT: 10, NOV: 11, DES: 12, DEC: 12,
};

/** Excel serial date (1900 or 1904 system) to 'YYYY-MM-DD'. Time of day is dropped. */
function excelSerialToIsoDate(serial, date1904 = false) {
  if (typeof serial !== 'number' || !Number.isFinite(serial)) return null;
  if (!date1904 && serial < 61) return null; // Excel's fictitious 1900-02-29 zone; never valid business data
  const epoch = date1904 ? EXCEL_EPOCH_1904 : EXCEL_EPOCH_1900;
  return new Date(epoch + Math.floor(serial) * MS_PER_DAY).toISOString().slice(0, 10);
}

function isValidIsoDate(iso) {
  if (typeof iso !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return false;
  const time = Date.parse(`${iso}T00:00:00Z`);
  return !Number.isNaN(time) && new Date(time).toISOString().slice(0, 10) === iso;
}

function makeIsoDate(year, month, day) {
  const iso = `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
  return isValidIsoDate(iso) ? iso : null;
}

function isoToDayNumber(iso) {
  return Date.parse(`${iso}T00:00:00Z`) / MS_PER_DAY;
}

function daysBetween(fromIso, toIso) {
  return isoToDayNumber(toIso) - isoToDayNumber(fromIso);
}

/** Year * 12 + month index, so month distances can be computed by subtraction. */
function monthIndex(year, month) {
  return year * 12 + (month - 1);
}

function isoMonthIndex(iso) {
  const [year, month] = iso.split('-').map(Number);
  return monthIndex(year, month);
}

/** The date with day and month exchanged, or null when that is impossible or changes nothing. */
function swapDayMonth(iso) {
  const [year, month, day] = iso.split('-').map(Number);
  if (day > 12 || day === month) return null;
  return makeIsoDate(year, day, month);
}

/** True when both day and month are <= 12 and differ, i.e. the date reads validly both ways. */
function isAmbiguousDayMonth(iso) {
  return swapDayMonth(iso) !== null;
}

function cleanText(value) {
  if (value === null || value === undefined) return null;
  const text = String(value).replace(/\s+/g, ' ').trim();
  return text === '' ? null : text;
}

/** Company/person name key: ignores case, punctuation, spacing and legal forms (PT, CV, Tbk, UD). */
function nameKey(value) {
  const text = cleanText(value);
  if (!text) return null;
  const key = text
    .toLowerCase()
    .replace(/\b(pt|cv|tbk|ud)\b\.?/g, ' ')
    .replace(/[^a-z0-9]+/g, '');
  return key || null;
}

/** Free-text key: ignores case, punctuation and spacing. */
function textKey(value) {
  const text = cleanText(value);
  if (!text) return null;
  return text.toLowerCase().replace(/[^a-z0-9]+/g, '') || null;
}

/** Document number key (PO, SJ, invoice): whitespace removed, upper-case. */
function documentKey(value) {
  const text = cleanText(value);
  return text ? text.replace(/\s+/g, '').toUpperCase() : null;
}

/** Product/component code without brackets, parentheses or spaces, upper-case. "[ABC123]" -> "ABC123". */
function normalizeCode(value) {
  const text = cleanText(value);
  if (!text) return null;
  return text.replace(/[[\]()\s]/g, '').toUpperCase() || null;
}

/** Code written at the start of a legacy label: "[ABC123] Botol ..." or "( ABC123 ) Botol ...". */
function leadingCode(value) {
  const text = cleanText(value);
  if (!text) return null;
  const match = text.match(/^[[(]\s*([A-Za-z0-9._-]+)\s*[\])]/);
  return match ? match[1].toUpperCase() : null;
}

/** Label with a leading code removed: "[ABC123] Pot 10 ml" -> "Pot 10 ml". */
function withoutLeadingCode(value) {
  const text = cleanText(value);
  if (!text) return null;
  return cleanText(text.replace(/^[[(]\s*[A-Za-z0-9._-]+\s*[\])]/, ''));
}

/** "12345678901234.0" -> "12345678901234": undoes float conversion of long numeric identifiers. */
function stripFloatSuffix(value) {
  const text = cleanText(value);
  if (!text) return null;
  return /^\d+\.0+$/.test(text) ? text.replace(/\.0+$/, '') : text;
}

function approxEqual(a, b, tolerance = 0.5) {
  return Math.abs(a - b) <= tolerance;
}

function round(value, decimals = 2) {
  const factor = 10 ** decimals;
  return Math.round(value * factor) / factor;
}

module.exports = {
  MONTH_TOKENS,
  ROMAN_MONTHS,
  approxEqual,
  cleanText,
  daysBetween,
  documentKey,
  excelSerialToIsoDate,
  isAmbiguousDayMonth,
  isValidIsoDate,
  isoMonthIndex,
  isoToDayNumber,
  leadingCode,
  makeIsoDate,
  monthIndex,
  nameKey,
  normalizeCode,
  round,
  stripFloatSuffix,
  swapDayMonth,
  textKey,
  withoutLeadingCode,
};
