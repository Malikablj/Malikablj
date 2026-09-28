'use strict';

/**
 * Minimal read-only XLSX reader with no dependencies.
 *
 * Covers what the PIK workbooks use: stored/deflated zip entries, inline and shared
 * strings, numbers, booleans, errors, cached formula results, number formats (to
 * recognise date cells), Excel table definitions and the sheet-level features that
 * matter for profiling. ZIP64 archives are rejected. The source file is only read.
 */

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');

const { cleanText, excelSerialToIsoDate } = require('./normalize');

const ZIP_SIGNATURE = { endOfDirectory: 0x06054b50, directoryEntry: 0x02014b50, localHeader: 0x04034b50 };

// Built-in Excel number formats that display as a date and/or time.
const BUILTIN_DATE_FORMAT_IDS = new Set([
  14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56,
  57, 58,
]);

const XML_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };

function decodeXml(text) {
  return text.replace(/&(#x[0-9a-fA-F]+|#[0-9]+|amp|lt|gt|quot|apos);/g, (_, entity) => {
    if (entity[0] !== '#') return XML_ENTITIES[entity];
    const code = entity[1] === 'x' ? parseInt(entity.slice(2), 16) : parseInt(entity.slice(1), 10);
    return String.fromCodePoint(code);
  });
}

function parseAttributes(fragment) {
  const attributes = {};
  for (const match of fragment.matchAll(/([\w:.-]+)\s*=\s*"([^"]*)"/g)) {
    attributes[match[1]] = decodeXml(match[2]);
  }
  return attributes;
}

/** Concatenates the text runs of an <si> or <is> element, ignoring phonetic hints. */
function richText(fragment) {
  const withoutPhonetic = fragment.replace(/<rPh\b[\s\S]*?<\/rPh>/g, '');
  let text = '';
  for (const match of withoutPhonetic.matchAll(/<t(?:\s[^>]*)?>([\s\S]*?)<\/t>/g)) text += decodeXml(match[1]);
  return text;
}

function openZip(buffer) {
  let end = -1;
  for (let i = buffer.length - 22; i >= Math.max(0, buffer.length - 22 - 0xffff); i--) {
    if (buffer.readUInt32LE(i) === ZIP_SIGNATURE.endOfDirectory) {
      end = i;
      break;
    }
  }
  if (end < 0) throw new Error('Not a valid .xlsx file: zip directory not found.');
  const entryCount = buffer.readUInt16LE(end + 10);
  const directoryOffset = buffer.readUInt32LE(end + 16);
  if (entryCount === 0xffff || directoryOffset === 0xffffffff) throw new Error('ZIP64 workbooks are not supported.');

  const entries = new Map();
  let offset = directoryOffset;
  for (let i = 0; i < entryCount; i++) {
    if (buffer.readUInt32LE(offset) !== ZIP_SIGNATURE.directoryEntry) throw new Error('Corrupt zip directory.');
    const nameLength = buffer.readUInt16LE(offset + 28);
    entries.set(buffer.toString('utf8', offset + 46, offset + 46 + nameLength), {
      method: buffer.readUInt16LE(offset + 10),
      compressedSize: buffer.readUInt32LE(offset + 20),
      localOffset: buffer.readUInt32LE(offset + 42),
    });
    offset += 46 + nameLength + buffer.readUInt16LE(offset + 30) + buffer.readUInt16LE(offset + 32);
  }

  return {
    names: [...entries.keys()],
    read(name) {
      const entry = entries.get(name);
      if (!entry) return null;
      const header = entry.localOffset;
      if (buffer.readUInt32LE(header) !== ZIP_SIGNATURE.localHeader) throw new Error(`Corrupt zip entry: ${name}`);
      const start = header + 30 + buffer.readUInt16LE(header + 26) + buffer.readUInt16LE(header + 28);
      const data = buffer.subarray(start, start + entry.compressedSize);
      if (entry.method === 0) return data.toString('utf8');
      if (entry.method === 8) return zlib.inflateRawSync(data).toString('utf8');
      throw new Error(`Unsupported zip compression method ${entry.method} in ${name}`);
    },
  };
}

