import config from '../config/index.js';
import * as systemRepository from '../repositories/systemRepository.js';
import { businessToday } from '../utils/dates.js';
import logger from '../utils/logger.js';

export async function checkHealth() {
  const base = { service: 'pik-marketing-control-api', timezone: config.appTimezone, today: businessToday() };
  try {
    const db = await systemRepository.ping();
    return { ...base, status: 'ok', database: 'ok', schema_version: db.schema_version ?? null };
  } catch (error) {
    logger.error('Health check: database unreachable', { error: error.message });
    return { ...base, status: 'degraded', database: 'unreachable', schema_version: null };
  }
}
