/**
 * Database test cases (Phase 02), shared by the Node emulator runner and runDatabaseSelfTest().
 * Names start with a group used as filter: schema, init, verify, id, relasi, validasi, data, akses.
 * All fixtures are synthetic; no business data is used.
 */

function getDatabaseTestCases_() {
  return [
    { name: 'schema: 21 sheet dengan nama, urutan, dan prefiks ID final', run: testSchemaSheets_ },
    { name: 'schema: tata letak kolom standar dan tipe data', run: testSchemaColumnLayout_ },
    { name: 'schema: relasi, enum, default, dan setting saling konsisten', run: testSchemaReferences_ },
    { name: 'init: membuat seluruh sheet, header, seed, format, validasi, proteksi, audit', run: testInitCreatesDatabase_ },
    { name: 'init: database baru lolos verifyDatabase', run: testVerifyCleanDatabase_ },
    { name: 'init: dijalankan ulang tanpa perubahan (idempoten)', run: testInitIdempotent_ },
    { name: 'init: header berbeda membatalkan tanpa perubahan', run: testInitAbortsOnHeaderMismatch_ },
    { name: 'init: kolom baru ditambahkan di akhir tanpa menyentuh data', run: testInitAppendsColumns_ },
    { name: 'init: sheet kosong diberi header; data tanpa header ditolak', run: testInitEmptyAndHeaderlessSheets_ },
    { name: 'init: label enum, nilai tambahan, dan nilai setting tidak ditimpa', run: testInitPreservesCustomizations_ },
    { name: 'init: SCHEMA_VERSION dinaikkan; versi database lebih baru ditolak', run: testInitSchemaVersion_ },
    { name: 'init: upgrade skema v1 menambahkan kolom v2 di akhir tanpa menyentuh data', run: testInitUpgradeFromV1_ },
    { name: 'verify: mendeteksi ID ganda, relasi yatim, enum, tipe, kunci turunan, sheet hilang', run: testVerifyDetectsProblems_ },
    { name: 'id: format PREFIX-10HEX dan unik', run: testIdFormatAndUniqueness_ },
    { name: 'id: ID yang bentrok dengan ID tersimpan tidak dipakai', run: testIdCollisionRetry_ },
    { name: 'id: setiap tabel memberi ID berprefiks sendiri; ID tetap setelah update', run: testIdsAcrossTables_ },
    { name: 'relasi: foreign key harus ada, berformat benar, dan aktif', run: testForeignKeys_ },
    { name: 'relasi: contact/lead/baris PO/PO harus konsisten', run: testReferenceConsistency_ },
    { name: 'relasi: data legacy hanya lewat konteks migrasi', run: testLegacyRules_ },
    { name: 'relasi: nomor PO unik per customer kecuali CANCELLED', run: testPoNumberUniqueness_ },
    { name: 'relasi: nomor PO ganda antar-data legacy diterima, PO baru tetap ditolak', run: testLegacyDuplicatePoNumbers_ },
    { name: 'relasi: satu contact utama aktif per customer', run: testPrimaryContact_ },
    { name: 'validasi: wajib isi, tipe, format, panjang, formula', run: testValidationTypes_ },
    { name: 'validasi: enum, angka, dan aturan tabel', run: testValidationEnumsNumbersRules_ },
    { name: 'validasi: kolom sistem, migrasi, internal, dan tak dikenal ditolak', run: testValidationFieldAccess_ },
    { name: 'validasi: batch atomik (semua atau tidak sama sekali)', run: testBatchAtomic_ },
    { name: 'data: teks tetap teks (nol di depan, mirip tanggal/angka)', run: testTextRoundTrip_ },
    { name: 'data: update, konflik versi, arsip, pulihkan, daftar, audit log', run: testUpdateArchiveAudit_ },
    { name: 'data: update batch atomik dengan audit per record', run: testUpdateMany_ },
    { name: 'data: tabel read-only, isu migrasi, dan tabel sistem', run: testReadOnlyTables_ },
    { name: 'data: SETTINGS dibaca sesuai tipe dan dilindungi', run: testSettings_ },
    { name: 'data: kolom *_legacy menyimpan teks asli apa adanya', run: testLegacyRawText_ },
    { name: 'data: audit ringkas MIGRATION_RUN untuk batch migrasi', run: testMigrationAuditSummary_ },
    { name: 'akses: pemilik skrip boleh menjalankan pemeliharaan', run: testMaintenanceAccess_ },
    { name: 'verify: seluruh data hasil uji lolos verifyDatabase', run: testVerifyAfterData_ }
  ];
}

// ---------------------------------------------------------------------------------------------------------------
// Fixtures (synthetic data only)
// ---------------------------------------------------------------------------------------------------------------

function testCtx_(extra) {
  return Object.assign({ actor: SELF_TEST_ACTOR }, extra || {});
}

function migrationCtx_(extra) {
  return Object.assign({ actor: 'system:migration-test', migration: true }, extra || {});
}

function uniqueTag_() {
  return randomHex_(6);
}

function insertOne_(tableName, record, ctx) {
  return dbInsert_(tableName, [record], ctx || testCtx_())[0];
}

function makeUser_(overrides) {
  const tag = uniqueTag_();
  return insertOne_('USERS', Object.assign({
    name: 'User Uji ' + tag, email: 'user.' + tag.toLowerCase() + '@example.com', role: 'MARKETING'
  }, overrides || {}));
}

function makeCustomer_(overrides) {
  return insertOne_('CUSTOMERS', Object.assign({ name: 'Customer Uji ' + uniqueTag_(), status: 'ACTIVE' }, overrides || {}));
}

function makeContact_(customerId, overrides) {
  return insertOne_('CONTACTS', Object.assign({ customer_id: customerId, name: 'Contact Uji ' + uniqueTag_() }, overrides || {}));
}

function makeProduct_(overrides) {
  const tag = uniqueTag_();
  return insertOne_('PRODUCTS', Object.assign({ name: 'Produk Uji ' + tag, product_code: '[UJI-' + tag + ']' }, overrides || {}));
}

function makePurchaseOrder_(customerId, overrides) {
  return insertOne_('PURCHASE_ORDERS', Object.assign({
    customer_id: customerId, po_number: 'UJI/PO/' + uniqueTag_(), po_date: '2026-09-01'
  }, overrides || {}));
}

function makePoLine_(purchaseOrderId, productId, overrides) {
  return insertOne_('PO_LINES', Object.assign({
    purchase_order_id: purchaseOrderId, product_id: productId, order_quantity: 1000
  }, overrides || {}));
}

function auditEntries_(entityId) {
  resetDbCache_();
  return loadTable_('AUDIT_LOG').records.filter(function (record) { return record.entity_id === entityId; });
}

function findIndexWhere_(records, predicate) {
  for (let i = 0; i < records.length; i++) {
    if (predicate(records[i])) return i;
  }
  throw new Error('Record uji tidak ditemukan.');
}

/** Writes rows straight into a sheet, bypassing validation (to simulate manual edits). */
function writeRawRecords_(tableName, partials) {
  const table = getTableDef_(tableName);
  const now = nowIso_();
  const rows = partials.map(function (partial) {
    const record = {};
    table.columns.forEach(function (column) {
      const value = partial[column.name];
      record[column.name] = value !== undefined ? value : column.defaultValue !== undefined ? column.defaultValue : null;
    });
    table.columns.forEach(function (column) {
      if (column.derive && partial[column.name] === undefined) record[column.name] = column.derive(record);
    });
    if (table.hasAudit) {
      ['created_at', 'updated_at'].forEach(function (name) { if (partial[name] === undefined) record[name] = now; });
      ['created_by', 'updated_by'].forEach(function (name) { if (partial[name] === undefined) record[name] = SELF_TEST_ACTOR; });
    }
    return recordToRow_(table, record);
  });
  appendRowsToSheet_(getCheckedSheet_(table), table, rows);
  resetDbCache_();
}

/** Deactivates an enum value in the ENUMS sheet while `callback` runs. */
function withEnumInactive_(enumName, value, callback) {
  resetDbCache_();
  const state = loadTable_('ENUMS');
  const index = findIndexWhere_(state.records, function (r) { return r.enum_name === enumName && r.enum_value === value; });
  const cell = state.sheet.getRange(state.rowNumbers[index], state.table.columnNames.indexOf('is_active') + 1);
  cell.setValue(false);
  resetDbCache_();
  try {
    return callback();
  } finally {
    cell.setValue(true);
    resetDbCache_();
  }
}

/** @param {GoogleAppsScript.Spreadsheet.Spreadsheet} spreadsheet */
function snapshotSpreadsheet_(spreadsheet) {
  const sheets = spreadsheet.getSheets().map(function (sheet) {
    return {
      name: sheet.getName(),
      maxRows: sheet.getMaxRows(),
      maxColumns: sheet.getMaxColumns(),
      frozenRows: sheet.getFrozenRows(),
      values: sheet.getLastRow() > 0 ? sheet.getRange(1, 1, sheet.getLastRow(), sheet.getLastColumn()).getValues() : []
    };
  });
  return {
    sheets: sheets,
    protections: [SpreadsheetApp.ProtectionType.SHEET, SpreadsheetApp.ProtectionType.RANGE].map(function (type) {
      return spreadsheet.getProtections(type).map(function (protection) { return protection.getDescription(); }).sort();
    })
  };
}

// ---------------------------------------------------------------------------------------------------------------
// schema
// ---------------------------------------------------------------------------------------------------------------

