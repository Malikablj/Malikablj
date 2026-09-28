/**
 * Workbook -> PostgreSQL import engine.
 *
 *   dry-run  everything runs inside one transaction that is ROLLED BACK at the end, so every
 *            constraint, foreign key and trigger is exercised but nothing is persisted.
 *   apply    same code path, COMMITTED as one transaction (all or nothing), recorded in
 *            migration_runs, issues upserted into migration_issues.
 *
 * Per record: parse fields -> legacy key -> duplicate check -> resolve references ->
 * entity rules -> upsert keyed on legacy_key. Each record runs inside a SAVEPOINT, so an
 * unexpected constraint violation becomes an ERROR issue instead of aborting the migration.
 * Rows are never dropped silently: every row is imported or reported.
 */
import { ENTITIES } from './entities.js';
import { fieldSpec, validateMapping } from './gate.js';
import { IssueCollector } from './issues.js';
import { contentHash, declaredKey, fallbackKey } from './keys.js';
import { createResolver } from './resolver.js';
import {
  parseBoolean,
  parseDate,
  parseDateTime,
  parseEnum,
  parseInteger,
  parseNumber,
  parseText,
  parseTime,
} from './values.js';
import { readWorkbook, toSourceData } from './workbook.js';
import pg from './pg.js';

function parseField(raw, definition, spec, formats, timeZone) {
  switch (definition.type) {
    case 'text':
      return parseText(raw);
    case 'number':
      return parseNumber(raw, { format: spec.numberFormat ?? formats.number });
    case 'integer':
      return parseInteger(raw, { format: spec.numberFormat ?? formats.number });
    case 'date':
      return parseDate(raw, { format: spec.dateFormat ?? formats.date });
    case 'datetime':
      return parseDateTime(raw, { format: spec.dateFormat ?? formats.date, timeZone });
    case 'time':
      return parseTime(raw);
    case 'boolean':
      return parseBoolean(raw);
    case 'enum':
      return parseEnum(raw, { values: definition.values, valueMap: spec.valueMap });
    default:
      throw new Error(`Unknown field type ${definition.type}`);
  }
}

/** Stock rows can carry one quantity column per stock type; each becomes its own record. */
function expandRow(entity, config, row) {
  if (entity.expand !== 'stockTypes' || !config.quantityColumns) return [{ suffix: null, raw: {} }];
  const variants = [];
  for (const [stockType, column] of Object.entries(config.quantityColumns)) {
    const raw = row.values[column];
    if (raw === null || raw === undefined || (typeof raw === 'string' && raw.trim() === '')) continue;
    variants.push({ suffix: stockType, raw: { stock_type: stockType, quantity: raw } });
  }
  return variants;
}

async function upsert(client, entity, record, lineage) {
  const columns = Object.keys(record.values).filter((column) => !entity.fields[column]?.virtual);
  const all = [...columns, 'source_file', 'source_sheet', 'legacy_row', 'source_data'];
  const params = [
    ...columns.map((column) => record.values[column]),
    lineage.sourceFile,
    lineage.sheet,
    lineage.row,
    JSON.stringify(lineage.sourceData),
  ];
  await client.query('SAVEPOINT migration_record');
  try {
    const existing = await client.query(
      `SELECT id, updated_at > migrated_at AS modified_in_app FROM ${entity.table} WHERE legacy_key = $1`,
      [record.legacyKey],
    );
    let outcome;
    if (!existing.rowCount) {
      const placeholders = [...params, record.legacyKey].map((_, i) => `$${i + 1}`).join(', ');
      await client.query(
        `INSERT INTO ${entity.table} (${all.join(', ')}, legacy_key, migrated_at) VALUES (${placeholders}, now())`,
        [...params, record.legacyKey],
      );
      outcome = 'inserted';
    } else if (existing.rows[0].modified_in_app) {
      outcome = 'skipped_modified_in_app';
    } else {
      const assignments = all.map((column, i) => `${column} = $${i + 1}`).join(', ');
      const compare = `(${all.join(', ')}) IS DISTINCT FROM (${all.map((_, i) => `$${i + 1}`).join(', ')})`;
      const result = await client.query(
        `UPDATE ${entity.table} SET ${assignments}, migrated_at = now() WHERE legacy_key = $${all.length + 1} AND ${compare}`,
        [...params, record.legacyKey],
      );
      outcome = result.rowCount ? 'updated' : 'unchanged';
    }
    await client.query('RELEASE SAVEPOINT migration_record');
    return { outcome };
  } catch (error) {
    await client.query('ROLLBACK TO SAVEPOINT migration_record');
    return { outcome: 'not_imported', error };
  }
}

