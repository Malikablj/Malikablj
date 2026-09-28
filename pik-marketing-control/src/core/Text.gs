/**
 * Text normalization. Pure functions without Apps Script services.
 *
 * Same semantics as migration/scripts/lib/normalize.js (rules T-01, T-07 and T-08 of the migration mapping):
 * stored values are only trimmed; the *Key helpers build comparison keys that are never shown as business values.
 */

function isBlank_(value) {
  return value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
}

/** Trimmed text, or null when empty. Whitespace inside the text is kept as is. */
function cleanText_(value) {
  if (value === null || value === undefined) return null;
  const text = String(value).trim();
  return text === '' ? null : text;
}

/** Document number key (PO, SJ, invoice): all whitespace removed, upper-case. */
function documentKey_(value) {
  const text = cleanText_(value);
  return text === null ? null : text.replace(/\s+/g, '').toUpperCase();
}

/** Product/component code key: brackets, parentheses and whitespace removed, upper-case. "[ABC 12]" -> "ABC12". */
function codeKey_(value) {
  const text = cleanText_(value);
  if (text === null) return null;
  const key = text.replace(/[\[\]()\s]/g, '').toUpperCase();
  return key === '' ? null : key;
}

/** Code written at the start of a legacy label: "[ABC123] Botol 100 ml" -> "ABC123". */
function leadingCode_(value) {
  const text = cleanText_(value);
  if (text === null) return null;
  const match = text.match(/^[\[(]\s*([A-Za-z0-9._-]+)\s*[\])]/);
  return match ? match[1].toUpperCase() : null;
}

/** Case-insensitive search key: lower-case with runs of whitespace collapsed. */
function searchKey_(value) {
  const text = cleanText_(value);
  return text === null ? '' : text.replace(/\s+/g, ' ').toLowerCase();
}
