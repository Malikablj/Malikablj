/**
 * Enum definitions seeded into the ENUMS sheet, and runtime access to the enum values stored there.
 *
 * Values are UPPER_SNAKE (Technical Specification); labels follow the source workbook's ENUMS sheet where it has
 * the value. Seeding only adds missing (enum_name, enum_value) pairs: labels, order and extra values maintained by
 * an Admin are never overwritten. Seed values are system values: code depends on them, so they must stay present
 * and active (verifyDatabase checks this). Only enums marked `extensible` should receive extra values.
 */

function getEnumDefinitions_() {
  return [
    {
      name: 'USER_ROLE', description: 'Role pengguna aplikasi',
      values: [['ADMIN', 'Admin'], ['MARKETING', 'Marketing'], ['SALES', 'Sales'], ['MANAGEMENT', 'Management'],
        ['VIEWER', 'Viewer']]
    },
    {
      name: 'CUSTOMER_STATUS', description: 'Status customer',
      values: [['ACTIVE', 'Active'], ['INACTIVE', 'Inactive'], ['POTENTIAL', 'Potential'], ['DORMANT', 'Dormant']]
    },
    {
      name: 'LEAD_STATUS', description: 'Tahap pipeline lead',
      values: [['NEW', 'New'], ['CONTACTED', 'Contacted'], ['QUALIFIED', 'Qualified'], ['QUOTATION', 'Quotation'],
        ['NEGOTIATION', 'Negotiation'], ['WON', 'Won'], ['LOST', 'Lost'], ['DORMANT', 'Dormant']]
    },
    {
      name: 'PRIORITY', description: 'Prioritas lead/follow-up',
      values: [['LOW', 'Low'], ['MEDIUM', 'Medium'], ['HIGH', 'High'], ['CRITICAL', 'Critical']]
    },
    {
      name: 'ACTIVITY_TYPE', description: 'Jenis aktivitas dan follow-up', extensible: true,
      values: [['WHATSAPP', 'WhatsApp'], ['PHONE_CALL', 'Phone Call'], ['EMAIL', 'Email'], ['MEETING', 'Meeting'],
        ['VISIT', 'Visit'], ['QUOTATION', 'Quotation'], ['SAMPLE', 'Sample'], ['PRESENTATION', 'Presentation'],
        ['FOLLOW_UP', 'Follow Up'], ['COMPLAINT', 'Complaint'], ['OTHER', 'Other']]
    },
    {
      name: 'FOLLOW_UP_STATUS', description: 'Status follow-up. OVERDUE tidak disimpan: dihitung dari tanggal (D14).',
      values: [['PLANNED', 'Planned'], ['DONE', 'Done'], ['RESCHEDULE', 'Reschedule'], ['CANCELLED', 'Cancelled']]
    },
    {
      name: 'PO_STATUS', description: 'Status PO',
      values: [['OPEN', 'Open'], ['ON_PROCESS', 'On Process'], ['PARTIAL', 'Partial'],
        ['ON_HOLD', 'On Hold', 'Padanan status legacy "Hold"/"On Hold" (usulan D4, dapat diubah).'],
        ['CLOSED', 'Closed'], ['CANCELLED', 'Cancelled']]
    },
    {
      name: 'DELIVERY_STATUS', description: 'Status pengiriman dan jadwal lead time',
      values: [['SCHEDULED', 'Scheduled'], ['ON_DELIVERY', 'On Delivery'], ['DELIVERED', 'Delivered'],
        ['DELAYED', 'Delayed'], ['CANCELLED', 'Cancelled']]
    },
    {
      name: 'RETURN_STATUS', description: 'Status retur (sementara; belum ada di sumber maupun spesifikasi)',
      values: [['OPEN', 'Open'], ['CLOSED', 'Closed'], ['CANCELLED', 'Cancelled']]
    },
    {
      name: 'STOCK_TYPE', description: 'Jenis stok',
      values: [['FG', 'FG', 'Finished goods'], ['WIP', 'WIP', 'Work in progress']]
    },
    {
      name: 'STOCK_STATUS', description: 'Status stok (di workbook tercatat sebagai StockType Ready/Reserved)',
      values: [['READY', 'Ready'], ['RESERVED', 'Reserved']]
    },
    {
      name: 'PAYMENT_STATUS', description: 'Status pembayaran',
      values: [['UNPAID', 'Unpaid'], ['PARTIAL', 'Partial'], ['PAID', 'Paid']]
    },
    {
      name: 'INVOICE_TYPE', description: 'Jenis invoice dari segmen nomor invoice (arti TUM belum dikonfirmasi)',
      values: [['INV', 'INV'], ['TUM', 'TUM'], ['OTHER', 'Other']]
    },
    {
      name: 'ISSUE_SEVERITY', description: 'Tingkat isu migrasi',
      values: [['BLOCKER', 'Blocker'], ['HIGH', 'High'], ['MEDIUM', 'Medium'], ['LOW', 'Low'], ['INFO', 'Info']]
    },
    {
      name: 'RESOLUTION_STATUS', description: 'Status penyelesaian isu migrasi',
      values: [['OPEN', 'Open'], ['RESOLVED', 'Resolved'], ['ACCEPTED_AS_IS', 'Accepted as is'],
        ['EXCLUDED', 'Excluded']]
    },
    {
      name: 'AUDIT_ACTION', description: 'Jenis aksi di AUDIT_LOG',
      values: [['CREATE', 'Create'], ['UPDATE', 'Update'], ['ARCHIVE', 'Archive'], ['RESTORE', 'Restore'],
        ['DB_INIT', 'Database init'], ['SETTING_UPDATE', 'Setting update'], ['MIGRATION_RUN', 'Migration run']]
    },
    {
      name: 'SETTING_TYPE', description: 'Tipe nilai SETTINGS',
      values: [['STRING', 'String'], ['INTEGER', 'Integer'], ['NUMBER', 'Number'], ['BOOLEAN', 'Boolean'],
        ['DATE', 'Date'], ['DATETIME', 'Datetime'], ['JSON', 'JSON']]
    }
  ];
}

