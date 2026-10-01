<?php
/**
 * @var array<string,int> $existing
 * @var array<string,mixed>|null $lastImport
 * @var array<string,mixed>|null $report
 * @var string|null $mode
 * @var array<string,int>|null $counts
 * @var list<string> $files
 * @var string|null $uploadError
 * @var string $maxUpload
 * @var string|null $excelProblem ekstensi PHP zip/xmlreader belum aktif
 */
$hasData = $existing !== [];
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Import Data</h1>
        <p class="page-subtitle">Muat data awal dari <span class="code-chip">PIK_Master_Database_AppSheet.xlsx</span>. File spreadsheet asli (legacy) opsional, dipakai untuk memverifikasi tanggal & angka.</p>
    </div>
</div>

<?php if ($excelProblem): ?>
    <div class="callout callout-warning section-gap"><i class="bi bi-exclamation-triangle me-1"></i><?= e($excelProblem) ?></div>
<?php endif; ?>

<?php if ($uploadError): ?>
    <div class="callout callout-warning section-gap"><i class="bi bi-exclamation-triangle me-1"></i><?= e($uploadError) ?></div>
<?php endif; ?>

<?php if ($mode === 'import' && $counts !== null): ?>
    <div class="callout callout-success section-gap">
        <div class="fw-semibold mb-1"><i class="bi bi-check2-circle me-1"></i>Import selesai</div>
        <div class="small mb-2"><?= e(implode(' · ', array_map(static fn ($t, $n) => $t . ' ' . fmt_qty($n), array_keys($counts), $counts))) ?></div>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/migration-issues')) ?>">Tinjau Migration Issues</a>
        <a class="btn btn-light btn-sm" href="<?= e(url('/')) ?>">Ke Dashboard</a>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7 min-w-0">
        <?php if ($hasData): ?>
            <div class="callout callout-info section-gap small">
                <div class="fw-semibold mb-1">Database sudah berisi data</div>
                <?= e(implode(' · ', array_map(static fn ($t, $n) => $t . ' ' . fmt_qty($n), array_keys($existing), $existing))) ?>.
                <div class="mt-1">Import lewat web hanya untuk database kosong agar data tidak tertimpa tanpa sengaja. Anda tetap bisa <strong>Cek dulu</strong> untuk melihat hasil pemetaan.
                    Untuk mengganti seluruh data bisnis, gunakan CLI: <span class="code-chip">php database/import_workbook.php --master=... --fresh</span></div>
            </div>
        <?php endif; ?>

        <form class="surface" method="post" action="<?= e(url('/import')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="f_master">Master workbook (.xlsx)<span class="req">*</span></label>
                        <input class="form-control" type="file" id="f_master" name="master" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                        <div class="form-text">Sheet wajib: CUSTOMERS, PRODUCTS, PURCHASE_ORDERS, PO_LINES, DELIVERIES, dst. Batas upload server: <?= e($maxUpload) ?> per file.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="f_legacy">File legacy (opsional, maks. 5)</label>
                        <input class="form-control" type="file" id="f_legacy" name="legacy[]" accept=".xlsx" multiple>
                        <div class="form-text">Mis. PIK X PT SCL.xlsx, PIK X ALL CUSTOMER.xlsx, RECAP PURCHASE DELIVERY PAYMENT.xlsx.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="apply_corrections" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="f_apply" name="apply_corrections" value="1" checked>
                            <label class="form-check-label" for="f_apply">Terapkan koreksi otomatis yang didukung bukti dari file legacy</label>
                        </div>
                        <div class="form-text">Koreksi hanya diterapkan bila ada bukti independen (bulan di nomor dokumen, urutan surat jalan, dll.). Semua koreksi tercatat sebagai migration issue.</div>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-light" type="submit" name="mode" value="dry"<?= $excelProblem ? ' disabled' : '' ?>><i class="bi bi-search"></i> Cek dulu (tanpa menyimpan)</button>
                <button class="btn btn-primary" type="submit" name="mode" value="import"<?= $hasData || $excelProblem ? ' disabled' : '' ?> data-confirm="Import data ke database sekarang?"><i class="bi bi-cloud-arrow-up"></i> Import</button>
            </div>
        </form>
    </div>

    <div class="col-xl-5 min-w-0">
        <?php if ($lastImport): ?>
            <section class="surface surface-pad section-gap small text-break">
                <div class="fw-semibold mb-1">Import terakhir</div>
                <div class="text-secondary"><?= e(fmt_datetime($lastImport['at'] ?? null)) ?> · <?= e((string) ($lastImport['file'] ?? '')) ?></div>
                <?php if (!empty($lastImport['legacy_files'])): ?><div class="text-secondary">Legacy: <?= e(implode(', ', (array) $lastImport['legacy_files'])) ?></div><?php endif; ?>
                <?php if (isset($lastImport['auto_corrected'])): ?><div class="text-secondary">Koreksi otomatis: <?= (int) $lastImport['auto_corrected'] ?></div><?php endif; ?>
            </section>
        <?php endif; ?>
        <section class="surface surface-pad small text-secondary">
            <div class="fw-semibold text-body mb-1">Aturan import</div>
            <ul class="mb-0 ps-3">
                <li>Relasi hanya dibuat dari ID atau kecocokan persis — tidak ada fuzzy matching.</li>
                <li>Record yang tidak bisa dipetakan dengan aman tetap disimpan dan ditandai sebagai migration issue.</li>
                <li>Semua data ditulis dalam satu transaksi: bila gagal, tidak ada yang tersimpan.</li>
            </ul>
        </section>
    </div>
