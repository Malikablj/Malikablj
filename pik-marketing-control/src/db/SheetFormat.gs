/**
 * Sheet-level formatting derived from the schema: number formats, dropdown/checkbox validation, header style,
 * header notes and warning-only protection.
 *
 * Text-like columns (IDs, codes, document numbers, dates stored as 'yyyy-MM-dd', phone numbers) are formatted as
 * Plain text ('@') BEFORE values are written, so Sheets never converts '00123' to 123 or '2026-09-28' to a date.
 * Boolean columns keep the default format and get checkbox validation. Sheet validation only guards manual edits;
 * the application validator (Validation.gs) is the source of truth.
 */

const TEXT_NUMBER_FORMAT = '@';
const NUMBER_FORMAT_BY_TYPE = Object.freeze({
  integer: '0',
  quantity: '#,##0.###',
  money: '#,##0.00',
  decimal: '#,##0.######'
});
const HEADER_STYLE = Object.freeze({ background: '#F5F5F7', fontColor: '#111111', fontWeight: 'bold' });
const DB_PROTECTION_PREFIX = 'PIK_DB:';
const ROW_GROWTH_STEP = 500;

/** Number format of a column, or null for boolean columns (default format, checkbox validation). */
function columnNumberFormat_(column) {
  if (column.type === 'boolean') return null;
  return NUMBER_FORMAT_BY_TYPE[column.type] || TEXT_NUMBER_FORMAT;
}

/** Contiguous runs of formatted columns: [{ start: 1-based column, formats: [...] }]. Boolean columns split runs. */
function formatColumnRuns_(table) {
  const runs = [];
  let current = null;
  table.columns.forEach(function (column, index) {
    const format = columnNumberFormat_(column);
    if (format === null) {
      current = null;
      return;
    }
    if (!current) {
      current = { start: index + 1, formats: [] };
      runs.push(current);
    }
    current.formats.push(format);
  });
  return runs;
}

/**
 * Applies the schema number formats to rows [startRow, startRow + numRows). One call per run of columns.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function applyNumberFormats_(sheet, table, startRow, numRows) {
  if (numRows <= 0) return;
  formatColumnRuns_(table).forEach(function (run) {
    const block = [];
    for (let i = 0; i < numRows; i++) block.push(run.formats.slice());
    sheet.getRange(startRow, run.start, numRows, run.formats.length).setNumberFormats(block);
  });
}

/** @param {GoogleAppsScript.Spreadsheet.Sheet} sheet */
function numberFormatsMatch_(sheet, table, startRow, numRows) {
  if (numRows <= 0) return true;
  const current = sheet.getRange(startRow, 1, numRows, table.columns.length).getNumberFormats();
  for (let c = 0; c < table.columns.length; c++) {
    const expected = columnNumberFormat_(table.columns[c]);
    if (expected === null) continue;
    for (let r = 0; r < numRows; r++) {
      if (current[r][c] !== expected) return false;
    }
  }
  return true;
}

/**
 * Data validation rule for a column (checkbox or enum dropdown), or null when the column has none.
 * @return {GoogleAppsScript.Spreadsheet.DataValidation}
 */
function buildColumnValidation_(column, enumValues) {
  if (column.type === 'boolean') {
    return SpreadsheetApp.newDataValidation().requireCheckbox().setAllowInvalid(false).build();
  }
  if (column.type === 'enum') {
    const values = enumValues[column.enumName] || [];
    if (values.length === 0) return null;
    return SpreadsheetApp.newDataValidation()
      .requireValueInList(values, true)
      .setAllowInvalid(false)
      .setHelpText('Pilih nilai ' + column.enumName + ' dari sheet ENUMS.')
      .build();
  }
  return null;
}

/** @param {GoogleAppsScript.Spreadsheet.Sheet} sheet */
function applyColumnValidations_(sheet, table, startRow, numRows, enumValues) {
  if (numRows <= 0) return;
  table.columns.forEach(function (column, index) {
    const rule = buildColumnValidation_(column, enumValues);
    if (rule) sheet.getRange(startRow, index + 1, numRows, 1).setDataValidation(rule);
  });
}

/**
 * @param {GoogleAppsScript.Spreadsheet.DataValidation} actual
 * @param {GoogleAppsScript.Spreadsheet.DataValidation} expected
 */
function sameValidationRule_(actual, expected) {
  if (!actual || !expected) return actual === expected;
  return String(actual.getCriteriaType()) === String(expected.getCriteriaType()) &&
    actual.getAllowInvalid() === expected.getAllowInvalid() &&
    JSON.stringify(actual.getCriteriaValues()) === JSON.stringify(expected.getCriteriaValues());
}

