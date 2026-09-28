/** Logs one line per API request: method, path, status, duration and user. Never logs bodies. */
import logger from '../utils/logger.js';

export function requestLogger(req, res, next) {
  const started = process.hrtime.bigint();
  res.on('finish', () => {
    if (!req.originalUrl.startsWith('/api')) return;
    const durationMs = Number(process.hrtime.bigint() - started) / 1e6;
    logger.info(`${req.method} ${req.originalUrl.split('?')[0]} ${res.statusCode}`, {
      ms: Math.round(durationMs),
      user: req.user?.id,
    });
  });
  next();
}
