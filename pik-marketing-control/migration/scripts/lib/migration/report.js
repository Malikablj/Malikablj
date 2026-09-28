'use strict';

/**
 * REPORT step: turns the package and the results of the Apps Script entry points (rehearsed in the emulator, or run in
 * Apps Script and downloaded from Drive) into migration-summary.json and the Markdown migration report.
 * The report contains business data (counts, totals, IDs) and is therefore written to ignored locations only.
 */

const MAX_ROWS = 40;

const fmt = (value) => {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'number') return value.toLocaleString('id-ID', { maximumFractionDigits: 6 });
  return String(value);
};
const code = (value) => `\`${value}\``;
const cell = (value) => fmt(value).replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');

function table(header, rows) {
  return [
    `| ${header.join(' | ')} |`,
    `|${header.map(() => '---').join('|')}|`,
    ...rows.map((row) => `| ${row.map(cell).join(' | ')} |`),
  ].join('\n');
}

function status(ok) {
  return ok ? 'OK' : 'GAGAL';
}

function countBy(items, keyOf) {
  const counts = {};
  for (const item of items) counts[keyOf(item)] = (counts[keyOf(item)] || 0) + 1;
  return counts;
}

function findCheck(verify, prefix) {
  return verify ? verify.checks.find((check) => check.name.startsWith(prefix)) : null;
}

/** Condensed machine-readable summary of one pipeline run. */
function buildSummary({ pkg, analysis, steps, generatedAt }) {
  const dry = steps.dryRun && steps.dryRun.result;
  const runs = (steps.migrate || []).map((run) => run.result).filter(Boolean);
  const last = runs[runs.length - 1] || null;
  const verify = steps.verify && steps.verify.result;
  const rerun = steps.rerun && steps.rerun.result;
  return {
    generatedAt,
    mode: steps.mode,
    source: { ...pkg.source, sha256UnchangedAfterRead: true },
    mappingVersion: pkg.mappingVersion,
    schemaVersion: pkg.schemaVersion,
    packageHash: pkg.packageHash,
    profile: { sheets: pkg.expectations.sheets, profilerIssues: analysis ? analysis.result.issues.length : null },
    package: {
      records: Object.fromEntries(pkg.loadOrder.map((name) => [name, pkg.tables[name].length])),
      dispositions: pkg.expectations.dispositions,
      structuralErrors: pkg.structuralErrors.length,
      transformations: pkg.transformations.length,
    },
    validate: steps.validate ? (steps.validate.error || { ok: steps.validate.result.ok, errors: steps.validate.result.integrity.errorCount }) : null,
    dryRun: dry ? { ok: dry.ok, errorCount: dry.errorCount, errorsByCode: dry.errorsByCode, plan: dry.plan,
      spreadsheetWrites: steps.dryRun.spreadsheetWrites, warnings: dry.warnings } : (steps.dryRun && steps.dryRun.error) || null,
    migrate: last ? { runs: runs.length, completed: last.completed, tables: runs.map((run) => run.tables),
      spreadsheetWrites: steps.migrate.reduce((total, run) => total + run.spreadsheetWrites, 0) } : null,
    rerun: rerun ? { completed: rerun.completed, tables: rerun.tables, spreadsheetWrites: steps.rerun.spreadsheetWrites } : null,
    verify: verify ? { ok: verify.ok, summary: verify.summary,
      checks: verify.checks.map((check) => ({ name: check.name, status: check.status })) } : null,
    reconciliation: verify && verify.reconciliation ? verify.reconciliation.summary : null,
  };
}

