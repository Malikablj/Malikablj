/**
 * CRM: leads (pipeline), activities and follow-ups.
 *
 * Smart defaults (PRD §26): PIC = the signed-in user, date/time = now. A lead or contact given without a customer
 * brings its customer along (the relation is known, not guessed). Follow-up buckets are computed on read (D14):
 * TODAY / OVERDUE / UPCOMING for open follow-ups (PLANNED, RESCHEDULE), plus DONE / CANCELLED.
 */

const LEAD_FIELDS = ['customer_id', 'contact_id', 'product_id', 'name', 'source', 'product_interest', 'estimated_quantity',
  'estimated_value', 'status', 'priority', 'owner_user_id', 'expected_closing_date', 'notes'];
const ACTIVITY_FIELDS = ['customer_id', 'contact_id', 'lead_id', 'type', 'subject', 'description', 'owner_user_id',
  'activity_at', 'attachment_url'];
const FOLLOW_UP_FIELDS = ['customer_id', 'lead_id', 'activity_id', 'owner_user_id', 'follow_up_date', 'follow_up_time',
  'type', 'purpose', 'priority', 'status', 'result', 'notes'];
const LEAD_BOARD_COLUMN_LIMIT = 50;
const FOLLOW_UP_BUCKETS = ['TODAY', 'OVERDUE', 'UPCOMING', 'OPEN', 'DONE', 'CANCELLED', 'RESCHEDULE'];

/** Fills customer_id from the lead, contact or source activity when the form left it empty. */
function inheritCustomer_(data) {
  if (data.customer_id) return data;
  const lead = data.lead_id ? loadTable_('LEADS').byId[data.lead_id] : null;
  const contact = data.contact_id ? loadTable_('CONTACTS').byId[data.contact_id] : null;
  const activity = data.activity_id ? loadTable_('ACTIVITIES').byId[data.activity_id] : null;
  const customerId = (lead && lead.customer_id) || (contact && contact.customer_id) || (activity && activity.customer_id) || null;
  return customerId ? Object.assign({}, data, { customer_id: customerId }) : data;
}

/**
 * Activities and follow-ups belong to a customer (PRD §20: CUSTOMERS → ACTIVITIES / FOLLOW_UP). The column is optional in
 * the sheet for records without one in the source, but the application never creates or leaves one without it.
 */
function requireCustomer_(data, current) {
  const customerId = data.customer_id !== undefined ? data.customer_id : current ? current.customer_id : null;
  if (customerId) return;
  throw appError_(ERROR_CODE.VALIDATION, 'Customer wajib dipilih.',
    { errors: [{ field: 'customer_id', code: 'REQUIRED', message: 'Customer wajib dipilih.' }] });
}

function leadStatusOrder_() {
  const order = {};
  (getEnumState_().byName.LEAD_STATUS || { items: [] }).items.forEach(function (item, index) { order[item.enum_value] = index; });
  return order;
}

// ---------------------------------------------------------------------------------------------------------------
// Leads
// ---------------------------------------------------------------------------------------------------------------

