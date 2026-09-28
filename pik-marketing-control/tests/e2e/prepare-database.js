/**
 * Recreates the E2E database (name must end in _e2e) and inserts the E2E fixtures.
 * Run by the Playwright webServer command before the API starts.
 */
import pg from 'pg';
import { resetTestDatabase } from '../../database/scripts/lib/database.js';
import { hashPassword } from '../../apps/api/src/utils/password.js';
import { E2E_CUSTOMER, E2E_PASSWORD, E2E_USERS, e2eDatabaseUrl } from './fixtures.js';

const url = e2eDatabaseUrl();
if (!/_e2e$/.test(new URL(url).pathname)) {
  throw new Error('The E2E database name must end with _e2e.');
}
await resetTestDatabase(url);

const client = new pg.Client({ connectionString: url });
await client.connect();
try {
  const passwordHash = await hashPassword(E2E_PASSWORD);
  for (const user of Object.values(E2E_USERS)) {
    await client.query('INSERT INTO users (name, email, password_hash, role) VALUES ($1, $2, $3, $4)', [
      user.name,
      user.email,
      passwordHash,
      user.role,
    ]);
  }
  await client.query('INSERT INTO customers (name, industry) VALUES ($1, $2)', [E2E_CUSTOMER.name, E2E_CUSTOMER.industry]);
} finally {
  await client.end();
}
console.log('E2E database ready.');