function resolvePartPath(baseDir, target) {
  if (target.startsWith('/')) return target.slice(1);
  const resolved = [];
  for (const part of `${baseDir}/${target}`.split('/')) {
    if (part === '..') resolved.pop();
    else if (part && part !== '.') resolved.push(part);
  }
  return resolved.join('/');
}

function readRelationships(zip, partPath) {
  const dir = path.posix.dirname(partPath);
  const xml = zip.read(`${dir}/_rels/${path.posix.basename(partPath)}.rels`);
  if (!xml) return [];
  return [...xml.matchAll(/<Relationship\b([^>]*?)\/?>/g)].map((match) => {
    const attributes = parseAttributes(match[1]);
    const external = attributes.TargetMode === 'External';
    return {
      id: attributes.Id,
      type: (attributes.Type || '').split('/').pop(),
      target: external ? attributes.Target : resolvePartPath(dir, attributes.Target),
      external,
    };
  });
}

function isDateFormatCode(formatCode) {
  const withoutLiterals = formatCode.replace(/"[^"]*"|\[[^\]]*\]|\\./g, '');
  return /[dmyhs]/i.test(withoutLiterals);
}

function parseStyles(xml) {
  const customFormats = new Map();
  if (!xml) return [];
  for (const match of xml.matchAll(/<numFmt\b([^>]*?)\/?>/g)) {
    const attributes = parseAttributes(match[1]);
    customFormats.set(Number(attributes.numFmtId), attributes.formatCode);
  }
  const cellFormats = xml.match(/<cellXfs\b[^>]*>([\s\S]*?)<\/cellXfs>/);
  if (!cellFormats) return [];
  return [...cellFormats[1].matchAll(/<xf\b([^>]*?)\/?>/g)].map((match) => {
    const numFmtId = Number(parseAttributes(match[1]).numFmtId || 0);
    const formatCode = customFormats.get(numFmtId) ?? null;
    const isDate = BUILTIN_DATE_FORMAT_IDS.has(numFmtId) || (formatCode !== null && isDateFormatCode(formatCode));
    return { numFmtId, formatCode, isDate };
  });
}

function columnNumber(letters) {
  let number = 0;
  for (const char of letters) number = number * 26 + (char.charCodeAt(0) - 64);
  return number;
}

function columnLetters(number) {
  let letters = '';
  for (let n = number; n > 0; n = Math.floor((n - 1) / 26)) letters = String.fromCharCode(65 + ((n - 1) % 26)) + letters;
  return letters;
}

function parseCell(attributes, inner, sharedStrings, styles) {
  const style = styles[Number(attributes.s || 0)] || { isDate: false, formatCode: null };
  const formula = inner.match(/<f\b[^>]*>([\s\S]*?)<\/f>|<f\b[^>]*\/>/);
  const rawMatch = inner.match(/<v>([\s\S]*?)<\/v>/);
  const raw = rawMatch ? decodeXml(rawMatch[1]) : null;

  let type;
  let value;
  switch (attributes.t) {
    case 's':
      type = 'string';
      value = raw === null ? null : sharedStrings[Number(raw)];
      break;
    case 'inlineStr':
      type = 'string';
      value = richText(inner);
      break;
    case 'str':
      type = 'string';
      value = raw;
      break;
    case 'b':
      type = 'boolean';
      value = raw === null ? null : raw === '1';
      break;
    case 'e':
      type = 'error';
      value = raw;
      break;
    case 'd':
      type = 'isoDate';
      value = raw;
      break;
    default:
      type = style.isDate ? 'date' : 'number';
      value = raw === null ? null : Number(raw);
  }
  if (value === null || value === '') {
    type = 'empty';
    value = null;
  }
  return {
    ref: attributes.r,
    column: columnNumber(attributes.r.match(/^[A-Z]+/)[0]),
    type,
    value,
    numberFormat: style.formatCode,
    styleId: Number(attributes.s || 0),
    formula: formula ? decodeXml(formula[1] || '') : null,
  };
}

