/** Shared helpers for the migration command-line scripts. */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { loadEnv, projectRoot } from '../../../database/scripts/lib/database.js';

export { projectRoot };

export function parseArgs(argv) {
  const args = { _: [] };
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (!arg.startsWith('--')) {
      args._.push(arg);
      continue;
    }
    const [key, inline] = arg.slice(2).split('=');
    if (inline !== undefined) args[key] = inline;
    else if (argv[i + 1] && !argv[i + 1].startsWith('--')) args[key] = argv[(i += 1)];
    else args[key] = true;
  }
  return args;
}

export async function loadMapping(mappingPath) {
  const resolved = path.resolve(projectRoot, mappingPath ?? 'migration/mapping/workbook.mapping.js');
  if (!fs.existsSync(resolved)) throw new Error(`Mapping tidak ditemukan: ${resolved}`);
  const module = await import(pathToFileURL(resolved).href);
  return { mapping: module.default, mappingPath: resolved };
}

export function workbookPathFor(args, mapping) {
  return path.resolve(projectRoot, args.file ?? path.join('migration/source', mapping.sourceFile));
}

export function requireWorkbook(workbookPath) {
  if (fs.existsSync(workbookPath)) return;
  console.error(
    [
      `Workbook sumber tidak ditemukan: ${path.relative(projectRoot, workbookPath)}`,
      '',
      'Salin workbook ke migration/source/ (folder ini tidak masuk git karena berisi data bisnis),',
      'atau tunjuk file lain dengan --file <path>.',
    ].join('\n'),
  );
  process.exit(1);
}

export function environment() {
  loadEnv();
  return {
    databaseUrl: process.env.DATABASE_URL,
    timeZone: process.env.APP_TIMEZONE || 'Asia/Jakarta',
  };
}
