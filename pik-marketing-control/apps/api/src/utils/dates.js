/**
 * Business-date helpers. "Today" is the calendar date in APP_TIMEZONE (default
 * Asia/Jakarta), not the server's UTC date: at 06:00 WIB the UTC date is still yesterday.
 */
import config from '../config/index.js';

export function businessToday(now = new Date(), timeZone = config.appTimezone) {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
}

/** Adds whole days to a "YYYY-MM-DD" date. */
export function addDays(isoDate, days) {
  const date = new Date(`${isoDate}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}
