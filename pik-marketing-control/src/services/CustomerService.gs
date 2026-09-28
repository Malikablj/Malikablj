/**
 * Customers and contacts. The customer detail is the customer workspace: contacts, leads, activities, follow-ups,
 * purchase orders with outstanding, deliveries, returns and (for roles with finance access) invoices.
 */

const CUSTOMER_FIELDS = ['customer_code', 'name', 'industry', 'address', 'phone', 'email', 'website', 'status',
  'owner_user_id', 'notes'];
const CONTACT_FIELDS = ['customer_id', 'name', 'position', 'phone', 'email', 'whatsapp', 'is_primary', 'notes'];
const WORKSPACE_LIST_LIMIT = 100;

/** Per-customer figures for lists and the workspace, computed once per execution. */
function customerStats_() {
  if (DB_CACHE_.customerStats) return DB_CACHE_.customerStats;
  const stats = {};
  const entry = function (customerId) {
    return stats[customerId] || (stats[customerId] = {
      open_leads: 0, contacts: 0, open_purchase_orders: 0, outstanding_quantity: 0, last_activity_at: null,
      next_follow_up_date: null, overdue_follow_ups: 0
    });
  };
  const today = todayIso_();
  loadTable_('CONTACTS').records.forEach(function (contact) {
    if (contact.customer_id && contact.is_active !== false) entry(contact.customer_id).contacts++;
  });
  loadTable_('LEADS').records.forEach(function (lead) {
    if (lead.customer_id && isOpenLead_(lead)) entry(lead.customer_id).open_leads++;
  });
  loadTable_('ACTIVITIES').records.forEach(function (activity) {
    if (!activity.customer_id || activity.is_active === false || !activity.activity_at) return;
    const item = entry(activity.customer_id);
    if (!item.last_activity_at || activity.activity_at > item.last_activity_at) item.last_activity_at = activity.activity_at;
  });
  loadTable_('FOLLOW_UP').records.forEach(function (followUp) {
    if (!followUp.customer_id || !isOpenFollowUp_(followUp)) return;
    const item = entry(followUp.customer_id);
    if (followUp.follow_up_date < today) item.overdue_follow_ups++;
    else if (!item.next_follow_up_date || followUp.follow_up_date < item.next_follow_up_date) {
      item.next_follow_up_date = followUp.follow_up_date;
    }
  });
  const summaries = purchaseOrderSummaries_();
  loadTable_('PURCHASE_ORDERS').records.forEach(function (po) {
    if (!po.customer_id || !isOpenPurchaseOrder_(po)) return;
    const item = entry(po.customer_id);
    item.open_purchase_orders++;
    item.outstanding_quantity = roundQty_(item.outstanding_quantity + ((summaries[po.id] || {}).outstanding_quantity || 0));
  });
  DB_CACHE_.customerStats = stats;
  return stats;
}

function customerWithStats_(customer) {
  const stats = customerStats_()[customer.id] || {};
  return Object.assign(withNames_(customer), {
    open_leads: stats.open_leads || 0,
    contact_count: stats.contacts || 0,
    open_purchase_orders: stats.open_purchase_orders || 0,
    outstanding_quantity: stats.outstanding_quantity || 0,
    last_activity_at: stats.last_activity_at || null,
    next_follow_up_date: stats.next_follow_up_date || null,
    overdue_follow_ups: stats.overdue_follow_ups || 0
  });
}

function listCustomers_(input) {
  const records = loadTable_('CUSTOMERS').records.map(customerWithStats_);
  return runListQuery_(records, input, {
    searchFields: ['name', 'customer_code', 'industry', 'email', 'phone', 'owner_name'],
    filters: {
      status: eqFilter_('status'),
      owner_user_id: eqFilter_('owner_user_id'),
      industry: eqFilter_('industry'),
      has_open_po: function (record, value) { return (record.open_purchase_orders > 0) === (value === true || value === 'true'); },
      has_overdue_follow_up: function (record, value) { return (record.overdue_follow_ups > 0) === (value === true || value === 'true'); }
    },
    sortFields: ['name', 'customer_code', 'status', 'industry', 'owner_name', 'open_leads', 'open_purchase_orders',
      'outstanding_quantity', 'last_activity_at', 'next_follow_up_date', 'created_at', 'updated_at'],
    defaultSort: { field: 'name', direction: 'asc' }
  });
}

function customerOptions_(input) {
  return optionsFrom_(loadTable_('CUSTOMERS').records, input, function (c) { return c.name; },
    function (c) { return c.customer_code || c.industry || null; });
}

