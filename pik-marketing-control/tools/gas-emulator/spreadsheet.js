'use strict';

/**
 * In-memory SpreadsheetApp emulator covering the API surface used by src/.
 *
 * Behaviours reproduced on purpose because the code depends on them:
 *  - fixed sheet dimensions: ranges outside maxRows/maxColumns throw, rows/columns must be inserted first;
 *  - setValues checks the data dimensions against the range;
 *  - "user entered" parsing of strings in cells whose number format is not Plain text ('@'): numeric strings become
 *    numbers, TRUE/FALSE become booleans, yyyy-mm-dd becomes a Date (local midnight in the spreadsheet time zone),
 *    a leading apostrophe is swallowed, and strings starting with '=' are formulas (not supported: they throw);
 *  - Plain text cells keep strings unchanged; numbers, booleans and dates keep their type in any format;
 *  - getValues returns '' for empty cells; rows inserted with insertRowsAfter carry no formats or validation;
 *  - data validation is not enforced for script writes (as in Apps Script).
 */

const { localMidnight } = require('./format-date');

const DEFAULT_ROWS = 1000;
const DEFAULT_COLUMNS = 26;
const DEFAULT_NUMBER_FORMAT = '0.###############';
const ProtectionType = Object.freeze({ SHEET: 'SHEET', RANGE: 'RANGE' });
const DataValidationCriteria = Object.freeze({ CHECKBOX: 'CHECKBOX', VALUE_IN_LIST: 'VALUE_IN_LIST' });

function isDate(value) {
  return Object.prototype.toString.call(value) === '[object Date]';
}

class DataValidation {
  constructor(criteriaType, criteriaValues, allowInvalid, helpText) {
    this.criteriaType = criteriaType;
    this.criteriaValues = criteriaValues;
    this.allowInvalid = allowInvalid;
    this.helpText = helpText;
  }
  getCriteriaType() { return this.criteriaType; }
  getCriteriaValues() { return JSON.parse(JSON.stringify(this.criteriaValues)); }
  getAllowInvalid() { return this.allowInvalid; }
  getHelpText() { return this.helpText; }
}

class DataValidationBuilder {
  constructor() {
    this.criteriaType = null;
    this.criteriaValues = [];
    this.allowInvalid = true;
    this.helpText = '';
  }
  requireCheckbox(checked, unchecked) {
    this.criteriaType = DataValidationCriteria.CHECKBOX;
    this.criteriaValues = checked === undefined ? [] : [checked, unchecked];
    return this;
  }
  requireValueInList(values, showDropdown) {
    if (!Array.isArray(values) || values.length === 0) throw new Error('Emulator: requireValueInList needs values');
    this.criteriaType = DataValidationCriteria.VALUE_IN_LIST;
    this.criteriaValues = [values.slice(), showDropdown === undefined ? true : Boolean(showDropdown)];
    return this;
  }
  setAllowInvalid(allowInvalid) { this.allowInvalid = Boolean(allowInvalid); return this; }
  setHelpText(text) { this.helpText = String(text); return this; }
  build() {
    if (!this.criteriaType) throw new Error('Emulator: data validation without criteria');
    return new DataValidation(this.criteriaType, this.criteriaValues, this.allowInvalid, this.helpText);
  }
}

class Protection {
  constructor(sheet, type, range) {
    this.sheet = sheet;
    this.type = type;
    this.range = range; // { row, column, numRows, numColumns } for RANGE protections
    this.description = '';
    this.warningOnly = false;
    this.removed = false;
  }
  getProtectionType() { return this.type; }
  getDescription() { return this.description; }
  setDescription(description) { this.description = String(description); return this; }
  isWarningOnly() { return this.warningOnly; }
  setWarningOnly(value) { this.warningOnly = Boolean(value); return this; }
  getRange() {
    if (this.type === ProtectionType.SHEET) {
      return this.sheet.getRange(1, 1, this.sheet.getMaxRows(), this.sheet.getMaxColumns());
    }
    return this.sheet.getRange(this.range.row, this.range.column, this.range.numRows, this.range.numColumns);
  }
  setRange(range) {
    if (this.type !== ProtectionType.RANGE) throw new Error('Emulator: setRange on a sheet protection');
    this.range = { row: range.getRow(), column: range.getColumn(), numRows: range.getNumRows(), numColumns: range.getNumColumns() };
    return this;
  }
  remove() {
    this.removed = true;
    this.sheet.protections = this.sheet.protections.filter((p) => p !== this);
  }
  canEdit() { return true; }
}

class Range {
  constructor(sheet, row, column, numRows, numColumns) {
    this.sheet = sheet;
    this.row = row;
    this.column = column;
    this.numRows = numRows;
    this.numColumns = numColumns;
  }
  getSheet() { return this.sheet; }
  getRow() { return this.row; }
  getColumn() { return this.column; }
  getNumRows() { return this.numRows; }
  getNumColumns() { return this.numColumns; }
  getLastRow() { return this.row + this.numRows - 1; }
  getLastColumn() { return this.column + this.numColumns - 1; }

