/**
 * Target schema of the PIK Marketing Control database (Google Sheets, one sheet per table).
 *
 * Sheet names, sheet order and column order are part of the contract. The initializer creates missing sheets and
 * appends missing columns at the END of a sheet; it never renames, reorders or removes anything. To evolve the
 * schema: append columns at the end of a table (never in the middle), bump SCHEMA_VERSION, run initializeDatabase().
 *
 * Standard column layout of a data table:
 *   id | business columns | is_active | lineage (migrated tables) | created_at, created_by, updated_at, updated_by
 *   | columns added in later schema versions (`appendedColumns`, each with `since`), in the order they were added
 *
 * Column attributes (see col_):
 *   type                  logical type (COLUMN_TYPES): drives the sheet number format and validation
 *   required              must be filled on every row
 *   requiredUnlessLegacy  must be filled on new rows; may stay empty on migrated legacy rows (is_legacy = TRUE, D3)
 *   writable              who may write the column: 'user' (default), 'auto' (repository only), 'internal' (server
 *                         services only), 'migration' (migration engine only), 'archive' (archive/restore; the migration engine
 *                         may also set it)
 *   ref / enumName        foreign-key target table / enum in ENUMS
 *   min, minExclusive, max, maxLength, pattern, defaultValue, derive, note
 *   preserveWhitespace    keep text exactly as supplied (original legacy values); other text is trimmed
 *   sensitive             secret value (password hash): masked in AUDIT_LOG and never sent to the browser
 *   since                 schema version that introduced the column (1 = initial layout)
 * Numeric ranges and table rules apply to new data; legacy rows are only checked structurally (types, enums,
 * references, uniqueness) so migration never rewrites source values. Problems in legacy data are recorded in
 * MIGRATION_ISSUES by the migration engine.
 */

const SCHEMA_VERSION = 3;

const COLUMN_TYPES = Object.freeze({
  id: 'ID record, format PREFIX-XXXXXXXXXX (hex huruf besar)',
  ref: 'ID record tabel lain (relasi)',
  string: 'Teks satu baris',
  text: 'Teks panjang',
  enum: 'Nilai dari sheet ENUMS',
  email: 'Email huruf kecil',
  url: 'URL http(s)',
  phone: 'Nomor telepon sebagai teks',
  date: 'Tanggal, teks yyyy-MM-dd',
  datetime: 'Waktu, teks ISO 8601 UTC (…Z)',
  time: 'Jam, teks HH:mm',
  integer: 'Bilangan bulat',
  quantity: 'Kuantitas, maksimal 3 desimal',
  money: 'Nilai uang (IDR), maksimal 2 desimal',
  decimal: 'Angka desimal',
  boolean: 'TRUE/FALSE (checkbox)',
  json: 'JSON sebagai teks'
});

const DEFAULT_MAX_LENGTH = Object.freeze({
  id: 20, ref: 20, string: 255, text: 5000, enum: 50, email: 255, url: 2000, phone: 100,
  date: 10, datetime: 30, time: 5, json: 45000
});

const WRITABLE = Object.freeze({
  USER: 'user',
  AUTO: 'auto',
  INTERNAL: 'internal',
  MIGRATION: 'migration',
  ARCHIVE: 'archive'
});

const TABLE_KIND = Object.freeze({ DATA: 'data', SYSTEM: 'system', DOC: 'doc' });

const UPPER_SNAKE_PATTERN = /^[A-Z][A-Z0-9_]*$/;

var SCHEMA_CACHE_ = null;

function getSchema_() {
  if (!SCHEMA_CACHE_) SCHEMA_CACHE_ = compileSchema_(defineTables_());
  return SCHEMA_CACHE_;
}

function getTableDef_(name) {
  const table = getSchema_().byName[name];
  if (!table) throw appError_(ERROR_CODE.INTERNAL, 'Tabel tidak dikenal: ' + name);
  return table;
}

function getTableNames_() {
  return getSchema_().tables.map(function (table) { return table.name; });
}

function col_(name, type, label, options) {
  const column = {
    name: name,
    type: type,
    label: label,
    required: false,
    requiredUnlessLegacy: false,
    writable: WRITABLE.USER,
    ref: null,
    enumName: null,
    min: null,
    minExclusive: null,
    max: null,
    maxLength: null,
    pattern: null,
    patternMessage: null,
    defaultValue: undefined,
    derive: null,
    preserveWhitespace: false,
    since: 1,
    note: ''
  };
  const extra = options || {};
  Object.keys(extra).forEach(function (key) { column[key] = extra[key]; });
  if (column.maxLength === null && DEFAULT_MAX_LENGTH[type]) column.maxLength = DEFAULT_MAX_LENGTH[type];
  return column;
}

/** Original legacy value kept exactly as in the source (reference only, never used in calculations). */
function legacyCol_(name, type, label, options) {
  return col_(name, type, label, Object.assign({
    writable: WRITABLE.MIGRATION,
    preserveWhitespace: true,
    note: 'Nilai asli data legacy apa adanya, hanya referensi.'
  }, options || {}));
}

function documentKeyCol_(name, sourceColumn, label) {
  return col_(name, 'string', label, {
    maxLength: 150,
    writable: WRITABLE.AUTO,
    derive: function (record) { return documentKey_(record[sourceColumn]); },
    note: 'Otomatis dari ' + sourceColumn + ': tanpa spasi, huruf besar. Untuk pencarian dan keunikan.'
  });
}

