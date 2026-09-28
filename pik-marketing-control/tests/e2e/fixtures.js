/**
 * E2E fixtures. Everything here lives only in the disposable *_e2e database that
 * tests/e2e/prepare-database.js recreates before each run; names are obviously synthetic.
 */
import { loadEnv } from '../../database/scripts/lib/database.js';

export const E2E_PORT = 4310;
export const E2E_PASSWORD = 'e2e-password-123';
export const E2E_USERS = {
  admin: { name: 'Uji Admin', email: 'admin.e2e@test.local', role: 'ADMIN' },
  marketing: { name: 'Uji Marketing', email: 'marketing.e2e@test.local', role: 'MARKETING' },
  sales: { name: 'Uji Sales', email: 'sales.e2e@test.local', role: 'SALES' },
  management: { name: 'Uji Manajemen', email: 'management.e2e@test.local', role: 'MANAGEMENT' },
  viewer: { name: 'Uji Viewer', email: 'viewer.e2e@test.local', role: 'VIEWER' },
};
export const E2E_CUSTOMER = { name: 'PT Uji E2E Kemasan', industry: 'Uji Coba' };

/** DATABASE_URL_E2E, or DATABASE_URL_TEST with the "_test" suffix replaced by "_e2e". */
export function e2eDatabaseUrl() {
  loadEnv();
  if (process.env.DATABASE_URL_E2E) return process.env.DATABASE_URL_E2E;
  const testUrl = process.env.DATABASE_URL_TEST;
  if (!testUrl) throw new Error('Set DATABASE_URL_E2E or DATABASE_URL_TEST (see .env.example).');
  const url = new URL(testUrl);
  url.pathname = url.pathname.replace(/_test$/, '_e2e');
  return url.toString();
}

/** Business "today" (YYYY-MM-DD) in the app timezone, as the server computes it. */
export function businessToday(timeZone = process.env.APP_TIMEZONE || 'Asia/Jakarta') {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

export function addDays(iso, days) {
  const date = new Date(`${iso}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}
