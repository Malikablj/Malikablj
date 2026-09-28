'use strict';

/**
 * Time-zone helpers for the emulator: Utilities.formatDate (SimpleDateFormat subset) and local-midnight conversion.
 */

function zonedParts(date, timeZone) {
  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
  const parts = {};
  for (const part of formatter.formatToParts(date)) parts[part.type] = part.value;
  return {
    year: Number(parts.year),
    month: Number(parts.month),
    day: Number(parts.day),
    hour: Number(parts.hour),
    minute: Number(parts.minute),
    second: Number(parts.second),
    millisecond: date.getTime() % 1000 < 0 ? date.getTime() % 1000 + 1000 : date.getTime() % 1000,
  };
}

/** Offset of the time zone at the instant, in milliseconds (local - UTC). */
function timeZoneOffset(timeMs, timeZone) {
  const p = zonedParts(new Date(timeMs), timeZone);
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
  return asUtc - (timeMs - (((timeMs % 1000) + 1000) % 1000));
}

/** UTC milliseconds of local midnight of the given calendar date in the time zone. */
function localMidnight(year, month, day, timeZone) {
  const guess = Date.UTC(year, month - 1, day);
  const offset = timeZoneOffset(guess, timeZone);
  const candidate = guess - offset;
  const correction = timeZoneOffset(candidate, timeZone) - offset;
  return candidate - correction;
}

const pad = (value, length) => String(value).padStart(length, '0');

/** Utilities.formatDate subset: yyyy yy MM M dd d HH H mm ss SSS and 'quoted' literals. */
function formatDate(date, timeZone, pattern) {
  if (!date || typeof date.getTime !== 'function' || Number.isNaN(date.getTime())) {
    throw new Error('Invalid argument: date');
  }
  const p = zonedParts(new Date(date.getTime()), timeZone);
  let output = '';
  let i = 0;
  while (i < pattern.length) {
    const char = pattern[i];
    if (char === "'") {
      const end = pattern.indexOf("'", i + 1);
      if (end === -1) throw new Error(`Unterminated quote in pattern ${pattern}`);
      output += end === i + 1 ? "'" : pattern.slice(i + 1, end);
      i = end + 1;
      continue;
    }
    if (!/[A-Za-z]/.test(char)) {
      output += char;
      i += 1;
      continue;
    }
    let length = 1;
    while (pattern[i + length] === char) length += 1;
    const token = char.repeat(length);
    switch (token) {
      case 'yyyy': output += pad(p.year, 4); break;
      case 'yy': output += pad(p.year % 100, 2); break;
      case 'MM': output += pad(p.month, 2); break;
      case 'M': output += String(p.month); break;
      case 'dd': output += pad(p.day, 2); break;
      case 'd': output += String(p.day); break;
      case 'HH': output += pad(p.hour, 2); break;
      case 'H': output += String(p.hour); break;
      case 'mm': output += pad(p.minute, 2); break;
      case 'ss': output += pad(p.second, 2); break;
      case 'SSS': output += pad(p.millisecond, 3); break;
      default: throw new Error(`Emulator: unsupported date pattern token "${token}" in "${pattern}"`);
    }
    i += length;
  }
  return output;
}

module.exports = { formatDate, localMidnight, timeZoneOffset, zonedParts };
