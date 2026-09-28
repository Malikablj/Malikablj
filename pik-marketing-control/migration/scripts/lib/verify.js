/**
 * Post-migration verification (npm run migrate:verify). Independently checks the database
 * against what the latest applied run recorded:
 *   - row counts: every record the run imported exists; every source record is accounted for
 *   - rows not imported are all explained by an ERROR issue (nothing dropped silently)
 *   - lineage is complete on every migrated row
 *   - records no longer present in the source ("stale") are reported
 *   - relationship quality (unmatched products, unallocated deliveries, missing owners)
 *   - business calculations reconcile with legacy values when the mapping names them
 */
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import pg from 'pg';
import { ENTITIES } from './entities.js';
import { parseNumber } from './values.js';

const IMPORTED = ['inserted', 'updated', 'unchanged', 'skipped_modified_in_app'];

const RECONCILE_SOURCES = {
  po_lines: {
    sql: `SELECT l.legacy_row, l.source_data, v.delivered_quantity, v.returned_quantity, v.outstanding_quantity, v.order_quantity
          FROM po_lines l JOIN v_po_line_fulfillment v ON v.po_line_id = l.id WHERE l.legacy_key IS NOT NULL`,
  },
  purchase_orders: {
    sql: `SELECT p.legacy_row, p.source_data, s.ordered_quantity, s.delivered_quantity, s.returned_quantity,
                 s.outstanding_quantity, s.total_value
          FROM purchase_orders p JOIN v_purchase_order_summary s ON s.purchase_order_id = p.id WHERE p.legacy_key IS NOT NULL`,
  },
  po_financials: {
    sql: `SELECT f.legacy_row, f.source_data, s.total_value AS computed_po_value
          FROM po_financials f JOIN v_purchase_order_summary s ON s.purchase_order_id = f.purchase_order_id
          WHERE f.legacy_key IS NOT NULL`,
  },
};

