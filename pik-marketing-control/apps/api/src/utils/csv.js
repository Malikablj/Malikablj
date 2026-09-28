/**
 * RFC 4180 CSV writer.
 * Text cells that start with = + - @ or a control character are prefixed with an
 * apostrophe so spreadsheet apps do not evaluate them as formulas (CSV injection).
 * Numbers are written plainly with "." as decimal separator.
 */
const FORMULA_START = /^[=+\-@\t\r]/;

function cell(value) {
  if (value === null || value === undefined) return '';
  if (typeof value === 'number') return Number.isFinite(value) ? String(value) : '';
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
  let text = value instanceof Date ? value.toISOString() : String(value);
  if (FORMULA_START.test(text)) text = `'${text}`;
  return /[",\r\n]/.test(text) || text !== text.trim() ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * @param {{ key: string, header: string, value?: (row) => unknown }[]} columns
 * @param {object[]} rows
 */
export function toCsv(columns, rows) {
  const lines = [columns.map((column) => cell(column.header)).join(',')];
  for (const row of rows) {
    lines.push(columns.map((column) => cell(column.value ? column.value(row) : row[column.key])).join(','));
  }
  return `${lines.join('\r\n')}\r\n`;
}
