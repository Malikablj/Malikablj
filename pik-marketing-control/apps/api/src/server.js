/** Starts the HTTP server. Configuration errors stop the process with a clear message. */
import { createApp } from './app.js';
import config, { assertConfig } from './config/index.js';
import { closePool } from './db/pool.js';
import logger from './utils/logger.js';

try {
  assertConfig();
} catch (error) {
  logger.error(error.message);
  process.exit(1);
}

const app = createApp();
const server = app.listen(config.port, () => {
  logger.info(`PIK Marketing Control API listening on http://localhost:${config.port}`, {
    env: config.nodeEnv,
    timezone: config.appTimezone,
  });
});

function shutdown(signal) {
  logger.info(`${signal} received, shutting down`);
  server.close(async () => {
    await closePool();
    process.exit(0);
  });
  setTimeout(() => process.exit(1), 10_000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
