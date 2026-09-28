'use strict';

/** RFC 4180 CSV with a UTF-8 BOM (so Excel and Google Sheets detect UTF-8). */

function escapeCell(value) {
  if (value === null || value === undefined) return '';
  let text = String(value);
  // Neutralise text that a spreadsheet would evaluate as a formula (CSV injection).
  if (/^[=+@\t\r]/.test(text) || /^-[^\d]/.test(text)) text = `'${text}`;
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function toCsv(rows, columns) {
  const lines = [columns.join(','), ...rows.map((row) => columns.map((column) => escapeCell(row[column])).join(','))];
  return `﻿${lines.join('\r\n')}\r\n`;
}

module.exports = { toCsv };
