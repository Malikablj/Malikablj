/**
 * Session bootstrap for the frontend: who is signed in, what the role may do, and the reference data every screen
 * needs (enum labels, settings, form metadata derived from the schema). Also user options for PIC pickers and the
 * global search.
 */

const LOGIN_RECORD_INTERVAL_MS = 10 * 60 * 1000;

function getSession_(input, user) {
  return {
    user: user,
    permissions: permissionsFor_(user.role),
    enums: enumsForClient_(),
    settings: {
      companyName: getSetting_('COMPANY_NAME', null),
      currency: getSetting_('CURRENCY', 'IDR'),
      defaultPageSize: getSetting_('DEFAULT_PAGE_SIZE', 25),
      maxPageSize: getSetting_('MAX_PAGE_SIZE', 100)
    },
    schema: schemaForClient_(),
    app: {
      name: getConfig_().APP_NAME,
      schemaVersion: SCHEMA_VERSION,
      timeZone: getAppTimeZone_(),
      today: todayIso_(),
      now: nowIso_()
    }
  };
}

/** Sign-in: records last_login_at (at most every 10 minutes, so reloads do not write) and returns the session. */
function loginSession_(input, user) {
  const last = user.lastLoginAt ? new Date(user.lastLoginAt).getTime() : 0;
  if (!last || currentDate_().getTime() - last > LOGIN_RECORD_INTERVAL_MS) {
    const updated = dbUpdate_('USERS', user.id, { last_login_at: nowIso_() }, { actor: user.email, internal: true });
    CURRENT_USER_ = toSessionUser_(updated, user.signInMethod);
  }
  return getSession_(input, CURRENT_USER_ || user);
}

/** { ENUM_NAME: [{ value, label, active }] } in display order, from the ENUMS sheet. */
function enumsForClient_() {
  const state = getEnumState_();
  const result = {};
  Object.keys(state.byName).forEach(function (name) {
    result[name] = state.byName[name].items.map(function (item) {
      return { value: item.enum_value, label: item.label, active: item.is_active === true };
    });
  });
  return result;
}

/** Column metadata of the data tables for forms and client-side validation (the server validates again). */
function schemaForClient_() {
  const result = {};
  getSchema_().tables.forEach(function (table) {
    if (table.kind !== TABLE_KIND.DATA) return;
    result[table.name] = {
      label: table.label,
      columns: table.columns.filter(function (column) { return !column.sensitive; }).map(function (column) {
        return {
          name: column.name, type: column.type, label: column.label, required: column.required === true,
          requiredUnlessLegacy: column.requiredUnlessLegacy === true, enumName: column.enumName, ref: column.ref,
          maxLength: column.maxLength, min: column.min, minExclusive: column.minExclusive, max: column.max,
          userWritable: column.writable === WRITABLE.USER
        };
      })
    };
  });
  return result;
}

/** Active users for PIC pickers (name and role only). */
function listUserOptions_(input) {
  return optionsFrom_(loadTable_('USERS').records, input, function (user) { return user.name; },
    function (user) { return user.role; });
}

/** Quick search across the modules the caller can read: at most 5 hits per module. */
function globalSearch_(input, user) {
  const params = objectInput_(input);
  const query = searchKey_(params.query);
  if (query.length < 2) return { query: query, groups: [] };
  const terms = query.split(' ');
  const matches = function (text) {
    const key = searchKey_(text);
    return terms.every(function (term) { return key.indexOf(term) !== -1; });
  };
  const groups = [];
  const collect = function (module, key, label, tableName, textOf, titleOf, subtitleOf) {
    if (!can_(user, module, ACCESS.READ)) return;
    const hits = loadTable_(tableName).records.filter(function (record) {
      return record.is_active !== false && matches(textOf(record));
    });
    if (hits.length === 0) return;
    groups.push({
      key: key, label: label, total: hits.length,
      items: hits.slice(0, 5).map(function (record) {
        return { id: record.id, title: titleOf(record), subtitle: subtitleOf(record) };
      })
    });
  };
  const names = nameMaps_();
  collect('customers', 'customers', 'Customer', 'CUSTOMERS',
    function (r) { return [r.name, r.customer_code, r.industry, r.email, r.phone].join(' '); },
    function (r) { return r.name; }, function (r) { return r.customer_code || r.industry || null; });
  collect('contacts', 'contacts', 'Contact', 'CONTACTS',
    function (r) { return [r.name, r.email, r.phone, r.whatsapp, r.position].join(' '); },
    function (r) { return r.name; }, function (r) { return names.customer[r.customer_id] || null; });
  collect('leads', 'leads', 'Lead', 'LEADS', function (r) { return [r.name, r.product_interest].join(' '); },
    function (r) { return r.name; }, function (r) { return names.customer[r.customer_id] || null; });
  collect('purchaseOrders', 'purchaseOrders', 'Purchase order', 'PURCHASE_ORDERS',
    function (r) { return [r.po_number, r.po_number_legacy, names.customer[r.customer_id]].join(' '); },
    purchaseOrderLabel_, function (r) { return names.customer[r.customer_id] || null; });
  collect('products', 'products', 'Produk', 'PRODUCTS',
    function (r) { return [r.product_code, r.name, r.variant].join(' '); },
    productLabel_, function (r) { return r.category || null; });
  return { query: query, groups: groups };
}