function testSchemaSheets_() {
  assertDeepEqual_(getTableNames_(), ['README', 'USERS', 'CUSTOMERS', 'CONTACTS', 'PRODUCTS', 'LEADS', 'ACTIVITIES',
    'FOLLOW_UP', 'PURCHASE_ORDERS', 'PO_LINES', 'DELIVERIES', 'RETURNS', 'STOCK', 'LEADTIME', 'INBOUND_MAKLON',
    'INVOICES_PAYMENTS', 'PO_FINANCIALS', 'MIGRATION_ISSUES', 'ENUMS', 'SETTINGS', 'AUDIT_LOG'], 'daftar sheet');
  const prefixes = {};
  getSchema_().tables.forEach(function (table) {
    table.idPrefixes.forEach(function (prefix) {
      assertTrue_(!prefixes[prefix], 'prefiks ' + prefix + ' hanya dipakai satu tabel');
      prefixes[prefix] = table.name;
    });
  });
  assertDeepEqual_(prefixes, {
    USR: 'USERS', CUS: 'CUSTOMERS', CON: 'CONTACTS', PRD: 'PRODUCTS', LED: 'LEADS', ACT: 'ACTIVITIES', FUP: 'FOLLOW_UP',
    PO: 'PURCHASE_ORDERS', POL: 'PO_LINES', DEL: 'DELIVERIES', RET: 'RETURNS', STK: 'STOCK', LT: 'LEADTIME',
    INB: 'INBOUND_MAKLON', PAY: 'INVOICES_PAYMENTS', POF: 'PO_FINANCIALS', MIG: 'MIGRATION_ISSUES',
    PRF: 'MIGRATION_ISSUES', AUD: 'AUDIT_LOG'
  }, 'prefiks ID per tabel');
}

function testSchemaColumnLayout_() {
  const types = Object.keys(COLUMN_TYPES);
  const writables = Object.keys(WRITABLE).map(function (key) { return WRITABLE[key]; });
  const lineage = ['is_legacy', 'source_file', 'source_sheet', 'legacy_row', 'import_ref', 'migrated_at', 'migration_hash'];
  getSchema_().tables.forEach(function (table) {
    const names = table.columnNames;
    const seen = {};
    table.columns.forEach(function (column) {
      const where = table.name + '.' + column.name;
      assertMatch_(column.name, /^[a-z][a-z0-9_]*$/, 'snake_case ' + where);
      assertTrue_(!seen[column.name], 'kolom unik ' + where);
      seen[column.name] = true;
      assertTrue_(types.indexOf(column.type) !== -1, 'tipe ' + where);
      assertTrue_(writables.indexOf(column.writable) !== -1, 'writable ' + where);
      assertTrue_(typeof column.label === 'string' && column.label !== '', 'label ' + where);
      if (column.type === 'ref') assertTrue_(Boolean(column.ref) && /_id$/.test(column.name), 'relasi ' + where);
      if (column.type === 'enum') assertTrue_(Boolean(column.enumName), 'enum ' + where);
      if (column.derive) assertEqual_(column.writable, WRITABLE.AUTO, 'kolom turunan diisi sistem ' + where);
    });
    if (table.hasId) assertEqual_(names[0], 'id', 'kolom pertama ' + table.name);
    let lastSince = 1;
    table.columns.forEach(function (column) {
      assertTrue_(column.since >= lastSince && column.since <= SCHEMA_VERSION, 'urutan versi kolom ' + table.name + '.' + column.name);
      lastSince = column.since;
    });
    const initialNames = table.columns.filter(function (c) { return c.since === 1; }).map(function (c) { return c.name; });
    if (table.hasAudit) {
      assertDeepEqual_(initialNames.slice(-4), ['created_at', 'created_by', 'updated_at', 'updated_by'], 'kolom audit ' + table.name);
    }
    if (table.softDelete) assertTrue_(names.indexOf('is_active') !== -1, 'is_active ' + table.name);
    if (table.lineage) {
      const start = names.indexOf('is_legacy');
      assertDeepEqual_(names.slice(start, start + lineage.length), lineage, 'lineage ' + table.name);
      assertEqual_(names[start - 1], 'is_active', 'is_active sebelum lineage ' + table.name);
    }
  });
}

function testSchemaReferences_() {
  const schema = getSchema_();
  const enums = {};
  getEnumDefinitions_().forEach(function (definition) {
    assertMatch_(definition.name, UPPER_SNAKE_PATTERN, 'nama enum');
    assertTrue_(!enums[definition.name], 'enum unik ' + definition.name);
    enums[definition.name] = { used: false, values: {} };
    definition.values.forEach(function (value) {
      assertMatch_(value[0], UPPER_SNAKE_PATTERN, 'nilai ' + definition.name);
      assertTrue_(!enums[definition.name].values[value[0]], 'nilai unik ' + definition.name + '.' + value[0]);
      assertTrue_(typeof value[1] === 'string' && value[1] !== '', 'label ' + definition.name + '.' + value[0]);
      enums[definition.name].values[value[0]] = true;
    });
  });
  schema.tables.forEach(function (table) {
    table.columns.forEach(function (column) {
      const where = table.name + '.' + column.name;
      if (column.ref) assertTrue_(Boolean(schema.byName[column.ref]) && schema.byName[column.ref].hasId, 'target relasi ' + where);
      if (column.enumName) {
        assertTrue_(Boolean(enums[column.enumName]), 'enum terdefinisi ' + where);
        enums[column.enumName].used = true;
        if (column.defaultValue !== undefined) {
          assertTrue_(enums[column.enumName].values[column.defaultValue] === true, 'default enum ' + where);
        }
      }
      if (column.type === 'boolean' && column.defaultValue !== undefined) {
        assertEqual_(typeof column.defaultValue, 'boolean', 'default boolean ' + where);
      }
    });
    table.unique.forEach(function (constraint) {
      constraint.columns.forEach(function (name) { assertTrue_(Boolean(table.columnByName[name]), 'unique ' + table.name + '.' + name); });
    });
    table.consistency.forEach(function (rule) {
      const refColumn = table.columnByName[rule.field];
      assertTrue_(Boolean(refColumn) && refColumn.ref === rule.ref, 'konsistensi ' + table.name + '.' + rule.field);
      rule.pairs.forEach(function (pair) {
        assertTrue_(Boolean(table.columnByName[pair[0]]), 'konsistensi ' + table.name + '.' + pair[0]);
        assertTrue_(Boolean(schema.byName[rule.ref].columnByName[pair[1]]), 'konsistensi ' + rule.ref + '.' + pair[1]);
      });
    });
  });
  Object.keys(enums).forEach(function (name) { assertTrue_(enums[name].used, 'enum ' + name + ' dipakai skema'); });
  const keys = {};
  getSettingDefinitions_().forEach(function (definition) {
    assertMatch_(definition.key, UPPER_SNAKE_PATTERN, 'key setting');
    assertTrue_(!keys[definition.key], 'key setting unik ' + definition.key);
    keys[definition.key] = true;
    assertTrue_(enums.SETTING_TYPE.values[definition.type] === true, 'tipe setting ' + definition.key);
    if (definition.value !== null) {
      assertTrue_(parseSettingValue_(definition.value, definition.type).ok, 'nilai setting ' + definition.key);
    }
  });
}

// ---------------------------------------------------------------------------------------------------------------
// init
// ---------------------------------------------------------------------------------------------------------------

function testInitCreatesDatabase_(t) {
  const shared = t.shared();
  const spreadsheet = shared.spreadsheet;
  const report = shared.initReport;
  const names = getTableNames_();
  assertTrue_(report.changed, 'init pertama mengubah database');
  assertDeepEqual_(report.createdSheets, names, 'sheet yang dibuat');
  assertEqual_(report.removedDefaultSheets.length, 1, 'sheet bawaan dihapus');
  assertDeepEqual_(spreadsheet.getSheets().map(function (sheet) { return sheet.getName(); }), names, 'urutan sheet');
  assertEqual_(spreadsheet.getSpreadsheetTimeZone(), getConfig_().TIMEZONE, 'zona waktu spreadsheet');
  t.onShared(function () {
    const enumValues = getEnumValuesForValidation_();
    const protections = indexDatabaseProtections_(spreadsheet);
    getSchema_().tables.forEach(function (table) {
      const sheet = spreadsheet.getSheetByName(table.name);
      const width = table.columns.length;
      assertDeepEqual_(sheet.getRange(1, 1, 1, width).getValues()[0], table.columnNames, 'header ' + table.name);
      assertEqual_(sheet.getMaxColumns(), width, 'jumlah kolom ' + table.name);
      assertEqual_(sheet.getFrozenRows(), 1, 'header dibekukan ' + table.name);
      assertEqual_(sheet.getRange(1, 1).getFontWeight(), 'bold', 'header tebal ' + table.name);
      assertDeepEqual_(sheet.getRange(1, 1, 1, width).getNotes(), buildHeaderNotes_(table), 'catatan header ' + table.name);
      assertTrue_(numberFormatsMatch_(sheet, table, 2, sheet.getMaxRows() - 1), 'format kolom ' + table.name);
      const rules = sheet.getRange(2, 1, 1, width).getDataValidations()[0];
      table.columns.forEach(function (column, index) {
        assertTrue_(sameValidationRule_(rules[index], buildColumnValidation_(column, enumValues)),
          'validasi sheet ' + table.name + '.' + column.name);
      });
      const wholeSheet = table.name === 'README' || table.name === 'AUDIT_LOG';
      const key = DB_PROTECTION_PREFIX + (wholeSheet ? 'SHEET:' : 'HEADER:') + table.name;
      assertEqual_((protections[key] || []).length, 1, 'proteksi ' + table.name);
      assertTrue_(protections[key][0].isWarningOnly(), 'proteksi hanya peringatan ' + table.name);
    });
    const pick = function (r) { return [r.enum_name, r.enum_value, r.label, r.sort_order, r.is_active]; };
    assertDeepEqual_(loadTable_('ENUMS').records.map(pick), buildEnumSeedRecords_().map(pick), 'isi ENUMS');
    assertEqual_(report.seededEnumValues, buildEnumSeedRecords_().length, 'jumlah nilai enum');
    const settings = getSettings_();
    getSettingDefinitions_().forEach(function (definition) {
      assertTrue_(Boolean(settings[definition.key]) && settings[definition.key].valid, 'setting ' + definition.key);
    });
    assertEqual_(getSetting_('SCHEMA_VERSION'), SCHEMA_VERSION, 'SCHEMA_VERSION');
    assertTrue_(isValidIsoDateTime_(getSetting_('DB_INITIALIZED_AT')), 'DB_INITIALIZED_AT');
    const readme = spreadsheet.getSheetByName('README');
    assertDeepEqual_(readme.getRange(2, 1, readme.getLastRow() - 1, 2).getValues(), buildReadmeRows_(), 'isi README');
    const audit = loadTable_('AUDIT_LOG').records;
    assertEqual_(audit.length, 1, 'satu entri audit DB_INIT');
    assertEqual_(audit[0].action, 'DB_INIT', 'aksi audit');
    assertEqual_(audit[0].actor_email, SELF_TEST_ACTOR, 'pelaku audit');
    assertMatch_(audit[0].id, /^AUD-[0-9A-F]{16}$/, 'ID audit');
  });
}

