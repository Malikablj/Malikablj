/**
 * Minimal structured logger. Writes one JSON line per event in production and a readable
 * line in development. Silent during tests unless LOG_IN_TESTS=1.
 */
const isProduction = process.env.NODE_ENV === 'production';
const silent = process.env.NODE_ENV === 'test' && process.env.LOG_IN_TESTS !== '1';

function write(level, message, context) {
  if (silent) return;
  const stream = level === 'error' || level === 'warn' ? process.stderr : process.stdout;
  if (isProduction) {
    stream.write(`${JSON.stringify({ time: new Date().toISOString(), level, message, ...context })}\n`);
  } else {
    const extra = context && Object.keys(context).length ? ` ${JSON.stringify(context)}` : '';
    stream.write(`[${level}] ${message}${extra}\n`);
  }
}

const logger = {
  info: (message, context) => write('info', message, context),
  warn: (message, context) => write('warn', message, context),
  error: (message, context) => write('error', message, context),
};

export default logger;
