/**
 * Date and time helpers.
 *
 * Business dates are stored as 'yyyy-MM-dd' text and timestamps as ISO 8601 UTC text ('...Z'), so stored
 * values never depend on the spreadsheet locale or on Sheets' automatic date conversion.
 */

const ISO_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const ISO_DATETIME_PATTERN = /^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(\.\d{1,3})?Z$/;
const TIME_OF_DAY_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/;

var CLOCK_OVERRIDE_MS_ = null;

/** Current time. Tests can pin it with setClockForTesting_(). */
function currentDate_() {
  return CLOCK_OVERRIDE_MS_ === null ? new Date() : new Date(CLOCK_OVERRIDE_MS_);
}

/** Pins the clock to an ISO timestamp; null restores the real clock. For tests only. */
function setClockForTesting_(isoTimestamp) {
  CLOCK_OVERRIDE_MS_ = isoTimestamp === null || isoTimestamp === undefined ? null : new Date(isoTimestamp).getTime();
}

function nowIso_() {
  return currentDate_().toISOString();
}

/** Today's date ('yyyy-MM-dd') in the given or the application time zone. */
function todayIso_(timeZone) {
  return Utilities.formatDate(currentDate_(), timeZone || getAppTimeZone_(), 'yyyy-MM-dd');
}

/** True for a real calendar date written as 'yyyy-MM-dd' (years 1900-2999). */
function isValidIsoDate_(value) {
  if (typeof value !== 'string') return false;
  const match = ISO_DATE_PATTERN.exec(value);
  if (!match) return false;
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  if (year < 1900 || year > 2999 || month < 1 || month > 12 || day < 1) return false;
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

/** True for an ISO 8601 UTC timestamp such as '2026-09-28T03:15:00.000Z'. */
function isValidIsoDateTime_(value) {
  if (typeof value !== 'string') return false;
  const match = ISO_DATETIME_PATTERN.exec(value);
  return Boolean(match) && isValidIsoDate_(match[1]);
}

/** True for a time of day written as 'HH:mm' (24-hour). */
function isValidTimeOfDay_(value) {
  return typeof value === 'string' && TIME_OF_DAY_PATTERN.test(value);
}