function testVerifyCleanDatabase_(t) {
  const report = verifyDatabase_({ spreadsheet: t.shared().spreadsheet, includeAuditLog: true });
  assertTrue_(report.ok, 'database baru valid: ' + JSON.stringify(report.errors.slice(0, 5)));
  assertEqual_(report.warnings.length, 0, 'tanpa peringatan: ' + JSON.stringify(report.warnings.slice(0, 5)));
  assertEqual_(report.schemaVersion.database, SCHEMA_VERSION, 'versi skema database');
}

function testInitIdempotent_(t) {
  const spreadsheet = t.shared().spreadsheet;
  const before = snapshotSpreadsheet_(spreadsheet);
  const report = initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR });
  assertTrue_(!report.changed, 'run kedua tanpa perubahan: ' + JSON.stringify(summarizeInitReport_(report)));
  assertDeepEqual_(snapshotSpreadsheet_(spreadsheet), before, 'isi spreadsheet tidak berubah');
}

function testInitAbortsOnHeaderMismatch_(t) {
  const spreadsheet = t.fresh('mismatch');
  const sheet = spreadsheet.getSheets()[0];
  sheet.setName('CUSTOMERS');
  sheet.getRange(1, 1, 1, 3).setValues([['id', 'nama', 'kode']]);
  const namesBefore = spreadsheet.getSheets().map(function (s) { return s.getName(); });
  const error = expectError_(function () {
    initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR });
  }, ERROR_CODE.SCHEMA_MISMATCH, 'header berbeda');
  assertEqual_(error.details.conflicts[0].code, 'HEADER_MISMATCH', 'jenis konflik');
  assertEqual_(error.details.conflicts[0].sheet, 'CUSTOMERS', 'sheet konflik');
  assertDeepEqual_(spreadsheet.getSheets().map(function (s) { return s.getName(); }), namesBefore, 'tidak ada sheet baru');
  assertDeepEqual_(sheet.getRange(1, 1, 1, 3).getValues(), [['id', 'nama', 'kode']], 'header lama tidak ditimpa');
  assertEqual_(sheet.getFrozenRows(), 0, 'sheet tidak diformat');
}

function testInitAppendsColumns_(t) {
  const spreadsheet = t.fresh('append');
  const table = getTableDef_('CUSTOMERS');
  const sheet = spreadsheet.getSheets()[0];
  sheet.setName('CUSTOMERS');
  const oldColumns = table.columnNames.slice(0, table.columns.length - 4);
  sheet.getRange(1, 1, 1, oldColumns.length).setValues([oldColumns]);
  const oldRow = oldColumns.map(function (name) {
    if (name === 'id') return 'CUS-00000000A1';
    if (name === 'name') return 'Customer Uji Lama';
    if (name === 'is_active') return true;
    if (name === 'is_legacy') return false;
    return '';
  });
  sheet.getRange(2, 1, 1, oldRow.length).setValues([oldRow]);
  const report = initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['CUSTOMERS'] });
  assertDeepEqual_(report.appendedColumns, { CUSTOMERS: ['created_at', 'created_by', 'updated_at', 'updated_by'] },
    'kolom ditambahkan di akhir');
  assertDeepEqual_(sheet.getRange(1, 1, 1, table.columns.length).getValues()[0], table.columnNames, 'header lengkap');
  const row = sheet.getRange(2, 1, 1, table.columns.length).getValues()[0];
  assertDeepEqual_(row.slice(0, oldRow.length), oldRow, 'data lama tidak berubah');
  assertDeepEqual_(row.slice(oldRow.length), ['', '', '', ''], 'kolom baru kosong');
  const rerun = initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['CUSTOMERS'] });
  assertTrue_(!rerun.changed, 'run kedua tanpa perubahan: ' + JSON.stringify(summarizeInitReport_(rerun)));
}

function testInitEmptyAndHeaderlessSheets_(t) {
  const spreadsheet = t.fresh('empty');
  spreadsheet.getSheets()[0].setName('PRODUCTS');
  const report = initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['PRODUCTS'] });
  assertDeepEqual_(report.headersWritten, ['PRODUCTS'], 'header ditulis pada sheet kosong');
  const products = getTableDef_('PRODUCTS');
  assertDeepEqual_(spreadsheet.getSheetByName('PRODUCTS').getRange(1, 1, 1, products.columns.length).getValues()[0],
    products.columnNames, 'header PRODUCTS');
  const stock = spreadsheet.insertSheet('STOCK');
  stock.getRange(3, 1, 1, 2).setValues([['Barang Uji', 10]]);
  const error = expectError_(function () {
    initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['STOCK'] });
  }, ERROR_CODE.SCHEMA_MISMATCH, 'data tanpa header');
  assertEqual_(error.details.conflicts[0].code, 'DATA_WITHOUT_HEADER', 'jenis konflik');
  assertEqual_(stock.getRange(1, 1).getValue(), '', 'sheet STOCK tidak diubah');
}

function testInitPreservesCustomizations_(t) {
  const spreadsheet = t.fresh('custom');
  initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['ENUMS', 'SETTINGS'] });
  withDatabaseSpreadsheet_(spreadsheet, function () {
    const enums = loadTable_('ENUMS');
    const labelColumn = enums.table.columnNames.indexOf('label') + 1;
    const open = findIndexWhere_(enums.records, function (r) { return r.enum_name === 'PO_STATUS' && r.enum_value === 'OPEN'; });
    enums.sheet.getRange(enums.rowNumbers[open], labelColumn).setValue('Terbuka');
    const critical = findIndexWhere_(enums.records, function (r) { return r.enum_name === 'PRIORITY' && r.enum_value === 'CRITICAL'; });
    enums.sheet.getRange(enums.rowNumbers[critical], 1, 1, enums.table.columns.length).clearContent();
    appendRowsToSheet_(enums.sheet, enums.table, [recordToRow_(enums.table, {
      enum_name: 'ACTIVITY_TYPE', enum_value: 'TRADE_SHOW', label: 'Trade show', sort_order: 200, is_active: true
    })]);
    const settings = loadTable_('SETTINGS');
    const pageSize = findIndexWhere_(settings.records, function (r) { return r.key === 'DEFAULT_PAGE_SIZE'; });
    settings.sheet.getRange(settings.rowNumbers[pageSize], settings.table.columnNames.indexOf('value') + 1).setValue('50');
  });
  const report = initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['ENUMS', 'SETTINGS'] });
  assertEqual_(report.seededEnumValues, 1, 'hanya nilai sistem yang hilang ditambahkan');
  assertDeepEqual_(report.seededSettings, [], 'setting tidak ditambah ulang');
  withDatabaseSpreadsheet_(spreadsheet, function () {
    const state = getEnumState_();
    assertEqual_(state.byName.PO_STATUS.labels.OPEN, 'Terbuka', 'label dari Admin dipertahankan');
    assertTrue_(state.byName.ACTIVITY_TYPE.active.TRADE_SHOW === true, 'nilai tambahan dipertahankan');
    assertTrue_(state.byName.PRIORITY.active.CRITICAL === true, 'nilai sistem yang hilang dipulihkan');
    assertEqual_(getSetting_('DEFAULT_PAGE_SIZE'), 50, 'nilai setting dipertahankan');
  });
}

function testInitSchemaVersion_(t) {
  const spreadsheet = t.fresh('version');
  initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['SETTINGS'] });
  const setVersion = function (value) {
    withDatabaseSpreadsheet_(spreadsheet, function () {
      const state = loadTable_('SETTINGS');
      const index = findIndexWhere_(state.records, function (r) { return r.key === 'SCHEMA_VERSION'; });
      state.sheet.getRange(state.rowNumbers[index], state.table.columnNames.indexOf('value') + 1).setValue(value);
    });
  };
  setVersion(String(SCHEMA_VERSION - 1));
  const upgrade = initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['SETTINGS'] });
  assertDeepEqual_(upgrade.updatedSettings, ['SCHEMA_VERSION'], 'versi skema dinaikkan');
  withDatabaseSpreadsheet_(spreadsheet, function () {
    assertEqual_(getSetting_('SCHEMA_VERSION'), SCHEMA_VERSION, 'versi skema terbaru');
  });
  setVersion(String(SCHEMA_VERSION + 1));
  const error = expectError_(function () {
    initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['SETTINGS'] });
  }, ERROR_CODE.SCHEMA_MISMATCH, 'versi database lebih baru');
  assertEqual_(error.details.conflicts[0].code, 'SCHEMA_VERSION_NEWER', 'jenis konflik');
}

function testInitUpgradeFromV1_(t) {
  const spreadsheet = t.fresh('upgrade');
  const table = getTableDef_('LEADTIME');
  const v1Columns = table.columns.filter(function (c) { return c.since === 1; }).map(function (c) { return c.name; });
  const added = table.columns.filter(function (c) { return c.since > 1; }).map(function (c) { return c.name; });
  assertTrue_(added.length > 0, 'LEADTIME punya kolom v2');
  const sheet = spreadsheet.getSheets()[0];
  sheet.setName('LEADTIME');
  sheet.getRange(1, 1, 1, v1Columns.length).setValues([v1Columns]);
  sheet.getRange(2, 1, 1, 2).setValues([['LT-00000000A1', 'PO-00000000A1']]);
  const report = initializeDatabase_({ spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR, tables: ['LEADTIME'] });
  assertDeepEqual_(report.appendedColumns, { LEADTIME: added }, 'kolom v2 ditambahkan di akhir');
  assertDeepEqual_(sheet.getRange(1, 1, 1, table.columns.length).getValues()[0], table.columnNames, 'header v2');
  assertDeepEqual_(sheet.getRange(2, 1, 1, 2).getValues()[0], ['LT-00000000A1', 'PO-00000000A1'], 'data lama utuh');
}