function defineTables_() {
  const M = WRITABLE.MIGRATION;
  return [
    {
      name: 'README', label: 'README', group: 'Dokumentasi', kind: TABLE_KIND.DOC,
      description: 'Panduan singkat struktur database. Dibuat ulang oleh initializeDatabase().',
      columns: [
        col_('key', 'string', 'Kunci', { required: true, maxLength: 100 }),
        col_('value', 'text', 'Keterangan')
      ]
    },
    {
      name: 'USERS', label: 'User', group: 'Master', idPrefix: 'USR', softDelete: true, audit: true,
      description: 'Pengguna aplikasi dan role-nya. Login memakai akun Google (Workspace) atau email + password; ' +
        'password hanya disimpan sebagai hash.',
      columns: [
        col_('name', 'string', 'Nama', { required: true, maxLength: 150 }),
        col_('email', 'email', 'Email', { required: true }),
        col_('role', 'enum', 'Role', { required: true, enumName: 'USER_ROLE' }),
        col_('phone', 'phone', 'Telepon'),
        col_('last_login_at', 'datetime', 'Login terakhir', { writable: WRITABLE.INTERNAL })
      ],
      appendedColumns: [
        col_('password_hash', 'string', 'Hash password', {
          writable: WRITABLE.INTERNAL, maxLength: 200, sensitive: true, since: 3,
          note: 'PBKDF2-SHA256 dengan salt, bukan password. Diisi server; tidak pernah dikirim ke browser atau audit log.'
        }),
        col_('password_changed_at', 'datetime', 'Password diubah', {
          writable: WRITABLE.INTERNAL, since: 3, note: 'Sesi login yang dibuat sebelum waktu ini tidak berlaku lagi.'
        }),
        col_('must_change_password', 'boolean', 'Wajib ganti password', {
          writable: WRITABLE.INTERNAL, since: 3, note: 'TRUE setelah Admin mengatur password sementara.'
        })
      ],
      unique: [{
        columns: ['email'], normalize: 'lower', description: 'email unik (tanpa membedakan huruf besar/kecil).',
        message: 'Email sudah dipakai user lain.'
      }]
    },
    {
      name: 'CUSTOMERS', label: 'Customer', group: 'Master', idPrefix: 'CUS', softDelete: true, lineage: true,
      audit: true,
      description: 'Master customer.',
      columns: [
        col_('customer_code', 'string', 'Kode customer', { maxLength: 100 }),
        col_('name', 'string', 'Nama customer', { required: true }),
        col_('industry', 'string', 'Industri', { maxLength: 150 }),
        col_('address', 'text', 'Alamat', { maxLength: 2000 }),
        col_('phone', 'phone', 'Telepon'),
        col_('email', 'email', 'Email'),
        col_('website', 'url', 'Website', { maxLength: 255 }),
        col_('status', 'enum', 'Status customer', { enumName: 'CUSTOMER_STATUS' }),
        col_('owner_user_id', 'ref', 'PIC marketing', { ref: 'USERS' }),
        col_('notes', 'text', 'Catatan')
      ],
      unique: [{
        columns: ['customer_code'], normalize: 'upper', description: 'customer_code unik bila diisi (tanpa membedakan huruf).',
        message: 'Kode customer sudah dipakai customer lain.'
      }]
    },
    {
      name: 'CONTACTS', label: 'Contact', group: 'Master', idPrefix: 'CON', softDelete: true, audit: true,
      description: 'Contact person per customer. Maksimal satu contact utama aktif per customer.',
      columns: [
        col_('customer_id', 'ref', 'Customer', { required: true, ref: 'CUSTOMERS' }),
        col_('name', 'string', 'Nama contact', { required: true, maxLength: 150 }),
        col_('position', 'string', 'Jabatan', { maxLength: 150 }),
        col_('phone', 'phone', 'Telepon'),
        col_('email', 'email', 'Email'),
        col_('whatsapp', 'phone', 'WhatsApp'),
        col_('is_primary', 'boolean', 'Contact utama', { required: true, defaultValue: false }),
        col_('notes', 'text', 'Catatan')
      ],
      unique: [{
        columns: ['customer_id'],
        where: function (record) { return record.is_primary === true && record.is_active !== false; },
        field: 'is_primary',
        description: 'maksimal satu contact is_primary = TRUE yang aktif per customer_id.',
        message: 'Customer ini sudah memiliki contact utama yang aktif.'
      }]
    },
    {
      name: 'PRODUCTS', label: 'Produk', group: 'Master', idPrefix: 'PRD', softDelete: true, lineage: true,
      audit: true,
      description: 'Master produk. Kode produk boleh sama di beberapa produk (duplikat legacy tidak digabung, D8).',
      columns: [
        col_('product_code', 'string', 'Kode produk', { maxLength: 100 }),
        col_('product_code_key', 'string', 'Kunci kode produk', {
          maxLength: 100,
          writable: WRITABLE.AUTO,
          derive: function (record) { return codeKey_(record.product_code) || leadingCode_(record.name); },
          note: 'Otomatis: kode tanpa kurung/spasi, huruf besar; bila kode kosong diambil dari kode di awal nama.'
        }),
        col_('name', 'string', 'Nama produk', { required: true }),
        col_('variant', 'string', 'Varian'),
        col_('category', 'string', 'Kategori', { maxLength: 150 }),
        col_('customer_id', 'ref', 'Customer pemilik', {
          ref: 'CUSTOMERS', note: 'Diisi bila produk khusus untuk satu customer.'
        }),
        col_('description', 'text', 'Deskripsi'),
        col_('unit', 'string', 'Satuan', { required: true, maxLength: 50, defaultValue: 'pcs' }),
        col_('lead_time_days', 'integer', 'Lead time (hari)', { min: 0 })
      ]
    },
    {
      name: 'LEADS', label: 'Lead', group: 'CRM', idPrefix: 'LED', softDelete: true, audit: true,
      description: 'Pipeline peluang penjualan per customer.',
      columns: [
        col_('customer_id', 'ref', 'Customer', { required: true, ref: 'CUSTOMERS' }),
        col_('contact_id', 'ref', 'Contact', { ref: 'CONTACTS' }),
        col_('product_id', 'ref', 'Produk', { ref: 'PRODUCTS' }),
        col_('name', 'string', 'Nama lead', { required: true }),
        col_('source', 'string', 'Sumber lead', { maxLength: 100 }),
        col_('product_interest', 'string', 'Minat produk'),
        col_('estimated_quantity', 'quantity', 'Estimasi kuantitas', { min: 0 }),
        col_('estimated_value', 'money', 'Estimasi nilai', { min: 0 }),
        col_('status', 'enum', 'Status lead', { required: true, enumName: 'LEAD_STATUS', defaultValue: 'NEW' }),
        col_('priority', 'enum', 'Prioritas', { enumName: 'PRIORITY' }),
        col_('owner_user_id', 'ref', 'PIC', { ref: 'USERS' }),
        col_('expected_closing_date', 'date', 'Perkiraan closing'),
        col_('notes', 'text', 'Catatan')
      ],
      consistency: [{ field: 'contact_id', ref: 'CONTACTS', pairs: [['customer_id', 'customer_id']] }]
    },
    {
      name: 'ACTIVITIES', label: 'Aktivitas', group: 'CRM', idPrefix: 'ACT', softDelete: true, audit: true,
      description: 'Interaksi marketing dengan customer (WhatsApp, telepon, visit, dan lain-lain).',
      columns: [
        col_('customer_id', 'ref', 'Customer', { ref: 'CUSTOMERS' }),
        col_('contact_id', 'ref', 'Contact', { ref: 'CONTACTS' }),
        col_('lead_id', 'ref', 'Lead', { ref: 'LEADS' }),
        col_('type', 'enum', 'Jenis aktivitas', { required: true, enumName: 'ACTIVITY_TYPE' }),
        col_('subject', 'string', 'Subjek', { required: true }),
        col_('description', 'text', 'Deskripsi'),
        col_('owner_user_id', 'ref', 'PIC', { required: true, ref: 'USERS' }),
        col_('activity_at', 'datetime', 'Waktu aktivitas', { required: true }),
        col_('attachment_url', 'url', 'Lampiran')
      ],
      consistency: [
        { field: 'contact_id', ref: 'CONTACTS', pairs: [['customer_id', 'customer_id']] },
        { field: 'lead_id', ref: 'LEADS', pairs: [['customer_id', 'customer_id']] }
      ]
    },
    {
      name: 'FOLLOW_UP', label: 'Follow-up', group: 'CRM', idPrefix: 'FUP', softDelete: true, audit: true,
      description: 'Jadwal follow-up. Overdue dihitung saat dibaca (tanggal < hari ini dan status bukan DONE/CANCELLED), ' +
        'tidak disimpan (D14).',
      columns: [
        col_('customer_id', 'ref', 'Customer', { ref: 'CUSTOMERS' }),
        col_('lead_id', 'ref', 'Lead', { ref: 'LEADS' }),
        col_('activity_id', 'ref', 'Aktivitas asal', { ref: 'ACTIVITIES' }),
        col_('owner_user_id', 'ref', 'PIC', { required: true, ref: 'USERS' }),
        col_('follow_up_date', 'date', 'Tanggal follow-up', { required: true }),
        col_('follow_up_time', 'time', 'Jam follow-up'),
        col_('type', 'enum', 'Jenis follow-up', { enumName: 'ACTIVITY_TYPE' }),
        col_('purpose', 'string', 'Tujuan'),
        col_('priority', 'enum', 'Prioritas', { enumName: 'PRIORITY' }),
        col_('status', 'enum', 'Status', { required: true, enumName: 'FOLLOW_UP_STATUS', defaultValue: 'PLANNED' }),
        col_('result', 'text', 'Hasil'),
        col_('notes', 'text', 'Catatan')
      ],
      consistency: [
        { field: 'lead_id', ref: 'LEADS', pairs: [['customer_id', 'customer_id']] },
        { field: 'activity_id', ref: 'ACTIVITIES', pairs: [['customer_id', 'customer_id']] }
      ]
    },
    {
      name: 'PURCHASE_ORDERS', label: 'Purchase order', group: 'Operasional', idPrefix: 'PO', softDelete: true,
      lineage: true, audit: true,
      description: 'Header PO customer. Nomor PO unik per customer untuk PO yang tidak CANCELLED (D7).',
      columns: [
        col_('po_number', 'string', 'Nomor PO', { requiredUnlessLegacy: true, maxLength: 150 }),
        documentKeyCol_('po_number_key', 'po_number', 'Kunci nomor PO'),
        col_('customer_id', 'ref', 'Customer', { requiredUnlessLegacy: true, ref: 'CUSTOMERS' }),
        col_('po_date', 'date', 'Tanggal PO', { requiredUnlessLegacy: true }),
        col_('expected_delivery_date', 'date', 'Target kirim'),
        col_('status', 'enum', 'Status PO', { required: true, enumName: 'PO_STATUS', defaultValue: 'OPEN' }),
        col_('payment_term', 'string', 'Termin pembayaran', { maxLength: 100 }),
        col_('owner_user_id', 'ref', 'PIC', { ref: 'USERS' }),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('po_number_legacy', 'string', 'Nomor PO legacy'),
        legacyCol_('status_legacy', 'string', 'Status PO legacy', { maxLength: 100 })
      ],
      unique: [{
        columns: ['customer_id', 'po_number_key'],
        where: function (record) { return record.status !== 'CANCELLED'; },
        field: 'po_number',
        legacyExempt: true,
        description: '(customer_id, po_number_key) unik untuk PO yang tidak CANCELLED; PO tanpa nomor/customer tidak ' +
          'dihitung. Nomor ganda yang sudah ada di data legacy diterima dan dicatat di MIGRATION_ISSUES (D7), ' +
          'tetapi PO baru tidak boleh memakai nomor yang sama.',
        message: 'Nomor PO ini sudah dipakai PO lain milik customer yang sama.'
      }],
      rules: [{
        description: 'expected_delivery_date tidak boleh sebelum po_date.',
        check: function (record) {
          if (record.po_date && record.expected_delivery_date && record.expected_delivery_date < record.po_date) {
            return { field: 'expected_delivery_date', message: 'Target kirim tidak boleh sebelum tanggal PO.' };
          }
          return null;
        }
      }]
    },
    {
      name: 'PO_LINES', label: 'Baris PO', group: 'Operasional', idPrefix: 'POL', softDelete: true, lineage: true,
      audit: true,
      description: 'Item per PO. Qty terkirim, retur, dan outstanding dihitung dari DELIVERIES dan RETURNS (tidak disimpan).',
      columns: [
        col_('purchase_order_id', 'ref', 'PO', { required: true, ref: 'PURCHASE_ORDERS' }),
        col_('product_id', 'ref', 'Produk', { required: true, ref: 'PRODUCTS' }),
        col_('order_quantity', 'quantity', 'Qty order', { required: true, minExclusive: 0 }),
        col_('unit', 'string', 'Satuan', { maxLength: 50 }),
        col_('unit_price', 'money', 'Harga satuan', { min: 0 }),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('product_name_legacy', 'string', 'Nama produk legacy'),
        legacyCol_('variant_legacy', 'string', 'Varian legacy'),
        legacyCol_('delivered_qty_legacy', 'quantity', 'Qty terkirim legacy'),
        legacyCol_('returned_qty_legacy', 'quantity', 'Qty retur legacy'),
        legacyCol_('outstanding_qty_legacy', 'quantity', 'Outstanding legacy'),
        legacyCol_('status_legacy', 'string', 'Status legacy', { maxLength: 100 })
      ]
    },
    {
      name: 'DELIVERIES', label: 'Delivery', group: 'Operasional', idPrefix: 'DEL', softDelete: true, lineage: true,
      audit: true,
      description: 'Transaksi pengiriman per surat jalan (SJ). Satu nomor SJ dapat memuat beberapa produk.',
      columns: [
        col_('purchase_order_id', 'ref', 'PO', { requiredUnlessLegacy: true, ref: 'PURCHASE_ORDERS' }),
        col_('po_line_id', 'ref', 'Baris PO', { ref: 'PO_LINES' }),
        col_('product_id', 'ref', 'Produk', { requiredUnlessLegacy: true, ref: 'PRODUCTS' }),
        col_('delivery_date', 'date', 'Tanggal kirim', { requiredUnlessLegacy: true }),
        col_('quantity', 'quantity', 'Qty kirim', {
          requiredUnlessLegacy: true, minExclusive: 0, note: 'Data legacy boleh negatif (koreksi, D9).'
        }),
        col_('status', 'enum', 'Status delivery', { enumName: 'DELIVERY_STATUS' }),
        col_('sj_number', 'string', 'Nomor SJ', { maxLength: 100 }),
        documentKeyCol_('sj_number_key', 'sj_number', 'Kunci nomor SJ'),
        col_('destination', 'string', 'Tujuan kirim'),
        col_('attachment_url', 'url', 'Lampiran'),
        col_('notes', 'text', 'Catatan')
      ],
      consistency: [{
        field: 'po_line_id', ref: 'PO_LINES',
        pairs: [['purchase_order_id', 'purchase_order_id'], ['product_id', 'product_id']]
      }]
    },
    {
      name: 'RETURNS', label: 'Retur', group: 'Operasional', idPrefix: 'RET', softDelete: true, lineage: true,
      audit: true,
      description: 'Transaksi retur barang dari customer.',
      columns: [
        col_('purchase_order_id', 'ref', 'PO', { ref: 'PURCHASE_ORDERS' }),
        col_('po_line_id', 'ref', 'Baris PO', { ref: 'PO_LINES' }),
        col_('product_id', 'ref', 'Produk', { requiredUnlessLegacy: true, ref: 'PRODUCTS' }),
        col_('return_date', 'date', 'Tanggal retur', { requiredUnlessLegacy: true }),
        col_('quantity', 'quantity', 'Qty retur', { required: true, minExclusive: 0 }),
        col_('reason', 'text', 'Alasan retur'),
        col_('status', 'enum', 'Status retur', { enumName: 'RETURN_STATUS' }),
        col_('sj_number', 'string', 'Nomor SJ', { maxLength: 100 }),
        col_('destination', 'string', 'Tujuan'),
        col_('attachment_url', 'url', 'Lampiran'),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('po_number_legacy', 'string', 'Nomor PO legacy'),
        legacyCol_('product_legacy', 'string', 'Produk legacy')
      ],
      consistency: [{
        field: 'po_line_id', ref: 'PO_LINES',
        pairs: [['purchase_order_id', 'purchase_order_id'], ['product_id', 'product_id']]
      }]
    },
    {
      name: 'STOCK', label: 'Stok', group: 'Inventori', idPrefix: 'STK', softDelete: true, lineage: true, audit: true,
      description: 'Catatan/snapshot stok per produk.',
      columns: [
        col_('product_id', 'ref', 'Produk', { requiredUnlessLegacy: true, ref: 'PRODUCTS' }),
        col_('stock_type', 'enum', 'Jenis stok', { required: true, enumName: 'STOCK_TYPE' }),
        col_('status', 'enum', 'Status stok', { enumName: 'STOCK_STATUS' }),
        col_('quantity', 'quantity', 'Qty', { requiredUnlessLegacy: true, min: 0 }),
        col_('box_count', 'quantity', 'Jumlah box', { min: 0 }),
        col_('qty_per_box', 'quantity', 'Qty per box', { min: 0 }),
        col_('warehouse', 'string', 'Gudang', { maxLength: 150 }),
        col_('stock_date', 'date', 'Tanggal stok', { requiredUnlessLegacy: true }),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('product_legacy', 'string', 'Produk legacy'),
        legacyCol_('status_legacy', 'string', 'Status legacy', { maxLength: 100 })
      ]
    },
    {
      name: 'LEADTIME', label: 'Jadwal lead time', group: 'Inventori', idPrefix: 'LT', softDelete: true,
      lineage: true, audit: true,
      description: 'Jadwal pengiriman terencana per PO/produk (D10). Tidak dihitung sebagai qty terkirim.',
      columns: [
        col_('purchase_order_id', 'ref', 'PO', { requiredUnlessLegacy: true, ref: 'PURCHASE_ORDERS' }),
        col_('po_line_id', 'ref', 'Baris PO', { ref: 'PO_LINES' }),
        col_('product_id', 'ref', 'Produk', { requiredUnlessLegacy: true, ref: 'PRODUCTS' }),
        col_('customer_id', 'ref', 'Customer', { ref: 'CUSTOMERS' }),
        col_('planned_date', 'date', 'Tanggal rencana kirim', { requiredUnlessLegacy: true }),
        col_('quantity', 'quantity', 'Qty rencana', { requiredUnlessLegacy: true, minExclusive: 0 }),
        col_('status', 'enum', 'Status', { enumName: 'DELIVERY_STATUS' }),
        col_('lead_time_days', 'integer', 'Lead time (hari)', { min: 0 }),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('po_number_legacy', 'string', 'Nomor PO legacy'),
        legacyCol_('product_legacy', 'string', 'Produk legacy')
      ],
      appendedColumns: [
        legacyCol_('status_legacy', 'string', 'Status legacy', { maxLength: 100, since: 2 })
      ],
      consistency: [
        {
          field: 'po_line_id', ref: 'PO_LINES',
          pairs: [['purchase_order_id', 'purchase_order_id'], ['product_id', 'product_id']]
        },
        { field: 'purchase_order_id', ref: 'PURCHASE_ORDERS', pairs: [['customer_id', 'customer_id']] }
      ]
    },
    {
      name: 'INBOUND_MAKLON', label: 'Inbound maklon', group: 'Inventori', idPrefix: 'INB', softDelete: true,
      lineage: true, audit: true,
      description: 'Penerimaan komponen di maklon. Kolom mengikuti sheet sumber yang diprofil di Phase 01.',
      columns: [
        col_('purchase_order_id', 'ref', 'PO', { requiredUnlessLegacy: true, ref: 'PURCHASE_ORDERS' }),
        col_('product_id', 'ref', 'Produk', { ref: 'PRODUCTS' }),
        col_('vendor', 'string', 'Vendor', { maxLength: 150 }),
        col_('receiver', 'string', 'Penerima', { maxLength: 150 }),
        col_('inbound_date', 'date', 'Tanggal masuk', { required: true }),
        col_('sj_date', 'date', 'Tanggal SJ'),
        col_('sj_number', 'string', 'Nomor SJ', { maxLength: 100 }),
        documentKeyCol_('sj_number_key', 'sj_number', 'Kunci nomor SJ'),
        col_('internal_component_code', 'string', 'Kode komponen internal', { maxLength: 100 }),
        col_('component_type', 'string', 'Jenis komponen', { maxLength: 100 }),
        col_('component_name', 'string', 'Nama komponen'),
        col_('factory_component_code', 'string', 'Kode komponen pabrik', { maxLength: 100 }),
        col_('quantity', 'quantity', 'Qty', { requiredUnlessLegacy: true, min: 0 }),
        col_('reject_quantity', 'quantity', 'Qty reject', { min: 0 }),
        col_('total_in', 'quantity', 'Total masuk', { min: 0 }),
        col_('attachment', 'string', 'Lampiran/keterangan SJ', {
          note: 'Teks bebas; di data legacy berisi nomor SJ, bukan URL.'
        }),
        col_('odoo_checklist', 'string', 'Checklist Odoo', { maxLength: 100 }),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('po_number_legacy', 'string', 'Nomor PO legacy')
      ]
    },
    {
      name: 'INVOICES_PAYMENTS', label: 'Invoice & pembayaran', group: 'Keuangan', idPrefix: 'PAY',
      softDelete: true, lineage: true, audit: true,
      description: 'Satu baris per invoice beserta pembayarannya. Prefiks ID PAY- mengikuti workbook (D12).',
      columns: [
        col_('purchase_order_id', 'ref', 'PO', { requiredUnlessLegacy: true, ref: 'PURCHASE_ORDERS' }),
        col_('invoice_number', 'string', 'Nomor invoice', { required: true, maxLength: 150 }),
        col_('invoice_type', 'enum', 'Jenis invoice', { enumName: 'INVOICE_TYPE' }),
        col_('invoice_date', 'date', 'Tanggal invoice', { required: true }),
        col_('due_date', 'date', 'Jatuh tempo'),
        col_('amount', 'money', 'Nilai invoice', { required: true, min: 0 }),
        col_('paid_amount', 'money', 'Nilai dibayar', { min: 0, defaultValue: 0 }),
        col_('payment_date', 'date', 'Tanggal bayar'),
        col_('payment_receipt_number', 'string', 'Nomor bukti bayar', { maxLength: 100 }),
        col_('payment_status', 'enum', 'Status bayar', {
          required: true, enumName: 'PAYMENT_STATUS', defaultValue: 'UNPAID'
        }),
        col_('invoice_attachment_url', 'url', 'Lampiran invoice'),
        col_('payment_attachment_url', 'url', 'Lampiran bukti bayar'),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('po_number_legacy', 'string', 'Nomor PO legacy'),
        legacyCol_('outstanding_legacy', 'money', 'Outstanding legacy')
      ],
      unique: [{
        columns: ['invoice_number'], normalize: 'document', description: 'invoice_number unik (tanpa spasi, huruf besar).',
        message: 'Nomor invoice sudah dipakai.'
      }],
      rules: [
        {
          description: 'due_date tidak boleh sebelum invoice_date.',
          check: function (record) {
            if (record.invoice_date && record.due_date && record.due_date < record.invoice_date) {
              return { field: 'due_date', message: 'Jatuh tempo tidak boleh sebelum tanggal invoice.' };
            }
            return null;
          }
        },
        {
          description: 'paid_amount tidak boleh melebihi amount.',
          check: function (record) {
            if (typeof record.amount === 'number' && typeof record.paid_amount === 'number' &&
                record.paid_amount > record.amount) {
              return { field: 'paid_amount', message: 'Nilai dibayar tidak boleh melebihi nilai invoice.' };
            }
            return null;
          }
        },
        {
          description: 'payment_date wajib bila paid_amount > 0.',
          check: function (record) {
            if (typeof record.paid_amount === 'number' && record.paid_amount > 0 && !record.payment_date) {
              return { field: 'payment_date', message: 'Tanggal bayar wajib diisi bila ada pembayaran.' };
            }
            return null;
          }
        }
      ]
    },
    {
      name: 'PO_FINANCIALS', label: 'Ringkasan keuangan PO', group: 'Keuangan', idPrefix: 'POF', softDelete: true,
      lineage: true, audit: true, migrationOnly: true,
      description: 'Ringkasan keuangan PO dari data legacy; referensi read-only (D11). Hanya proses migrasi yang menulis.',
      columns: [
        col_('purchase_order_id', 'ref', 'PO', { ref: 'PURCHASE_ORDERS' }),
        col_('brand', 'string', 'Brand', { maxLength: 150 }),
        col_('po_date', 'date', 'Tanggal PO'),
        col_('order_quantity', 'quantity', 'Qty order', { min: 0 }),
        col_('total_order_amount', 'money', 'Total order', { min: 0 }),
        col_('ppn_amount', 'money', 'PPN', { min: 0 }),
        col_('total_incl_ppn', 'money', 'Total termasuk PPN', { min: 0 }),
        col_('payment_status', 'enum', 'Status bayar', { enumName: 'PAYMENT_STATUS' }),
        col_('attachment_url', 'url', 'Lampiran PO'),
        col_('notes', 'text', 'Catatan'),
        legacyCol_('po_number_legacy', 'string', 'Nomor PO legacy', { required: true }),
        legacyCol_('product_legacy', 'string', 'Produk legacy'),
        legacyCol_('product_code_legacy', 'string', 'Kode produk legacy', { maxLength: 100 }),
        legacyCol_('unit_price_legacy', 'decimal', 'Harga satuan legacy', {
          note: 'Nilai asli tanpa normalisasi (skala di sumber tidak konsisten).'
        }),
        legacyCol_('delivered_qty_legacy', 'quantity', 'Qty terkirim legacy'),
        legacyCol_('undelivered_qty_legacy', 'quantity', 'Qty belum terkirim legacy'),
        legacyCol_('outstanding_amount_legacy', 'decimal', 'Outstanding (Rp) legacy', {
          note: 'Nilai asli tanpa pembulatan (sumber memuat artefak desimal).'
        }),
        legacyCol_('status_legacy', 'string', 'Status legacy', { maxLength: 100 })
      ]
    },
    {
      name: 'MIGRATION_ISSUES', label: 'Isu migrasi', group: 'Migrasi', idPrefix: 'MIG', extraIdPrefixes: ['PRF'],
      audit: true, insertAccess: M,
      description: 'Record legacy yang data/relasinya belum pasti. Diselesaikan Admin; relasi tidak pernah ditebak.',
      columns: [
        col_('severity', 'enum', 'Tingkat', { required: true, enumName: 'ISSUE_SEVERITY', writable: M }),
        col_('issue_type', 'string', 'Jenis isu', { required: true, maxLength: 100, writable: M }),
        col_('entity_type', 'string', 'Entitas', { required: true, maxLength: 100, writable: M }),
        col_('record_id', 'string', 'ID record', { maxLength: 50, writable: M }),
        col_('field', 'string', 'Kolom', { maxLength: 100, writable: M }),
        col_('value', 'text', 'Nilai', { writable: M }),
        col_('description', 'text', 'Deskripsi', { required: true, writable: M }),
        col_('candidate_reference', 'text', 'Kandidat', { writable: M }),
        col_('candidate_method', 'string', 'Metode kandidat', { maxLength: 100, writable: M }),
        col_('evidence', 'text', 'Bukti', { writable: M }),
        col_('decision_ref', 'string', 'Referensi keputusan', { maxLength: 50, writable: M }),
        col_('existing_issue_id', 'string', 'ID isu workbook', { maxLength: 50, writable: M }),
        col_('source_file', 'string', 'File sumber', { writable: M }),
        col_('source_sheet', 'string', 'Sheet sumber', { maxLength: 100, writable: M }),
        col_('legacy_row', 'integer', 'Baris sumber', { min: 1, writable: M }),
        col_('import_ref', 'string', 'Referensi impor', { writable: M }),
        col_('resolution_status', 'enum', 'Status penyelesaian', {
          required: true, enumName: 'RESOLUTION_STATUS', defaultValue: 'OPEN'
        }),
        col_('resolution_note', 'text', 'Catatan penyelesaian'),
        col_('resolved_by', 'string', 'Diselesaikan oleh', { writable: WRITABLE.INTERNAL }),
        col_('resolved_at', 'datetime', 'Diselesaikan pada', { writable: WRITABLE.INTERNAL })
      ]
    },
    {
      name: 'ENUMS', label: 'Enum', group: 'Sistem', kind: TABLE_KIND.SYSTEM,
      description: 'Nilai pilihan (status, role, jenis). Label, urutan, dan nilai tambahan boleh dikelola Admin; ' +
        'nilai bawaan sistem tidak boleh dihapus atau dinonaktifkan.',
      columns: [
        col_('enum_name', 'string', 'Nama enum', {
          required: true, maxLength: 50, pattern: UPPER_SNAKE_PATTERN, patternMessage: 'harus UPPER_SNAKE_CASE'
        }),
        col_('enum_value', 'string', 'Nilai', {
          required: true, maxLength: 50, pattern: UPPER_SNAKE_PATTERN, patternMessage: 'harus UPPER_SNAKE_CASE'
        }),
        col_('label', 'string', 'Label', { required: true, maxLength: 100 }),
        col_('sort_order', 'integer', 'Urutan', { required: true, min: 0 }),
        col_('is_active', 'boolean', 'Aktif', { required: true, defaultValue: true }),
        col_('description', 'text', 'Keterangan', { maxLength: 500 })
      ],
      unique: [{
        columns: ['enum_name', 'enum_value'], field: 'enum_value', description: '(enum_name, enum_value) unik.',
        message: 'Nilai enum sudah ada.'
      }]
    },
    {
      name: 'SETTINGS', label: 'Pengaturan', group: 'Sistem', kind: TABLE_KIND.SYSTEM,
      description: 'Pengaturan aplikasi (key-value). Nilai disimpan sebagai teks dan dibaca sesuai value_type.',
      columns: [
        col_('key', 'string', 'Kunci', {
          required: true, maxLength: 100, pattern: UPPER_SNAKE_PATTERN, patternMessage: 'harus UPPER_SNAKE_CASE'
        }),
        col_('value', 'text', 'Nilai'),
        col_('value_type', 'enum', 'Tipe nilai', { required: true, enumName: 'SETTING_TYPE' }),
        col_('description', 'text', 'Keterangan', { maxLength: 500 }),
        col_('is_system', 'boolean', 'Dikelola sistem', { required: true, defaultValue: false }),
        col_('updated_at', 'datetime', 'Diubah pada', { required: true }),
        col_('updated_by', 'string', 'Diubah oleh', { required: true })
      ],
      unique: [{ columns: ['key'], field: 'key', description: 'key unik.', message: 'Key setting sudah ada.' }]
    },
    {
      name: 'AUDIT_LOG', label: 'Audit log', group: 'Sistem', kind: TABLE_KIND.SYSTEM, idPrefix: 'AUD',
      idHexLength: AUDIT_ID_HEX_LENGTH,
      description: 'Jejak perubahan data, hanya ditambah (append-only). Ditulis otomatis oleh aplikasi.',
      columns: [
        col_('occurred_at', 'datetime', 'Waktu', { required: true }),
        col_('actor_email', 'string', 'Pelaku', { required: true }),
        col_('action', 'enum', 'Aksi', { required: true, enumName: 'AUDIT_ACTION' }),
        col_('entity_type', 'string', 'Tabel', { maxLength: 100 }),
        col_('entity_id', 'string', 'ID record', { maxLength: 50 }),
        col_('request_id', 'string', 'ID permintaan', { maxLength: 50 }),
        col_('changes_json', 'json', 'Perubahan (JSON)'),
        col_('note', 'text', 'Catatan')
      ]
    }
  ];
}

