/**
 * Shared building blocks for the module services: list queries, input whitelisting, display names, options for pickers.
 *
 * Lists work on records already read from the sheet (one getValues per table per execution, see Repository.gs):
 * filter → search → sort → page, all on the server so the client never loads a whole table.
 *   params (client): { search, filters: { key: value }, sort: { field, direction }, page (1-based), pageSize,
 *                      includeInactive }
 *   spec (service):  { searchFields: [...] | function (record) -> text, filters: { key: function (record, value) },
 *                      sortFields: { key: function (record) -> value } | [field...], defaultSort: { field, direction } }
 * Unknown filter or sort keys are rejected instead of ignored, so a client bug never silently shows wrong data.
 */

/** Plain object input or {} (null/undefined). Arrays and primitives are rejected. */
function objectInput_(value, label) {
  if (value === null || value === undefined) return {};
  if (typeof value !== 'object' || Array.isArray(value)) {
    throw appError_(ERROR_CODE.VALIDATION, (label || 'Data') + ' tidak valid.');
  }
  return value;
}

function requireId_(value, label) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw appError_(ERROR_CODE.VALIDATION, (label || 'ID') + ' wajib diisi.', { errors: [{ field: 'id', code: 'REQUIRED', message: 'ID wajib diisi.' }] });
  }
  return value.trim();
}

/** Only the listed fields of `input` (fields the form may change); anything else is ignored. */
function pickFields_(input, fields) {
  const picked = {};
  fields.forEach(function (field) {
    if (Object.prototype.hasOwnProperty.call(input, field)) picked[field] = input[field];
  });
  return picked;
}

/**
 * Optimistic concurrency for changes that write more than one record: the client sends the updated_at it saw, and the
 * change is refused when the record has been changed since (single-record updates get this from dbUpdate_).
 */
function assertUnchanged_(tableName, current, expectedUpdatedAt) {
  if (expectedUpdatedAt && current.updated_at !== expectedUpdatedAt) {
    throw appError_(ERROR_CODE.CONFLICT, 'Data ' + getTableDef_(tableName).label + ' telah diubah pengguna lain. Muat ulang lalu coba lagi.',
      { id: current.id, updatedAt: current.updated_at });
  }
}

/**
 * A number typed by the client, read the way the repository stores it (Repository.gs parseNumericInput_), so checks that
 * run before the write (quantity left, payment status) see the value that will be saved. Anything else is returned as
 * is and rejected by validation.
 */
function numericInput_(value) {
  return typeof value === 'string' && value.trim() !== '' ? parseNumericInput_(value.trim()) : value;
}

/** The record or NOT_FOUND. Archived records are returned too (callers decide what archived means). */
function findOrThrow_(tableName, id) {
  const record = loadTable_(tableName).byId[id];
  if (!record) throw appError_(ERROR_CODE.NOT_FOUND, getTableDef_(tableName).label + ' ' + id + ' tidak ditemukan.');
  return record;
}

function eqFilter_(field) {
  return function (record, value) {
    if (Array.isArray(value)) return value.indexOf(record[field]) !== -1;
    return record[field] === value;
  };
}

/** value: { from, to } (inclusive, 'yyyy-MM-dd'); datetime fields compare on their date in the application time zone. */
function dateRangeFilter_(field) {
  return function (record, value) {
    const range = objectInput_(value, 'Rentang tanggal');
    ['from', 'to'].forEach(function (key) {
      if (range[key] && !isValidIsoDate_(range[key])) throw appError_(ERROR_CODE.VALIDATION, 'Rentang tanggal tidak valid.');
    });
    const day = localDateOf_(record[field]);
    if (!day) return false;
    if (range.from && day < range.from) return false;
    if (range.to && day > range.to) return false;
    return true;
  };
}

function boolFilter_(field) {
  return function (record, value) { return (record[field] === true) === (value === true || value === 'true'); };
}

function compareForSort_(a, b) {
  const aEmpty = a === null || a === undefined || a === '';
  const bEmpty = b === null || b === undefined || b === '';
  if (aEmpty || bEmpty) return aEmpty === bEmpty ? 0 : aEmpty ? 1 : -1;
  if (typeof a === 'number' && typeof b === 'number') return a - b;
  return String(a).localeCompare(String(b), 'id', { numeric: true, sensitivity: 'base' });
}

