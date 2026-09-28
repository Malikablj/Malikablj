/**
 * Database utilities shared by `npm run db:setup`, the seed script and the test setup.
 *
 * Schema versioning:
 *  - A fresh database gets database/schema.sql + database/views.sql, is recorded as
 *    "0000_baseline", and every file in apps/api/migrations/ is marked as applied
 *    (schema.sql already contains the full current schema).
 *  - An existing database only receives the numbered files in apps/api/migrations/
 *    it has not seen yet, then views.sql is re-applied (views hold no data).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import dotenv from 'dotenv';
import pg from 'pg';

export const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const schemaFile = path.join(projectRoot, 'database/schema.sql');
const viewsFile = path.join(projectRoot, 'database/views.sql');
const migrationsDir = path.join(projectRoot, 'apps/api/migrations');

export function loadEnv() {
  dotenv.config({ path: path.join(projectRoot, '.env'), quiet: true });
}

export function databaseName(connectionString) {
  const name = decodeURIComponent(new URL(connectionString).pathname.replace(/^\//, ''));
  if (!/^[A-Za-z0-9_]+$/.test(name)) {
    throw new Error(`Unsupported database name "${name}" (letters, digits and underscores only).`);
  }
  return name;
}

export async function connect(connectionString) {
  const client = new pg.Client({ connectionString });
  await client.connect();
  return client;
}

/** Creates the database named in the URL when it does not exist yet. Never drops anything. */
export async function ensureDatabase(connectionString) {
  try {
    const client = await connect(connectionString);
    await client.end();
    return false;
  } catch (error) {
    if (error.code !== '3D000') throw error; // 3D000 = database does not exist
  }
  const name = databaseName(connectionString);
  const adminUrl = new URL(connectionString);
  adminUrl.pathname = '/postgres';
  const admin = await connect(adminUrl.toString());
  try {
    await admin.query(`CREATE DATABASE "${name}"`);
  } finally {
    await admin.end();
  }
  return true;
}

function migrationFiles() {
  if (!fs.existsSync(migrationsDir)) return [];
  return fs
    .readdirSync(migrationsDir)
    .filter((file) => /^\d{4}_[a-z0-9_]+\.sql$/.test(file))
    .sort();
}

/**
 * Brings the schema up to date inside one transaction.
 * @returns {{ fresh: boolean, applied: string[] }}
 */
export async function applySchema(client) {
  const applied = [];
  await client.query('BEGIN');
  try {
    const { rows } = await client.query(`SELECT to_regclass('public.schema_migrations') IS NOT NULL AS present`);
    let fresh = !rows[0].present;
    if (!fresh) {
      const baseline = await client.query(`SELECT 1 FROM schema_migrations WHERE version = '0000_baseline'`);
      fresh = baseline.rowCount === 0;
    }

    if (fresh) {
      await client.query(fs.readFileSync(schemaFile, 'utf8'));
      await client.query(`INSERT INTO schema_migrations (version) VALUES ('0000_baseline') ON CONFLICT DO NOTHING`);
      for (const file of migrationFiles()) {
        await client.query(`INSERT INTO schema_migrations (version) VALUES ($1) ON CONFLICT DO NOTHING`, [
          file.replace(/\.sql$/, ''),
        ]);
      }
      applied.push('0000_baseline');
    } else {
      const done = new Set((await client.query('SELECT version FROM schema_migrations')).rows.map((r) => r.version));
      for (const file of migrationFiles()) {
        const version = file.replace(/\.sql$/, '');
        if (done.has(version)) continue;
        await client.query(fs.readFileSync(path.join(migrationsDir, file), 'utf8'));
        await client.query('INSERT INTO schema_migrations (version) VALUES ($1)', [version]);
        applied.push(version);
      }
    }

    await client.query(fs.readFileSync(viewsFile, 'utf8'));
    await client.query('COMMIT');
    return { fresh, applied };
  } catch (error) {
    await client.query('ROLLBACK').catch(() => {});
    throw error;
  }
}

/**
 * Drops and recreates the public schema. Refuses to run unless the database name ends
 * with "_test" or "_e2e", so it can never touch a development or production database.
 */
export async function resetTestDatabase(connectionString) {
  const name = databaseName(connectionString);
  if (!/_(test|e2e)$/.test(name)) {
    throw new Error(`Refusing to reset "${name}": test database names must end with _test or _e2e.`);
  }
  await ensureDatabase(connectionString);
  const client = await connect(connectionString);
  try {
    await client.query('DROP SCHEMA IF EXISTS public CASCADE');
    await client.query('CREATE SCHEMA public');
    await applySchema(client);
  } finally {
    await client.end();
  }
}
