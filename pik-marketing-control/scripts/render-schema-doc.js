'use strict';

/**
 * Generates docs/DATABASE_SCHEMA.md from the Apps Script schema (src/db/Schema.gs, Enums.gs, Settings.gs), so the
 * documentation can never drift from the code. Usage: npm run docs:schema  (tests/docs.test.js checks freshness).
 */

const fs = require('node:fs');
const path = require('node:path');

const { PROJECT_ROOT, loadGasProject } = require('../tests/helpers/load-gas');

const OUTPUT = path.join(PROJECT_ROOT, 'docs', 'DATABASE_SCHEMA.md');

const WRITABLE_LABEL = {
  user: 'aplikasi',
  auto: 'sistem (otomatis)',
  internal: 'layanan server',
  migration: 'migrasi',
  archive: 'arsip/pulihkan',
};

const escape = (text) => String(text === null || text === undefined ? '' : text).replace(/\|/g, '\\|').replace(/\n/g, ' ');

function requiredLabel(column) {
  if (column.required) return 'wajib';
  if (column.requiredUnlessLegacy) return 'wajib (kecuali legacy)';
  return '';
}

function constraintLabel(column) {
  const parts = [];
  if (column.ref) parts.push(`→ ${column.ref}.id`);
  if (column.enumName) parts.push(`enum ${column.enumName}`);
  if (column.min !== null) parts.push(`≥ ${column.min}`);
  if (column.minExclusive !== null) parts.push(`> ${column.minExclusive}`);
  if (column.max !== null) parts.push(`≤ ${column.max}`);
  if (column.maxLength && !['id', 'ref', 'enum', 'date', 'datetime', 'time'].includes(column.type)) {
    parts.push(`maks ${column.maxLength} karakter`);
  }
  if (column.patternMessage) parts.push(column.patternMessage);
  return parts.join('; ');
}

function defaultLabel(column) {
  if (column.defaultValue === undefined) return '';
  if (typeof column.defaultValue === 'string') return `\`${column.defaultValue}\``;
  return `\`${String(column.defaultValue).toUpperCase()}\``;
}