function pageSizeFrom_(value) {
  const max = getSetting_('MAX_PAGE_SIZE', 100);
  const fallback = getSetting_('DEFAULT_PAGE_SIZE', 25);
  const size = Number(value);
  if (!size || size < 1) return Math.min(fallback, max);
  return Math.min(Math.floor(size), max);
}

/** Filter, search, sort and page. Returns { items, total, page, pageSize, pageCount }. */
function runListQuery_(records, params, spec) {
  const input = objectInput_(params, 'Parameter daftar');
  const result = queryRecords_(records, input, spec);
  const pageSize = pageSizeFrom_(input.pageSize);
  const pageCount = Math.max(1, Math.ceil(result.length / pageSize));
  const page = Math.min(Math.max(1, Math.floor(Number(input.page) || 1)), pageCount);
  return {
    items: result.slice((page - 1) * pageSize, page * pageSize),
    total: result.length,
    page: page,
    pageSize: pageSize,
    pageCount: pageCount
  };
}

/** Filter, search and sort without paging (for aggregates such as the lead board or follow-up counts). */
function queryRecords_(records, params, spec) {
  const input = objectInput_(params, 'Parameter daftar');
  const filters = objectInput_(input.filters, 'Filter');
  const filterSpecs = spec.filters || {};
  Object.keys(filters).forEach(function (key) {
    if (!filterSpecs[key]) throw appError_(ERROR_CODE.VALIDATION, 'Filter tidak dikenal: ' + key + '.');
  });
  let result = records;
  if (input.includeInactive !== true) result = result.filter(function (record) { return record.is_active !== false; });
  Object.keys(filters).forEach(function (key) {
    const value = filters[key];
    if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) return;
    result = result.filter(function (record) { return filterSpecs[key](record, value); });
  });
  const search = searchKey_(input.search);
  if (search) {
    const terms = search.split(' ');
    result = result.filter(function (record) {
      const text = searchTextOf_(record, spec.searchFields);
      return terms.every(function (term) { return text.indexOf(term) !== -1; });
    });
  }
  const sort = objectInput_(input.sort, 'Urutan');
  const sortField = sort.field || (spec.defaultSort && spec.defaultSort.field);
  if (sortField) {
    const getter = sortGetter_(spec, sortField);
    const direction = (sort.field ? sort.direction : spec.defaultSort && spec.defaultSort.direction) === 'desc' ? -1 : 1;
    // Empty values stay at the end in both directions (undated legacy rows must not lead a "newest first" list).
    result = result.slice().sort(function (a, b) {
      const valueA = getter(a);
      const valueB = getter(b);
      const emptyA = valueA === null || valueA === undefined || valueA === '';
      const emptyB = valueB === null || valueB === undefined || valueB === '';
      if (emptyA || emptyB) return emptyA === emptyB ? 0 : emptyA ? 1 : -1;
      return compareForSort_(valueA, valueB) * direction;
    });
  }
  return result;
}

function searchTextOf_(record, searchFields) {
  if (typeof searchFields === 'function') return searchKey_(searchFields(record));
  return searchKey_((searchFields || []).map(function (field) { return record[field]; })
    .filter(function (value) { return value !== null && value !== undefined; }).join(' '));
}

function sortGetter_(spec, field) {
  const sortFields = spec.sortFields || [];
  if (Array.isArray(sortFields)) {
    if (sortFields.indexOf(field) === -1) throw appError_(ERROR_CODE.VALIDATION, 'Urutan tidak dikenal: ' + field + '.');
    return function (record) { return record[field]; };
  }
  if (!sortFields[field]) throw appError_(ERROR_CODE.VALIDATION, 'Urutan tidak dikenal: ' + field + '.');
  return sortFields[field];
}

// ---------------------------------------------------------------------------------------------------------------
// Display names (joins) — built once per execution from the cached tables
// ---------------------------------------------------------------------------------------------------------------