  forEachCell(callback) {
    for (let r = 0; r < this.numRows; r++) {
      for (let c = 0; c < this.numColumns; c++) callback(this.row + r, this.column + c, r, c);
    }
  }
  map2d(read) {
    const rows = [];
    for (let r = 0; r < this.numRows; r++) {
      const row = [];
      for (let c = 0; c < this.numColumns; c++) row.push(read(this.row + r, this.column + c));
      rows.push(row);
    }
    return rows;
  }
  checkDimensions(values, what) {
    if (!Array.isArray(values) || values.length !== this.numRows) {
      throw new Error(`The number of rows in the ${what} does not match the number of rows in the range. ` +
        `The ${what} has ${Array.isArray(values) ? values.length : 0} but the range has ${this.numRows}.`);
    }
    values.forEach((row) => {
      if (!Array.isArray(row) || row.length !== this.numColumns) {
        throw new Error(`The number of columns in the ${what} does not match the number of columns in the range. ` +
          `The ${what} has ${Array.isArray(row) ? row.length : 0} but the range has ${this.numColumns}.`);
      }
    });
  }

  getValues() {
    this.sheet.env.record('Range.getValues', this.sheet, this);
    return this.map2d((r, c) => this.sheet.env.exportValue(this.sheet.cell(r, c).value));
  }
  getValue() {
    return this.sheet.env.exportValue(this.sheet.cell(this.row, this.column).value);
  }
  setValues(values) {
    this.checkDimensions(values, 'data');
    this.sheet.env.record('Range.setValues', this.sheet, this, true);
    this.forEachCell((r, c, i, j) => this.sheet.writeValue(r, c, values[i][j]));
    return this;
  }
  setValue(value) {
    this.sheet.env.record('Range.setValue', this.sheet, this, true);
    this.forEachCell((r, c) => this.sheet.writeValue(r, c, value));
    return this;
  }
  clearContent() {
    this.sheet.env.record('Range.clearContent', this.sheet, this, true);
    this.forEachCell((r, c) => { this.sheet.cellForWrite(r, c).value = ''; });
    return this;
  }

  getNumberFormats() { return this.map2d((r, c) => this.sheet.cell(r, c).format); }
  getNumberFormat() { return this.sheet.cell(this.row, this.column).format; }
  setNumberFormat(format) {
    this.sheet.env.record('Range.setNumberFormat', this.sheet, this, true);
    this.forEachCell((r, c) => { this.sheet.cellForWrite(r, c).format = String(format); });
    return this;
  }
  setNumberFormats(formats) {
    this.checkDimensions(formats, 'number formats');
    this.sheet.env.record('Range.setNumberFormats', this.sheet, this, true);
    this.forEachCell((r, c, i, j) => { this.sheet.cellForWrite(r, c).format = String(formats[i][j]); });
    return this;
  }

  getDataValidations() { return this.map2d((r, c) => this.sheet.cell(r, c).validation); }
  getDataValidation() { return this.sheet.cell(this.row, this.column).validation; }
  setDataValidation(rule) {
    this.sheet.env.record('Range.setDataValidation', this.sheet, this, true);
    this.forEachCell((r, c) => { this.sheet.cellForWrite(r, c).validation = rule; });
    return this;
  }

  getNotes() { return this.map2d((r, c) => this.sheet.cell(r, c).note); }
  getNote() { return this.sheet.cell(this.row, this.column).note; }
  setNotes(notes) {
    this.checkDimensions(notes, 'notes');
    this.sheet.env.record('Range.setNotes', this.sheet, this, true);
    this.forEachCell((r, c, i, j) => { this.sheet.cellForWrite(r, c).note = notes[i][j] === null ? '' : String(notes[i][j]); });
    return this;
  }

  setFontWeight(weight) {
    this.forEachCell((r, c) => { this.sheet.cellForWrite(r, c).fontWeight = weight === null ? 'normal' : weight; });
    return this;
  }
  getFontWeight() { return this.sheet.cell(this.row, this.column).fontWeight; }
  setBackground(color) {
    this.forEachCell((r, c) => { this.sheet.cellForWrite(r, c).background = color === null ? '#ffffff' : color; });
    return this;
  }
  getBackground() { return this.sheet.cell(this.row, this.column).background; }
  setFontColor(color) {
    this.forEachCell((r, c) => { this.sheet.cellForWrite(r, c).fontColor = color === null ? '#000000' : color; });
    return this;
  }
  getFontColor() { return this.sheet.cell(this.row, this.column).fontColor; }