function renderSchemaDoc() {
  const { context, run } = loadGasProject();
  const schema = context.getSchema_();
  const enums = context.getEnumDefinitions_();
  const settings = context.getSettingDefinitions_();
  const columnTypes = run('COLUMN_TYPES'); // top-level const: read through the script scope

  const lines = [];
  const out = (line = '') => lines.push(line);

  out('# DATABASE SCHEMA — PIK Marketing Control (Google Sheets)');
  out();
  out('> Dibuat otomatis dari `src/db/Schema.gs`, `src/db/Enums.gs`, dan `src/db/Settings.gs` dengan');
  out('> `npm run docs:schema`. Jangan diedit manual; `npm test` gagal bila dokumen ini tidak sesuai kode.');
  out();
  out(`**Versi skema:** ${schema.version} · **Jumlah sheet:** ${schema.tables.length}`);
  out();
  out('## 1. Konvensi');
  out();
  out('- **Satu sheet = satu tabel**, baris 1 = header (nama kolom `snake_case`), data mulai baris 2. Urutan sheet dan kolom');
  out('  adalah kontrak: kolom baru hanya ditambahkan di ujung kanan oleh `initializeDatabase()`; tidak pernah disisipkan,');
  out('  diganti nama, atau dihapus.');
  out('- **Tata letak kolom tabel data:** `id` → kolom bisnis → `is_active` → lineage (tabel hasil migrasi:');
  out('  `is_legacy`, `source_file`, `source_sheet`, `legacy_row`, `import_ref`, `migrated_at`, `migration_hash`) →');
  out('  `created_at`, `created_by`, `updated_at`, `updated_by`.');
  out('- **ID stabil:** `PREFIX-XXXXXXXXXX` (10 digit heksadesimal huruf besar; `AUDIT_LOG` 16 digit). Format ini sama dengan');
  out('  ID workbook sehingga ID legacy dipakai apa adanya (D12). ID dibuat dari bit acak UUID, dicek terhadap ID yang sudah');
  out('  ada, tidak pernah berasal dari nomor baris, dan tidak dapat diubah.');
  out('- **Soft delete:** record tidak pernah dihapus permanen; arsip = `is_active` FALSE. Relasi baru tidak boleh menunjuk');
  out('  record yang diarsipkan; relasi lama tetap sah.');
  out('- **Data legacy:** `is_legacy` TRUE hanya dapat diisi oleh proses migrasi. Baris legacy divalidasi secara struktural');
  out('  (tipe, enum, relasi, keunikan); kolom "wajib (kecuali legacy)", batas angka, dan aturan tabel hanya berlaku untuk');
  out('  data baru (D3, D9). Kolom `*_legacy` menyimpan nilai asli sebagai referensi.');
  out('- **Audit:** setiap insert/update/arsip/pulihkan menulis `AUDIT_LOG` dalam lock yang sama; `created_by`/`updated_by`');
  out('  berisi email pelaku.');
  out('- **Nilai teks** di-trim; teks yang diawali `=` atau `\'` ditolak (mencegah formula dan apostrof yang ditelan Sheets).');
  out();
  out('### Tipe data logis');
  out();
  out('| Tipe | Isi | Format sel Sheets |');
  out('|---|---|---|');
  for (const type of Object.keys(columnTypes)) {
    const format = context.columnNumberFormat_({ type });
    out(`| \`${type}\` | ${escape(columnTypes[type])} | ${format === null ? 'bawaan + checkbox' : `\`${format}\``} |`);
  }
  out();
  out('## 2. Daftar sheet');
  out();
  out('| # | Sheet | Kelompok | Prefiks ID | Kolom | Keterangan |');
  out('|---:|---|---|---|---:|---|');
  schema.tables.forEach((table, index) => {
    const prefix = table.idPrefixes.length ? table.idPrefixes.map((p) => `\`${p}-\``).join(', ') : '—';
    out(`| ${index + 1} | \`${table.name}\` | ${table.group} | ${prefix} | ${table.columns.length} | ${escape(table.description)} |`);
  });
  out();
  out('## 3. Relasi');
  out();
  out('```mermaid');
  out('erDiagram');
  schema.tables.forEach((table) => {
    table.columns.filter((column) => column.ref).forEach((column) => {
      const cardinality = column.required ? '||--o{' : '|o--o{';
      out(`  ${column.ref} ${cardinality} ${table.name} : ${column.name}`);
    });
  });
  out('```');
  out();
  out('| Tabel.kolom | Merujuk | Wajib | Konsistensi |');
  out('|---|---|---|---|');
  schema.tables.forEach((table) => {
    table.columns.filter((column) => column.ref).forEach((column) => {
      const rules = table.consistency.filter((rule) => rule.field === column.name)
        .map((rule) => rule.pairs.map((pair) => `${pair[0]} = ${rule.ref}.${pair[1]}`).join(', '));
      out(`| \`${table.name}.${column.name}\` | \`${column.ref}.id\` | ${requiredLabel(column) || 'opsional'} | ${escape(rules.join('; '))} |`);
    });
  });
  out();
  out('Aturan relasi: nilai relasi harus berformat ID tabel tujuan dan record tujuan harus ada (tidak ada record yatim).');
  out('Relasi baru atau yang diubah harus menunjuk record aktif (kecuali proses migrasi). Kolom konsistensi: bila relasi');
  out('diisi, nilai kolom lokal harus sama dengan nilai di record tujuan; pada data legacy, kolom lokal boleh kosong tetapi');
  out('tidak boleh bertentangan.');
  out();
  out('## 4. Kolom per sheet');
  out();
  out('Kolom "Ditulis oleh": *aplikasi* = input pengguna melalui layanan; *sistem* = diisi repository; *layanan server* =');
  out('hanya layanan internal; *migrasi* = hanya proses migrasi; *arsip/pulihkan* = hanya fungsi arsip/pulihkan.');
  schema.tables.forEach((table, index) => {
    out();
    out(`### 4.${index + 1} \`${table.name}\` — ${table.label}`);
    out();
    out(escape(table.description));
    const facts = [];
    if (table.idPrefixes.length) facts.push(`ID: ${table.idPrefixes.map((p) => `\`${p}-\``).join(' / ')}`);
    if (table.softDelete) facts.push('soft delete');
    if (table.lineage) facts.push('lineage migrasi');
    if (table.migrationOnly) facts.push('read-only (hanya migrasi yang menulis)');
    if (table.insertAccess === 'migration') facts.push('record baru hanya dari migrasi');
    if (table.kind !== 'data') facts.push('dikelola sistem (bukan lewat repository umum)');
    if (facts.length) {
      out();
      out(facts.join(' · '));
    }
    out();
    out('| # | Kolom | Tipe | Wajib | Relasi / batasan | Default | Ditulis oleh | Catatan |');
    out('|---:|---|---|---|---|---|---|---|');
    table.columns.forEach((column, columnIndex) => {
      out(`| ${columnIndex + 1} | \`${column.name}\` | ${column.type} | ${requiredLabel(column)} | ${escape(constraintLabel(column))} | ` +
        `${defaultLabel(column)} | ${WRITABLE_LABEL[column.writable]} | ${escape([column.label, column.note].filter(Boolean).join('. '))} |`);
    });
    if (table.unique.length || table.rules.length) {
      out();
      table.unique.forEach((constraint) => out(`- **Unik:** ${escape(constraint.description)}`));
      table.rules.forEach((rule) => out(`- **Aturan (data baru):** ${escape(rule.description)}`));
    }
  });
  out();
  out('## 5. ENUMS');
  out();
  out('Nilai bawaan di bawah di-seed ke sheet `ENUMS` (hanya yang belum ada). Label, urutan, dan nilai tambahan boleh');
  out('dikelola Admin; nilai bawaan tidak boleh dihapus atau dinonaktifkan (`verifyDatabase` memeriksanya). Enum bertanda');
  out('*extensible* boleh diberi nilai tambahan.');
  enums.forEach((definition) => {
    out();
    out(`### \`${definition.name}\`${definition.extensible ? ' (extensible)' : ''}`);
    out();
    out(escape(definition.description));
    out();
    out('| Nilai | Label | Keterangan |');
    out('|---|---|---|');
    definition.values.forEach((value) => out(`| \`${value[0]}\` | ${escape(value[1])} | ${escape(value[2] || '')} |`));
  });
  out();
  out('## 6. SETTINGS');
  out();
  out('| Key | Tipe | Nilai awal | Dikelola sistem | Keterangan |');
  out('|---|---|---|---|---|');
  settings.forEach((setting) => {
    const initial = setting.value === null ? '(waktu inisialisasi)' : `\`${setting.value}\``;
    out(`| \`${setting.key}\` | ${setting.type} | ${initial} | ${setting.system ? 'ya' : 'tidak'} | ${escape(setting.description)} |`);
  });
  out();
  out('Konfigurasi deployment dan rahasia tidak disimpan di sheet ini, melainkan di Script Properties');
  out('(`DATABASE_SPREADSHEET_ID`, `DRIVE_ROOT_FOLDER_ID`, `APP_NAME`, `TIMEZONE`, `ADMIN_EMAILS`).');
  out();
  out('## 7. Kode kesalahan validasi');
  out();
  out('| Kode | Arti |');
  out('|---|---|');
  [
    ['REQUIRED', 'Kolom wajib kosong.'],
    ['TYPE', 'Tipe/format nilai salah (teks vs angka, tanggal yyyy-MM-dd, email, URL, jam, desimal).'],
    ['MAX_LENGTH', 'Teks melebihi panjang maksimum.'],
    ['PATTERN', 'Format ID/relasi salah, teks diawali `=` atau `\'`, atau pola khusus kolom tidak terpenuhi.'],
    ['ENUM', 'Nilai tidak ada di ENUMS.'],
    ['ENUM_INACTIVE', 'Nilai enum sudah dinonaktifkan (hanya untuk nilai baru/berubah).'],
    ['MIN / MAX', 'Angka di luar batas (data baru).'],
    ['REF_NOT_FOUND', 'Record yang dirujuk tidak ada.'],
    ['REF_INACTIVE', 'Record yang dirujuk sudah diarsipkan (relasi baru/berubah).'],
    ['REF_MISMATCH', 'Relasi saling bertentangan (mis. baris PO milik PO lain).'],
    ['UNIQUE', 'Melanggar keunikan.'],
    ['RULE', 'Melanggar aturan tabel (data baru).'],
    ['UNKNOWN_FIELD', 'Kolom tidak dikenal.'],
    ['SYSTEM_FIELD', 'Kolom diisi sistem atau hanya lewat fungsi khusus.'],
    ['LEGACY_FORBIDDEN', 'Kolom legacy/lineage hanya boleh diisi proses migrasi.'],
  ].forEach(([code, meaning]) => out(`| \`${code}\` | ${escape(meaning)} |`));
  out();
  out('### Kode tambahan dari `verifyDatabase()`');
  out();
  out('| Kode | Tingkat | Arti |');
  out('|---|---|---|');
  [
    ['SHEET_MISSING', 'error', 'Sheet tabel tidak ada.'],
    ['HEADER_MISMATCH', 'error', 'Header tidak sama dengan skema (urutan/nama/kolom kurang).'],
    ['DUPLICATE_ID', 'error', 'ID dipakai lebih dari satu baris.'],
    ['DERIVED_MISMATCH', 'error', 'Kolom kunci turunan (`*_key`) tidak sesuai kolom sumbernya.'],
    ['ENUM_SEED_MISSING / ENUM_SEED_INACTIVE', 'error', 'Nilai enum bawaan hilang atau dinonaktifkan.'],
    ['SETTING_MISSING / SETTING_TYPE / SETTING_INVALID', 'error', 'Setting bawaan hilang, tipe salah, atau nilai tidak sesuai tipe.'],
    ['SCHEMA_VERSION', 'error', 'Versi skema database berbeda dari kode.'],
    ['EXTRA_COLUMNS', 'peringatan', 'Ada kolom di luar skema di kanan tabel.'],
    ['HEADER_NOT_FROZEN', 'peringatan', 'Baris header tidak dibekukan.'],
    ['UNKNOWN_SHEET', 'peringatan', 'Ada sheet yang tidak dikenal skema.'],
    ['PLACEHOLDER_ROWS', 'peringatan', 'Baris tanpa data berisi checkbox FALSE (mis. dari Insert > Checkbox).'],
    ['ENUM_UNKNOWN / ENUM_VALUE_UNKNOWN', 'peringatan', 'Enum atau nilai tambahan yang belum dikenali kode.'],
    ['REF_NOT_CHECKED', 'peringatan', 'Relasi ke sheet yang bermasalah tidak dapat diperiksa.'],
  ].forEach(([code, level, meaning]) => out(`| \`${code}\` | ${level} | ${escape(meaning)} |`));
  out();
  return `${lines.join('\n')}`;
}

if (require.main === module) {
  fs.writeFileSync(OUTPUT, renderSchemaDoc());
  console.log(`Wrote ${path.relative(PROJECT_ROOT, OUTPUT)}`);
}

module.exports = { OUTPUT, renderSchemaDoc };
