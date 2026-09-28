/**
 * Runs in every test worker before test files are imported: points the API at the
 * test database so no test can ever touch the development database.
 */
import { loadEnv } from '../../database/scripts/lib/database.js';

loadEnv();
process.env.NODE_ENV = 'test';
process.env.DATABASE_URL = process.env.DATABASE_URL_TEST;
process.env.SESSION_SECRET ||= 'test-session-secret-that-is-long-enough-123456';
