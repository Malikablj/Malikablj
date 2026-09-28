import { query } from '../db/pool.js';

/** Confirms the database answers and reports the applied schema version (null before setup). */
export async function ping() {
  const { rows } = await query(
    `SELECT now() AS database_time, to_regclass('public.schema_migrations') IS NOT NULL AS has_schema`,
  );
  let schemaVersion = null;
  if (rows[0].has_schema) {
    const version = await query('SELECT max(version) AS version FROM schema_migrations');
    schemaVersion = version.rows[0].version;
  }
  return { database_time: rows[0].database_time, schema_version: schemaVersion };
}