/**
 * Compares the validation of the first and last data rows with the schema (a sample; enough to detect drift).
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function validationsMatch_(sheet, table, numRows, enumValues) {
  if (numRows <= 0) return true;
  const width = table.columns.length;
  const rowsToCheck = numRows === 1 ? [2] : [2, numRows + 1];
  for (let i = 0; i < rowsToCheck.length; i++) {
    const actual = sheet.getRange(rowsToCheck[i], 1, 1, width).getDataValidations()[0];
    for (let c = 0; c < width; c++) {
      if (!sameValidationRule_(actual[c], buildColumnValidation_(table.columns[c], enumValues))) return false;
    }
  }
  return true;
}

/** @param {GoogleAppsScript.Spreadsheet.Sheet} sheet */
function styleHeader_(sheet, table) {
  sheet.getRange(1, 1, 1, table.columns.length)
    .setFontWeight(HEADER_STYLE.fontWeight)
    .setBackground(HEADER_STYLE.background)
    .setFontColor(HEADER_STYLE.fontColor);
}

function buildColumnNote_(column) {
  const lines = [column.label, 'Tipe: ' + column.type + ' — ' + COLUMN_TYPES[column.type]];
  if (column.required) lines.push('Wajib diisi.');
  else if (column.requiredUnlessLegacy) lines.push('Wajib untuk data baru; boleh kosong pada data legacy.');
  if (column.ref) lines.push('Relasi: ' + column.ref + '.id');
  if (column.enumName) lines.push('Pilihan: ENUMS ' + column.enumName);
  if (column.writable === WRITABLE.AUTO) lines.push('Diisi otomatis oleh sistem.');
  if (column.writable === WRITABLE.MIGRATION) lines.push('Hanya diisi oleh proses migrasi.');
  if (column.writable === WRITABLE.INTERNAL) lines.push('Diisi oleh proses sistem.');
  if (column.note) lines.push(column.note);
  return lines.join('\n');
}

function buildHeaderNotes_(table) {
  return [table.columns.map(buildColumnNote_)];
}

/**
 * Adds rows (formatted and validated like the rest of the table) until the sheet has `requiredLastRow` rows.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 */
function ensureRowCapacity_(sheet, table, requiredLastRow) {
  const maxRows = sheet.getMaxRows();
  if (requiredLastRow <= maxRows) return false;
  const added = Math.max(requiredLastRow - maxRows, ROW_GROWTH_STEP);
  sheet.insertRowsAfter(maxRows, added);
  applyNumberFormats_(sheet, table, maxRows + 1, added);
  if (table.columns.some(function (column) { return column.type === 'boolean' || column.type === 'enum'; })) {
    applyColumnValidations_(sheet, table, maxRows + 1, added, getEnumValuesForValidation_());
  }
  return true;
}

/** @param {GoogleAppsScript.Spreadsheet.Sheet} sheet */
function ensureColumnCapacity_(sheet, width) {
  const maxColumns = sheet.getMaxColumns();
  if (maxColumns < width) sheet.insertColumnsAfter(maxColumns, width - maxColumns);
}

/**
 * Makes sure the sheet has its warning-only protection: the whole sheet for README and AUDIT_LOG, the header row
 * elsewhere. Existing protections are identified by description; a stricter protection set by an Admin is kept.
 */
/**
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 * @param {Object<string, GoogleAppsScript.Spreadsheet.Protection[]>} protectionIndex
 */
function ensureTableProtection_(sheet, table, protectionIndex) {
  const wholeSheet = table.name === 'README' || table.name === 'AUDIT_LOG';
  const description = DB_PROTECTION_PREFIX + (wholeSheet ? 'SHEET:' : 'HEADER:') + table.name;
  const existing = protectionIndex[description] || [];
  let changed = false;
  for (let i = 1; i < existing.length; i++) {
    existing[i].remove();
    changed = true;
  }
  const width = table.columns.length;
  if (existing.length === 0) {
    const protection = wholeSheet ? sheet.protect() : sheet.getRange(1, 1, 1, width).protect();
    protection.setDescription(description);
    protection.setWarningOnly(true);
    return true;
  }
  if (!wholeSheet) {
    const range = existing[0].getRange();
    if (range.getRow() !== 1 || range.getColumn() !== 1 || range.getNumRows() !== 1 || range.getNumColumns() !== width) {
      existing[0].setRange(sheet.getRange(1, 1, 1, width));
      changed = true;
    }
  }
  return changed;
}

/**
 * Our protections in the spreadsheet, grouped by description.
 * @param {GoogleAppsScript.Spreadsheet.Spreadsheet} spreadsheet
 * @return {Object<string, GoogleAppsScript.Spreadsheet.Protection[]>}
 */
function indexDatabaseProtections_(spreadsheet) {
  /** @type {Object<string, GoogleAppsScript.Spreadsheet.Protection[]>} */
  const index = {};
  [SpreadsheetApp.ProtectionType.SHEET, SpreadsheetApp.ProtectionType.RANGE].forEach(function (type) {
    spreadsheet.getProtections(type).forEach(function (protection) {
      const description = protection.getDescription() || '';
      if (description.indexOf(DB_PROTECTION_PREFIX) !== 0) return;
      (index[description] = index[description] || []).push(protection);
    });
  });
  return index;
}