async function importEntity({ entity, config, sheet, mapping, client, resolver, issues, timeZone, sourceFile, processed }) {
  const stats = {
    entity: entity.name,
    sheet: sheet.name,
    status: 'RUN',
    key_strategy: config.key?.length ? 'declared' : 'row+content',
    source_rows: 0,
    empty_rows: 0,
    records: 0,
    inserted: 0,
    updated: 0,
    unchanged: 0,
    skipped_modified_in_app: 0,
    not_imported: 0,
    merged_duplicates: 0,
    duplicate_candidates: 0,
    unresolved_relationships: 0,
    warnings: 0,
    errors: 0,
  };
  const formats = {
    date: config.dateFormat ?? mapping.formats?.date ?? null,
    number: config.numberFormat ?? mapping.formats?.number ?? null,
  };
  const seenKeys = new Map();
  const seenContent = new Map();

  for (const row of sheet.rows) {
    if (row.empty) {
      stats.empty_rows += 1;
      continue;
    }
    stats.source_rows += 1;
    const sourceData = toSourceData(row.values);

    for (const variant of expandRow(entity, config, row)) {
      stats.records += 1;
      const record = { values: {}, resolved: {}, fatal: null, legacyKey: null, reportedRefs: new Set(), productTried: null };
      const report = (severity, type, description, candidate) =>
        issues.add({
          entity: entity.name,
          sheet: sheet.name,
          row: row.rowNumber,
          legacyKey: record.legacyKey,
          severity,
          type,
          description,
          candidate,
          sourceData,
        });

      // 1. Fields ---------------------------------------------------------------
      const fieldProblems = [];
      for (const [field, definition] of Object.entries(entity.fields)) {
        const mapped = config.fields?.[field];
        const fromVariant = Object.hasOwn(variant.raw, field);
        const spec = mapped ? fieldSpec(mapped) : {};
        const fallback = spec.default !== undefined ? spec.default : definition.default;
        if (!mapped && !fromVariant) {
          if (fallback !== undefined && !definition.virtual) record.values[field] = fallback;
          continue;
        }
        const raw = fromVariant ? variant.raw[field] : row.values[spec.column];
        let { value, problem } = parseField(raw, definition, spec, formats, timeZone);

        if (problem) {
          if (definition.required) {
            record.fatal = { type: problem.type, message: `${field}: ${problem.message}` };
          } else {
            fieldProblems.push(['WARNING', problem.type, `${field}: ${problem.message}${fallback != null ? ` Dipakai nilai default "${fallback}".` : ' Dikosongkan.'}`]);
          }
          value = null;
        }
        if (value === null && fallback !== undefined) value = fallback;
        if (value !== null && definition.type === 'text') {
          if (definition.lowercase) value = value.toLowerCase();
          if (definition.phone && typeof raw === 'number') {
            fieldProblems.push(['WARNING', 'NUMBER_AS_TEXT', `${field}: nomor tersimpan sebagai angka (${raw}); angka 0 di depan mungkin hilang.`]);
          }
          if (definition.max && value.length > definition.max) {
            fieldProblems.push(['WARNING', 'VALUE_TRUNCATED', `${field}: lebih dari ${definition.max} karakter, dipotong. Nilai asli ada di source_data.`]);
            value = value.slice(0, definition.max);
          }
        }
        if (value !== null && definition.min !== undefined && value < definition.min) {
          if (definition.required) {
            record.fatal = { type: 'INVALID_VALUE', message: `${field}: nilai ${value} kurang dari ${definition.min}.` };
          } else {
            fieldProblems.push(['WARNING', 'INVALID_VALUE', `${field}: nilai ${value} kurang dari ${definition.min}; dikosongkan.`]);
          }
          value = null;
        }
        if (definition.required && value === null && !record.fatal) {
          record.fatal = { type: 'MISSING_REQUIRED', message: `${field} wajib diisi tetapi kosong.` };
        }
        record.values[field] = value;
      }

      // 2. Legacy key -------------------------------------------------------------
      const declared = declaredKey(entity.name, config.key, row.values);
      record.legacyKey = declared
        ? `${declared}${variant.suffix ? `|${variant.suffix}` : ''}`
        : fallbackKey(entity.name, sheet.name, row.rowNumber, { ...record.values, variant: variant.suffix });
      for (const [severity, type, description] of fieldProblems) report(severity, type, description);
      if (config.key?.length && !declared) {
        report('INFO', 'NO_KEY_VALUE', 'Kolom key kosong; baris dikenali dari nomor baris + isinya.');
      }

      // 3. Duplicates (rows already rejected are not compared: their parsed values are incomplete)
      const content = contentHash(record.values);
      if (record.fatal) {
        // reported in step 5
      } else if (declared) {
        const previous = seenKeys.get(record.legacyKey);
        if (previous) {
          if (previous.content === content) {
            stats.merged_duplicates += 1;
            if (!config.allowRepeatedKeys) report('INFO', 'DUPLICATE_ROW', `Baris identik dengan baris ${previous.row}; diimpor sekali.`, `row ${previous.row}`);
          } else {
            stats.duplicate_candidates += 1;
            stats.not_imported += 1;
            report(
              'ERROR',
              'DUPLICATE_KEY_CONFLICT',
              `Key sama dengan baris ${previous.row} tetapi isinya berbeda; baris ini TIDAK diimpor. Periksa mana yang benar.`,
              `row ${previous.row}`,
            );
            processed.push([entity.name, record.legacyKey, row.rowNumber, 'not_imported']);
          }
          continue;
        }
        seenKeys.set(record.legacyKey, { row: row.rowNumber, content });
      } else {
        const previousRow = seenContent.get(content);
        if (previousRow) {
          stats.duplicate_candidates += 1;
          report('INFO', 'POSSIBLE_DUPLICATE', `Isi baris sama dengan baris ${previousRow}; keduanya diimpor. Periksa apakah ini duplikat.`, `row ${previousRow}`);
        } else {
          seenContent.set(content, row.rowNumber);
        }
      }

      // 4. References -------------------------------------------------------------
      if (!record.fatal) {
        for (const [refField, ref] of Object.entries(entity.refs)) {
          const strategies = config.refs?.[refField];
          if (!strategies) continue;
          const result = await resolver.resolve(ref, strategies, record, row.values);
          const label = `${refField} (${ref.target})`;
          if (result.match) {
            record.values[refField] = result.match.id;
            record.resolved[refField] = result.match;
            if (result.notFound.length) {
              const missed = result.notFound.map((item) => `${item.via} "${item.value}"`).join(', ');
              report('WARNING', 'REFERENCE_FALLBACK', `${label}: ${missed} tidak ditemukan; dicocokkan lewat ${result.via} "${result.value}".`, result.match.label);
            }
            continue;
          }
          record.values[refField] = null;
          if (result.ambiguous) {
            stats.unresolved_relationships += 1;
            const candidates = result.ambiguous.map((candidate) => `${candidate.label} (${candidate.id})`).join('; ');
            const message = `${label}: ${result.via} "${result.value}" cocok dengan ${result.ambiguous.length} data; tidak dipilih otomatis.`;
            if (ref.required) {
              record.fatal = { type: 'AMBIGUOUS_REFERENCE', message, candidate: candidates };
            } else {
              report('WARNING', 'AMBIGUOUS_REFERENCE', `${message} ${ref.fallbackToItemName ? 'Disimpan sebagai teks.' : 'Dikosongkan.'}`, candidates);
              record.reportedRefs.add(refField);
            }
          } else if (!result.empty) {
            stats.unresolved_relationships += 1;
            const tried = result.notFound.map((item) => `${item.via} "${item.value}"`).join(', ');
            if (ref.required) {
              record.fatal = { type: 'UNRESOLVED_REFERENCE', message: `${label}: ${tried} tidak ditemukan.` };
            } else if (ref.fallbackToItemName) {
              record.productTried = tried; // reported once as UNRESOLVED_PRODUCT below
            } else {
              report(ref.severity ?? 'WARNING', 'UNRESOLVED_REFERENCE', `${label}: ${tried} tidak ditemukan${ref.unallocatedIsInfo ? '' : '; dikosongkan'}.`);
            }
          } else if (ref.required) {
            record.fatal = { type: 'MISSING_REQUIRED', message: `${label} kosong.` };
          }
          if (ref.fallbackToItemName && !record.values[refField] && !record.values.item_name) {
            const text = strategies.map((strategy) => parseText(row.values[strategy.column]).value).find((value) => value !== null);
            if (text) record.values.item_name = text.slice(0, 255);
          }
          if (record.fatal) break;
        }
      }

      // 5. Entity rules -----------------------------------------------------------
      if (!record.fatal && entity.after) {
        await entity.after(record, {
          timeZone,
          query: (text, params) => client.query(text, params),
          issue: (_record, severity, type, description, candidate) => report(severity, type, description, candidate),
        });
      }
      if (!record.fatal && 'item_name' in entity.fields && entity.refs.product_id && !record.values.product_id) {
        if (record.values.item_name) {
          if (!record.reportedRefs.has('product_id')) {
            const tried = record.productTried ? ` (${record.productTried} tidak ditemukan)` : '';
            report('WARNING', 'UNRESOLVED_PRODUCT', `Produk tidak cocok dengan master produk${tried}; disimpan sebagai teks "${record.values.item_name}".`);
          }
        } else if (entity.name !== 'leadtime' && entity.name !== 'inbound_maklon') {
          record.fatal = { type: 'MISSING_REQUIRED', message: 'Produk kosong (tidak ada kode maupun nama).' };
        }
      }
      if (record.fatal) {
        stats.not_imported += 1;
        report('ERROR', record.fatal.type, `${record.fatal.message} Baris TIDAK diimpor.`, record.fatal.candidate);
        processed.push([entity.name, record.legacyKey, row.rowNumber, 'not_imported']);
        continue;
      }

      // 6. Upsert -----------------------------------------------------------------
      const { outcome, error } = await upsert(client, entity, record, {
        sourceFile,
        sheet: sheet.name,
        row: row.rowNumber,
        sourceData,
      });
      stats[outcome] += 1;
      processed.push([entity.name, record.legacyKey, row.rowNumber, outcome]);
      if (outcome === 'skipped_modified_in_app') {
        report('INFO', 'MODIFIED_IN_APP', 'Data ini sudah diubah di aplikasi setelah migrasi sebelumnya; tidak ditimpa.');
      } else if (error) {
        report('ERROR', 'DATABASE_REJECTED', `Database menolak baris: ${error.message}${error.constraint ? ` (${error.constraint})` : ''}. Baris TIDAK diimpor.`);
      }
    }
  }

  const entityIssues = issues.forEntity(entity.name);
  stats.warnings = entityIssues.filter((issue) => issue.severity === 'WARNING').length;
  stats.errors = entityIssues.filter((issue) => issue.severity === 'ERROR').length;
  return stats;
}

