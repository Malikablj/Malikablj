/**
 * npm run migrate:profile [-- --file <xlsx>] [--mapping <js>] [--no-samples] [--out <md>]
 *
 * Profiles the source workbook (read-only) and writes docs/DATA_PROFILE.md plus
 * migration/reports/data-profile.json. Also checks the mapping against the workbook.
 */
import fs from 'node:fs';
import path from 'node:path';
import { loadMapping, parseArgs, projectRoot, requireWorkbook, workbookPathFor } from './lib/cli.js';
import { validateMapping } from './lib/gate.js';
import { profileWorkbook, renderProfileMarkdown } from './lib/profile.js';
import { readWorkbook } from './lib/workbook.js';

const args = parseArgs(process.argv.slice(2));
const { mapping } = await loadMapping(args.mapping);
const workbookPath = workbookPathFor(args, mapping);
requireWorkbook(workbookPath);

const headerRows = {};
for (const config of Object.values(mapping.entities ?? {})) if (config.headerRow) headerRows[config.sheet] = config.headerRow;
const workbook = await readWorkbook(workbookPath, { headerRows });
const profile = profileWorkbook(workbook, { withSamples: !args['no-samples'] });
const gate = validateMapping(workbook, mapping);

const outPath = path.resolve(projectRoot, args.out ?? 'docs/DATA_PROFILE.md');
fs.writeFileSync(outPath, renderProfileMarkdown(profile, gate, mapping));
const jsonPath = path.join(projectRoot, 'migration/reports/data-profile.json');
fs.mkdirSync(path.dirname(jsonPath), { recursive: true });
fs.writeFileSync(jsonPath, `${JSON.stringify(profile, null, 2)}\n`);

console.log(`Profiled ${profile.sheets.length} sheet(s) of ${workbook.fileName}:`);
for (const sheet of profile.sheets) console.log(`  - ${sheet.name}: ${sheet.data_rows} rows, ${sheet.columns.length} columns`);
console.log(`\nMapping check: ${gate.ok ? 'OK' : `${gate.errors.length} error(s)`}`);
for (const error of gate.errors) console.log(`  x ${error}`);
console.log(`\nWrote ${path.relative(projectRoot, outPath)} and ${path.relative(projectRoot, jsonPath)}`);
