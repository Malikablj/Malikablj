/**
 * Legacy keys identify a source record across migration re-runs (UNIQUE legacy_key column).
 *
 *   declared key   "<entity>|<alternative>|<VALUE>[|<VALUE>...]"  from mapping.key columns
 *   fallback       "<entity>|row|<sheet>|<row>|<content hash>"     when no key value exists
 *
 * Declared keys (e.g. an AppSheet ID column) are stable. The fallback survives re-runs of an
 * unchanged workbook; if rows are edited or moved, the old record is reported as stale by
 * `npm run migrate:verify` instead of being silently overwritten.
 */
import { createHash } from 'node:crypto';
import { parseText } from './values.js';

export function keyPart(value) {
  if (value instanceof Date) return value.toISOString().slice(0, 10);
  const text = parseText(value).value;
  return text === null ? null : text.toUpperCase();
}

export function legacyKeyFromValues(entityName, alternative, values) {
  return `${entityName}|${alternative}|${values.map((value) => keyPart(value)).join('|')}`;
}

/** First key alternative whose columns are all filled, or null. */
export function declaredKey(entityName, alternatives, rowValues) {
  for (const [index, columns] of (alternatives ?? []).entries()) {
    const parts = columns.map((column) => keyPart(rowValues[column]));
    if (parts.every((part) => part !== null)) return `${entityName}|${index}|${parts.join('|')}`;
  }
  return null;
}

export function contentHash(values) {
  return createHash('sha1').update(JSON.stringify(values)).digest('hex').slice(0, 16);
}

export function fallbackKey(entityName, sheetName, rowNumber, mappedValues) {
  return `${entityName}|row|${sheetName}|${rowNumber}|${contentHash(mappedValues)}`;
}