/** Impact of each open decision on this package, in numbers. */
function decisionImpact(id, pkg, rec) {
  const empty = (key) => (pkg.expectations.unmatched[key] ? pkg.expectations.unmatched[key].count : 0);
  if (id === 'D3') {
    const lines = [
      `record legacy dengan relasi wajib kosong: ${fmt(empty('PURCHASE_ORDERS.customer_id'))} PO tanpa customer, ` +
        `${fmt(empty('DELIVERIES.purchase_order_id'))} delivery tanpa PO, ${fmt(empty('DELIVERIES.product_id'))} delivery tanpa ` +
        `produk, ${fmt(empty('RETURNS.product_id'))} retur tanpa produk, ${fmt(empty('STOCK.product_id'))} stok tanpa produk, ` +
        `${fmt(empty('INVOICES_PAYMENTS.purchase_order_id'))} invoice tanpa PO`,
    ];
    if (rec) {
      const open = rec.outstanding.openPurchaseOrders;
      lines.push(`outstanding PO terbuka dengan A: ${fmt(open.computedTotal)} pcs dihitung dari transaksi tertaut vs ` +
        `${fmt(open.legacyTotal)} pcs menurut nilai legacy (${fmt(open.purchaseOrdersDifferent)} dari ${fmt(open.purchaseOrders)} PO ` +
        'terbuka berbeda); selisih mengecil setiap kali Admin menautkan delivery legacy');
    }
    return lines;
  }
  if (id === 'D4') {
    const count = pkg.tables.PURCHASE_ORDERS.filter((po) => po.status === 'ON_HOLD').length;
    return [`${fmt(count)} PO berstatus ON_HOLD (teks asli di status_legacy)`];
  }
  return [];
}

function decisionSection(decisions, pkg, rec) {
  const lines = [];
  for (const decision of decisions.filter((item) => item.status === 'OPEN')) {
    lines.push(`### DECISION REQUIRED — ${decision.id}: ${decision.topic}`, '',
      `- **Diterapkan di paket ini (default, dapat dibalik):** ${decision.applied}`,
      ...decisionImpact(decision.id, pkg, rec).map((line) => `- **Dampak pada data ini:** ${line}.`),
      `- **Alternatif:** ${decision.alternatives}`,
      '- **Status:** menunggu konfirmasi pemilik. Migrasi produksi sebaiknya dijalankan setelah keputusan ini dikonfirmasi;',
      '  bila alternatif dipilih, pemetaan diubah, paket dibuat ulang, dan rerun memperbarui record yang terdampak.', '');
  }
  lines.push('Keputusan lain yang dipakai pemetaan (default, lihat `docs/IMPLEMENTATION_PLAN.md` §2):', '');
  for (const decision of decisions.filter((item) => item.status !== 'OPEN')) lines.push(`- ${decision.id} ${decision.topic}: ${decision.applied}`);
  return lines;
}

