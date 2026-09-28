/**
 * npm run db:setup
 * Creates the database if needed and applies the schema (idempotent, non-destructive).
 */
import { applySchema, connect, databaseName, ensureDatabase, loadEnv } from './lib/database.js';

loadEnv();
const url = process.env.DATABASE_URL;
if (!url) {
  console.error('DATABASE_URL is not set. Copy .env.example to .env and fill it in.');
  process.exit(1);
}

try {
  const created = await ensureDatabase(url);
  if (created) console.log(`Created database "${databaseName(url)}".`);
  const client = await connect(url);
  try {
    const { fresh, applied } = await applySchema(client);
    const tables = await client.query(
      `SELECT count(*)::int AS n FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'`,
    );
    console.log(
      fresh
        ? 'Applied baseline schema and views.'
        : applied.length
          ? `Applied migrations: ${applied.join(', ')}; views refreshed.`
          : 'Schema already up to date; views refreshed.',
    );
    console.log(`Database "${databaseName(url)}" has ${tables.rows[0].n} tables.`);
  } finally {
    await client.end();
  }
} catch (error) {
  console.error(`Database setup failed: ${error.message}`);
  process.exit(1);
}