async function persistRunRecords(client, runId, processed) {
  for (let start = 0; start < processed.length; start += 5000) {
    const chunk = processed.slice(start, start + 5000);
    await client.query(
      `INSERT INTO migration_run_records (run_id, entity, legacy_key, legacy_row, outcome)
       SELECT $1, * FROM unnest($2::text[], $3::text[], $4::int[], $5::text[])`,
      [runId, chunk.map((r) => r[0]), chunk.map((r) => r[1]), chunk.map((r) => r[2]), chunk.map((r) => r[3])],
    );
  }
}

async function persistIssues(client, issues, runId) {
  for (const issue of issues) {
    await client.query(
      `INSERT INTO migration_issues (fingerprint, entity_type, source_file, source_sheet, legacy_row, issue_type, severity,
                                     description, candidate_reference, source_data, first_seen_run_id, last_seen_run_id)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $11)
       ON CONFLICT (fingerprint) DO UPDATE SET
         last_seen_run_id = EXCLUDED.last_seen_run_id,
         legacy_row = EXCLUDED.legacy_row,
         severity = EXCLUDED.severity,
         candidate_reference = EXCLUDED.candidate_reference,
         source_data = EXCLUDED.source_data,
         -- An issue auto-resolved earlier that reappears is reopened; human decisions are kept.
         resolution_status = CASE WHEN migration_issues.resolution_status = 'RESOLVED' AND migration_issues.resolved_by IS NULL
                                  THEN 'OPEN' ELSE migration_issues.resolution_status END,
         resolved_at = CASE WHEN migration_issues.resolution_status = 'RESOLVED' AND migration_issues.resolved_by IS NULL
                            THEN NULL ELSE migration_issues.resolved_at END`,
      [
        issue.fingerprint,
        issue.entity_type,
        issue.source_file,
        issue.source_sheet,
        issue.legacy_row,
        issue.issue_type,
        issue.severity,
        issue.description,
        issue.candidate_reference,
        issue.source_data ? JSON.stringify(issue.source_data) : null,
        runId,
      ],
    );
  }
  const autoResolved = await client.query(
    `UPDATE migration_issues
     SET resolution_status = 'RESOLVED', resolved_at = now(),
         resolution_notes = 'Otomatis: tidak muncul lagi pada migrasi berikutnya.'
     WHERE resolution_status = 'OPEN' AND last_seen_run_id IS DISTINCT FROM $1`,
    [runId],
  );
  return autoResolved.rowCount;
}