  protect() {
    const protection = new Protection(this.sheet, ProtectionType.RANGE,
      { row: this.row, column: this.column, numRows: this.numRows, numColumns: this.numColumns });
    this.sheet.protections.push(protection);
    return protection;
  }
}

const EMPTY_CELL = Object.freeze({
  value: '', format: DEFAULT_NUMBER_FORMAT, validation: null, note: '', fontWeight: 'normal',
  background: '#ffffff', fontColor: '#000000',
});

class Sheet {
  constructor(spreadsheet, name, id) {
    this.spreadsheet = spreadsheet;
    this.env = spreadsheet.env;
    this.name = name;
    this.sheetId = id;
    this.maxRows = DEFAULT_ROWS;
    this.maxColumns = DEFAULT_COLUMNS;
    this.frozenRows = 0;
    this.cells = new Map();
    this.protections = [];
  }
  key(row, column) { return row * 100000 + column; }
  cell(row, column) { return this.cells.get(this.key(row, column)) || EMPTY_CELL; }
  cellForWrite(row, column) {
    const key = this.key(row, column);
    let cell = this.cells.get(key);
    if (!cell) {
      cell = { ...EMPTY_CELL };
      this.cells.set(key, cell);
    }
    return cell;
  }
  writeValue(row, column, value) {
    const cell = this.cellForWrite(row, column);
    cell.value = this.env.importValue(value, cell.format, this.spreadsheet.timeZone);
  }

  getName() { return this.name; }
  setName(name) {
    const existing = this.spreadsheet.getSheetByName(name);
    if (existing && existing !== this) throw new Error(`A sheet with the name "${name}" already exists. Please enter another name.`);
    this.name = String(name);
    return this;
  }
  getSheetId() { return this.sheetId; }
  getIndex() { return this.spreadsheet.sheets.indexOf(this) + 1; }
  getParent() { return this.spreadsheet; }
  getMaxRows() { return this.maxRows; }
  getMaxColumns() { return this.maxColumns; }
  getLastRow() {
    let last = 0;
    for (const [key, cell] of this.cells) if (cell.value !== '') last = Math.max(last, Math.floor(key / 100000));
    return last;
  }
  getLastColumn() {
    let last = 0;
    for (const [key, cell] of this.cells) if (cell.value !== '') last = Math.max(last, key % 100000);
    return last;
  }
  getRange(row, column, numRows, numColumns) {
    if (typeof row !== 'number' || typeof column !== 'number') throw new Error('Emulator: only numeric getRange is supported');
    const rows = numRows === undefined ? 1 : numRows;
    const columns = numColumns === undefined ? 1 : numColumns;
    if (!Number.isInteger(row) || !Number.isInteger(column) || !Number.isInteger(rows) || !Number.isInteger(columns)) {
      throw new Error('Emulator: getRange arguments must be integers');
    }
    if (rows < 1) throw new Error('The number of rows in the range must be at least 1.');
    if (columns < 1) throw new Error('The number of columns in the range must be at least 1.');
    if (row < 1 || column < 1 || row + rows - 1 > this.maxRows || column + columns - 1 > this.maxColumns) {
      throw new Error('The coordinates of the range are outside the dimensions of the sheet.');
    }
    return new Range(this, row, column, rows, columns);
  }
  getDataRange() {
    return this.getRange(1, 1, Math.max(this.getLastRow(), 1), Math.max(this.getLastColumn(), 1));
  }
  setFrozenRows(rows) { this.frozenRows = rows; return this; }
  getFrozenRows() { return this.frozenRows; }