function parseWorksheet(xml, sharedStrings, styles) {
  const rows = [];
  for (const rowMatch of xml.matchAll(/<row\b([^>]*?)(?:\/>|>([\s\S]*?)<\/row>)/g)) {
    const rowAttributes = parseAttributes(rowMatch[1]);
    const cells = [];
    for (const cellMatch of (rowMatch[2] || '').matchAll(/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g)) {
      cells.push(parseCell(parseAttributes(cellMatch[1]), cellMatch[2] || '', sharedStrings, styles));
    }
    rows.push({ number: Number(rowAttributes.r), hidden: rowAttributes.hidden === '1', cells });
  }
  return {
    rows,
    features: {
      dimension: (xml.match(/<dimension\s+ref="([^"]+)"/) || [])[1] || null,
      mergedRanges: [...xml.matchAll(/<mergeCell\s+ref="([^"]+)"/g)].map((match) => match[1]),
      dataValidations: (xml.match(/<dataValidation\b/g) || []).length,
      conditionalFormats: (xml.match(/<conditionalFormatting\b/g) || []).length,
      hyperlinks: (xml.match(/<hyperlink\b/g) || []).length,
      hiddenRows: rows.filter((row) => row.hidden).length,
      hiddenColumns: (xml.match(/<col\b[^>]*hidden="(?:1|true)"/g) || []).length,
      sheetProtection: /<sheetProtection\b/.test(xml),
      formulas: (xml.match(/<f[\s>/]/g) || []).length,
    },
  };
}

function parseTable(xml, partPath) {
  const attributes = parseAttributes((xml.match(/<table\b([^>]*)>/) || [])[1] || '');
  return {
    part: partPath,
    name: attributes.name || null,
    ref: attributes.ref || null,
    headerRowCount: Number(attributes.headerRowCount ?? 1),
    columns: [...xml.matchAll(/<tableColumn\b([^>]*?)\/?>/g)].map((match) => parseAttributes(match[1]).name),
  };
}

function readDocumentProperties(zip) {
  const core = zip.read('docProps/core.xml') || '';
  const app = zip.read('docProps/app.xml') || '';
  const pick = (xml, tag) => {
    const match = xml.match(new RegExp(`<${tag}\\b[^>]*>([\\s\\S]*?)</${tag}>`));
    return match ? decodeXml(match[1]) : null;
  };
  return {
    creator: pick(core, 'dc:creator'),
    lastModifiedBy: pick(core, 'cp:lastModifiedBy'),
    created: pick(core, 'dcterms:created'),
    modified: pick(core, 'dcterms:modified'),
    application: pick(app, 'Application'),
    appVersion: pick(app, 'AppVersion'),
  };
}

function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

