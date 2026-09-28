/**
 * Workbook profiler: describes every sheet and column so the mapping can be written from
 * facts instead of assumptions (docs/DATA_PROFILE.md).
 */
import { createHash } from 'node:crypto';
import { normalizeKey } from './values.js';

const DATE_TEXT = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})(\s.*)?$|^\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|^\d{1,2}[\s-]+[A-Za-z]{3,9}\.?[\s-,]+\d{2,4}/;
const NUMBER_TEXT = /^-?(rp\.?\s*)?\d{1,3}([.,]\d{3})+([.,]\d+)?$|^-?\d+,\d+$/i;
const ID_HEADER = /(^|\b|_)(id|key|kode|code|no|nomor|number|row ?id|_rownumber)(\b|_|$)/i;

function displayValue(value) {
  if (value instanceof Date) return value.toISOString().slice(0, 10);
  const text = String(value);
  return text.length > 40 ? `${text.slice(0, 37)}...` : text;
}

function profileColumn(sheet, header, withSamples) {
  const kinds = {};
  const distinct = new Map();
  let filled = 0;
  let maxLength = 0;
  let min = null;
  let max = null;
  let dateMin = null;
  let dateMax = null;
  let textDates = 0;
  let ambiguousTextDates = 0;
  let textNumbers = 0;
  const formulas = new Set();

  for (const row of sheet.rows) {
    if (row.empty) continue;
    const cell = row.kinds[header.name];
    const value = row.values[header.name];
    if (cell.kind !== 'empty') kinds[cell.kind] = (kinds[cell.kind] ?? 0) + 1;
    if (cell.formula) formulas.add(String(cell.formula));
    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) continue;
    filled += 1;
    const key = value instanceof Date ? value.toISOString() : String(value).trim();
    distinct.set(key, (distinct.get(key) ?? 0) + 1);
    if (typeof value === 'number') {
      min = min === null ? value : Math.min(min, value);
      max = max === null ? value : Math.max(max, value);
    } else if (value instanceof Date) {
      const iso = value.toISOString().slice(0, 10);
      dateMin = dateMin === null || iso < dateMin ? iso : dateMin;
      dateMax = dateMax === null || iso > dateMax ? iso : dateMax;
    } else if (typeof value === 'string') {
      const text = value.trim();
      maxLength = Math.max(maxLength, text.length);
      const dateMatch = DATE_TEXT.exec(text);
      if (dateMatch) {
        textDates += 1;
        if (dateMatch[1] && Number(dateMatch[1]) <= 12 && Number(dateMatch[2]) <= 12 && dateMatch[1] !== dateMatch[2]) {
          ambiguousTextDates += 1;
        }
      } else if (NUMBER_TEXT.test(text)) {
        textNumbers += 1;
      }
    }
  }

  const repeated = [...distinct.values()].filter((count) => count > 1).length;
  const nonEmptyRows = sheet.rows.filter((row) => !row.empty).length;
  return {
    name: header.name,
    letter: header.letter,
    kinds,
    filled,
    fill_rate: nonEmptyRows ? filled / nonEmptyRows : 0,
    distinct: distinct.size,
    repeated_values: repeated,
    unique: filled > 0 && distinct.size === filled && !kinds.number && !kinds.date,
    key_candidate: filled > 0 && distinct.size === filled && ID_HEADER.test(header.name),
    samples: withSamples ? [...distinct.keys()].slice(0, 3).map(displayValue) : [],
    number_range: min === null ? null : [min, max],
    date_range: dateMin === null ? null : [dateMin, dateMax],
    max_text_length: maxLength,
    text_dates: textDates,
    ambiguous_text_dates: ambiguousTextDates,
    text_numbers: textNumbers,
    formulas: [...formulas].slice(0, 3),
    errors: kinds.error ?? 0,
    _values: new Set([...distinct.keys()].map((value) => normalizeKey(value)).filter(Boolean)),
  };
}

export function profileWorkbook(workbook, { withSamples = true } = {}) {
  const sheets = workbook.sheets.map((sheet) => {
    const dataRows = sheet.rows.filter((row) => !row.empty);
    const hashes = new Map();
    let duplicateRows = 0;
    for (const row of dataRows) {
      const hash = createHash('sha1').update(JSON.stringify(row.values)).digest('hex');
      if (hashes.has(hash)) duplicateRows += 1;
      else hashes.set(hash, row.rowNumber);
    }
    return {
      name: sheet.name,
      header_row: sheet.headerRow,
      data_rows: dataRows.length,
      empty_rows: sheet.rows.length - dataRows.length,
      duplicate_rows: duplicateRows,
      merges: sheet.merges,
      header_problems: sheet.headerProblems,
      rows_with_unlabelled_cells: sheet.rows.filter((row) => row.orphanCells > 0).length,
      columns: sheet.headers.map((header) => profileColumn(sheet, header, withSamples)),
    };
  });

  // Candidate relationships: most distinct values of a column appear in a unique column of
  // another sheet (e.g. "PO Lines"."PO ID" -> "Purchase Orders"."ID").
  const relationships = [];
  for (const sheet of sheets) {
    for (const column of sheet.columns) {
      if (column._values.size < 2) continue;
      for (const other of sheets) {
        if (other === sheet) continue;
        for (const target of other.columns) {
          // Targets must look like keys: unique and either ID-like by name or with enough values.
          if (!target.unique || !(target.key_candidate || target.distinct >= 10)) continue;
          let hits = 0;
          for (const value of column._values) if (target._values.has(value)) hits += 1;
          const coverage = hits / column._values.size;
          if (coverage >= 0.6) {
            relationships.push({ from: `${sheet.name}.${column.name}`, to: `${other.name}.${target.name}`, coverage });
          }
        }
      }
    }
  }
  relationships.sort((a, b) => b.coverage - a.coverage);
  for (const sheet of sheets) for (const column of sheet.columns) delete column._values;

  return {
    file: workbook.fileName,
    checksum: workbook.checksum,
    generated_at: new Date().toISOString(),
    sheets,
    relationships,
  };
}