// ---------------------------------------------------------------------------------------------------------------
// verify
// ---------------------------------------------------------------------------------------------------------------

function testVerifyDetectsProblems_(t) {
  const spreadsheet = t.fresh('verify');
  initializeDatabase_({
    spreadsheet: spreadsheet, actor: SELF_TEST_ACTOR,
    tables: ['ENUMS', 'SETTINGS', 'USERS', 'CUSTOMERS', 'CONTACTS', 'PRODUCTS']
  });
  withDatabaseSpreadsheet_(spreadsheet, function () {
    writeRawRecords_('CUSTOMERS', [
      { id: 'CUS-00000000A1', name: 'Customer Uji A', status: 'ACTIVE' },
      { id: 'CUS-00000000A1', name: 'Customer Uji Kembar', status: 'ACTIVE' },
      { id: 'CUS-00000000A2', name: 'Customer Uji B', status: 'AKTIF' },
      { id: 'CUS-00000000A3', name: 'Customer Uji C', created_at: 'kemarin' }
    ]);
    writeRawRecords_('CONTACTS', [{ id: 'CON-00000000B1', customer_id: 'CUS-00000000FF', name: 'Contact Yatim' }]);
    writeRawRecords_('USERS', [{ id: 'USR-00000000C1', name: 'User Uji', email: 'Admin@Example.com', role: 'ADMIN' }]);
    writeRawRecords_('PRODUCTS', [{ id: 'PRD-00000000D1', name: 'Produk Uji', product_code: '[ABC-1]', product_code_key: 'XYZ' }]);
    const products = getCheckedSheet_(getTableDef_('PRODUCTS'));
    const activeColumn = getTableDef_('PRODUCTS').columnNames.indexOf('is_active') + 1;
    products.getRange(products.getLastRow() + 2, activeColumn).setValue(false);
  });
  const report = verifyDatabase_({ spreadsheet: spreadsheet });
  assertTrue_(!report.ok, 'verifikasi gagal');
  const codes = function (sheetName) {
    return report.errors.filter(function (e) { return e.sheet === sheetName; })
      .map(function (e) { return e.code + ':' + (e.field || ''); });
  };
  const expected = [
    ['CUSTOMERS', 'DUPLICATE_ID:id'], ['CUSTOMERS', 'ENUM:status'], ['CUSTOMERS', 'TYPE:created_at'],
    ['CONTACTS', 'REF_NOT_FOUND:customer_id'], ['USERS', 'TYPE:email'], ['PRODUCTS', 'DERIVED_MISMATCH:product_code_key'],
    ['LEADS', 'SHEET_MISSING:']
  ];
  expected.forEach(function (item) {
    assertTrue_(codes(item[0]).indexOf(item[1]) !== -1, item[0] + ' ' + item[1] + ' terdeteksi: ' + JSON.stringify(codes(item[0])));
  });
  const placeholder = report.warnings.filter(function (w) { return w.sheet === 'PRODUCTS' && w.code === 'PLACEHOLDER_ROWS'; });
  assertEqual_(placeholder.length, 1, 'baris checkbox FALSE tanpa data diperingatkan');
  const duplicate = report.errors.filter(function (e) { return e.code === 'DUPLICATE_ID'; })[0];
  assertEqual_(duplicate.row, 3, 'nomor baris ID ganda');
  assertEqual_(duplicate.id, 'CUS-00000000A1', 'ID ganda');
}

function testVerifyAfterData_(t) {
  const report = verifyDatabase_({ spreadsheet: t.shared().spreadsheet, includeAuditLog: true });
  assertTrue_(report.ok, 'semua data uji valid: ' + JSON.stringify(report.errors.slice(0, 5)));
  assertEqual_(report.warnings.length, 0, 'tanpa peringatan: ' + JSON.stringify(report.warnings.slice(0, 5)));
}

// ---------------------------------------------------------------------------------------------------------------
// id
// ---------------------------------------------------------------------------------------------------------------

function testIdFormatAndUniqueness_() {
  const used = new Set();
  for (let i = 0; i < 500; i++) assertMatch_(generateId_('CUS', used), /^CUS-[0-9A-F]{10}$/, 'format ID');
  assertEqual_(used.size, 500, 'ID unik');
  assertMatch_(generateAuditId_(), /^AUD-[0-9A-F]{16}$/, 'format ID audit');
  expectError_(function () { generateId_('cus', null); }, ERROR_CODE.INTERNAL, 'prefiks huruf kecil ditolak');
  expectError_(function () { generateId_('CUST1', null); }, ERROR_CODE.INTERNAL, 'prefiks tidak valid ditolak');
  getSchema_().tables.forEach(function (table) {
    if (!table.hasId) return;
    assertTrue_(table.idPattern.test(generateId_(table.idPrefix, null, table.idHexLength)), 'pola ID ' + table.name);
  });
}

function testIdCollisionRetry_(t) {
  const sequence = ['AAAAAAAAAA', 'AAAAAAAAAA', 'BBBBBBBBBB'];
  let position = 0;
  setIdRandomSourceForTesting_(function () { return sequence[Math.min(position++, sequence.length - 1)]; });
  assertEqual_(generateId_('CUS', new Set(['CUS-AAAAAAAAAA'])), 'CUS-BBBBBBBBBB', 'ID bentrok dibuat ulang');
  setIdRandomSourceForTesting_(function () { return 'CCCCCCCCCC'; });
  expectError_(function () { generateId_('CUS', new Set(['CUS-CCCCCCCCCC'])); }, ERROR_CODE.INTERNAL, 'batas percobaan');
  setIdRandomSourceForTesting_(null);
  t.onShared(function () {
    const existing = makeCustomer_();
    const queue = [existing.id.slice(4), 'DDDDDDDDD1'];
    setIdRandomSourceForTesting_(function () { return queue.length ? queue.shift() : 'EEEEEEEEE1'; });
    const created = dbInsert_('CUSTOMERS', [{ name: 'Customer Uji Bentrok ID' }], testCtx_())[0];
    setIdRandomSourceForTesting_(null);
    assertEqual_(created.id, 'CUS-DDDDDDDDD1', 'ID yang sudah tersimpan tidak dipakai ulang');
  });
}

function testIdsAcrossTables_(t) {
  t.onShared(function () {
    const user = makeUser_();
    const customer = makeCustomer_({ owner_user_id: user.id });
    const contact = makeContact_(customer.id, { is_primary: true });
    const product = makeProduct_({ customer_id: customer.id });
    const lead = insertOne_('LEADS', {
      customer_id: customer.id, contact_id: contact.id, product_id: product.id, name: 'Lead Uji', owner_user_id: user.id
    });
    const activity = insertOne_('ACTIVITIES', {
      customer_id: customer.id, contact_id: contact.id, lead_id: lead.id, type: 'VISIT', subject: 'Kunjungan uji',
      owner_user_id: user.id, activity_at: '2026-09-28T03:00:00.000Z'
    });
    const followUp = insertOne_('FOLLOW_UP', {
      customer_id: customer.id, lead_id: lead.id, activity_id: activity.id, owner_user_id: user.id,
      follow_up_date: '2026-10-01', follow_up_time: '09:30', type: 'WHATSAPP'
    });
    const po = makePurchaseOrder_(customer.id, { owner_user_id: user.id });
    const line = makePoLine_(po.id, product.id);
    const delivery = insertOne_('DELIVERIES', {
      purchase_order_id: po.id, po_line_id: line.id, product_id: product.id, delivery_date: '2026-09-10', quantity: 400,
      status: 'DELIVERED', sj_number: 'SJ-UJI 0001'
    });
    const returned = insertOne_('RETURNS', {
      purchase_order_id: po.id, po_line_id: line.id, product_id: product.id, return_date: '2026-09-15', quantity: 20,
      status: 'OPEN'
    });
    const stock = insertOne_('STOCK', {
      product_id: product.id, stock_type: 'FG', status: 'READY', quantity: 500, stock_date: '2026-09-20'
    });
    const leadtime = insertOne_('LEADTIME', {
      purchase_order_id: po.id, po_line_id: line.id, product_id: product.id, customer_id: customer.id,
      planned_date: '2026-10-05', quantity: 600, status: 'SCHEDULED'
    });
    const inbound = insertOne_('INBOUND_MAKLON', {
      purchase_order_id: po.id, product_id: product.id, vendor: 'Vendor Uji', receiver: 'Maklon Uji',
      inbound_date: '2026-09-05', sj_number: 'SJ-IN 0001', quantity: 1000
    });
    const invoice = insertOne_('INVOICES_PAYMENTS', {
      purchase_order_id: po.id, invoice_number: 'UJI/INV/' + uniqueTag_(), invoice_type: 'INV',
      invoice_date: '2026-09-11', due_date: '2026-10-11', amount: 1500000
    });
    const financial = insertOne_('PO_FINANCIALS', {
      purchase_order_id: po.id, po_number_legacy: po.po_number, brand: 'Brand Uji', is_legacy: true
    }, migrationCtx_());
    const issue = insertOne_('MIGRATION_ISSUES', {
      severity: 'LOW', issue_type: 'UJI', entity_type: 'deliveries', record_id: delivery.id, description: 'Isu uji'
    }, migrationCtx_());
    const created = {
      USERS: user, CUSTOMERS: customer, CONTACTS: contact, PRODUCTS: product, LEADS: lead, ACTIVITIES: activity,
      FOLLOW_UP: followUp, PURCHASE_ORDERS: po, PO_LINES: line, DELIVERIES: delivery, RETURNS: returned, STOCK: stock,
      LEADTIME: leadtime, INBOUND_MAKLON: inbound, INVOICES_PAYMENTS: invoice, PO_FINANCIALS: financial,
      MIGRATION_ISSUES: issue
    };
    Object.keys(created).forEach(function (name) {
      const table = getTableDef_(name);
      assertEqual_(created[name].id.split('-')[0], table.idPrefix, 'prefiks ID ' + name);
      assertTrue_(table.idPattern.test(created[name].id), 'format ID ' + name);
      assertEqual_(dbFindById_(name, created[name].id).id, created[name].id, 'tersimpan ' + name);
    });
    assertEqual_(delivery.sj_number_key, 'SJ-UJI0001', 'kunci nomor SJ');
    assertEqual_(product.product_code_key, codeKey_(product.product_code), 'kunci kode produk');
    assertEqual_(invoice.payment_status, 'UNPAID', 'status bayar default');
    assertEqual_(invoice.paid_amount, 0, 'nilai dibayar default');
    const updated = dbUpdate_('CUSTOMERS', customer.id, { notes: 'Diperbarui' }, testCtx_());
    assertEqual_(updated.id, customer.id, 'ID tetap setelah update');
    makeCustomer_();
    assertEqual_(dbFindById_('CUSTOMERS', customer.id).notes, 'Diperbarui', 'ID tetap menunjuk record yang sama');
  });
}