</div>

<?php if ($report): ?>
    <section class="surface section-gap mt-4">
        <div class="surface-header"><div><h2 class="surface-title"><?= $mode === 'import' ? 'Hasil import' : 'Hasil pengecekan (belum disimpan)' ?></h2>
            <p class="surface-subtitle"><?= e(implode(' · ', $files)) ?></p></div></div>
        <div class="row g-0">
            <div class="col-md-6">
                <div class="table-wrap">
                    <table class="table-pik table-compact">
                        <thead><tr><th>Tabel</th><th class="num">Baris</th></tr></thead>
                        <tbody>
                        <?php foreach ((array) ($report['counts'] ?? []) as $table => $n): ?>
                            <tr><td><?= e((string) $table) ?></td><td class="num"><?= e(fmt_qty($n, '0')) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="table-wrap">
                    <table class="table-pik table-compact">
                        <thead><tr><th>Migration issue</th><th class="num">Jumlah</th></tr></thead>
                        <tbody>
                        <?php foreach ((array) ($report['issues_by_type'] ?? []) as $type => $n): ?>
                            <tr><td><?= e((string) $type) ?></td><td class="num"><?= e(fmt_qty($n, '0')) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php if (!empty($report['legacy'])): ?>
            <div class="surface-pad small border-top">
                <div class="fw-semibold mb-1">Verifikasi terhadap file legacy</div>
                <?php foreach ((array) $report['legacy'] as $table => $s): ?>
                    <div class="text-secondary"><?= e((string) $table) ?>: diperiksa <?= (int) $s['checked'] ?> · cocok <?= (int) $s['verified'] ?> · tidak terverifikasi <?= (int) $s['unverified'] ?></div>
                <?php endforeach; ?>
                <div class="mt-1">Koreksi otomatis (didukung bukti): <strong><?= (int) ($report['auto_corrected'] ?? 0) ?></strong> · perlu ditinjau: <strong><?= (int) ($report['suggestions'] ?? 0) ?></strong> · master terbukti benar: <strong><?= (int) ($report['master_confirmed'] ?? 0) ?></strong></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($report['linked_exact'])): ?>
            <div class="surface-pad small border-top">
                <div class="fw-semibold mb-1">Relasi dari kecocokan persis</div>
                <?php foreach ((array) $report['linked_exact'] as $label => $n): ?><div class="text-secondary"><?= e((string) $label) ?>: <?= (int) $n ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($report['notes'])): ?>
            <div class="surface-pad small border-top">
                <?php foreach ((array) $report['notes'] as $note): ?><div class="text-secondary"><i class="bi bi-info-circle me-1"></i><?= e((string) $note) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
