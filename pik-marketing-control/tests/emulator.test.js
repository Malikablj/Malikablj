'use strict';

/**
 * Pins the Google Sheets behaviours the emulator reproduces. If one of these assumptions turns out to differ from
 * real Apps Script, fix the emulator and this file together, then rerun the whole suite.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');

const { createGasEnvironment } = require('./gas-emulator');
const { formatDate } = require('./gas-emulator/format-date');
const { loadGasProject } = require('./helpers/load-gas');

function newSheet() {
  const env = createGasEnvironment();
  const spreadsheet = env.globals.SpreadsheetApp.create('uji');
  return { env, spreadsheet, sheet: spreadsheet.getSheets()[0] };
}

test('sel format otomatis mengonversi teks mirip angka/boolean/tanggal; sel Plain text tidak', () => {
  const { sheet } = newSheet();
  sheet.getRange(1, 1, 1, 5).setValues([['00123', 'TRUE', '2026-09-28', '-5', 'teks']]);
  const [general] = sheet.getRange(1, 1, 1, 5).getValues();
  assert.equal(general[0], 123);
  assert.equal(general[1], true);
  assert.ok(general[2] instanceof Date);
  assert.equal(formatDate(general[2], 'Asia/Jakarta', 'yyyy-MM-dd'), '2026-09-28', 'tengah malam zona spreadsheet');
  assert.equal(general[3], -5);
  assert.equal(general[4], 'teks');

  sheet.getRange(2, 1, 1, 4).setNumberFormat('@').setValues([['00123', 'TRUE', '2026-09-28', '=1+1']]);
  assert.deepEqual(sheet.getRange(2, 1, 1, 4).getValues(), [['00123', 'TRUE', '2026-09-28', '=1+1']]);
});

test('apostrof di depan ditelan dan formula pada sel otomatis tidak didukung', () => {
  const { sheet } = newSheet();
  sheet.getRange(1, 1).setValue("'00123");
  assert.equal(sheet.getRange(1, 1).getValue(), '00123');
  assert.throws(() => sheet.getRange(1, 2).setValue('=SUM(A1)'), /formula write is not supported/);
});

test('angka, boolean, dan null mempertahankan tipe; undefined ditolak', () => {
  const { sheet } = newSheet();
  sheet.getRange(1, 1, 1, 3).setNumberFormat('@').setValues([[12.5, false, null]]);
  assert.deepEqual(sheet.getRange(1, 1, 1, 3).getValues(), [[12.5, false, '']]);
  assert.throws(() => sheet.getRange(1, 1).setValue(undefined), /undefined cell value/);
});

test('rentang di luar ukuran sheet dan dimensi data yang salah melempar error', () => {
  const { sheet } = newSheet();
  assert.throws(() => sheet.getRange(1001, 1), /outside the dimensions/);
  assert.throws(() => sheet.getRange(1, 27), /outside the dimensions/);
  assert.throws(() => sheet.getRange(1, 1, 0, 1), /at least 1/);
  assert.throws(() => sheet.getRange(1, 1, 2, 2).setValues([[1, 2]]), /number of rows/);
  assert.throws(() => sheet.getRange(1, 1, 1, 2).setValues([[1]]), /number of columns/);
});

test('getLastRow/getLastColumn hanya menghitung sel bernilai; baris baru tanpa format', () => {
  const { sheet } = newSheet();
  sheet.getRange(1, 1, 10, 3).setNumberFormat('@');
  assert.equal(sheet.getLastRow(), 0);
  sheet.getRange(2, 2).setValue('x');
  assert.equal(sheet.getLastRow(), 2);
  assert.equal(sheet.getLastColumn(), 2);
  sheet.insertRowsAfter(1000, 5);
  assert.equal(sheet.getMaxRows(), 1005);
  assert.notEqual(sheet.getRange(1003, 1).getNumberFormat(), '@', 'baris sisipan tidak mewarisi format');
  sheet.insertRowsAfter(1, 1);
  assert.equal(sheet.getRange(3, 2).getValue(), 'x', 'sisipan menggeser baris di bawahnya');
});

test('validasi data tidak ditegakkan untuk penulisan skrip', () => {
  const { env, sheet } = newSheet();
  const rule = env.globals.SpreadsheetApp.newDataValidation().requireValueInList(['A', 'B'], true).setAllowInvalid(false).build();
  sheet.getRange(1, 1).setDataValidation(rule);
  sheet.getRange(1, 1).setValue('Z');
  assert.equal(sheet.getRange(1, 1).getValue(), 'Z');
  assert.equal(sheet.getRange(1, 1).getDataValidation().getCriteriaType(), 'VALUE_IN_LIST');
  assert.deepEqual(sheet.getRange(1, 1).getDataValidation().getCriteriaValues(), [['A', 'B'], true]);
});

test('Utilities.formatDate mengikuti zona waktu', () => {
  const instant = new Date(Date.UTC(2026, 9, 1, 17, 30, 5, 7));
  assert.equal(formatDate(instant, 'Asia/Jakarta', 'yyyy-MM-dd'), '2026-10-02');
  assert.equal(formatDate(instant, 'UTC', "yyyy-MM-dd'T'HH:mm:ss.SSS"), '2026-10-01T17:30:05.007');
  assert.equal(formatDate(instant, 'Asia/Jakarta', 'HH:mm'), '00:30');
});

test('tanggal dari emulator adalah Date milik konteks skrip', () => {
  const { context, env } = loadGasProject();
  const spreadsheet = env.globals.SpreadsheetApp.create('uji');
  const sheet = spreadsheet.getSheets()[0];
  sheet.getRange(1, 1).setValue('2026-09-28');
  context.__cell = sheet.getRange(1, 1).getValue();
  assert.equal(env.evaluate('__cell instanceof Date'), true);
});
