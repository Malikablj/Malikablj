/**
 * Stable record IDs: PREFIX-XXXXXXXXXX (10 upper-case hex digits; AUDIT_LOG uses 16).
 *
 * This is the format already used by the source workbook, so migrated IDs are kept unchanged (decision D12).
 * IDs come from random UUID bits, never from row numbers, and are checked against the IDs already taken in
 * the table and in the current batch before they are used.
 */

const ID_HEX_LENGTH = 10;
const AUDIT_ID_HEX_LENGTH = 16;
const ID_PREFIX_PATTERN = /^[A-Z]{2,4}$/;
const MAX_ID_ATTEMPTS = 20;

var ID_RANDOM_SOURCE_ = null;

/** Random upper-case hex digits. Skips the fixed version and variant nibbles of a v4 UUID. */
function randomHex_(length) {
  let hex = '';
  while (hex.length < length) {
    if (ID_RANDOM_SOURCE_) {
      hex += String(ID_RANDOM_SOURCE_());
    } else {
      const uuid = Utilities.getUuid().replace(/-/g, '');
      hex += uuid.slice(0, 12) + uuid.slice(13, 16) + uuid.slice(17);
    }
  }
  return hex.slice(0, length).toUpperCase();
}

/** RegExp matching IDs with one of the prefixes. */
function idPattern_(prefixes, hexLength) {
  return new RegExp('^(' + prefixes.join('|') + ')-[0-9A-F]{' + (hexLength || ID_HEX_LENGTH) + '}$');
}

/**
 * New ID for the prefix. `usedIds` (a Set, optional) holds IDs that are already taken; the new ID is added to it
 * so a batch never receives the same ID twice.
 */
function generateId_(prefix, usedIds, hexLength) {
  if (!ID_PREFIX_PATTERN.test(prefix)) {
    throw appError_(ERROR_CODE.INTERNAL, 'Prefiks ID tidak valid: ' + prefix);
  }
  for (let attempt = 0; attempt < MAX_ID_ATTEMPTS; attempt++) {
    const id = prefix + '-' + randomHex_(hexLength || ID_HEX_LENGTH);
    if (usedIds && usedIds.has(id)) continue;
    if (usedIds) usedIds.add(id);
    return id;
  }
  throw appError_(ERROR_CODE.INTERNAL, 'Gagal membuat ID unik untuk prefiks ' + prefix + '.');
}

function generateAuditId_() {
  return generateId_('AUD', null, AUDIT_ID_HEX_LENGTH);
}

/** Replaces the random source with a function returning hex strings; null restores UUIDs. For tests only. */
function setIdRandomSourceForTesting_(source) {
  ID_RANDOM_SOURCE_ = source || null;
}
