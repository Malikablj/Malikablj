/**
 * Runtime configuration, read once from environment variables.
 * The project-root .env file is loaded if present; real environment variables win.
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import dotenv from 'dotenv';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
dotenv.config({ path: path.join(projectRoot, '.env'), quiet: true });

const env = process.env;
const nodeEnv = env.NODE_ENV || 'development';
const isProduction = nodeEnv === 'production';

function intFromEnv(name, fallback) {
  const raw = env[name];
  if (raw === undefined || raw === '') return fallback;
  const value = Number.parseInt(raw, 10);
  if (!Number.isFinite(value)) throw new Error(`Environment variable ${name} must be an integer.`);
  return value;
}

function isValidTimeZone(timeZone) {
  try {
    new Intl.DateTimeFormat('en-CA', { timeZone });
    return true;
  } catch {
    return false;
  }
}

const config = Object.freeze({
  projectRoot,
  nodeEnv,
  isProduction,
  isTest: nodeEnv === 'test',
  port: intFromEnv('PORT', 4000),
  databaseUrl: env.DATABASE_URL || '',
  sessionSecret: env.SESSION_SECRET || '',
  sessionTtlHours: intFromEnv('SESSION_TTL_HOURS', 168),
  corsOrigin: env.CORS_ORIGIN || 'http://localhost:5173',
  appTimezone: env.APP_TIMEZONE || 'Asia/Jakarta',
  trustProxy: env.TRUST_PROXY === '1' || env.TRUST_PROXY === 'true',
  webDistDir: path.join(projectRoot, 'apps/web/dist'),
});

/** Throws a descriptive error when configuration is unusable. Called at server start. */
export function assertConfig() {
  const problems = [];
  if (!config.databaseUrl) problems.push('DATABASE_URL is not set.');
  if (!isValidTimeZone(config.appTimezone)) problems.push(`APP_TIMEZONE "${config.appTimezone}" is not a valid IANA timezone.`);
  if (config.isProduction && config.sessionSecret.length < 32) {
    problems.push('SESSION_SECRET must be at least 32 characters in production.');
  }
  if (config.sessionTtlHours < 1) problems.push('SESSION_TTL_HOURS must be at least 1.');
  if (problems.length) {
    throw new Error(`Invalid configuration:\n- ${problems.join('\n- ')}`);
  }
}

/** Secret used to hash session tokens. Development falls back to a fixed, clearly-marked key. */
export function sessionSecret() {
  return config.sessionSecret || 'development-only-session-secret-do-not-use-in-production';
}

export default config;