/** Seed rows for the ENUMS sheet, in display order. sort_order leaves gaps (10, 20, ...) for Admin additions. */
function buildEnumSeedRecords_() {
  const records = [];
  getEnumDefinitions_().forEach(function (definition) {
    definition.values.forEach(function (entry, index) {
      records.push({
        enum_name: definition.name,
        enum_value: entry[0],
        label: entry[1],
        sort_order: (index + 1) * 10,
        is_active: true,
        description: entry[2] || null
      });
    });
  });
  return records;
}

function getEnumDefinition_(name) {
  const definitions = getEnumDefinitions_();
  for (let i = 0; i < definitions.length; i++) {
    if (definitions[i].name === name) return definitions[i];
  }
  return null;
}

/**
 * Enum values stored in the ENUMS sheet, cached per execution:
 * { byName: { NAME: { all: {VALUE: true}, active: {VALUE: true}, activeList: [VALUE...], labels: {VALUE: label} } } }
 */
function getEnumState_() {
  if (DB_CACHE_.enums) return DB_CACHE_.enums;
  const byName = {};
  loadTable_('ENUMS').records.forEach(function (record) {
    if (typeof record.enum_name !== 'string' || typeof record.enum_value !== 'string') return;
    const entry = byName[record.enum_name] ||
      (byName[record.enum_name] = { all: {}, active: {}, activeList: [], labels: {}, items: [] });
    entry.all[record.enum_value] = true;
    entry.labels[record.enum_value] = record.label;
    if (record.is_active === true) entry.active[record.enum_value] = true;
    entry.items.push(record);
  });
  Object.keys(byName).forEach(function (name) {
    const entry = byName[name];
    entry.items.sort(function (a, b) {
      const orderA = typeof a.sort_order === 'number' ? a.sort_order : Number.MAX_SAFE_INTEGER;
      const orderB = typeof b.sort_order === 'number' ? b.sort_order : Number.MAX_SAFE_INTEGER;
      return orderA - orderB || (a.enum_value < b.enum_value ? -1 : a.enum_value > b.enum_value ? 1 : 0);
    });
    entry.activeList = entry.items
      .filter(function (item) { return item.is_active === true; })
      .map(function (item) { return item.enum_value; });
  });
  DB_CACHE_.enums = { byName: byName };
  return DB_CACHE_.enums;
}

/** Active values per enum name for sheet dropdowns: from the ENUMS sheet, or the seed when it cannot be read. */
function getEnumValuesForValidation_() {
  const result = {};
  try {
    const state = getEnumState_();
    Object.keys(state.byName).forEach(function (name) { result[name] = state.byName[name].activeList.slice(); });
    return result;
  } catch (error) {
    if (!isAppError_(error) || error.code !== ERROR_CODE.SCHEMA_MISMATCH) throw error;
  }
  getEnumDefinitions_().forEach(function (definition) {
    result[definition.name] = definition.values.map(function (entry) { return entry[0]; });
  });
  return result;
}