  shiftCells(axis, after, count) {
    const moved = new Map();
    for (const [key, cell] of this.cells) {
      let row = Math.floor(key / 100000);
      let column = key % 100000;
      if (axis === 'row' && row > after) row += count;
      if (axis === 'column' && column > after) column += count;
      moved.set(this.key(row, column), cell);
    }
    this.cells = moved;
  }
  removeCells(axis, start, count) {
    const moved = new Map();
    for (const [key, cell] of this.cells) {
      let row = Math.floor(key / 100000);
      let column = key % 100000;
      const position = axis === 'row' ? row : column;
      if (position >= start && position < start + count) continue;
      if (position >= start + count) {
        if (axis === 'row') row -= count;
        else column -= count;
      }
      moved.set(this.key(row, column), cell);
    }
    this.cells = moved;
  }
  insertRowsAfter(afterPosition, howMany) {
    if (afterPosition < 1 || afterPosition > this.maxRows || howMany < 1) throw new Error('Emulator: invalid insertRowsAfter');
    this.env.record('Sheet.insertRowsAfter', this, null, true);
    this.shiftCells('row', afterPosition, howMany);
    this.maxRows += howMany;
    return this;
  }
  insertColumnsAfter(afterPosition, howMany) {
    if (afterPosition < 1 || afterPosition > this.maxColumns || howMany < 1) throw new Error('Emulator: invalid insertColumnsAfter');
    this.env.record('Sheet.insertColumnsAfter', this, null, true);
    this.shiftCells('column', afterPosition, howMany);
    this.maxColumns += howMany;
    return this;
  }
  deleteColumns(columnPosition, howMany) {
    if (columnPosition < 1 || columnPosition + howMany - 1 > this.maxColumns) throw new Error('Emulator: invalid deleteColumns');
    if (howMany >= this.maxColumns) throw new Error('You can\'t delete all the columns on the sheet.');
    this.env.record('Sheet.deleteColumns', this, null, true);
    this.removeCells('column', columnPosition, howMany);
    this.maxColumns -= howMany;
    return this;
  }
  deleteRows(rowPosition, howMany) {
    if (rowPosition < 1 || rowPosition + howMany - 1 > this.maxRows) throw new Error('Emulator: invalid deleteRows');
    if (howMany >= this.maxRows) throw new Error('You can\'t delete all the rows on the sheet.');
    this.env.record('Sheet.deleteRows', this, null, true);
    this.removeCells('row', rowPosition, howMany);
    this.maxRows -= howMany;
    return this;
  }
  protect() {
    const protection = new Protection(this, ProtectionType.SHEET, null);
    this.protections.push(protection);
    return protection;
  }
  getProtections(type) { return this.protections.filter((p) => p.type === type); }
}

class Spreadsheet {
  constructor(env, id, name) {
    this.env = env;
    this.id = id;
    this.name = name;
    this.timeZone = env.scriptTimeZone;
    this.sheets = [];
    this.nextSheetId = 0;
    this.sheets.push(new Sheet(this, env.defaultSheetName, this.nextSheetId++));
  }
  getId() { return this.id; }
  getName() { return this.name; }
  getUrl() { return `https://docs.google.com/spreadsheets/d/${this.id}/edit`; }
  getSheets() { return this.sheets.slice(); }
  getNumSheets() { return this.sheets.length; }
  getSheetByName(name) { return this.sheets.find((sheet) => sheet.name === name) || null; }
  insertSheet(name, index) {
    if (this.getSheetByName(name)) throw new Error(`A sheet with the name "${name}" already exists. Please enter another name.`);
    const sheet = new Sheet(this, String(name), this.nextSheetId++);
    const position = index === undefined ? this.sheets.length : index;
    if (position < 0 || position > this.sheets.length) throw new Error('Emulator: sheet index out of range');
    this.sheets.splice(position, 0, sheet);
    this.env.record('Spreadsheet.insertSheet', sheet, null, true);
    return sheet;
  }
  deleteSheet(sheet) {
    if (this.sheets.length <= 1) throw new Error('A spreadsheet must have at least one sheet.');
    this.env.record('Spreadsheet.deleteSheet', sheet, null, true);
    this.sheets = this.sheets.filter((candidate) => candidate !== sheet);
  }
  getSpreadsheetTimeZone() { return this.timeZone; }
  setSpreadsheetTimeZone(timeZone) {
    new Intl.DateTimeFormat('en-US', { timeZone }); // throws on an unknown zone
    this.timeZone = timeZone;
  }
  getProtections(type) {
    return this.sheets.reduce((all, sheet) => all.concat(sheet.getProtections(type)), []);
  }
}

/** Converts a value written by a script into the stored cell value (see header comment). */
function importCellValue(value, format, timeZone, makeDate) {
  if (value === null) return '';
  if (value === undefined) throw new Error('Emulator: undefined cell value (Apps Script would write an empty or invalid cell)');
  if (isDate(value)) {
    if (Number.isNaN(value.getTime())) throw new Error('Emulator: invalid Date');
    return makeDate(value.getTime());
  }
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) throw new Error('Emulator: non-finite number');
    return value;
  }
  if (typeof value === 'boolean') return value;
  if (typeof value !== 'string') throw new Error(`Emulator: unsupported cell value type ${typeof value}`);
  if (format === '@') return value;
  if (value.startsWith("'")) return value.slice(1);
  if (value.startsWith('=')) throw new Error(`Emulator: formula write is not supported ("${value}")`);
  const trimmed = value.trim();
  if (/^[+-]?(\d+\.?\d*|\.\d+)$/.test(trimmed)) return Number(trimmed);
  if (/^(true|false)$/i.test(trimmed)) return trimmed.toLowerCase() === 'true';
  const date = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(trimmed);
  if (date) return makeDate(localMidnight(Number(date[1]), Number(date[2]), Number(date[3]), timeZone));
  return value;
}

module.exports = {
  DataValidationBuilder,
  DataValidationCriteria,
  DEFAULT_NUMBER_FORMAT,
  ProtectionType,
  Spreadsheet,
  importCellValue,
  isDate,
};