/** Markdown report (Indonesian), written next to the other confidential Phase 01–03 documents. */
function buildMarkdownReport({ pkg, analysis, steps, generatedAt, outputs, decisions }) {
  const out = [];
  const add = (...lines) => out.push(...lines);
  const dry = steps.dryRun && steps.dryRun.result;
  const runs = (steps.migrate || []).map((run) => run.result).filter(Boolean);
  const last = runs[runs.length - 1] || null;
  const verify = steps.verify && steps.verify.result;
  const rerun = steps.rerun && steps.rerun.result;
  const issues = pkg.tables.MIGRATION_ISSUES;
  const rehearsal = steps.mode === 'emulator';

  add('# MIGRATION REPORT — PIK Marketing Control (Phase 03)', '');
  add('> **RAHASIA** — berisi data bisnis PIK (jumlah, total rupiah, ID, nomor dokumen). File ini di-ignore git (D2) dan',
    '> dibuat ulang dengan `npm run migrate -- migrate`. Jangan diedit manual.', '');
  add(table(['Item', 'Nilai'], [
    ['Dibuat', generatedAt],
    ['Mode', rehearsal ? 'Gladi (rehearsal) di emulator Apps Script — database produksi Google Sheets BELUM diisi' : steps.mode],
    ['File sumber', `${pkg.source.file} (${fmt(pkg.source.sizeBytes)} byte)`],
    ['SHA-256 sumber', `${code(pkg.source.sha256)} — sama sebelum & sesudah dibaca (file tidak diubah)`],
    ['Tanggal acuan (as-of)', pkg.source.asOf],
    ['Versi pemetaan / skema', `${pkg.mappingVersion} / v${pkg.schemaVersion}`],
    ['Hash paket', code(pkg.packageHash)],
  ]), '');

  // 1. Pipeline
  add('## 1. Ringkasan pipeline', '');
  const validateOk = steps.validate && steps.validate.result && steps.validate.result.ok;
  const verifyCounts = verify ? verify.summary : null;
  const rerunWrites = steps.rerun ? steps.rerun.spreadsheetWrites : null;
  add(table(['Langkah', 'Status', 'Keterangan'], [
    ['PROFILE', 'OK', `${Object.keys(pkg.expectations.sheets).length} sheet, ${fmt(pkg.accounting.length)} baris data; ` +
      `${fmt(analysis ? analysis.result.issues.length : null)} isu profil (Phase 01)`],
    ['MAP', pkg.structuralErrors.length === 0 ? 'OK' : 'GAGAL', `${fmt(pkg.transformations.length)} nilai ditransformasi ` +
      `(tercatat); ${pkg.structuralErrors.length} error struktural`],
    ['VALIDATE', status(validateOk), steps.validate && steps.validate.result ? `integritas paket: ${steps.validate.result.integrity.errorCount} error` : 'tidak dijalankan'],
    ['DRY RUN', dry ? status(dry.ok) : 'GAGAL', dry ? `${dry.errorCount} error validasi; ${steps.dryRun.spreadsheetWrites} penulisan ke spreadsheet` : 'tidak selesai'],
    ['MIGRATE', last ? (last.completed ? 'OK' : 'BELUM SELESAI') : 'tidak dijalankan',
      last ? `${runs.length} kali jalan; ${fmt(runs.reduce((t, run) => t + Object.values(run.tables).reduce((s, c) => s + c.inserted, 0), 0))} record ditulis` : 'diblokir: dry run tidak lolos'],
    ['VERIFY', verify ? (verify.ok ? 'LULUS' : 'GAGAL') : 'tidak dijalankan',
      verifyCounts ? `${verifyCounts.pass} lulus, ${verifyCounts.fail} gagal, ${verifyCounts.info} informasi` : ''],
    ['RERUN (idempoten)', rerun ? (rerunWrites === 0 ? 'OK' : 'GAGAL') : 'tidak dijalankan',
      rerun ? `${rerunWrites} penulisan ke spreadsheet saat migrasi dijalankan ulang` : ''],
    ['REPORT', 'OK', 'dokumen ini + file di `migration/reports/`'],
  ]), '');

  // 2. Source vs migrated
  add('## 2. Jumlah record: sumber vs termigrasi', '');
  const recordCheck = (name) => findCheck(verify, `Record ${name}:`);
  add(table(['Sheet sumber → tabel', 'Baris sumber', 'Dikecualikan (+ isu)', 'Diharapkan', 'Di database', 'Hilang', 'Duplikat import_ref', 'Isi beda', 'Tak terduga'],
    pkg.loadOrder.filter((name) => pkg.expectations.counts[name]).map((name) => {
      const counts = pkg.expectations.counts[name];
      const check = recordCheck(name);
      const details = check ? check.details : {};
      return [name, counts.sourceRows, counts.excludedRows, counts.expectedRecords, check ? details.found : '—',
        check ? details.missing : '—', check ? details.duplicateImportRefs.length : '—', check ? details.contentMismatches : '—',
        check ? details.unexpectedLegacyRecords.length : '—'];
    })), '');
  const issueCheck = recordCheck('MIGRATION_ISSUES');
  add(`MIGRATION_ISSUES: ${fmt(issues.length)} isu di paket` + (issueCheck ? `, ${fmt(issueCheck.details.found)} tersimpan, ` +
    `${fmt(issueCheck.details.missing)} hilang.` : '.'), '');

  // 3. Accounting
  add('## 3. Setiap baris sumber tercatat (accounting)', '');
  add('Disposisi: **MIGRATED** = menjadi record tabel bisnis; **EXCLUDED** = bukan data bisnis (header/total/baris kosong),',
    'baris asli lengkap disimpan di isunya; **ISSUE_ONLY** = hanya di MIGRATION_ISSUES; **REPRESENTED** = sudah diwakili',
    '(isu workbook oleh isu Phase 01, nilai ENUMS oleh seed database); **NOT_MIGRATED** = dokumentasi workbook.', '');
  const bySheet = {};
  for (const entry of pkg.accounting) {
    const row = bySheet[entry.sheet] || (bySheet[entry.sheet] = { MIGRATED: 0, EXCLUDED: 0, ISSUE_ONLY: 0, REPRESENTED: 0, NOT_MIGRATED: 0, total: 0 });
    row[entry.disposition]++;
    row.total++;
  }
  add(table(['Sheet', 'Baris', 'MIGRATED', 'EXCLUDED', 'ISSUE_ONLY', 'REPRESENTED', 'NOT_MIGRATED'],
    Object.entries(bySheet).map(([sheet, row]) => [sheet, row.total, row.MIGRATED, row.EXCLUDED, row.ISSUE_ONLY, row.REPRESENTED, row.NOT_MIGRATED])), '');
  const reasons = countBy(pkg.accounting.filter((entry) => entry.reason), (entry) => `${entry.sheet} · ${entry.disposition} · ${entry.reason}`);
  add(table(['Alasan', 'Baris'], Object.entries(reasons)), '');
  const accountingCheck = findCheck(verify, 'Setiap baris sumber');
  if (accountingCheck) add(`Verifikasi: **${accountingCheck.status}** — ${fmt(accountingCheck.details.unaccounted)} baris tidak tercatat.`, '');

  // 4. Checks
  add('## 4. Hasil verifikasi', '');
  if (verify) {
    add(table(['Pemeriksaan', 'Status'], verify.checks.map((check) => [check.name, check.status])), '');
    const failed = verify.checks.filter((check) => check.status === 'FAIL');
    if (failed.length) {
      add('Detail pemeriksaan yang gagal:', '');
      for (const check of failed) add(`- ${check.name}: \`${JSON.stringify(check.details).slice(0, 1500)}\``);
      add('');
    }
    const db = findCheck(verify, 'verifyDatabase');
    if (db) add(`\`verifyDatabase\` atas seluruh database (termasuk AUDIT_LOG): ${fmt(db.details.rows)} baris, ` +
      `${fmt(db.details.errors)} error, ${fmt(db.details.warnings)} peringatan.`, '');
  } else {
    add('Verifikasi tidak dijalankan.', '');
  }

  // 5. Relations
  add('## 5. Relasi: unmatched, orphan, referensi tidak valid', '');
  add('- **Orphan & referensi tidak valid** diperiksa `verifyDatabase` untuk setiap kolom relasi (ID harus ada di tabel tujuan) dan',
    '  konsistensi relasi (mis. baris PO milik PO yang sama). Hasil: lihat §4.',
    '- **Relasi tidak ditebak.** Relasi diisi hanya dari ID workbook (R-1) atau kecocokan persis & unik (R-3). Relasi yang kosong',
    '  tetap kosong dan dijelaskan isu (kandidat hanya di kolom kandidat isu, tidak diterapkan).', '');
  const unmatched = findCheck(verify, 'Relasi tidak cocok');
  if (unmatched) {
    add(table(['Relasi', 'Kosong (paket)', 'Kosong (database)', 'Tanpa isu penjelas', 'Status'],
      Object.entries(unmatched.details.relations).map(([key, value]) => [key, value.expected, value.found, value.withoutIssue,
        unmatched.details.failed.includes(key) ? 'FAIL' : 'PASS'])), '');
  }
  const filled = findCheck(verify, 'Relasi terisi');
  if (filled) {
    add(table(['Relasi', 'Kolom ID sumber', 'ID di sumber', 'ID tidak dikenal (FK_NOT_FOUND)', 'Terisi di database', 'Status'],
      Object.entries(filled.details.counts).map(([key, value]) => [key, value.source, value.sourceIds, value.unknownIds, value.found,
        filled.details.failed.includes(key) ? 'FAIL' : 'PASS'])), '');
  }
  const r3 = pkg.transformations.filter((item) => item.rule.startsWith('R-3'));
  add(`Kecocokan R-3 (nomor PO / kode komponen persis dan unik, sheet INBOUND_MAKLON): ${fmt(r3.length)} relasi; ` +
    'daftar lengkapnya di `migration-transformations.csv`.', '');

  // 6. Totals
  add('## 6. Total numerik: sumber vs database', '');
  add('Total sumber dihitung langsung dari baris workbook (bukan dari hasil pemetaan), total database dari record tersimpan.', '');
  const sums = findCheck(verify, 'Total numerik');
  if (sums) {
    const entries = Object.entries(sums.details.sums);
    const lineage = entries.filter(([key]) => key.endsWith('.legacy_row'));
    add(table(['Kolom database', 'Kolom sumber', 'Total sumber', 'Total database', 'Selisih', 'Status'],
      entries.filter(([key]) => !key.endsWith('.legacy_row')).map(([key, value]) => [key, value.source, value.expected, value.found,
        Math.round((value.found - value.expected) * 1e6) / 1e6, sums.details.failed.includes(key) ? 'FAIL' : 'PASS'])), '');
    add('Kolom uang dibulatkan 2 desimal per record (T-04); selisih yang diizinkan hanya sebesar pembulatan itu.',
      `Checksum lineage \`legacy_row\` (${lineage.length} tabel): ` +
      `${lineage.filter(([key]) => !sums.details.failed.includes(key)).length} cocok, ` +
      `${lineage.filter(([key]) => sums.details.failed.includes(key)).length} tidak cocok.`, '');
  }

  // 7. Reconciliation
  add('## 7. Rekonsiliasi bisnis (informasi)', '');
  const rec = verify && verify.reconciliation ? verify.reconciliation.summary : null;
  if (rec) {
    add('### PO dan quantity', '');
    add(table(['Item', 'Nilai'], [
      ['PO', rec.purchaseOrders.count], ['PO tanpa customer', rec.purchaseOrders.withoutCustomer],
      ['PO tanpa baris', rec.purchaseOrders.withoutLines], ['Baris PO', rec.purchaseOrders.lines],
      ['Total qty order', rec.purchaseOrders.orderQuantity],
    ]), '');
    add('### Delivery dan retur', '');
    add(table(['Item', 'Delivery', 'Retur'], [
      ['Record', rec.deliveries.count, rec.returns.count],
      ['Total qty', rec.deliveries.quantity, rec.returns.quantity],
      ['Tertaut ke baris PO', rec.deliveries.linkedToLine, rec.returns.linkedToLine],
      ['Qty tertaut ke baris PO', rec.deliveries.quantityLinkedToLine, '—'],
      ['Tanpa PO', rec.deliveries.withoutPo, rec.returns.withoutPo],
      ['Qty negatif (D9)', rec.deliveries.negativeQuantity, '—'],
    ]), '');
    add('### Outstanding quantity', '');
    add(`Rumus: ${rec.outstanding.formula}.`, '');
    add(table(['Item', 'Nilai'], [
      ['Baris PO', rec.outstanding.lines], ['Outstanding legacy kosong', rec.outstanding.legacyEmpty],
      ['Sama dengan legacy', rec.outstanding.equalToLegacy], ['Berbeda dari legacy', rec.outstanding.differentFromLegacy],
      ['… di PO yang punya delivery/retur belum tertaut', rec.outstanding.differentOnPoWithUnlinkedTransactions],
      ['Total outstanding hitung', rec.outstanding.computedTotal], ['Total outstanding legacy', rec.outstanding.legacyTotal],
      ['PO dengan outstanding berbeda', rec.outstanding.purchaseOrdersDifferent],
    ]), '');
    const open = rec.outstanding.openPurchaseOrders;
    add(`Khusus ${open.scope}:`, '');
    add(table(['Item', 'Nilai'], [
      ['PO terbuka', open.purchaseOrders], ['Baris PO', open.lines], ['Outstanding hitung', open.computedTotal],
      ['Outstanding legacy', open.legacyTotal], ['Selisih', Math.round((open.computedTotal - open.legacyTotal) * 1e6) / 1e6],
      ['PO terbuka dengan outstanding berbeda', open.purchaseOrdersDifferent],
    ]), '');
    add('Perbedaan ini sudah diketahui sejak Phase 01 (delivery legacy yang belum tertaut ke baris PO) dan menjadi inti D3. ' +
      'Rincian per PO: `outstanding-reconciliation.csv`.', '');
    const examples = verify.reconciliation.outstandingExamples.slice(0, 10);
    if (examples.length) {
      add(table(['Baris PO', 'PO', 'Order', 'Kirim tertaut', 'Retur tertaut', 'Hitung', 'Legacy', 'PO punya transaksi tak tertaut'],
        examples.map((e) => [e.po_line_id, e.purchase_order_id, e.order, e.deliveredLinked, e.returnedLinked, e.computed, e.legacy,
          e.poHasUnlinkedTransactions ? 'ya' : 'tidak'])), '');
    }
    add('### Invoice dan pembayaran', '');
    add(table(['Item', 'Nilai'], [
      ['Invoice', rec.invoices.count], ['Total invoice (Rp)', rec.invoices.amount], ['Total pembayaran (Rp)', rec.invoices.paid],
      ['Invoice − pembayaran (Rp)', rec.invoices.amountMinusPaid], ['Outstanding legacy (Rp)', rec.invoices.outstandingLegacy],
      ['Invoice tanpa PO', rec.invoices.withoutPo],
    ]), '');
    add(table(['Status bayar', 'Invoice', 'Nilai (Rp)', 'Dibayar (Rp)', 'Outstanding legacy (Rp)'],
      Object.entries(rec.invoices.byPaymentStatus).map(([key, value]) => [key, value.count, value.amount, value.paid, value.outstandingLegacy])), '');
    add('### Ringkasan keuangan PO (PO_FINANCIALS, referensi legacy D11)', '');
    add(table(['Item', 'Nilai'], [
      ['Record', rec.poFinancials.count], ['Total order (Rp)', rec.poFinancials.totalOrderAmount], ['PPN (Rp)', rec.poFinancials.ppn],
      ['Total incl. PPN (Rp)', rec.poFinancials.totalInclPpn], ['Outstanding legacy (Rp)', rec.poFinancials.outstandingAmountLegacy],
      ['Tanpa PO', rec.poFinancials.withoutPo],
    ]), '');
  } else {
    add('Rekonsiliasi belum tersedia (migrasi belum dijalankan).', '');
  }

  // 8. Transformations
  add('## 8. Transformasi (traceability)', '');
  add('Setiap nilai yang berbeda dari sel sumbernya tercatat dengan aturan, nilai asal, dan nilai hasil di',
    '`migration-transformations.csv`. Setiap record menyimpan `import_ref` (file#SHEET!baris workbook), `source_file`,',
    '`source_sheet`, `legacy_row` (bila ada di sumber), `migration_hash`, dan `migrated_at`. ID record = ID workbook (bukan nomor baris).', '');
  add(table(['Tabel', 'Kolom', 'Aturan', 'Nilai'],
    Object.entries(countBy(pkg.transformations, (t) => `${t.table}\u0000${t.column}\u0000${t.rule}`))
      .map(([key, count]) => [...key.split('\u0000'), count])), '');

  // 9. Issues
  add('## 9. MIGRATION_ISSUES', '');
  add(table(['Tingkat', 'Isu'], Object.entries(countBy(issues, (issue) => issue.severity))), '');
  add(table(['Status', 'Isu'], Object.entries(countBy(issues, (issue) => issue.resolution_status))), '');
  add(table(['Jenis', 'Tingkat', 'Isu'], Object.entries(countBy(issues, (issue) => `${issue.issue_type}\u0000${issue.severity}`))
    .sort((a, b) => b[1] - a[1]).slice(0, MAX_ROWS).map(([key, count]) => [...key.split('\u0000'), count])), '');
  add('Setiap isu memuat catatan penanganan migrasi di `resolution_note`; status tetap OPEN untuk ditinjau Admin ' +
    '(EXCLUDED untuk baris yang tidak dimigrasikan ke tabel bisnis). Daftar lengkap: `migration-issues.csv`.', '');
  add('Tingkat BLOCKER berasal dari profil Phase 01: relasi wajib yang kosong (D3), status tanpa padanan (D4), dan nomor PO ' +
    'kosong/ganda (D7). Dengan default yang diterapkan, isu ini tidak menghalangi migrasi (record masuk sebagai legacy dan ' +
    'tidak ada relasi yang ditebak), tetapi menghalangi angka outstanding yang akurat sampai Admin menautkan transaksinya.', '');

  // 10. Decisions
  add('## 10. Keputusan', '');
  for (const line of decisionSection(decisions, pkg, rec)) add(line);
  add('');

  // 11. Production
  add('## 11. Menjalankan migrasi produksi di Apps Script', '');
  add('1. `npm run push` (kode terbaru), lalu di editor Apps Script: `setupDatabase()` (sekali) dan `verifyDatabase()`.',
    `2. Unggah \`${outputs.package}\` ke Google Drive (folder yang hanya bisa diakses Admin).`,
    '3. Script Properties → `MIGRATION_PACKAGE_FILE_ID` = ID file tersebut.',
    '4. Jalankan berurutan: `profileSourceWorkbook()`, `validateMigrationMapping()`, `dryRunMigration()`.',
    '5. Bila dry run OK: `runMigration()` (ulangi bila log menyebut berhenti karena batas waktu), lalu `verifyMigration()`.',
    '6. Bandingkan laporan JSON di Drive dengan dokumen ini: jumlah, total, dan hasil verifikasi harus sama.', '');

  // 12. Outputs
  add('## 12. File output', '');
  add(table(['File', 'Isi'], outputs.list.map((item) => [code(item.path), item.description])), '');
  return `${out.join('\n')}\n`;
}

module.exports = { buildMarkdownReport, buildSummary };