/** Customer workspace. Lists are capped (newest first); totals tell the client whether more exist. */
function getCustomer_(input, user) {
  const id = requireId_(objectInput_(input).id, 'ID customer');
  const customer = findOrThrow_('CUSTOMERS', id);
  const today = todayIso_();
  const mine = function (tableName) {
    return loadTable_(tableName).records.filter(function (record) { return record.customer_id === id; });
  };
  const capped = function (records, sortField) {
    const sorted = records.slice().sort(function (a, b) { return compareForSort_(b[sortField], a[sortField]); });
    return { items: sorted.slice(0, WORKSPACE_LIST_LIMIT), total: sorted.length };
  };
  const purchaseOrders = mine('PURCHASE_ORDERS');
  const poIds = idSet_(purchaseOrders.map(function (po) { return po.id; }));
  const byPo = function (tableName) {
    return loadTable_(tableName).records.filter(function (record) { return record.purchase_order_id && poIds[record.purchase_order_id]; });
  };
  const workspace = {
    customer: customerWithStats_(customer),
    contacts: mine('CONTACTS').map(withNames_).sort(function (a, b) {
      return Number(b.is_primary === true) - Number(a.is_primary === true) || compareForSort_(a.name, b.name);
    }),
    leads: capped(mine('LEADS').map(withNames_), 'updated_at'),
    activities: capped(mine('ACTIVITIES').map(withNames_), 'activity_at'),
    followUps: capped(mine('FOLLOW_UP').map(function (followUp) {
      return Object.assign(withNames_(followUp), { due_state: followUpDueState_(followUp, today) });
    }), 'follow_up_date'),
    purchaseOrders: capped(purchaseOrders.map(purchaseOrderWithSummary_), 'po_date'),
    deliveries: capped(byPo('DELIVERIES').map(deliveryForList_), 'delivery_date'),
    returns: capped(byPo('RETURNS').map(returnForList_), 'return_date'),
    invoices: null
  };
  if (can_(user, 'finance', ACCESS.READ)) workspace.invoices = capped(byPo('INVOICES_PAYMENTS').map(invoiceForList_), 'invoice_date');
  return workspace;
}

function createCustomer_(input, user) {
  return customerWithStats_(createRecord_('CUSTOMERS', input, CUSTOMER_FIELDS, user, { owner_user_id: user.id }));
}

function updateCustomer_(input, user) {
  return customerWithStats_(updateRecord_('CUSTOMERS', input, CUSTOMER_FIELDS, user));
}

function archiveCustomer_(input, user) {
  return archiveRecord_('CUSTOMERS', input, user);
}

function restoreCustomer_(input, user) {
  return restoreRecord_('CUSTOMERS', input, user);
}

// ---------------------------------------------------------------------------------------------------------------
// Contacts
// ---------------------------------------------------------------------------------------------------------------

function listContacts_(input) {
  return runListQuery_(namedRecords_('CONTACTS'), input, {
    searchFields: ['name', 'position', 'email', 'phone', 'whatsapp', 'customer_name'],
    filters: { customer_id: eqFilter_('customer_id'), is_primary: boolFilter_('is_primary') },
    sortFields: ['name', 'customer_name', 'position', 'is_primary', 'created_at', 'updated_at'],
    defaultSort: { field: 'name', direction: 'asc' }
  });
}

function contactOptions_(input) {
  const params = objectInput_(input);
  const records = loadTable_('CONTACTS').records.filter(function (contact) {
    return !params.customer_id || contact.customer_id === params.customer_id;
  });
  return optionsFrom_(records, params, function (c) { return c.name; }, function (c) { return c.position || null; });
}

/**
 * One active primary contact per customer (unique constraint). Marking a contact primary demotes the customer's current
 * primary in the same locked change; the new or edited contact is validated before anything is written, so a rejected
 * save leaves the old primary untouched and no request ever sees two primaries.
 */
function createContact_(input, user) {
  const data = pickFields_(objectInput_(objectInput_(input).data, 'Data'), CONTACT_FIELDS);
  const ctx = userContext_(user);
  return withScriptLock_(function () {
    if (isTrueInput_(data.is_primary) && data.customer_id) {
      dbValidateInsert_('CONTACTS', [Object.assign({}, data, { is_primary: false })], ctx);
      const demote = otherPrimaryContacts_(data.customer_id, null).map(function (contact) {
        return { id: contact.id, patch: { is_primary: false } };
      });
      if (demote.length) dbUpdateMany_('CONTACTS', demote, ctx);
    }
    return withNames_(dbInsert_('CONTACTS', [data], ctx)[0]);
  });
}

/** Edits a contact; with is_primary = true the other primary of the customer is demoted in the same batch write. */
function updateContact_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const data = pickFields_(objectInput_(params.data, 'Data'), CONTACT_FIELDS);
  return withScriptLock_(function () {
    const current = findOrThrow_('CONTACTS', id);
    assertUnchanged_('CONTACTS', current, params.expectedUpdatedAt);
    const updates = [];
    if (isTrueInput_(data.is_primary) && current.is_active !== false) {
      otherPrimaryContacts_(data.customer_id || current.customer_id, id).forEach(function (contact) {
        updates.push({ id: contact.id, patch: { is_primary: false } });
      });
    }
    updates.push({ id: id, patch: data });
    const saved = dbUpdateMany_('CONTACTS', updates, userContext_(user));
    return withNames_(saved[saved.length - 1]);
  });
}

function setPrimaryContact_(input, user) {
  const params = objectInput_(input);
  return updateContact_({ id: params.id, data: { is_primary: true }, expectedUpdatedAt: params.expectedUpdatedAt }, user);
}

function otherPrimaryContacts_(customerId, exceptId) {
  return loadTable_('CONTACTS').records.filter(function (contact) {
    return contact.customer_id === customerId && contact.is_primary === true && contact.is_active !== false && contact.id !== exceptId;
  });
}

function isTrueInput_(value) {
  return value === true || (typeof value === 'string' && value.trim().toLowerCase() === 'true');
}

function archiveContact_(input, user) {
  return archiveRecord_('CONTACTS', input, user);
}

function restoreContact_(input, user) {
  return restoreRecord_('CONTACTS', input, user);
}