function standardIdColumn_() {
  return col_('id', 'id', 'ID', {
    required: true, writable: WRITABLE.AUTO, note: 'ID permanen; tidak pernah diubah dan bukan nomor baris.'
  });
}

function standardActiveColumn_() {
  return col_('is_active', 'boolean', 'Aktif', {
    required: true, defaultValue: true, writable: WRITABLE.ARCHIVE,
    note: 'FALSE = diarsipkan (soft delete). Data tidak dihapus permanen.'
  });
}

function standardLineageColumns_() {
  const M = WRITABLE.MIGRATION;
  return [
    col_('is_legacy', 'boolean', 'Data legacy', {
      required: true, defaultValue: false, writable: M, note: 'TRUE untuk record hasil migrasi data legacy (D3).'
    }),
    col_('source_file', 'string', 'File sumber legacy', { writable: M }),
    col_('source_sheet', 'string', 'Sheet sumber legacy', { maxLength: 100, writable: M }),
    col_('legacy_row', 'integer', 'Baris sumber legacy', { min: 1, writable: M }),
    col_('import_ref', 'string', 'Referensi impor', {
      writable: M, note: 'Asal di workbook migrasi: <file>#<SHEET>!<baris>.'
    }),
    col_('migrated_at', 'datetime', 'Waktu migrasi', { writable: M }),
    col_('migration_hash', 'string', 'Hash sumber migrasi', { maxLength: 64, writable: M })
  ];
}