export async function verifyMigration({ databaseUrl, mapping, workbookPath }) {
  const checks = [];
  const add = (status, name, detail) => checks.push({ status, name, detail });
  const client = new pg.Client({ connectionString: databaseUrl });
  await client.connect();
  try {
    const runs = await client.query(`SELECT * FROM migration_runs WHERE finished_at IS NOT NULL ORDER BY started_at DESC LIMIT 1`);
    if (!runs.rowCount) {
      add('FAIL', 'Migration run', 'Belum ada migrasi yang diterapkan (npm run migrate).');
      return { status: 'FAIL', checks };
    }
    const run = runs.rows[0];
    add('PASS', 'Migration run', `Run ${run.id} (${run.source_file}) selesai ${run.finished_at.toISOString()}.`);

    if (workbookPath && fs.existsSync(workbookPath)) {
      const checksum = createHash('sha256').update(fs.readFileSync(workbookPath)).digest('hex');
      if (checksum === run.source_checksum) add('PASS', 'Source workbook', 'Workbook sama dengan yang dimigrasikan.');
      else add('WARN', 'Source workbook', 'Workbook berubah sejak migrasi terakhir; jalankan migrate:dry lalu migrate.');
    }

    const runEntities = Object.fromEntries((run.summary?.entities ?? []).map((entity) => [entity.entity, entity]));
    for (const entity of ENTITIES) {
      const stats = runEntities[entity.name];
      if (!stats || stats.status === 'NOT_RUN') continue;
      const table = entity.table;
      const recorded = await client.query(
        `SELECT count(*) FILTER (WHERE outcome = ANY($3)) AS imported,
                count(*) FILTER (WHERE outcome = 'not_imported') AS not_imported,
                count(*) AS total
         FROM migration_run_records WHERE run_id = $1 AND entity = $2`,
        [run.id, entity.name, IMPORTED],
      );
      const { imported, not_imported: notImported, total } = recorded.rows[0];

      const present = await client.query(
        `SELECT count(*) AS n FROM ${table} t
         JOIN migration_run_records r ON r.legacy_key = t.legacy_key
         WHERE r.run_id = $1 AND r.entity = $2 AND r.outcome = ANY($3)`,
        [run.id, entity.name, IMPORTED],
      );
      if (present.rows[0].n === imported) {
        add('PASS', `${entity.name}: row count`, `${stats.records} baris sumber: ${imported} ada di database, ${notImported} tidak diimpor, ${stats.merged_duplicates} digabung (duplikat identik).`);
      } else {
        add('FAIL', `${entity.name}: row count`, `Run mencatat ${imported} data diimpor tetapi hanya ${present.rows[0].n} yang ada di database.`);
      }
      if (total + stats.merged_duplicates !== stats.records) {
        add('FAIL', `${entity.name}: accounting`, `${stats.records} baris sumber tetapi ${total + stats.merged_duplicates} tercatat.`);
      }

      const unexplained = await client.query(
        `SELECT count(*) AS n FROM migration_run_records r
         WHERE r.run_id = $1 AND r.entity = $2 AND r.outcome = 'not_imported'
           AND NOT EXISTS (SELECT 1 FROM migration_issues i
                           WHERE i.entity_type = r.entity AND i.legacy_row = r.legacy_row AND i.severity = 'ERROR')`,
        [run.id, entity.name],
      );
      if (unexplained.rows[0].n > 0) {
        add('FAIL', `${entity.name}: dropped rows`, `${unexplained.rows[0].n} baris tidak diimpor tanpa issue ERROR.`);
      }

      const lineage = await client.query(
        `SELECT count(*) AS n FROM ${table}
         WHERE legacy_key IS NOT NULL
           AND (source_file IS NULL OR source_sheet IS NULL OR legacy_row IS NULL OR source_data IS NULL OR migrated_at IS NULL)`,
      );
      if (lineage.rows[0].n > 0) add('FAIL', `${entity.name}: lineage`, `${lineage.rows[0].n} data migrasi tanpa lineage lengkap.`);

      const stale = await client.query(
        `SELECT count(*) AS n FROM ${table} t
         WHERE t.legacy_key IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM migration_run_records r
                           WHERE r.run_id = $1 AND r.entity = $2 AND r.legacy_key = t.legacy_key)`,
        [run.id, entity.name],
      );
      if (stale.rows[0].n > 0) {
        add('WARN', `${entity.name}: stale records`, `${stale.rows[0].n} data dari migrasi sebelumnya tidak ada lagi di workbook terakhir. Tinjau lalu arsipkan/batalkan bila perlu.`);
      }
    }

    // Relationship quality ------------------------------------------------------
    const quality = [
      ['PO lines without matched product', `SELECT count(*) AS n FROM po_lines WHERE legacy_key IS NOT NULL AND product_id IS NULL`],
      ['Deliveries not allocated to a PO line', `SELECT count(*) AS n FROM deliveries WHERE legacy_key IS NOT NULL AND po_line_id IS NULL`],
      ['Returns with PO but without PO line', `SELECT count(*) AS n FROM returns WHERE legacy_key IS NOT NULL AND purchase_order_id IS NOT NULL AND po_line_id IS NULL`],
      ['Stock rows without matched product', `SELECT count(*) AS n FROM stock WHERE legacy_key IS NOT NULL AND product_id IS NULL`],
      ['Leads without owner', `SELECT count(*) AS n FROM leads WHERE legacy_key IS NOT NULL AND owner_user_id IS NULL`],
      ['Purchase orders without owner', `SELECT count(*) AS n FROM purchase_orders WHERE legacy_key IS NOT NULL AND owner_user_id IS NULL`],
    ];
    for (const [name, sql] of quality) {
      const { rows } = await client.query(sql);
      add(rows[0].n > 0 ? 'WARN' : 'PASS', name, `${rows[0].n}`);
    }

    // Business calculations -----------------------------------------------------
    for (const [entityName, fields] of Object.entries(mapping?.reconcile ?? {})) {
      const source = RECONCILE_SOURCES[entityName];
      if (!source) {
        add('WARN', `Reconcile ${entityName}`, 'Entity ini tidak didukung untuk rekonsiliasi.');
        continue;
      }
      const { rows } = await client.query(source.sql);
      for (const [computedField, sourceColumn] of Object.entries(fields)) {
        let compared = 0;
        const mismatches = [];
        for (const row of rows) {
          const raw = row.source_data?.[sourceColumn];
          if (raw === undefined || raw === null || raw === '') continue;
          const legacy = parseNumber(raw, { format: mapping.formats?.number ?? null }).value;
          if (legacy === null) continue;
          compared += 1;
          const computed = Number(row[computedField] ?? 0);
          if (Math.abs(legacy - computed) > 0.001) mismatches.push(`baris ${row.legacy_row}: sumber ${legacy}, dihitung ${computed}`);
        }
        const name = `Reconcile ${entityName}.${computedField} vs "${sourceColumn}"`;
        if (!compared) add('WARN', name, 'Tidak ada nilai sumber untuk dibandingkan.');
        else if (mismatches.length) add('WARN', name, `${mismatches.length}/${compared} berbeda. Contoh: ${mismatches.slice(0, 5).join('; ')}`);
        else add('PASS', name, `${compared} nilai cocok.`);
      }
    }

    const open = await client.query(
      `SELECT severity, count(*) AS n FROM migration_issues WHERE resolution_status = 'OPEN' GROUP BY severity`,
    );
    const bySeverity = Object.fromEntries(open.rows.map((row) => [row.severity, row.n]));
    add(
      bySeverity.ERROR ? 'WARN' : 'PASS',
      'Open migration issues',
      `ERROR ${bySeverity.ERROR ?? 0}, WARNING ${bySeverity.WARNING ?? 0}, INFO ${bySeverity.INFO ?? 0} (tinjau di menu Migration Issues).`,
    );
  } finally {
    await client.end();
  }
  const status = checks.some((c) => c.status === 'FAIL') ? 'FAIL' : checks.some((c) => c.status === 'WARN') ? 'WARN' : 'PASS';
  return { status, checks };
}
