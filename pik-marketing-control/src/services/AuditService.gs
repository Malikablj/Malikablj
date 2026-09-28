/**
 * Audit trail reads. AUDIT_LOG is append-only and written only by the repository (Audit.gs); nothing here writes it.
 *
 *   audit.list     Admin: the whole log, filtered and paged on the server.
 *   audit.history  the change history of one record, for anyone who may read the record's module. Migration batches
 *                  are audited as one MIGRATION_RUN entry listing the inserted ids (Repository.gs), so a migrated record's
 *                  history starts with the batch that created it.
 */

/** Module that guards the history of each table (Auth.gs MODULE_PERMISSIONS). */
const AUDIT_TABLE_MODULES = Object.freeze({
  USERS: 'users', CUSTOMERS: 'customers', CONTACTS: 'contacts', PRODUCTS: 'products', LEADS: 'leads',
  ACTIVITIES: 'activities', FOLLOW_UP: 'followUps', PURCHASE_ORDERS: 'purchaseOrders', PO_LINES: 'purchaseOrders',
  DELIVERIES: 'deliveries', RETURNS: 'returns', STOCK: 'stock', LEADTIME: 'leadTime', INBOUND_MAKLON: 'inbound',
  INVOICES_PAYMENTS: 'finance', PO_FINANCIALS: 'finance', MIGRATION_ISSUES: 'migrationIssues', ENUMS: 'settings',
  SETTINGS: 'settings'
});

const AUDIT_HISTORY_LIMIT = 200;
const AUDIT_ID_PREVIEW = 20;

function parseAuditChanges_(json) {
  if (json === null || json === undefined || json === '') return null;
  try {
    return JSON.parse(String(json));
  } catch (error) {
    return { unparsed: String(json).slice(0, 500) };
  }
}

/** Long id lists of migration batches are shortened for the client (count + first ids). */
function compactAuditChanges_(changes) {
  if (!changes || !Array.isArray(changes.ids)) return changes;
  return Object.assign({}, changes, { ids: changes.ids.slice(0, AUDIT_ID_PREVIEW), idCount: changes.ids.length });
}

function auditActorNames_() {
  const names = {};
  loadTable_('USERS').records.forEach(function (user) {
    if (user.email) names[String(user.email).toLowerCase()] = user.name;
  });
  return names;
}

function auditEntryForClient_(record, actorNames) {
  const table = record.entity_type ? getSchema_().byName[record.entity_type] : null;
  return {
    id: record.id,
    occurred_at: record.occurred_at,
    actor_email: record.actor_email,
    actor_name: actorNames[String(record.actor_email || '').toLowerCase()] || null,
    action: record.action,
    action_label: enumLabel_('AUDIT_ACTION', record.action),
    entity_type: record.entity_type,
    entity_label: table ? table.label : record.entity_type,
    entity_id: record.entity_id,
    request_id: record.request_id,
    changes: compactAuditChanges_(parseAuditChanges_(record.changes_json)),
    note: record.note
  };
}

/** Log entries newest first. The log is append-only, so for equal timestamps the later row is the newer entry. */
function auditRecordsNewestFirst_() {
  return loadTable_('AUDIT_LOG').records.slice().reverse();
}

function listAuditLog_(input) {
  const actorNames = auditActorNames_();
  const listed = runListQuery_(auditRecordsNewestFirst_(), input, {
    searchFields: ['entity_type', 'entity_id', 'actor_email', 'action', 'note', 'changes_json'],
    filters: {
      action: eqFilter_('action'),
      entity_type: eqFilter_('entity_type'),
      entity_id: eqFilter_('entity_id'),
      actor_email: function (record, value) { return String(record.actor_email || '').toLowerCase() === String(value).toLowerCase(); },
      occurred_at: dateRangeFilter_('occurred_at')
    },
    sortFields: ['occurred_at', 'actor_email', 'action', 'entity_type', 'entity_id'],
    defaultSort: { field: 'occurred_at', direction: 'desc' }
  });
  listed.items = listed.items.map(function (record) { return auditEntryForClient_(record, actorNames); });
  return listed;
}

/**
 * History of one record: { entityType, entityId } → newest first, at most AUDIT_HISTORY_LIMIT entries.
 * Allowed when the caller can read the module of the table; the record itself must exist.
 */
function getRecordHistory_(input, user) {
  const params = objectInput_(input);
  const entityType = String(params.entityType || '');
  const module = AUDIT_TABLE_MODULES[entityType];
  if (!module) throw appError_(ERROR_CODE.VALIDATION, 'Jenis data riwayat tidak dikenal: ' + entityType + '.');
  requirePermission_(user, module, ACCESS.READ);
  const entityId = requireId_(params.entityId, 'ID data');
  const table = getTableDef_(entityType);
  if (table.hasId) findOrThrow_(entityType, entityId);

  const actorNames = auditActorNames_();
  const entries = auditRecordsNewestFirst_().filter(function (record) {
    if (record.entity_type !== entityType) return false;
    if (record.entity_id === entityId) return true;
    if (record.action !== 'MIGRATION_RUN' || !record.changes_json) return false;
    const changes = parseAuditChanges_(record.changes_json);
    return Boolean(changes) && Array.isArray(changes.ids) && changes.ids.indexOf(entityId) !== -1;
  }).sort(function (a, b) { return compareForSort_(b.occurred_at, a.occurred_at); });
  return {
    entityType: entityType,
    entityId: entityId,
    total: entries.length,
    items: entries.slice(0, AUDIT_HISTORY_LIMIT).map(function (record) {
      const entry = auditEntryForClient_(record, actorNames);
      if (record.action === 'MIGRATION_RUN' && entry.changes && entry.changes.ids) {
        entry.changes = { operation: entry.changes.operation, count: entry.changes.idCount || entry.changes.count };
      }
      return entry;
    })
  };
}