/** Reads a workbook into plain objects. Throws on anything it cannot interpret faithfully. */
function readWorkbook(filePath) {
  const buffer = fs.readFileSync(filePath);
  const zip = openZip(buffer);
  const workbookXml = zip.read('xl/workbook.xml');
  if (!workbookXml) throw new Error('xl/workbook.xml not found: not an Excel workbook.');

  const workbookRelationships = new Map(readRelationships(zip, 'xl/workbook.xml').map((rel) => [rel.id, rel]));
  const sharedStringsXml = zip.read('xl/sharedStrings.xml');
  const sharedStrings = sharedStringsXml
    ? [...sharedStringsXml.matchAll(/<si\b[^>]*>([\s\S]*?)<\/si>/g)].map((match) => richText(match[1]))
    : [];
  const styles = parseStyles(zip.read('xl/styles.xml'));
  const workbookProperties = parseAttributes((workbookXml.match(/<workbookPr\b([^>]*?)\/?>/) || [])[1] || '');

  const sheets = [...workbookXml.matchAll(/<sheet\b([^>]*?)\/?>/g)].map((match, index) => {
    const attributes = parseAttributes(match[1]);
    const relationship = workbookRelationships.get(attributes['r:id']);
    if (!relationship) throw new Error(`Sheet "${attributes.name}" has no worksheet part.`);
    const { rows, features } = parseWorksheet(zip.read(relationship.target), sharedStrings, styles);
    const tables = readRelationships(zip, relationship.target)
      .filter((rel) => rel.type === 'table' && !rel.external)
      .map((rel) => parseTable(zip.read(rel.target), rel.target));
    return {
      index: index + 1,
      name: attributes.name,
      state: attributes.state || 'visible',
      part: relationship.target,
      rows,
      features,
      tables,
    };
  });

  return {
    file: {
      name: path.basename(filePath),
      sizeBytes: buffer.length,
      sha256: sha256(buffer),
      modifiedAt: fs.statSync(filePath).mtime.toISOString(),
    },
    date1904: workbookProperties.date1904 === '1' || workbookProperties.date1904 === 'true',
    workbookProtection: /<workbookProtection\b/.test(workbookXml),
    definedNames: [...workbookXml.matchAll(/<definedName\b([^>]*)>([\s\S]*?)<\/definedName>/g)].map((match) => ({
      name: parseAttributes(match[1]).name,
      value: decodeXml(match[2]),
    })),
    calculation: parseAttributes((workbookXml.match(/<calcPr\b([^>]*?)\/?>/) || [])[1] || ''),
    sharedStringCount: sharedStrings.length,
    documentProperties: readDocumentProperties(zip),
    parts: zip.names,
    sheets,
  };
}

/**
 * Turns a sheet into header + records. The header comes from the sheet's Excel table
 * when there is one (the AppSheet export defines one per data sheet), else from row 1.
 * Record values are plain JS values: dates become ISO strings (YYYY-MM-DD).
 */
function sheetToTable(sheet, workbook) {
  const table = sheet.tables[0] || null;
  const headerRowNumber = table ? Number(table.ref.match(/\d+/)[0]) : sheet.rows[0]?.number ?? 1;
  const headerRow = sheet.rows.find((row) => row.number === headerRowNumber);
  const headerCells = new Map((headerRow?.cells || []).map((cell) => [cell.column, cell]));
  const columnCount = Math.max(table ? table.columns.length : 0, ...[...headerCells.keys(), 0]);

  const header = [];
  for (let column = 1; column <= columnCount; column++) {
    const fromTable = table ? table.columns[column - 1] : null;
    const fromCell = headerCells.get(column);
    const name = cleanText(fromTable ?? fromCell?.value) || `(kolom ${columnLetters(column)})`;
    header.push({ column, letter: columnLetters(column), name, cellText: fromCell ? fromCell.value : null });
  }

  const records = [];
  let blankRows = 0;
  for (const row of sheet.rows) {
    if (row.number <= headerRowNumber) continue;
    const cells = new Map(row.cells.filter((cell) => cell.type !== 'empty').map((cell) => [cell.column, cell]));
    if (cells.size === 0) {
      blankRows += 1;
      continue;
    }
    const record = { _row: row.number, _cells: {} };
    for (const { column, name } of header) {
      const cell = cells.get(column) || null;
      record._cells[name] = cell;
      if (!cell) record[name] = null;
      else if (cell.type === 'date') record[name] = excelSerialToIsoDate(cell.value, workbook.date1904);
      else record[name] = cell.value;
    }
    const outside = [...cells.keys()].filter((column) => column > columnCount);
    if (outside.length) record._outsideHeader = outside.map(columnLetters);
    records.push(record);
  }

  return { name: sheet.name, table, headerRowNumber, header, records, blankRows };
}

module.exports = { readWorkbook, sheetToTable, columnLetters, sha256 };