function nameMaps_() {
  if (DB_CACHE_.names) return DB_CACHE_.names;
  const byId = function (tableName, label) {
    const map = {};
    loadTable_(tableName).records.forEach(function (record) { if (record.id) map[record.id] = label(record); });
    return map;
  };
  DB_CACHE_.names = {
    customer: byId('CUSTOMERS', function (r) { return r.name; }),
    contact: byId('CONTACTS', function (r) { return r.name; }),
    user: byId('USERS', function (r) { return r.name; }),
    product: byId('PRODUCTS', productLabel_),
    lead: byId('LEADS', function (r) { return r.name; }),
    purchaseOrder: byId('PURCHASE_ORDERS', purchaseOrderLabel_)
  };
  return DB_CACHE_.names;
}

function productLabel_(product) {
  const code = product.product_code ? product.product_code + ' ' : '';
  return code + product.name + (product.variant ? ' — ' + product.variant : '');
}

function purchaseOrderLabel_(po) {
  return po.po_number || po.po_number_legacy || '(tanpa nomor) ' + po.id;
}

/** Copy of the record with display names for its references (customer_name, product_label, owner_name, ...). */
function withNames_(record) {
  const names = nameMaps_();
  const copy = Object.assign({}, record);
  if ('customer_id' in record) copy.customer_name = record.customer_id ? names.customer[record.customer_id] || null : null;
  if ('contact_id' in record) copy.contact_name = record.contact_id ? names.contact[record.contact_id] || null : null;
  if ('product_id' in record) copy.product_label = record.product_id ? names.product[record.product_id] || null : null;
  if ('lead_id' in record) copy.lead_name = record.lead_id ? names.lead[record.lead_id] || null : null;
  if ('owner_user_id' in record) copy.owner_name = record.owner_user_id ? names.user[record.owner_user_id] || null : null;
  if ('purchase_order_id' in record) {
    copy.po_label = record.purchase_order_id ? names.purchaseOrder[record.purchase_order_id] || null : null;
  }
  return copy;
}

/** Records of a table in list form: active and archived, with display names. */
function namedRecords_(tableName) {
  return loadTable_(tableName).records.map(withNames_);
}

/** Options for pickers: { id, label, sublabel } matching the search, active records only, at most `limit`. */
function optionsFrom_(records, input, labelOf, sublabelOf) {
  const params = objectInput_(input, 'Parameter');
  const search = searchKey_(params.search);
  const limit = Math.min(Math.max(1, Number(params.limit) || 20), 50);
  const include = params.include ? String(params.include) : null;
  const result = [];
  records.forEach(function (record) {
    if (record.is_active === false && record.id !== include) return;
    const label = labelOf(record);
    const sublabel = sublabelOf ? sublabelOf(record) : null;
    if (search && searchKey_(label + ' ' + (sublabel || '') + ' ' + record.id).indexOf(search) === -1) return;
    result.push({ id: record.id, label: label, sublabel: sublabel || null });
  });
  result.sort(function (a, b) { return compareForSort_(a.label, b.label); });
  const items = result.slice(0, limit);
  if (include && !items.some(function (item) { return item.id === include; })) {
    const selected = result.filter(function (item) { return item.id === include; })[0];
    if (selected) items.unshift(selected);
  }
  return { items: items, total: result.length };
}

/** Standard archive/restore handlers for a table. */
function archiveRecord_(tableName, input, user) {
  const params = objectInput_(input);
  return withNames_(setRecordActive_(tableName, requireId_(params.id), false, userContext_(user)));
}

function restoreRecord_(tableName, input, user) {
  const params = objectInput_(input);
  return withNames_(setRecordActive_(tableName, requireId_(params.id), true, userContext_(user)));
}

/** Update with optimistic concurrency: the client sends the updated_at it saw. */
function updateRecord_(tableName, input, fields, user, extraPatch) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  const patch = Object.assign(pickFields_(objectInput_(params.data, 'Data'), fields), extraPatch || {});
  const ctx = userContext_(user, { expectedUpdatedAt: params.expectedUpdatedAt || null });
  return withNames_(dbUpdate_(tableName, id, patch, ctx));
}

function createRecord_(tableName, input, fields, user, defaults) {
  const params = objectInput_(input);
  const data = Object.assign({}, defaults || {}, pickFields_(objectInput_(params.data, 'Data'), fields));
  return withNames_(dbInsert_(tableName, [data], userContext_(user))[0]);
}
