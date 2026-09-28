/**
 * npm run db:seed
 * Creates the first Admin account from ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD (.env or
 * environment). If ADMIN_PASSWORD is empty a strong random password is generated and printed
 * once. Does nothing when a user with that email already exists. Creates no business data.
 */
import { randomBytes } from 'node:crypto';
import { hashPassword } from '../../apps/api/src/utils/password.js';
import { connect, loadEnv } from './lib/database.js';

loadEnv();
const url = process.env.DATABASE_URL;
const name = (process.env.ADMIN_NAME || 'Administrator').trim();
const email = (process.env.ADMIN_EMAIL || '').trim().toLowerCase();
let password = process.env.ADMIN_PASSWORD || '';
const generated = !password;

if (!url) {
  console.error('DATABASE_URL is not set.');
  process.exit(1);
}
if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
  console.error('Set ADMIN_EMAIL to a valid email address (in .env or the environment).');
  process.exit(1);
}
if (generated) password = randomBytes(18).toString('base64url');
if (password.length < 10) {
  console.error('ADMIN_PASSWORD must be at least 10 characters.');
  process.exit(1);
}

const client = await connect(url);
try {
  const existing = await client.query('SELECT id, role FROM users WHERE lower(email) = $1', [email]);
  if (existing.rowCount) {
    console.log(`User ${email} already exists (role ${existing.rows[0].role}); nothing changed.`);
  } else {
    await client.query(`INSERT INTO users (name, email, password_hash, role) VALUES ($1, $2, $3, 'ADMIN')`, [
      name,
      email,
      await hashPassword(password),
    ]);
    console.log(`Created Admin user ${email}.`);
    if (generated) {
      console.log(`Generated password (shown only once, change it after first login): ${password}`);
    }
  }
} catch (error) {
  console.error(`Seeding failed: ${error.message}`);
  process.exitCode = 1;
} finally {
  await client.end();
}
