'use strict';

/** Generic, domain-agnostic profiling of every column in a sheet table. */

const { cleanText, isAmbiguousDayMonth, round } = require('./normalize');

const TOP_VALUES = 8;
const LINEAGE = ['SourceFile', 'SourceSheet', 'LegacyRow'];

function decimalsOf(value) {
  const text = String(value);
  return text.includes('e') || !text.includes('.') ? 0 : text.split('.')[1].length;
}

function describeNumbers(values) {
  let min = Infinity;
  let max = -Infinity;
  let sum = 0;
  for (const value of values) {
    min = Math.min(min, value);
    max = Math.max(max, value);
    sum += value;
  }
  return {
    count: values.length,
    min,
    max,
    sum: round(sum, 4),
    negative: values.filter((value) => value < 0).length,
    zero: values.filter((value) => value === 0).length,
    nonInteger: values.filter((value) => !Number.isInteger(value)).length,
    maxDecimals: Math.max(...values.map(decimalsOf)),
  };
}

function describeDates(entries, asOf) {
  const isoValues = entries.map((entry) => entry.value).filter(Boolean).sort();
  const byYear = {};
  for (const iso of isoValues) byYear[iso.slice(0, 4)] = (byYear[iso.slice(0, 4)] || 0) + 1;
  return {
    count: isoValues.length,
    min: isoValues[0] ?? null,
    max: isoValues[isoValues.length - 1] ?? null,
    withTimeOfDay: entries.filter((entry) => entry.cell.value % 1 !== 0).length,
    afterAsOf: isoValues.filter((iso) => iso > asOf).length,
    ambiguousDayMonth: isoValues.filter(isAmbiguousDayMonth).length,
    byYear,
  };
}

function describeText(values) {
  const variants = new Map();
  for (const value of values) {
    const key = (cleanText(value) ?? '').toLowerCase();
    if (!variants.has(key)) variants.set(key, new Set());
    variants.get(key).add(value);
  }
  return {
    count: values.length,
    minLength: Math.min(...values.map((value) => value.length)),
    maxLength: Math.max(...values.map((value) => value.length)),
    surroundingWhitespace: values.filter((value) => value !== value.trim()).length,
    repeatedWhitespace: values.filter((value) => /\s{2,}/.test(value.trim())).length,
    lineBreaks: values.filter((value) => /[\r\n]/.test(value)).length,
    numericLike: values.filter((value) => /^-?\d+(?:[.,]\d+)?$/.test(value.trim())).length,
    floatSuffix: values.filter((value) => /^\d+\.0+$/.test(value.trim())).length,
    urls: values.filter((value) => /^https?:\/\//i.test(value.trim())).length,
    caseOrSpacingVariantGroups: [...variants.values()].filter((set) => set.size > 1).length,
  };
}

function classify(profile, rowCount) {
  if (profile.nonEmpty === 0) return 'kosong';
  if (profile.distinct === 1) return 'konstan';
  if (profile.unique && profile.nonEmpty === rowCount) return 'unik & lengkap (kandidat kunci)';
  if (profile.unique) return 'unik (ada yang kosong)';
  if (profile.distinct <= 12) return 'kategori';
  return 'bebas';
}

function profileColumn(header, records, asOf) {
  const present = [];
  for (const record of records) {
    const cell = record._cells[header.name];
    if (cell) present.push({ cell, value: record[header.name] });
  }
  const types = {};
  const frequencies = new Map();
  const numberFormats = {};
  let formulas = 0;
  for (const { cell, value } of present) {
    types[cell.type] = (types[cell.type] || 0) + 1;
    const key = cell.type === 'boolean' ? String(value).toUpperCase() : String(value);
    frequencies.set(key, (frequencies.get(key) || 0) + 1);
    if (cell.numberFormat) numberFormats[cell.numberFormat] = (numberFormats[cell.numberFormat] || 0) + 1;
    if (cell.formula !== null) formulas += 1;
  }
  const profile = {
    column: header.letter,
    name: header.name,
    nonEmpty: present.length,
    empty: records.length - present.length,
    fillRate: records.length ? round(present.length / records.length, 4) : 0,
    distinct: frequencies.size,
    unique: present.length > 0 && frequencies.size === present.length,
    types,
    numberFormats,
    formulas,
    top: [...frequencies.entries()]
      .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
      .slice(0, TOP_VALUES)
      .map(([value, count]) => ({ value, count })),
  };
  const numbers = present.filter((entry) => entry.cell.type === 'number').map((entry) => entry.value);
  if (numbers.length) profile.number = describeNumbers(numbers);
  const dates = present.filter((entry) => entry.cell.type === 'date');
  if (dates.length) profile.date = describeDates(dates, asOf);
  const texts = present.filter((entry) => entry.cell.type === 'string').map((entry) => entry.value);
  if (texts.length) profile.text = describeText(texts);
  profile.role = classify(profile, records.length);
  return profile;
}

/** Uniqueness of a column combination; with allowEmpty an empty part counts as a value ("no variant"). */
function compositeKeyReport(records, { columns, allowEmpty = false }) {
  const complete = allowEmpty
    ? records
    : records.filter((record) => columns.every((column) => cleanText(record[column]) !== null));
  const seen = new Map();
  for (const record of complete) {
    const key = columns.map((column) => cleanText(record[column])).join('|');
    seen.set(key, (seen.get(key) || 0) + 1);
  }
  const duplicateKeys = [...seen.values()].filter((count) => count > 1);
  return {
    columns,
    allowEmpty,
    rowsWithAllParts: complete.length,
    rowsMissingAPart: records.length - complete.length,
    duplicateKeys: duplicateKeys.length,
    rowsInDuplicateKeys: duplicateKeys.reduce((sum, count) => sum + count, 0),
    unique: duplicateKeys.length === 0,
  };
}

function profileSheet(table, sheet, { asOf, compositeKeys = [] }) {
  const columns = table.header.map((header) => profileColumn(header, table.records, asOf));
  const names = table.header.map((header) => header.name);
  const keysToTest = [...compositeKeys];
  if (LINEAGE.every((column) => names.includes(column))) keysToTest.push({ columns: LINEAGE });
  return {
    sheet: table.name,
    index: sheet.index,
    state: sheet.state,
    dimension: sheet.features.dimension,
    excelTable: table.table ? { name: table.table.name, ref: table.table.ref, columns: table.table.columns.length } : null,
    headerRow: table.headerRowNumber,
    rows: table.records.length,
    blankRows: table.blankRows,
    columnCount: names.length,
    features: sheet.features,
    columns,
    emptyColumns: columns.filter((column) => column.nonEmpty === 0).map((column) => column.name),
    constantColumns: columns.filter((column) => column.distinct === 1).map((column) => `${column.name}=${column.top[0].value}`),
    singleColumnKeys: columns.filter((column) => column.unique && column.nonEmpty === table.records.length && table.records.length > 0).map((column) => column.name),
    compositeKeys: keysToTest
      .filter((key) => key.columns.every((column) => names.includes(column)))
      .map((key) => compositeKeyReport(table.records, key)),
  };
}

module.exports = { profileSheet };