// ---------------------------------------------------------------------------------------------------------------
// relasi
// ---------------------------------------------------------------------------------------------------------------

function testForeignKeys_(t) {
  t.onShared(function () {
    expectValidation_(function () { makeContact_('CUS-FFFFFFFFF0'); },
      { field: 'customer_id', code: 'REF_NOT_FOUND' }, 'customer tidak ada');
    expectValidation_(function () { makeContact_('CUS-1'); }, { field: 'customer_id', code: 'PATTERN' }, 'format ID salah');
    const product = makeProduct_();
    expectValidation_(function () { makeContact_(product.id); },
      { field: 'customer_id', code: 'PATTERN' }, 'ID dari tabel lain ditolak');
    const customer = makeCustomer_();
    const contact = makeContact_(customer.id);
    dbArchive_('CUSTOMERS', customer.id, testCtx_());
    expectValidation_(function () { makeContact_(customer.id); },
      { field: 'customer_id', code: 'REF_INACTIVE' }, 'customer yang diarsipkan tidak dapat dipilih');
    const updated = dbUpdate_('CONTACTS', contact.id, { position: 'Purchasing' }, testCtx_());
    assertEqual_(updated.position, 'Purchasing', 'record lama tetap dapat diubah');
    const migrated = insertOne_('CONTACTS', { customer_id: customer.id, name: 'Contact Migrasi' }, migrationCtx_());
    assertEqual_(migrated.customer_id, customer.id, 'migrasi boleh merujuk record arsip');
  });
}

function testReferenceConsistency_(t) {
  t.onShared(function () {
    const user = makeUser_();
    const customerA = makeCustomer_();
    const customerB = makeCustomer_();
    const contactB = makeContact_(customerB.id);
    expectValidation_(function () {
      insertOne_('LEADS', { customer_id: customerA.id, contact_id: contactB.id, name: 'Lead Uji' });
    }, { field: 'contact_id', code: 'REF_MISMATCH' }, 'contact milik customer lain');
    const leadB = insertOne_('LEADS', { customer_id: customerB.id, name: 'Lead Uji B' });
    const activity = { type: 'EMAIL', subject: 'Email uji', owner_user_id: user.id, activity_at: '2026-09-28T03:00:00.000Z' };
    expectValidation_(function () {
      insertOne_('ACTIVITIES', Object.assign({ customer_id: customerA.id, lead_id: leadB.id }, activity));
    }, { field: 'lead_id', code: 'REF_MISMATCH' }, 'lead milik customer lain');
    expectValidation_(function () {
      insertOne_('ACTIVITIES', Object.assign({ contact_id: contactB.id }, activity));
    }, { field: 'customer_id', code: 'REF_MISMATCH' }, 'customer wajib sesuai contact');
    const product = makeProduct_();
    const otherProduct = makeProduct_();
    const poA = makePurchaseOrder_(customerA.id);
    const poB = makePurchaseOrder_(customerB.id);
    const lineA = makePoLine_(poA.id, product.id);
    expectValidation_(function () {
      insertOne_('DELIVERIES', { purchase_order_id: poB.id, po_line_id: lineA.id, product_id: product.id, delivery_date: '2026-09-10', quantity: 10 });
    }, { field: 'po_line_id', code: 'REF_MISMATCH' }, 'baris PO milik PO lain');
    expectValidation_(function () {
      insertOne_('DELIVERIES', { purchase_order_id: poA.id, po_line_id: lineA.id, product_id: otherProduct.id, delivery_date: '2026-09-10', quantity: 10 });
    }, { field: 'po_line_id', code: 'REF_MISMATCH' }, 'produk berbeda dari baris PO');
    const plan = { purchase_order_id: poA.id, product_id: product.id, planned_date: '2026-10-01', quantity: 5 };
    expectValidation_(function () { insertOne_('LEADTIME', plan); },
      { field: 'customer_id', code: 'REF_MISMATCH' }, 'customer jadwal mengikuti PO');
    const legacyPlan = insertOne_('LEADTIME', Object.assign({ is_legacy: true }, plan), migrationCtx_());
    assertEqual_(legacyPlan.customer_id, null, 'legacy boleh tanpa customer');
    expectValidation_(function () {
      insertOne_('LEADTIME', Object.assign({ is_legacy: true, customer_id: customerB.id }, plan), migrationCtx_());
    }, { field: 'purchase_order_id', code: 'REF_MISMATCH' }, 'legacy tetap tidak boleh bertentangan');
  });
}

function testLegacyRules_(t) {
  t.onShared(function () {
    expectValidation_(function () { insertOne_('DELIVERIES', { delivery_date: '2026-09-10', quantity: 10 }); },
      [{ field: 'purchase_order_id', code: 'REQUIRED' }, { field: 'product_id', code: 'REQUIRED' }], 'data baru wajib PO & produk');
    expectValidation_(function () { insertOne_('DELIVERIES', { delivery_date: '2026-09-10', quantity: 10, is_legacy: true }); },
      { field: 'is_legacy', code: 'LEGACY_FORBIDDEN' }, 'is_legacy hanya lewat migrasi');
    const legacy = insertOne_('DELIVERIES', {
      id: 'DEL-0123456789', is_legacy: true, delivery_date: '2026-01-05', quantity: -150, sj_number: 'SJ 00012',
      source_file: 'legacy-uji.xlsx', source_sheet: 'Kirim', legacy_row: 12, import_ref: 'uji.xlsx#DELIVERIES!13',
      migrated_at: '2026-09-28T00:00:00.000Z', migration_hash: 'abc123'
    }, migrationCtx_());
    assertEqual_(legacy.id, 'DEL-0123456789', 'ID legacy dipertahankan');
    assertEqual_(legacy.quantity, -150, 'qty negatif legacy dipertahankan (D9)');
    assertEqual_(legacy.sj_number_key, 'SJ00012', 'kunci nomor SJ');
    assertEqual_(legacy.legacy_row, 12, 'lineage tersimpan');
    expectValidation_(function () { insertOne_('DELIVERIES', { id: 'DEL-0123456789', is_legacy: true }, migrationCtx_()); },
      { field: 'id', code: 'UNIQUE' }, 'ID legacy ganda');
    expectValidation_(function () { insertOne_('DELIVERIES', { id: 'DEL-12345', is_legacy: true }, migrationCtx_()); },
      { field: 'id', code: 'PATTERN' }, 'format ID legacy');
    expectValidation_(function () { insertOne_('DELIVERIES', { id: 'CUS-0123456789', is_legacy: true }, migrationCtx_()); },
      { field: 'id', code: 'PATTERN' }, 'prefiks ID harus milik tabelnya');
    expectValidation_(function () {
      insertOne_('DELIVERIES', { is_legacy: false, delivery_date: '2026-01-05', quantity: -1 }, migrationCtx_());
    }, [{ field: 'quantity', code: 'MIN' }, { field: 'purchase_order_id', code: 'REQUIRED' }], 'non-legacy tetap ketat');
    expectValidation_(function () { dbUpdate_('DELIVERIES', legacy.id, { source_file: 'lain.xlsx' }, testCtx_()); },
      { field: 'source_file', code: 'LEGACY_FORBIDDEN' }, 'lineage tidak dapat diubah pengguna');
    const noted = dbUpdate_('DELIVERIES', legacy.id, { notes: 'Dicek Admin' }, testCtx_());
    assertEqual_(noted.is_legacy, true, 'update biasa mempertahankan status legacy');
  });
}

function testPoNumberUniqueness_(t) {
  t.onShared(function () {
    const customerA = makeCustomer_();
    const customerB = makeCustomer_();
    const tag = uniqueTag_();
    const first = makePurchaseOrder_(customerA.id, { po_number: 'uji/po/' + tag });
    assertEqual_(first.po_number_key, 'UJI/PO/' + tag, 'kunci nomor PO');
    expectValidation_(function () { makePurchaseOrder_(customerA.id, { po_number: ' UJI / PO / ' + tag + ' ' }); },
      { field: 'po_number', code: 'UNIQUE' }, 'nomor sama (beda spasi/huruf) untuk customer sama');
    makePurchaseOrder_(customerB.id, { po_number: 'UJI/PO/' + tag });
    dbUpdate_('PURCHASE_ORDERS', first.id, { status: 'CANCELLED' }, testCtx_());
    const replacement = makePurchaseOrder_(customerA.id, { po_number: 'UJI/PO/' + tag });
    assertTrue_(replacement.id !== first.id, 'PO pengganti setelah pembatalan');
    expectValidation_(function () { dbUpdate_('PURCHASE_ORDERS', first.id, { status: 'OPEN' }, testCtx_()); },
      { field: 'po_number', code: 'UNIQUE' }, 'mengaktifkan kembali PO bernomor sama ditolak');
    insertOne_('PURCHASE_ORDERS', { is_legacy: true, status: 'CLOSED' }, migrationCtx_());
    insertOne_('PURCHASE_ORDERS', { is_legacy: true, status: 'CLOSED' }, migrationCtx_());
  });
}

