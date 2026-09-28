/**
 * PostgreSQL connection pool and transaction helper.
 *
 * Type parsing:
 * - DATE (1082) stays a "YYYY-MM-DD" string: avoids timezone shifts from JS Date.
 * - NUMERIC (1700) and INT8 (20) become JS numbers. Quantities and IDR amounts stay far
 *   below 2^53; all arithmetic happens in SQL with exact NUMERIC types.
 */
import pg from 'pg';
import config from '../config/index.js';
import logger from '../utils/logger.js';

pg.types.setTypeParser(1082, (value) => value);
pg.types.setTypeParser(1700, (value) => (value === null ? null : Number(value)));
pg.types.setTypeParser(20, (value) => (value === null ? null : Number(value)));

let pool = null;

export function getPool() {
  if (!pool) {
    pool = new pg.Pool({
      connectionString: config.databaseUrl,
      max: 10,
      idleTimeoutMillis: 30_000,
      connectionTimeoutMillis: 10_000,
    });
    pool.on('error', (error) => logger.error('Unexpected PostgreSQL pool error', { error: error.message }));
  }
  return pool;
}

/** Runs a parameterized query on the pool (or on `db` when inside a transaction). */
export function query(text, params = [], db = null) {
  return (db || getPool()).query(text, params);
}

/**
 * Runs `work(client)` inside a transaction. Commits when it resolves, rolls back when it throws.
 * Repositories accept the client as their `db` argument to take part in the transaction.
 */
export async function withTransaction(work) {
  const client = await getPool().connect();
  try {
    await client.query('BEGIN');
    const result = await work(client);
    await client.query('COMMIT');
    return result;
  } catch (error) {
    await client.query('ROLLBACK').catch(() => {});
    throw error;
  } finally {
    client.release();
  }
}

export async function closePool() {
  if (pool) {
    const current = pool;
    pool = null;
    await current.end();
  }
}