function percent(value) {
  return `${Math.round(value * 100)}%`;
}

function escapeCell(value) {
  return String(value ?? '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');
}

export function renderProfileMarkdown(profile, gate, mapping) {
  const lines = [];
  lines.push('# Data Profile: Source Workbook', '');
  lines.push('> Generated by `npm run migrate:profile`. Do not edit by hand; re-run after the workbook changes.', '');
  lines.push('| Item | Value |', '|---|---|');
  lines.push(`| File | \`${profile.file}\` |`);
  lines.push(`| SHA-256 | \`${profile.checksum}\` |`);
  lines.push(`| Generated | ${profile.generated_at} |`);
  lines.push(`| Sheets | ${profile.sheets.length} |`);
  lines.push(`| Mapping | \`${mapping.version}\` (${mapping.status}) |`, '');

  lines.push('## Sheets', '', '| Sheet | Header row | Data rows | Empty rows | Duplicate rows | Merged ranges | Columns |', '|---|---|---|---|---|---|---|');
  for (const sheet of profile.sheets) {
    lines.push(
      `| ${escapeCell(sheet.name)} | ${sheet.header_row ?? '-'} | ${sheet.data_rows} | ${sheet.empty_rows} | ${sheet.duplicate_rows} | ${sheet.merges.length} | ${sheet.columns.length} |`,
    );
  }
  lines.push('');

  for (const sheet of profile.sheets) {
    lines.push(`## Sheet: ${sheet.name}`, '');
    const notes = [];
    if (sheet.header_problems.length) notes.push(...sheet.header_problems);
    if (sheet.merges.length) notes.push(`Merged ranges: ${sheet.merges.slice(0, 10).join(', ')}${sheet.merges.length > 10 ? ', ...' : ''}`);
    if (sheet.rows_with_unlabelled_cells) notes.push(`${sheet.rows_with_unlabelled_cells} rows have values in columns without a header.`);
    if (sheet.duplicate_rows) notes.push(`${sheet.duplicate_rows} rows are exact duplicates of an earlier row.`);
    for (const note of notes) lines.push(`- ${note}`);
    if (notes.length) lines.push('');
    lines.push('| Col | Header | Types | Filled | Distinct | Key? | Range / length | Watch out | Samples |', '|---|---|---|---|---|---|---|---|---|');
    for (const c of sheet.columns) {
      const types = Object.entries(c.kinds).map(([kind, n]) => `${kind} ${n}`).join(', ');
      const range = c.number_range
        ? `${c.number_range[0]} .. ${c.number_range[1]}`
        : c.date_range
          ? `${c.date_range[0]} .. ${c.date_range[1]}`
          : c.max_text_length
            ? `max ${c.max_text_length} chars`
            : '';
      const watch = [];
      if (c.text_dates) watch.push(`${c.text_dates} dates as text${c.ambiguous_text_dates ? ` (${c.ambiguous_text_dates} ambiguous D/M)` : ''}`);
      if (c.text_numbers) watch.push(`${c.text_numbers} numbers as text`);
      if (c.formulas.length) watch.push(`formula: \`${escapeCell(c.formulas[0])}\``);
      if (c.errors) watch.push(`${c.errors} error cells`);
      if (c.repeated_values && c.key_candidate === false && /(^|\b)(id|kode|code|no|nomor)(\b|$)/i.test(c.name)) {
        watch.push(`${c.repeated_values} repeated values`);
      }
      lines.push(
        `| ${c.letter} | ${escapeCell(c.name)} | ${types || '-'} | ${percent(c.fill_rate)} | ${c.distinct} | ${c.key_candidate ? 'yes' : c.unique ? 'unique' : ''} | ${escapeCell(range)} | ${escapeCell(watch.join('; '))} | ${escapeCell(c.samples.join(' · '))} |`,
      );
    }
    lines.push('');
  }

  lines.push('## Candidate relationships', '');
  if (profile.relationships.length) {
    lines.push('Share of a column\'s distinct values found in a unique column of another sheet (>= 60%). Candidates only: confirm before mapping.', '');
    lines.push('| From | To | Coverage |', '|---|---|---|');
    for (const rel of profile.relationships) lines.push(`| ${escapeCell(rel.from)} | ${escapeCell(rel.to)} | ${percent(rel.coverage)} |`);
  } else {
    lines.push('None detected.');
  }
  lines.push('');

  lines.push('## Comparison with the mapping', '');
  lines.push(gate.ok ? 'The mapping is valid for this workbook.' : `**${gate.errors.length} mapping error(s): the import is blocked until fixed.**`, '');
  for (const error of gate.errors) lines.push(`- ERROR: ${error}`);
  for (const warning of gate.warnings) lines.push(`- Warning: ${warning}`);
  const unmapped = Object.entries(gate.unmappedColumns).filter(([, columns]) => columns.length);
  if (unmapped.length) {
    lines.push('', 'Source columns not mapped to a field (kept in `source_data` of each record):', '');
    for (const [entity, columns] of unmapped) lines.push(`- **${entity}**: ${columns.map((c) => `\`${c}\``).join(', ')}`);
  }
  lines.push('');
  return lines.join('\n');
}