function testLegacyDuplicatePoNumbers_(t) {
  t.onShared(function () {
    const customer = makeCustomer_();
    const number = 'UJI/LEGACY/' + uniqueTag_();
    const legacy = { customer_id: customer.id, po_number: number, status: 'CLOSED', is_legacy: true };
    insertOne_('PURCHASE_ORDERS', legacy, migrationCtx_());
    const twin = insertOne_('PURCHASE_ORDERS', Object.assign({}, legacy, { status: 'ON_PROCESS' }), migrationCtx_());
    assertEqual_(twin.po_number, number, 'duplikat legacy tetap dimigrasikan (dicatat sebagai isu)');
    expectValidation_(function () { makePurchaseOrder_(customer.id, { po_number: number }); },
      { field: 'po_number', code: 'UNIQUE' }, 'PO baru tidak boleh memakai nomor legacy yang sama');
    const report = verifyDatabase_({ spreadsheet: t.shared().spreadsheet });
    assertEqual_(report.errors.filter(function (e) { return e.code === 'UNIQUE'; }).length, 0, 'verify menerima duplikat legacy');
  });
}

function testPrimaryContact_(t) {
  t.onShared(function () {
    const customer = makeCustomer_();
    const other = makeCustomer_();
    const first = makeContact_(customer.id, { is_primary: true });
    expectValidation_(function () { makeContact_(customer.id, { is_primary: true }); },
      { field: 'is_primary', code: 'UNIQUE' }, 'contact utama kedua');
    makeContact_(other.id, { is_primary: true });
    const second = makeContact_(customer.id, { is_primary: false });
    dbArchive_('CONTACTS', first.id, testCtx_());
    dbUpdate_('CONTACTS', second.id, { is_primary: true }, testCtx_());
    expectValidation_(function () { dbRestore_('CONTACTS', first.id, testCtx_()); },
      { field: 'is_primary', code: 'UNIQUE' }, 'pemulihan yang menimbulkan dua contact utama ditolak');
  });
}

// ---------------------------------------------------------------------------------------------------------------
// validasi
// ---------------------------------------------------------------------------------------------------------------

function testValidationTypes_(t) {
  t.onShared(function () {
    const user = makeUser_();
    const invalidCustomers = [
      [{ status: 'ACTIVE' }, 'name', 'REQUIRED', 'nama wajib'],
      [{ name: '   ' }, 'name', 'REQUIRED', 'spasi saja dianggap kosong'],
      [{ name: new Array(257).join('x') }, 'name', 'MAX_LENGTH', 'panjang maksimum'],
      [{ name: 12345 }, 'name', 'TYPE', 'angka bukan teks'],
      [{ name: '=HYPERLINK("https://contoh.test")' }, 'name', 'PATTERN', 'formula ditolak'],
      [{ name: '\'Customer Uji' }, 'name', 'PATTERN', 'apostrof di awal ditolak'],
      [{ name: 'Customer Uji', email: 'bukan-email' }, 'email', 'TYPE', 'format email'],
      [{ name: 'Customer Uji', website: 'www.contoh.test' }, 'website', 'TYPE', 'format URL'],
      [{ name: 'Customer Uji', phone: 'tidak ada' }, 'phone', 'TYPE', 'format telepon']
    ];
    invalidCustomers.forEach(function (item) {
      expectValidation_(function () { insertOne_('CUSTOMERS', item[0]); }, { field: item[1], code: item[2] }, item[3]);
    });
    const customer = insertOne_('CUSTOMERS', {
      name: '  Customer Uji Rapi  ', email: ' Info@Contoh.TEST ', website: 'https://contoh.test', phone: '+62 21 555 0101',
      notes: '-'
    });
    assertEqual_(customer.name, 'Customer Uji Rapi', 'spasi di awal/akhir dibuang');
    assertEqual_(customer.email, 'info@contoh.test', 'email huruf kecil');
    const base = { owner_user_id: user.id, customer_id: customer.id };
    const invalidFollowUps = [
      [{ follow_up_date: '28/09/2026' }, 'follow_up_date', 'format tanggal'],
      [{ follow_up_date: '2026-02-30' }, 'follow_up_date', 'tanggal di luar kalender'],
      [{ follow_up_date: '2026-10-01', follow_up_time: '25:00' }, 'follow_up_time', 'format jam']
    ];
    invalidFollowUps.forEach(function (item) {
      expectValidation_(function () { insertOne_('FOLLOW_UP', Object.assign({}, base, item[0])); },
        { field: item[1], code: 'TYPE' }, item[2]);
    });
    const followUp = insertOne_('FOLLOW_UP', Object.assign({ follow_up_date: '2026-10-01', follow_up_time: '09:30' }, base));
    assertEqual_(followUp.status, 'PLANNED', 'status follow-up default');
    const activityBase = { customer_id: customer.id, owner_user_id: user.id, type: 'PHONE_CALL', subject: 'Telepon uji' };
    expectValidation_(function () { insertOne_('ACTIVITIES', Object.assign({ activity_at: '2026-09-28 10:00' }, activityBase)); },
      { field: 'activity_at', code: 'TYPE' }, 'format waktu');
    const activity = insertOne_('ACTIVITIES', Object.assign({ activity_at: new Date(Date.UTC(2026, 8, 28, 3, 0, 0)) }, activityBase));
    assertEqual_(activity.activity_at, '2026-09-28T03:00:00.000Z', 'Date menjadi ISO UTC');
    const moment = new Date(Date.UTC(2026, 9, 1, 17, 30, 0));
    const dated = insertOne_('FOLLOW_UP', Object.assign({ follow_up_date: moment }, base));
    const timeZone = getDatabaseSpreadsheet_().getSpreadsheetTimeZone();
    assertEqual_(dated.follow_up_date, Utilities.formatDate(moment, timeZone, 'yyyy-MM-dd'), 'Date menjadi tanggal lokal');
  });
}

function testValidationEnumsNumbersRules_(t) {
  t.onShared(function () {
    const customer = makeCustomer_();
    const lead = function (extra) { return Object.assign({ customer_id: customer.id, name: 'Lead Uji' }, extra); };
    expectValidation_(function () { insertOne_('LEADS', lead({ status: 'BARU' })); }, { field: 'status', code: 'ENUM' }, 'status tidak dikenal');
    expectValidation_(function () { insertOne_('LEADS', lead({ priority: 'high' })); }, { field: 'priority', code: 'ENUM' }, 'enum peka huruf');
    const created = insertOne_('LEADS', lead({ priority: 'CRITICAL', estimated_value: '1500000', estimated_quantity: 2500.5 }));
    assertEqual_(created.status, 'NEW', 'status lead default');
    assertEqual_(created.estimated_value, 1500000, 'angka dari teks');
    const invalidNumbers = [
      [{ estimated_value: '1.500' }, 'estimated_value', 'TYPE', 'pemisah ribuan ambigu ditolak'],
      [{ estimated_value: '1,5' }, 'estimated_value', 'TYPE', 'koma desimal ditolak'],
      [{ estimated_value: 10.555 }, 'estimated_value', 'TYPE', 'uang maksimal 2 desimal'],
      [{ estimated_quantity: 1.2345 }, 'estimated_quantity', 'TYPE', 'qty maksimal 3 desimal'],
      [{ estimated_value: -1 }, 'estimated_value', 'MIN', 'nilai tidak boleh negatif']
    ];
    invalidNumbers.forEach(function (item) {
      expectValidation_(function () { insertOne_('LEADS', lead(item[0])); }, { field: item[1], code: item[2] }, item[3]);
    });
    withEnumInactive_('PRIORITY', 'CRITICAL', function () {
      expectValidation_(function () { insertOne_('LEADS', lead({ priority: 'CRITICAL' })); },
        { field: 'priority', code: 'ENUM_INACTIVE' }, 'nilai enum nonaktif ditolak untuk data baru');
      const renamed = dbUpdate_('LEADS', created.id, { name: 'Lead Uji Diubah' }, testCtx_());
      assertEqual_(renamed.priority, 'CRITICAL', 'nilai lama tetap sah saat record diubah');
    });
    const product = makeProduct_();
    const po = makePurchaseOrder_(customer.id);
    expectValidation_(function () { makePoLine_(po.id, product.id, { order_quantity: 0 }); },
      { field: 'order_quantity', code: 'MIN' }, 'qty order harus > 0');
    expectValidation_(function () {
      insertOne_('STOCK', { product_id: product.id, stock_type: 'FG', quantity: -1, stock_date: '2026-09-01' });
    }, { field: 'quantity', code: 'MIN' }, 'stok tidak negatif');
    insertOne_('STOCK', { product_id: product.id, stock_type: 'WIP', quantity: 0, stock_date: '2026-09-01' });
    expectValidation_(function () {
      makePurchaseOrder_(customer.id, { po_date: '2026-09-10', expected_delivery_date: '2026-09-01' });
    }, { field: 'expected_delivery_date', code: 'RULE' }, 'target kirim tidak sebelum tanggal PO');
    const invoice = function (extra) {
      return Object.assign({ purchase_order_id: po.id, invoice_number: 'UJI/INV/' + uniqueTag_(), invoice_date: '2026-09-10', amount: 1000000 }, extra);
    };
    expectValidation_(function () { insertOne_('INVOICES_PAYMENTS', invoice({ due_date: '2026-09-01' })); },
      { field: 'due_date', code: 'RULE' }, 'jatuh tempo tidak sebelum tanggal invoice');
    expectValidation_(function () { insertOne_('INVOICES_PAYMENTS', invoice({ paid_amount: 1000001, payment_date: '2026-09-12' })); },
      { field: 'paid_amount', code: 'RULE' }, 'bayar tidak melebihi invoice');
    expectValidation_(function () { insertOne_('INVOICES_PAYMENTS', invoice({ paid_amount: 500000 })); },
      { field: 'payment_date', code: 'RULE' }, 'tanggal bayar wajib bila ada pembayaran');
    const paid = insertOne_('INVOICES_PAYMENTS', invoice({ paid_amount: 500000, payment_date: '2026-09-12', payment_status: 'PARTIAL' }));
    expectValidation_(function () {
      insertOne_('INVOICES_PAYMENTS', invoice({ invoice_number: paid.invoice_number.toLowerCase().replace('/', ' / ') }));
    }, { field: 'invoice_number', code: 'UNIQUE' }, 'nomor invoice unik');
  });
}