function leadSpec_() {
  const order = leadStatusOrder_();
  return {
    searchFields: ['name', 'customer_name', 'product_interest', 'source', 'owner_name', 'contact_name'],
    filters: {
      status: eqFilter_('status'),
      owner_user_id: eqFilter_('owner_user_id'),
      customer_id: eqFilter_('customer_id'),
      priority: eqFilter_('priority'),
      open: function (record, value) { return isOpenLead_(record) === (value === true || value === 'true'); },
      expected_closing_date: dateRangeFilter_('expected_closing_date'),
      created_at: dateRangeFilter_('created_at')
    },
    sortFields: {
      name: function (r) { return r.name; },
      customer_name: function (r) { return r.customer_name; },
      status: function (r) { return order[r.status]; },
      priority: function (r) { return ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'].indexOf(r.priority); },
      estimated_value: function (r) { return r.estimated_value; },
      expected_closing_date: function (r) { return r.expected_closing_date; },
      owner_name: function (r) { return r.owner_name; },
      created_at: function (r) { return r.created_at; },
      updated_at: function (r) { return r.updated_at; }
    },
    defaultSort: { field: 'updated_at', direction: 'desc' }
  };
}

function listLeads_(input) {
  return runListQuery_(namedRecords_('LEADS'), input, leadSpec_());
}

/** Kanban: one column per active LEAD_STATUS value with count, total estimated value and the newest cards. */
function getLeadBoard_(input) {
  const params = objectInput_(input);
  const filtered = queryRecords_(namedRecords_('LEADS'), {
    search: params.search, filters: objectInput_(params.filters, 'Filter'), sort: { field: 'updated_at', direction: 'desc' }
  }, leadSpec_());
  const statuses = (getEnumState_().byName.LEAD_STATUS || { items: [] }).items.filter(function (item) { return item.is_active === true; });
  const columns = statuses.map(function (item) {
    const leads = filtered.filter(function (lead) { return lead.status === item.enum_value; });
    return {
      status: item.enum_value, label: item.label, count: leads.length,
      total_value: roundMoney_(leads.reduce(function (sum, lead) { return sum + (lead.estimated_value || 0); }, 0)),
      items: leads.slice(0, LEAD_BOARD_COLUMN_LIMIT)
    };
  });
  return { columns: columns, total: filtered.length };
}

function leadOptions_(input) {
  const params = objectInput_(input);
  const names = nameMaps_();
  const records = loadTable_('LEADS').records.filter(function (lead) { return !params.customer_id || lead.customer_id === params.customer_id; });
  return optionsFrom_(records, params, function (lead) { return lead.name; },
    function (lead) { return names.customer[lead.customer_id] || null; });
}

function getLead_(input) {
  const id = requireId_(objectInput_(input).id, 'ID lead');
  const lead = findOrThrow_('LEADS', id);
  const today = todayIso_();
  const byLead = function (tableName) {
    return loadTable_(tableName).records.filter(function (record) { return record.lead_id === id; }).map(withNames_);
  };
  return {
    lead: withNames_(lead),
    activities: byLead('ACTIVITIES').sort(function (a, b) { return compareForSort_(b.activity_at, a.activity_at); }),
    followUps: byLead('FOLLOW_UP').map(function (followUp) {
      return Object.assign(followUp, { due_state: followUpDueState_(followUp, today) });
    }).sort(function (a, b) { return compareForSort_(a.follow_up_date, b.follow_up_date); })
  };
}

function createLead_(input, user) {
  const params = objectInput_(input);
  const data = inheritCustomer_(Object.assign({ owner_user_id: user.id, status: 'NEW' },
    pickFields_(objectInput_(params.data, 'Data'), LEAD_FIELDS)));
  return withNames_(dbInsert_('LEADS', [data], userContext_(user))[0]);
}

function updateLead_(input, user) {
  const params = objectInput_(input);
  const data = inheritCustomer_(pickFields_(objectInput_(params.data, 'Data'), LEAD_FIELDS));
  return withNames_(dbUpdate_('LEADS', requireId_(params.id), data,
    userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
}

/** Pipeline move (Kanban drag or status menu). The transition is recorded in AUDIT_LOG like every update. */
function moveLead_(input, user) {
  const params = objectInput_(input);
  if (typeof params.status !== 'string' || params.status === '') {
    throw appError_(ERROR_CODE.VALIDATION, 'Status tujuan wajib diisi.', { errors: [{ field: 'status', code: 'REQUIRED', message: 'Status tujuan wajib diisi.' }] });
  }
  return withNames_(dbUpdate_('LEADS', requireId_(params.id), { status: params.status },
    userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
}

function archiveLead_(input, user) {
  return archiveRecord_('LEADS', input, user);
}

function restoreLead_(input, user) {
  return restoreRecord_('LEADS', input, user);
}

// ---------------------------------------------------------------------------------------------------------------
// Activities
// ---------------------------------------------------------------------------------------------------------------

function listActivities_(input) {
  return runListQuery_(namedRecords_('ACTIVITIES'), input, {
    searchFields: ['subject', 'description', 'customer_name', 'contact_name', 'lead_name', 'owner_name'],
    filters: {
      type: eqFilter_('type'),
      owner_user_id: eqFilter_('owner_user_id'),
      customer_id: eqFilter_('customer_id'),
      lead_id: eqFilter_('lead_id'),
      contact_id: eqFilter_('contact_id'),
      activity_at: dateRangeFilter_('activity_at')
    },
    sortFields: ['activity_at', 'type', 'subject', 'customer_name', 'owner_name', 'created_at'],
    defaultSort: { field: 'activity_at', direction: 'desc' }
  });
}

/**
 * Logs an activity. With `followUp` ({ follow_up_date, follow_up_time, purpose, priority, type }) the next follow-up is
 * created in the same unit (both or neither), linked to the activity, its customer and lead.
 */
function createActivity_(input, user) {
  const params = objectInput_(input);
  const data = inheritCustomer_(Object.assign({ owner_user_id: user.id, activity_at: nowIso_() },
    pickFields_(objectInput_(params.data, 'Data'), ACTIVITY_FIELDS)));
  requireCustomer_(data, null);
  if (!params.followUp) return withNames_(dbInsert_('ACTIVITIES', [data], userContext_(user))[0]);
  const next = pickFields_(objectInput_(params.followUp, 'Follow-up'),
    ['follow_up_date', 'follow_up_time', 'purpose', 'priority', 'type', 'notes']);
  const results = dbInsertUnit_([
    { table: 'ACTIVITIES', inputs: [data] },
    {
      table: 'FOLLOW_UP',
      inputs: function (previous) {
        const activity = previous[0][0];
        return [Object.assign({
          activity_id: activity.id, customer_id: activity.customer_id, lead_id: activity.lead_id,
          owner_user_id: activity.owner_user_id, status: 'PLANNED', type: activity.type
        }, next)];
      }
    }
  ], userContext_(user));
  return Object.assign(withNames_(results[0][0]), { follow_up: withNames_(results[1][0]) });
}

function updateActivity_(input, user) {
  const params = objectInput_(input);
  const data = inheritCustomer_(pickFields_(objectInput_(params.data, 'Data'), ACTIVITY_FIELDS));
  if ('customer_id' in data) requireCustomer_(data, null);
  return withNames_(dbUpdate_('ACTIVITIES', requireId_(params.id), data,
    userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
}

function archiveActivity_(input, user) {
  return archiveRecord_('ACTIVITIES', input, user);
}

function restoreActivity_(input, user) {
  return restoreRecord_('ACTIVITIES', input, user);
}

// ---------------------------------------------------------------------------------------------------------------
// Follow-ups
// ---------------------------------------------------------------------------------------------------------------

function followUpsWithDueState_() {
  const today = todayIso_();
  return namedRecords_('FOLLOW_UP').map(function (followUp) {
    return Object.assign(followUp, { due_state: followUpDueState_(followUp, today) });
  });
}

function followUpInBucket_(record, bucket) {
  if (bucket === 'OPEN') return OPEN_FOLLOW_UP_STATUSES.indexOf(record.status) !== -1;
  if (bucket === 'RESCHEDULE') return record.status === 'RESCHEDULE';
  return record.due_state === bucket;
}

function followUpSpec_() {
  return {
    searchFields: ['purpose', 'notes', 'result', 'customer_name', 'lead_name', 'owner_name'],
    filters: {
      bucket: function (record, value) {
        if (FOLLOW_UP_BUCKETS.indexOf(value) === -1) throw appError_(ERROR_CODE.VALIDATION, 'Kelompok follow-up tidak dikenal: ' + value + '.');
        return followUpInBucket_(record, value);
      },
      status: eqFilter_('status'),
      owner_user_id: eqFilter_('owner_user_id'),
      customer_id: eqFilter_('customer_id'),
      lead_id: eqFilter_('lead_id'),
      priority: eqFilter_('priority'),
      follow_up_date: dateRangeFilter_('follow_up_date')
    },
    sortFields: {
      follow_up_date: function (r) { return (r.follow_up_date || '') + ' ' + (r.follow_up_time || '99:99'); },
      priority: function (r) { return ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'].indexOf(r.priority); },
      customer_name: function (r) { return r.customer_name; },
      owner_name: function (r) { return r.owner_name; },
      status: function (r) { return r.status; },
      updated_at: function (r) { return r.updated_at; }
    },
    defaultSort: { field: 'follow_up_date', direction: 'asc' }
  };
}

function listFollowUps_(input) {
  return runListQuery_(followUpsWithDueState_(), input, followUpSpec_());
}

/** Number of follow-ups per bucket for the given filters (e.g. owner). */
function countFollowUps_(input) {
  const params = objectInput_(input);
  const base = queryRecords_(followUpsWithDueState_(), { filters: objectInput_(params.filters, 'Filter') }, followUpSpec_());
  const counts = {};
  FOLLOW_UP_BUCKETS.forEach(function (bucket) {
    counts[bucket] = base.filter(function (record) { return followUpInBucket_(record, bucket); }).length;
  });
  return counts;
}

function createFollowUp_(input, user) {
  const params = objectInput_(input);
  const data = inheritCustomer_(Object.assign({ owner_user_id: user.id, follow_up_date: todayIso_(), status: 'PLANNED' },
    pickFields_(objectInput_(params.data, 'Data'), FOLLOW_UP_FIELDS)));
  requireCustomer_(data, null);
  return withNames_(dbInsert_('FOLLOW_UP', [data], userContext_(user))[0]);
}

function updateFollowUp_(input, user) {
  const params = objectInput_(input);
  const data = inheritCustomer_(pickFields_(objectInput_(params.data, 'Data'), FOLLOW_UP_FIELDS));
  if ('customer_id' in data) requireCustomer_(data, null);
  return withNames_(dbUpdate_('FOLLOW_UP', requireId_(params.id), data,
    userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
}

function requireOpenFollowUp_(id) {
  const followUp = findOrThrow_('FOLLOW_UP', id);
  if (OPEN_FOLLOW_UP_STATUSES.indexOf(followUp.status) === -1) {
    throw appError_(ERROR_CODE.VALIDATION, 'Follow-up ini sudah ' + followUp.status + '; hanya follow-up terbuka yang dapat diubah statusnya.');
  }
  return followUp;
}

/**
 * DONE with its result; optionally schedules the next follow-up (same customer, lead and PIC). The next follow-up is
 * validated before the first write, so either both changes are saved or neither.
 */
function completeFollowUp_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const ctx = userContext_(user);
  return withScriptLock_(function () {
    const current = requireOpenFollowUp_(id);
    assertUnchanged_('FOLLOW_UP', current, params.expectedUpdatedAt);
    let nextInput = null;
    if (params.next) {
      nextInput = Object.assign({
        customer_id: current.customer_id, lead_id: current.lead_id, activity_id: current.activity_id,
        owner_user_id: current.owner_user_id, status: 'PLANNED', type: current.type
      }, pickFields_(objectInput_(params.next, 'Follow-up berikutnya'),
        ['follow_up_date', 'follow_up_time', 'purpose', 'priority', 'type', 'notes']));
      dbValidateInsert_('FOLLOW_UP', [nextInput], ctx);
    }
    const done = dbUpdate_('FOLLOW_UP', id, { status: 'DONE', result: params.result === undefined ? current.result : params.result }, ctx);
    const next = nextInput ? dbInsert_('FOLLOW_UP', [nextInput], ctx)[0] : null;
    return Object.assign(withNames_(done), { next: next ? withNames_(next) : null });
  });
}

/** New date (and time); status RESCHEDULE so the change stays visible. The old date is kept in AUDIT_LOG. */
function rescheduleFollowUp_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  if (!params.follow_up_date) {
    throw appError_(ERROR_CODE.VALIDATION, 'Tanggal baru wajib diisi.', { errors: [{ field: 'follow_up_date', code: 'REQUIRED', message: 'Tanggal baru wajib diisi.' }] });
  }
  return withScriptLock_(function () {
    requireOpenFollowUp_(id);
    const patch = { follow_up_date: params.follow_up_date, status: 'RESCHEDULE' };
    if (params.follow_up_time !== undefined) patch.follow_up_time = params.follow_up_time;
    if (params.notes !== undefined) patch.notes = params.notes;
    return withNames_(dbUpdate_('FOLLOW_UP', id, patch, userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
  });
}

function cancelFollowUp_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  return withScriptLock_(function () {
    requireOpenFollowUp_(id);
    const patch = { status: 'CANCELLED' };
    if (params.notes !== undefined) patch.notes = params.notes;
    return withNames_(dbUpdate_('FOLLOW_UP', id, patch, userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null })));
  });
}

function archiveFollowUp_(input, user) {
  return archiveRecord_('FOLLOW_UP', input, user);
}

function restoreFollowUp_(input, user) {
  return restoreRecord_('FOLLOW_UP', input, user);
}
