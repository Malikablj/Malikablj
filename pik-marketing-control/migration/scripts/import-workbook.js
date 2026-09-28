/**
 * npm run migrate:dry   -> node migration/scripts/import-workbook.js --dry-run
 * npm run migrate       -> node migration/scripts/import-workbook.js --apply
 *
 * Options: --file <xlsx>  --mapping <js>  --no-backup  --allow-provisional (never for production)
 *
 * --apply refuses to run when the mapping has errors or is still PROVISIONAL, and takes a
 * pg_dump backup to migration/backups/ first (rollback = restore that file).
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { environment, loadMapping, parseArgs, projectRoot, requireWorkbook, workbookPathFor } from './lib/cli.js';
import { runMigration } from './lib/engine.js';
import { formatSummary, writeReports } from './lib/report.js';

const args = parseArgs(process.argv.slice(2));
const mode = args.apply ? 'apply' : args['dry-run'] ? 'dry-run' : null;
if (!mode) {
  console.error('Pilih mode: --dry-run (tidak menyimpan apa pun) atau --apply.');
  process.exit(1);
}

const { databaseUrl, timeZone } = environment();
if (!databaseUrl) {
  console.error('DATABASE_URL belum diisi (.env).');
  process.exit(1);
}
const { mapping } = await loadMapping(args.mapping);
const workbookPath = workbookPathFor(args, mapping);
requireWorkbook(workbookPath);

if (mode === 'apply' && args['allow-provisional']) {
  console.warn('PERINGATAN: --allow-provisional dipakai. Jangan gunakan untuk data produksi.');
}

if (mode === 'apply' && !args['no-backup']) {
  const backupDir = path.join(projectRoot, 'migration/backups');
  fs.mkdirSync(backupDir, { recursive: true });
  const backupFile = path.join(backupDir, `pre-migration-${new Date().toISOString().replace(/[:.]/g, '-')}.dump`);
  const dump = spawnSync('pg_dump', ['--format=custom', `--file=${backupFile}`, databaseUrl], { encoding: 'utf8' });
  if (dump.error || dump.status !== 0) {
    console.error(
      `Backup gagal, migrasi dibatalkan: ${dump.error?.message ?? dump.stderr}\n` +
        'Pastikan pg_dump (versi sama dengan server) tersedia, atau jalankan dengan --no-backup bila backup sudah dibuat manual.',
    );
    process.exit(1);
  }
  console.log(`Backup database: ${path.relative(projectRoot, backupFile)} (pulihkan dengan pg_restore bila perlu).`);
}

try {
  const { summary, issues } = await runMigration({
    workbookPath,
    mapping,
    mode,
    databaseUrl,
    timeZone,
    allowProvisional: Boolean(args['allow-provisional']),
  });
  const { summaryPath, issuesPath } = writeReports(path.join(projectRoot, 'migration/reports'), summary, issues);
  console.log(formatSummary(summary));
  console.log(`\nReports: ${path.relative(projectRoot, summaryPath)}, ${path.relative(projectRoot, issuesPath)}`);
  if (summary.result === 'BLOCKED' || summary.result === 'DRY_RUN_WITH_MAPPING_ERRORS') process.exitCode = 1;
} catch (error) {
  console.error(`Migrasi gagal dan seluruh perubahan dibatalkan (rollback): ${error.message}`);
  process.exitCode = 1;
}
