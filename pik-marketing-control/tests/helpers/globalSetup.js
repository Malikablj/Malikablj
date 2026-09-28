/**
 * Vitest global setup: recreates the dedicated test database schema once per run.
 * resetTestDatabase() refuses any database whose name does not end in _test/_e2e.
 */
import { loadEnv, resetTestDatabase } from '../../database/scripts/lib/database.js';

export default async function setup() {
  loadEnv();
  const url = process.env.DATABASE_URL_TEST;
  if (!url) {
    throw new Error('DATABASE_URL_TEST is not set (see .env.example). Tests need their own database.');
  }
  await resetTestDatabase(url);
}
