/**
 * AUDIT_LOG writer. Append-only: entries are only ever added, in one batch per operation, inside the caller's lock.
 *
 * Entry: { action (AUDIT_ACTION), entityType, entityId, changes (object, stored as JSON), note }.
 * CREATE stores the non-empty fields of the new record; UPDATE/ARCHIVE/RESTORE store { column: [before, after] }.
 */

const MAX_AUDIT_JSON_LENGTH = 45000;

function writeAuditEntries_(entries, ctx, now) {
  if (!entries || entries.length === 0) return;
  const table = getTableDef_('AUDIT_LOG');
  const sheet = getCheckedSheet_(table);
  const rows = entries.map(function (entry) {
    return recordToRow_(table, {
      id: generateAuditId_(),
      occurred_at: now,
      actor_email: ctx.actor,
      action: entry.action,
      entity_type: entry.entityType || null,
      entity_id: entry.entityId || null,
      request_id: ctx.requestId || null,
      changes_json: entry.changes === undefined || entry.changes === null ? null : toAuditJson_(entry.changes),
      note: entry.note || null
    });
  });
  appendRowsToSheet_(sheet, table, rows);
}

/** JSON for changes_json, kept below the Sheets cell limit. */
function toAuditJson_(changes) {
  const json = JSON.stringify(changes);
  if (json.length <= MAX_AUDIT_JSON_LENGTH) return json;
  return JSON.stringify({ truncated: true, originalLength: json.length, preview: json.slice(0, 40000) });
}
