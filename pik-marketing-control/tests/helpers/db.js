/**
 * Direct database access for tests (fixtures and assertions). Only ever connects to
 * DATABASE_URL_TEST, whose name must end with _test or _e2e.
 */
import pg from 'pg';
import { databaseName } from '../../database/scripts/lib/database.js';

// Same parsing as the API pool (apps/api/src/db/pool.js).
pg.types.setTypeParser(1082, (value) => value);
pg.types.setTypeParser(1700, (value) => (value === null ? null : Number(value)));
pg.types.setTypeParser(20, (value) => (value === null ? null : Number(value)));

const BUSINESS_TABLES = [
  'migration_issues',
  'migration_run_records',
  'migration_runs',
  'po_financials',
  'invoices_payments',
  'inbound_maklon',
  'leadtime',
  'stock',
  'returns',
  'deliveries',
  'po_lines',
  'purchase_orders',
  'follow_ups',
  'activities',
  'leads',
  'products',
  'contacts',
  'customers',
  'user_sessions',
  'users',
];

function testUrl() {
  const url = process.env.DATABASE_URL_TEST;
  if (!url || !/_(test|e2e)$/.test(databaseName(url))) {
    throw new Error('DATABASE_URL_TEST must point to a database whose name ends with _test or _e2e.');
  }
  return url;
}

let client = null;

export async function db() {
  if (!client) {
    client = new pg.Client({ connectionString: testUrl() });
    await client.connect();
  }
  return client;
}

export async function sql(text, params = []) {
  return (await db()).query(text, params);
}

export async function closeDb() {
  if (client) {
    const current = client;
    client = null;
    await current.end();
  }
}

/** Empties every table of the test database (guarded by testUrl()). */
export async function truncateAll() {
  testUrl();
  await sql(`TRUNCATE ${BUSINESS_TABLES.join(', ')} RESTART IDENTITY CASCADE`);
}
