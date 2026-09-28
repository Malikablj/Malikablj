/**
 * Reads an .xlsx workbook into plain rows. Cell values are unwrapped (formula -> cached
 * result, rich text -> text, hyperlink -> text); the original cell type is kept so the
 * profiler can report formulas, errors and dates stored as text.
 * The source file is only read, never written.
 */
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import ExcelJS from 'exceljs';

const { ValueType } = ExcelJS;

function columnLetter(index) {
  let letters = '';
  let n = index;
  while (n > 0) {
    const remainder = (n - 1) % 26;
    letters = String.fromCharCode(65 + remainder) + letters;
    n = Math.floor((n - 1) / 26);
  }
  return letters;
}

/** @returns {{ value: unknown, kind: string }} */
export function unwrapCell(cell) {
  const raw = cell.value;
  if (raw === null || raw === undefined) return { value: null, kind: 'empty' };
  switch (cell.type) {
    case ValueType.Formula: {
      const result = raw.result;
      if (result && typeof result === 'object' && 'error' in result) return { value: null, kind: 'error', error: result.error };
      return { value: result instanceof Date || typeof result !== 'object' ? (result ?? null) : null, kind: 'formula', formula: raw.formula ?? raw.sharedFormula };
    }
    case ValueType.SharedString:
    case ValueType.String:
      return { value: String(raw), kind: 'text' };
    case ValueType.Number:
      return { value: raw, kind: 'number' };
    case ValueType.Date:
      return { value: raw, kind: 'date' };
    case ValueType.Boolean:
      return { value: raw, kind: 'boolean' };
    case ValueType.RichText:
      return { value: raw.richText.map((part) => part.text).join(''), kind: 'richtext' };
    case ValueType.Hyperlink:
      return { value: typeof raw.text === 'string' ? raw.text : String(raw.text?.richText?.map((p) => p.text).join('') ?? ''), kind: 'hyperlink' };
    case ValueType.Error:
      return { value: null, kind: 'error', error: raw.error };
    default:
      return { value: typeof raw === 'object' ? JSON.stringify(raw) : raw, kind: 'other' };
  }
}

function isBlank(value) {
  return value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
}

/** JSON-safe copy of a row for source_data lineage (dates as ISO strings). */
export function toSourceData(values) {
  const data = {};
  for (const [key, value] of Object.entries(values)) {
    if (isBlank(value)) continue;
    data[key] = value instanceof Date ? value.toISOString() : value;
  }
  return data;
}

function readSheet(worksheet, headerRowOverride) {
  const rowCount = worksheet.actualRowCount ? worksheet.rowCount : 0;
  let headerRow = headerRowOverride ?? null;
  if (!headerRow) {
    for (let r = 1; r <= rowCount; r += 1) {
      if (worksheet.getRow(r).actualCellCount > 0) {
        headerRow = r;
        break;
      }
    }
  }
  const sheet = {
    name: worksheet.name,
    headerRow,
    headers: [],
    rows: [],
    merges: [...(worksheet.model.merges ?? [])],
    headerProblems: [],
  };
  if (!headerRow) return sheet;

  const header = worksheet.getRow(headerRow);
  const seen = new Map();
  const columnCount = Math.max(worksheet.columnCount, header.cellCount);
  for (let c = 1; c <= columnCount; c += 1) {
    const { value } = unwrapCell(header.getCell(c));
    let name = isBlank(value) ? null : String(value).replace(/\s+/g, ' ').trim();
    if (!name) continue;
    if (seen.has(name)) {
      const count = seen.get(name) + 1;
      seen.set(name, count);
      sheet.headerProblems.push(`Kolom "${name}" muncul lebih dari sekali; kolom ${columnLetter(c)} dibaca sebagai "${name} (${count})".`);
      name = `${name} (${count})`;
    } else {
      seen.set(name, 1);
    }
    sheet.headers.push({ name, column: c, letter: columnLetter(c) });
  }

  for (let r = headerRow + 1; r <= rowCount; r += 1) {
    const row = worksheet.getRow(r);
    const values = {};
    const kinds = {};
    let empty = true;
    for (const { name, column } of sheet.headers) {
      const cell = unwrapCell(row.getCell(column));
      values[name] = cell.value;
      kinds[name] = cell;
      if (!isBlank(cell.value)) empty = false;
    }
    // Data in columns without a header would be lost silently: record it.
    let orphanCells = 0;
    if (row.actualCellCount > 0) {
      row.eachCell({ includeEmpty: false }, (cell, colNumber) => {
        if (!sheet.headers.some((h) => h.column === colNumber) && !isBlank(unwrapCell(cell).value)) orphanCells += 1;
      });
    }
    sheet.rows.push({ rowNumber: r, values, kinds, empty, orphanCells });
  }
  return sheet;
}

/**
 * @param {string} filePath
 * @param {{ headerRows?: Record<string, number> }} options header row per sheet name (default: first non-empty row)
 */
export async function readWorkbook(filePath, { headerRows = {} } = {}) {
  if (!fs.existsSync(filePath)) {
    throw new Error(`Workbook tidak ditemukan: ${filePath}`);
  }
  const buffer = fs.readFileSync(filePath);
  const workbook = new ExcelJS.Workbook();
  await workbook.xlsx.load(buffer);
  return {
    filePath,
    fileName: path.basename(filePath),
    checksum: createHash('sha256').update(buffer).digest('hex'),
    sheets: workbook.worksheets.map((worksheet) => readSheet(worksheet, headerRows[worksheet.name])),
  };
}
