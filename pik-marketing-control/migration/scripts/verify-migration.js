/**
 * npm run migrate:verify [-- --file <xlsx>] [--mapping <js>]
 * Verifies the latest applied migration. Exit code 1 when any check FAILs.
 */
import { environment, loadMapping, parseArgs, workbookPathFor } from './lib/cli.js';
import { verifyMigration } from './lib/verify.js';

const args = parseArgs(process.argv.slice(2));
const { databaseUrl } = environment();
const { mapping } = await loadMapping(args.mapping);
const result = await verifyMigration({ databaseUrl, mapping, workbookPath: workbookPathFor(args, mapping) });

for (const check of result.checks) console.log(`[${check.status}] ${check.name}: ${check.detail}`);
console.log(`\nVerification: ${result.status}`);
if (result.status === 'FAIL') process.exitCode = 1;
