import { randomBytes } from 'node:crypto';
import { defineConfig, devices } from '@playwright/test';
import { E2E_PORT, e2eDatabaseUrl } from './tests/e2e/fixtures.js';

const baseURL = `http://localhost:${E2E_PORT}`;

/**
 * E2E tests run the production build of the web app served by the API in production mode,
 * against a disposable *_e2e database that is recreated at the start of every run.
 * Only COOKIE_SECURE is relaxed, because the test server speaks plain HTTP on localhost.
 */
export default defineConfig({
  testDir: 'tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  outputDir: 'test-results',
  use: {
    baseURL,
    locale: 'id-ID',
    timezoneId: 'Asia/Jakarta',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } } }],
  webServer: {
    command: 'node tests/e2e/prepare-database.js && npm run build --workspace @pik/web && node apps/api/src/server.js',
    url: `${baseURL}/api/health`,
    reuseExistingServer: false,
    timeout: 120_000,
    env: {
      NODE_ENV: 'production',
      PORT: String(E2E_PORT),
      DATABASE_URL: e2eDatabaseUrl(),
      SESSION_SECRET: randomBytes(48).toString('base64url'),
      COOKIE_SECURE: '0',
    },
  },
});