/**
 * @param {{ workbookPath: string, mapping: object, mode: 'dry-run'|'apply', databaseUrl: string,
 *           timeZone?: string, allowProvisional?: boolean }} options
 */
export async function runMigration({ workbookPath, mapping, mode, databaseUrl, timeZone = 'Asia/Jakarta', allowProvisional = false }) {
  if (!['dry-run', 'apply'].includes(mode)) throw new Error(`Unknown mode ${mode}`);
  const startedAt = new Date();
  const headerRows = {};
  for (const config of Object.values(mapping.entities ?? {})) {
    if (config.headerRow) headerRows[config.sheet] = config.headerRow;
  }
  const workbook = await readWorkbook(workbookPath, { headerRows });
  const gate = validateMapping(workbook, mapping);
  const issues = new IssueCollector(workbook.fileName);
  const summary = {
    mode,
    result: null,
    started_at: startedAt.toISOString(),
    finished_at: null,
    source_file: workbook.fileName,
    source_checksum: workbook.checksum,
    mapping: { version: mapping.version, status: mapping.status },
    gate: { ok: gate.ok, errors: gate.errors, warnings: gate.warnings, unmapped_columns: gate.unmappedColumns },
    sheets: workbook.sheets.map((sheet) => ({ name: sheet.name, data_rows: sheet.rows.filter((row) => !row.empty).length })),
    entities: [],
    issues: { total: 0, by_severity: {}, by_type: {} },
    run_id: null,
    auto_resolved_issues: 0,
  };

  if (mode === 'apply') {
    const blocked = [];
    if (!gate.ok) blocked.push('mapping belum lolos validasi (lihat gate.errors)');
    if (mapping.status !== 'VERIFIED' && !allowProvisional) {
      blocked.push('mapping masih PROVISIONAL: verifikasi terhadap workbook asli (npm run migrate:profile) lalu set status VERIFIED');
    }
    if (blocked.length) {
      summary.result = 'BLOCKED';
      summary.blocked_reasons = blocked;
      summary.finished_at = new Date().toISOString();
      return { summary, issues: [] };
    }
  }

  const client = new pg.Client({ connectionString: databaseUrl });
  await client.connect();
  try {
    await client.query('BEGIN');
    let runId = null;
    if (mode === 'apply') {
      const run = await client.query(
        `INSERT INTO migration_runs (source_file, source_checksum, mapping_version) VALUES ($1, $2, $3) RETURNING id`,
        [workbook.fileName, workbook.checksum, mapping.version ?? null],
      );
      runId = run.rows[0].id;
    }
    const resolver = createResolver((text, params) => client.query(text, params));
    const processed = [];

    for (const entity of ENTITIES) {
      const config = mapping.entities?.[entity.name];
      if (!config) continue;
      if (gate.entityErrors[entity.name]) {
        summary.entities.push({ entity: entity.name, sheet: config.sheet, status: 'NOT_RUN', reason: 'Mapping error (lihat gate.errors)' });
        continue;
      }
      const sheet = workbook.sheets.find((candidate) => candidate.name === config.sheet);
      summary.entities.push(
        await importEntity({ entity, config, sheet, mapping, client, resolver, issues, timeZone, sourceFile: workbook.fileName, processed }),
      );
    }

    if (mode === 'apply') {
      await persistRunRecords(client, runId, processed);
      summary.auto_resolved_issues = await persistIssues(client, issues.issues, runId);
      summary.run_id = runId;
      await client.query(`UPDATE migration_runs SET finished_at = now(), summary = $2 WHERE id = $1`, [
        runId,
        JSON.stringify({ entities: summary.entities, issues: issues.countBy('severity') }),
      ]);
      await client.query('COMMIT');
    } else {
      await client.query('ROLLBACK');
    }
  } catch (error) {
    await client.query('ROLLBACK').catch(() => {});
    throw error;
  } finally {
    await client.end();
  }

  summary.issues = { total: issues.issues.length, by_severity: issues.countBy('severity'), by_type: issues.countBy('issue_type') };
  summary.finished_at = new Date().toISOString();
  summary.result = mode === 'apply' ? 'APPLIED' : gate.ok ? 'DRY_RUN_COMPLETED' : 'DRY_RUN_WITH_MAPPING_ERRORS';
  return { summary, issues: issues.issues };
}