function standardAuditColumns_() {
  const A = WRITABLE.AUTO;
  return [
    col_('created_at', 'datetime', 'Dibuat pada', { required: true, writable: A }),
    col_('created_by', 'string', 'Dibuat oleh', { required: true, writable: A }),
    col_('updated_at', 'datetime', 'Diubah pada', { required: true, writable: A }),
    col_('updated_by', 'string', 'Diubah oleh', { required: true, writable: A })
  ];
}

function compileSchema_(definitions) {
  const byName = {};
  const tables = definitions.map(function (definition, index) {
    const columns = [];
    if (definition.idPrefix) columns.push(standardIdColumn_());
    definition.columns.forEach(function (column) { columns.push(column); });
    if (definition.softDelete) columns.push(standardActiveColumn_());
    if (definition.lineage) standardLineageColumns_().forEach(function (column) { columns.push(column); });
    if (definition.audit) standardAuditColumns_().forEach(function (column) { columns.push(column); });
    (definition.appendedColumns || []).forEach(function (column) { columns.push(column); });

    const columnByName = {};
    columns.forEach(function (column) { columnByName[column.name] = column; });
    const idPrefixes = definition.idPrefix ? [definition.idPrefix].concat(definition.extraIdPrefixes || []) : [];
    const idHexLength = definition.idHexLength || ID_HEX_LENGTH;
    const idPatternForTable = idPrefixes.length ? idPattern_(idPrefixes, idHexLength) : null;
    if (idPatternForTable) {
      columnByName.id.pattern = idPatternForTable;
      columnByName.id.patternMessage = 'harus berformat ' + idPrefixes.join('/') + '-' + new Array(idHexLength + 1).join('X');
    }
    const table = Object.assign({}, definition, {
      index: index,
      kind: definition.kind || TABLE_KIND.DATA,
      columns: columns,
      columnNames: columns.map(function (column) { return column.name; }),
      columnByName: columnByName,
      idPrefixes: idPrefixes,
      idHexLength: idHexLength,
      idPattern: idPatternForTable,
      hasId: Boolean(definition.idPrefix),
      hasAudit: Boolean(definition.audit),
      softDelete: Boolean(definition.softDelete),
      lineage: Boolean(definition.lineage),
      migrationOnly: Boolean(definition.migrationOnly),
      insertAccess: definition.insertAccess || WRITABLE.USER,
      unique: definition.unique || [],
      rules: definition.rules || [],
      consistency: definition.consistency || []
    });
    byName[table.name] = table;
    return table;
  });
  return Object.freeze({ version: SCHEMA_VERSION, tables: tables, byName: byName });
}