function testValidationFieldAccess_(t) {
  t.onShared(function () {
    const forbidden = [
      [{ id: 'CUS-0000000001' }, 'id', 'SYSTEM_FIELD'],
      [{ created_at: '2026-09-28T00:00:00.000Z' }, 'created_at', 'SYSTEM_FIELD'],
      [{ is_active: false }, 'is_active', 'SYSTEM_FIELD'],
      [{ source_file: 'uji.xlsx' }, 'source_file', 'LEGACY_FORBIDDEN'],
      [{ foo: 'bar' }, 'foo', 'UNKNOWN_FIELD']
    ];
    forbidden.forEach(function (item) {
      expectValidation_(function () { insertOne_('CUSTOMERS', Object.assign({ name: 'Customer Uji' }, item[0])); },
        { field: item[1], code: item[2] }, 'insert ' + item[1]);
    });
    const customer = makeCustomer_();
    expectValidation_(function () { makePurchaseOrder_(customer.id, { po_number_key: 'X' }); },
      { field: 'po_number_key', code: 'SYSTEM_FIELD' }, 'kolom turunan');
    expectValidation_(function () { makeUser_({ last_login_at: '2026-09-28T01:00:00.000Z' }); },
      { field: 'last_login_at', code: 'SYSTEM_FIELD' }, 'kolom internal dari pengguna');
    const user = makeUser_();
    const loggedIn = dbUpdate_('USERS', user.id, { last_login_at: '2026-09-28T01:00:00.000Z' }, testCtx_({ internal: true }));
    assertEqual_(loggedIn.last_login_at, '2026-09-28T01:00:00.000Z', 'layanan internal boleh menulis');
    const patches = [
      [{ id: 'CUS-0000000002' }, 'id'],
      [{ is_active: false }, 'is_active'],
      [{ updated_at: '2026-09-28T00:00:00.000Z' }, 'updated_at']
    ];
    patches.forEach(function (item) {
      expectValidation_(function () { dbUpdate_('CUSTOMERS', customer.id, item[0], testCtx_()); },
        { field: item[1], code: 'SYSTEM_FIELD' }, 'update ' + item[1]);
    });
  });
}

function testBatchAtomic_(t) {
  t.onShared(function () {
    const countRows = function (tableName) {
      resetDbCache_();
      return loadTable_(tableName).records.length;
    };
    const customersBefore = countRows('CUSTOMERS');
    const auditBefore = countRows('AUDIT_LOG');
    const tag = uniqueTag_();
    expectValidation_(function () {
      dbInsert_('CUSTOMERS', [{ name: 'Batch Uji 1 ' + tag }, { name: 'Batch Uji 2 ' + tag }, { status: 'ACTIVE' }], testCtx_());
    }, { index: 2, field: 'name', code: 'REQUIRED' }, 'record ketiga tidak valid');
    expectValidation_(function () {
      dbInsert_('CUSTOMERS', [{ name: 'Batch Uji 3', customer_code: 'KODE-' + tag }, { name: 'Batch Uji 4', customer_code: 'kode-' + tag }], testCtx_());
    }, { index: 1, field: 'customer_code', code: 'UNIQUE' }, 'duplikat di dalam satu batch');
    assertEqual_(countRows('CUSTOMERS'), customersBefore, 'tidak ada baris data yang tertulis');
    assertEqual_(countRows('AUDIT_LOG'), auditBefore, 'tidak ada baris audit yang tertulis');
    const batch = [];
    for (let i = 0; i < 25; i++) batch.push({ name: 'Batch Uji ' + tag + ' #' + i });
    const inserted = dbInsert_('CUSTOMERS', batch, testCtx_());
    const ids = {};
    inserted.forEach(function (record) { ids[record.id] = true; });
    assertEqual_(Object.keys(ids).length, 25, 'ID unik dalam satu batch');
    assertEqual_(countRows('CUSTOMERS'), customersBefore + 25, 'seluruh batch tertulis');
    assertEqual_(countRows('AUDIT_LOG'), auditBefore + 25, 'satu entri audit per record');
  });
}

// ---------------------------------------------------------------------------------------------------------------
// data
// ---------------------------------------------------------------------------------------------------------------

function testTextRoundTrip_(t) {
  t.onShared(function () {
    const customer = insertOne_('CUSTOMERS', { name: 'Customer Uji Teks', customer_code: '000123', phone: '+6281234567890' });
    const product = insertOne_('PRODUCTS', { name: 'Produk Uji Teks', product_code: '2026-09-28', description: 'TRUE' });
    const po = makePurchaseOrder_(customer.id, { po_number: '0012345', notes: '1.500' });
    const line = makePoLine_(po.id, product.id, { order_quantity: 1234.5 });
    const cell = function (tableName, id, column) {
      resetDbCache_();
      const state = loadTable_(tableName);
      const index = findRecordIndex_(state, id);
      return state.sheet.getRange(state.rowNumbers[index], state.table.columnNames.indexOf(column) + 1).getValue();
    };
    assertEqual_(cell('CUSTOMERS', customer.id, 'customer_code'), '000123', 'nol di depan tetap ada');
    assertEqual_(cell('CUSTOMERS', customer.id, 'phone'), '+6281234567890', 'nomor telepon tetap teks');
    assertEqual_(cell('PRODUCTS', product.id, 'product_code'), '2026-09-28', 'teks mirip tanggal tetap teks');
    assertEqual_(cell('PRODUCTS', product.id, 'description'), 'TRUE', 'teks TRUE tetap teks');
    assertEqual_(cell('PURCHASE_ORDERS', po.id, 'po_number'), '0012345', 'nomor PO numerik tetap teks');
    assertEqual_(cell('PURCHASE_ORDERS', po.id, 'notes'), '1.500', 'catatan mirip angka tetap teks');
    assertEqual_(cell('PURCHASE_ORDERS', po.id, 'po_date'), '2026-09-01', 'tanggal disimpan sebagai teks');
    assertEqual_(cell('PO_LINES', line.id, 'order_quantity'), 1234.5, 'qty tetap angka');
    assertEqual_(cell('CUSTOMERS', customer.id, 'is_active'), true, 'boolean tetap boolean');
    assertEqual_(dbFindById_('CUSTOMERS', customer.id).customer_code, '000123', 'dibaca kembali sama');
  });
}

function testUpdateArchiveAudit_(t) {
  t.onShared(function () {
    setClockForTesting_('2026-09-28T01:00:00.000Z');
    const customer = makeCustomer_({ notes: 'Awal' });
    assertEqual_(customer.created_at, '2026-09-28T01:00:00.000Z', 'created_at');
    assertEqual_(customer.created_by, SELF_TEST_ACTOR, 'created_by');
    setClockForTesting_('2026-09-28T02:00:00.000Z');
    const updated = dbUpdate_('CUSTOMERS', customer.id, { notes: 'Diubah', industry: 'Kosmetik' },
      testCtx_({ expectedUpdatedAt: customer.updated_at }));
    assertEqual_(updated.updated_at, '2026-09-28T02:00:00.000Z', 'updated_at');
    assertEqual_(updated.created_at, customer.created_at, 'created_at tetap');
    const unchanged = dbUpdate_('CUSTOMERS', customer.id, { notes: 'Diubah' }, testCtx_());
    assertEqual_(unchanged.updated_at, updated.updated_at, 'update tanpa perubahan tidak menulis');
    expectError_(function () {
      dbUpdate_('CUSTOMERS', customer.id, { notes: 'Lagi' }, testCtx_({ expectedUpdatedAt: customer.updated_at }));
    }, ERROR_CODE.CONFLICT, 'perubahan bersamaan terdeteksi');
    expectError_(function () { dbUpdate_('CUSTOMERS', 'CUS-FFFFFFFFF1', { notes: 'x' }, testCtx_()); },
      ERROR_CODE.NOT_FOUND, 'record tidak ada');
    assertEqual_(dbFindById_('CUSTOMERS', 'CUS-FFFFFFFFF1'), null, 'findById record tidak ada');
    assertEqual_(dbArchive_('CUSTOMERS', customer.id, testCtx_()).is_active, false, 'diarsipkan');
    dbArchive_('CUSTOMERS', customer.id, testCtx_());
    const onlyThis = function (record) { return record.id === customer.id; };
    assertEqual_(dbList_('CUSTOMERS', { filter: onlyThis }).total, 0, 'arsip tidak tampil secara default');
    assertEqual_(dbList_('CUSTOMERS', { filter: onlyThis, includeInactive: true }).total, 1, 'arsip tampil bila diminta');
    assertEqual_(dbRestore_('CUSTOMERS', customer.id, testCtx_()).is_active, true, 'dipulihkan');
    const entries = auditEntries_(customer.id);
    assertDeepEqual_(entries.map(function (e) { return e.action; }), ['CREATE', 'UPDATE', 'ARCHIVE', 'RESTORE'], 'urutan audit');
    assertEqual_(JSON.parse(entries[0].changes_json).notes, 'Awal', 'isi audit CREATE');
    assertDeepEqual_(JSON.parse(entries[1].changes_json), { industry: [null, 'Kosmetik'], notes: ['Awal', 'Diubah'] },
      'isi audit UPDATE');
    assertEqual_(entries[1].occurred_at, '2026-09-28T02:00:00.000Z', 'waktu audit');
    assertEqual_(entries[1].actor_email, SELF_TEST_ACTOR, 'pelaku audit');
    const tag = uniqueTag_();
    dbInsert_('CUSTOMERS', [{ name: tag + ' Charlie' }, { name: tag + ' Alpha' }, { name: tag + ' Bravo' }], testCtx_());
    const page = dbList_('CUSTOMERS', {
      filter: function (record) { return record.name.indexOf(tag) === 0; }, sortBy: 'name', limit: 2, offset: 1
    });
    assertEqual_(page.total, 3, 'total hasil');
    assertDeepEqual_(page.items.map(function (r) { return r.name; }), [tag + ' Bravo', tag + ' Charlie'], 'urut dan halaman');
  });
}

function testUpdateMany_(t) {
  t.onShared(function () {
    setClockForTesting_('2026-09-28T03:00:00.000Z');
    const tag = uniqueTag_();
    const customers = dbInsert_('CUSTOMERS', [{ name: tag + ' A' }, { name: tag + ' B' }, { name: tag + ' C' }], testCtx_());
    setClockForTesting_('2026-09-28T04:00:00.000Z');
    const result = dbUpdateMany_('CUSTOMERS', [
      { id: customers[0].id, patch: { notes: 'Batch A' } },
      { id: customers[1].id, patch: { name: customers[1].name } },
      { id: customers[2].id, patch: { notes: 'Batch C', industry: 'Kosmetik' } }
    ], testCtx_({ auditNote: 'uji batch' }));
    assertDeepEqual_(result.map(function (r) { return r.id; }), customers.map(function (c) { return c.id; }), 'urutan hasil = input');
    assertEqual_(result[0].notes, 'Batch A', 'record pertama diubah');
    assertEqual_(result[1].updated_at, customers[1].updated_at, 'patch tanpa perubahan tidak menulis');
    assertEqual_(result[2].updated_at, '2026-09-28T04:00:00.000Z', 'updated_at');
    assertEqual_(dbFindById_('CUSTOMERS', customers[2].id).industry, 'Kosmetik', 'tersimpan');
    const audit = auditEntries_(customers[0].id);
    assertDeepEqual_(audit.map(function (e) { return e.action; }), ['CREATE', 'UPDATE'], 'audit per record');
    assertEqual_(audit[1].note, 'uji batch', 'catatan audit');
    assertEqual_(auditEntries_(customers[1].id).length, 1, 'tanpa perubahan tanpa audit');
    expectValidation_(function () {
      dbUpdateMany_('CUSTOMERS', [
        { id: customers[0].id, patch: { notes: 'Tidak tersimpan' } },
        { id: customers[1].id, patch: { email: 'bukan-email' } }
      ], testCtx_());
    }, { field: 'email', code: 'TYPE', index: 1 }, 'satu record tidak valid menggagalkan batch');
    assertEqual_(dbFindById_('CUSTOMERS', customers[0].id).notes, 'Batch A', 'batch gagal tidak menulis apa pun');
    expectValidation_(function () {
      dbUpdateMany_('CUSTOMERS', [{ id: customers[0].id, patch: { notes: 'x' } }, { id: customers[0].id, patch: { notes: 'y' } }], testCtx_());
    }, { field: 'id', code: 'UNIQUE', index: 1 }, 'ID ganda dalam satu batch');
    expectError_(function () { dbUpdateMany_('CUSTOMERS', [{ id: 'CUS-FFFFFFFFF2', patch: { notes: 'x' } }], testCtx_()); },
      ERROR_CODE.NOT_FOUND, 'record tidak ada');
    expectValidation_(function () { dbUpdateMany_('CUSTOMERS', [{ id: customers[0].id, patch: { is_active: false } }], testCtx_()); },
      { field: 'is_active', code: 'SYSTEM_FIELD' }, 'pengguna mengarsipkan lewat dbArchive_');
    const legacy = insertOne_('CUSTOMERS', { name: tag + ' Legacy', is_legacy: true }, migrationCtx_());
    const copied = dbUpdateMany_('CUSTOMERS', [{ id: legacy.id, patch: { is_active: false } }], migrationCtx_())[0];
    assertEqual_(copied.is_active, false, 'migrasi menyalin nilai is_active sumber');
  });
}

function testReadOnlyTables_(t) {
  t.onShared(function () {
    expectError_(function () { insertOne_('PO_FINANCIALS', { po_number_legacy: 'UJI-1' }); },
      ERROR_CODE.READ_ONLY, 'PO_FINANCIALS hanya dari migrasi');
    const financial = insertOne_('PO_FINANCIALS', {
      po_number_legacy: 'UJI-2', is_legacy: true, unit_price_legacy: 1.03604
    }, migrationCtx_());
    assertEqual_(financial.unit_price_legacy, 1.03604, 'harga legacy tidak dinormalisasi');
    expectError_(function () { dbUpdate_('PO_FINANCIALS', financial.id, { notes: 'x' }, testCtx_()); },
      ERROR_CODE.READ_ONLY, 'PO_FINANCIALS tidak dapat diubah pengguna');
    expectError_(function () { dbArchive_('PO_FINANCIALS', financial.id, testCtx_()); },
      ERROR_CODE.READ_ONLY, 'PO_FINANCIALS tidak dapat diarsipkan pengguna');
    expectError_(function () {
      insertOne_('MIGRATION_ISSUES', { severity: 'LOW', issue_type: 'UJI', entity_type: 'x', description: 'x' });
    }, ERROR_CODE.READ_ONLY, 'isu hanya dari migrasi');
    const issue = insertOne_('MIGRATION_ISSUES', {
      id: 'PRF-0A1B2C3D4E', severity: 'HIGH', issue_type: 'UJI', entity_type: 'deliveries', description: 'Isu uji',
      decision_ref: 'D3'
    }, migrationCtx_());
    assertEqual_(issue.resolution_status, 'OPEN', 'status isu default');
    const resolved = dbUpdate_('MIGRATION_ISSUES', issue.id, {
      resolution_status: 'ACCEPTED_AS_IS', resolution_note: 'Diterima Admin'
    }, testCtx_());
    assertEqual_(resolved.resolution_status, 'ACCEPTED_AS_IS', 'isu dapat diselesaikan');
    expectValidation_(function () { dbUpdate_('MIGRATION_ISSUES', issue.id, { description: 'Diubah' }, testCtx_()); },
      { field: 'description', code: 'LEGACY_FORBIDDEN' }, 'isi isu tidak dapat diubah pengguna');
    ['README', 'ENUMS', 'SETTINGS', 'AUDIT_LOG'].forEach(function (name) {
      expectError_(function () { dbInsert_(name, [{}], testCtx_()); }, ERROR_CODE.READ_ONLY, name + ' dikelola sistem');
    });
  });
}

function testSettings_(t) {
  t.onShared(function () {
    assertEqual_(getSetting_('DEFAULT_PAGE_SIZE'), 25, 'setting INTEGER');
    assertEqual_(getSetting_('CURRENCY'), 'IDR', 'setting STRING');
    assertEqual_(getSetting_('SCHEMA_VERSION'), SCHEMA_VERSION, 'setting sistem');
    assertEqual_(getSetting_('TIDAK_ADA', 'x'), 'x', 'nilai cadangan');
    assertEqual_(updateSetting_('DEFAULT_PAGE_SIZE', 30, testCtx_()).value, '30', 'setting diubah');
    resetDbCache_();
    assertEqual_(getSetting_('DEFAULT_PAGE_SIZE'), 30, 'nilai baru terbaca');
    expectError_(function () { updateSetting_('DEFAULT_PAGE_SIZE', 'tiga puluh', testCtx_()); },
      ERROR_CODE.VALIDATION, 'tipe nilai setting');
    expectError_(function () { updateSetting_('SCHEMA_VERSION', '2', testCtx_()); },
      ERROR_CODE.FORBIDDEN, 'setting sistem dilindungi');
    expectError_(function () { updateSetting_('TIDAK_ADA', '1', testCtx_()); }, ERROR_CODE.NOT_FOUND, 'key tidak ada');
    const entries = auditEntries_('DEFAULT_PAGE_SIZE');
    assertEqual_(entries[entries.length - 1].action, 'SETTING_UPDATE', 'perubahan setting diaudit');
    updateSetting_('DEFAULT_PAGE_SIZE', '25', testCtx_());
  });
}

function testLegacyRawText_(t) {
  t.onShared(function () {
    const customer = makeCustomer_();
    const po = insertOne_('PURCHASE_ORDERS', {
      customer_id: customer.id, po_number: '  UJI/RAW/' + uniqueTag_() + '  ', po_number_legacy: '  uji/raw  001 ',
      status: 'CLOSED', status_legacy: 'On Proses ', is_legacy: true
    }, migrationCtx_());
    assertEqual_(po.po_number_legacy, '  uji/raw  001 ', 'nilai legacy apa adanya');
    assertEqual_(po.status_legacy, 'On Proses ', 'status legacy apa adanya');
    assertEqual_(po.po_number.indexOf(' '), -1, 'kolom bisnis di-trim');
    const report = verifyDatabase_({ spreadsheet: t.shared().spreadsheet });
    assertEqual_(report.errors.filter(function (e) { return e.id === po.id; }).length, 0, 'verify menerima spasi di kolom legacy');
  });
}

function testMigrationAuditSummary_(t) {
  t.onShared(function () {
    const before = auditEntries_(null).length;
    const records = dbInsert_('CUSTOMERS', [
      { name: 'Customer Uji Migrasi A', is_legacy: true }, { name: 'Customer Uji Migrasi B', is_legacy: true }
    ], migrationCtx_({ audit: 'summary', auditNote: 'uji paket' }));
    const entries = auditEntries_(null);
    assertEqual_(entries.length, before + 1, 'satu entri audit untuk seluruh batch');
    const entry = entries[entries.length - 1];
    assertEqual_(entry.action, 'MIGRATION_RUN', 'aksi audit migrasi');
    assertEqual_(entry.entity_type, 'CUSTOMERS', 'tabel');
    assertEqual_(entry.note, 'uji paket', 'catatan audit');
    assertDeepEqual_(JSON.parse(entry.changes_json).ids, records.map(function (r) { return r.id; }), 'daftar ID');
    const userBatch = dbInsert_('CUSTOMERS', [{ name: 'Customer Uji Biasa' }], testCtx_({ audit: 'summary' }))[0];
    assertEqual_(auditEntries_(userBatch.id)[0].action, 'CREATE', 'mode ringkas hanya untuk konteks migrasi');
  });
}

// ---------------------------------------------------------------------------------------------------------------
// akses
// ---------------------------------------------------------------------------------------------------------------

function testMaintenanceAccess_() {
  const email = requireMaintenanceAccess_();
  assertTrue_(email !== '', 'email pengguna terbaca');
  assertEqual_(email, getActiveUserEmail_(), 'pemilik skrip diizinkan');
}
