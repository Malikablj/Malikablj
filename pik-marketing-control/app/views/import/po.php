<?php

use App\Helpers\View;

/**
 * @var App\Helpers\Paginator $logs
 * @var array<string,mixed>|null $report
 * @var string|null $uploadError
 * @var int|null $failedLog
 * @var string $maxUpload
 * @var string|null $excelProblem
 */
$statusTone = static fn (string $s): string => match ($s) {
    'COMPLETED' => 'success', 'COMPLETED_WITH_WARNING' => 'warning', 'FAILED', 'ROLLED_BACK' => 'danger', default => 'info',
};
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Import Database PO</h1>
        <p class="page-subtitle">Masukkan file <span class="code-chip">PIK_PO_DATABASE_*.xlsx</span> (PO_MASTER, PO_ITEMS, CUSTOMERS, PRODUCTS, VALIDATION) ke data PO yang sudah ada.</p>
    </div>
    <div class="page-actions"><a class="btn btn-light" href="<?= e(url('/import')) ?>"><i class="bi bi-arrow-left"></i> Import workbook AppSheet</a></div>
</div>

<?php if ($excelProblem): ?>
    <div class="callout callout-warning section-gap"><i class="bi bi-exclamation-triangle me-1"></i><?= e($excelProblem) ?></div>
<?php endif; ?>
<?php if ($uploadError): ?>
    <div class="callout callout-warning section-gap"><i class="bi bi-exclamation-triangle me-1"></i><?= e($uploadError) ?>
        <?php if ($failedLog): ?> <a href="<?= e(url('/import/logs/' . $failedLog)) ?>">Lihat log #<?= (int) $failedLog ?></a><?php endif; ?></div>
<?php endif; ?>

<?php if ($report): ?>
    <?php $isImport = ($report['mode'] ?? '') === 'IMPORT'; ?>
    <div class="callout <?= $report['status'] === 'COMPLETED' ? 'callout-success' : 'callout-info' ?> section-gap">
        <div class="fw-semibold mb-1"><i class="bi <?= $isImport ? 'bi-check2-circle' : 'bi-search' ?> me-1"></i>
            <?= $isImport ? 'Import selesai' : 'Dry run selesai — belum ada data yang diubah' ?>
            <span class="badge-soft badge-soft-<?= $statusTone($report['status']) ?> no-dot ms-1"><?= e($report['status']) ?></span></div>
        <div class="small mb-2"><?= e($report['file']) ?> · <a href="<?= e(url('/import/logs/' . $report['log_id'])) ?>">log #<?= (int) $report['log_id'] ?></a>
            <?php if ($isImport && !empty($report['backup_file'])): ?> · backup sebelum import: <span class="code-chip"><?= e($report['backup_file']) ?></span><?php endif; ?></div>
        <?php if (!$isImport && !$excelProblem): ?>
            <form method="post" action="<?= e(url('/import/po')) ?>" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="mode" value="import"><input type="hidden" name="log_id" value="<?= (int) $report['log_id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit" data-confirm="Backup database sudah dibuat? Import data PO ke database sekarang?"><i class="bi bi-cloud-arrow-up"></i> Import sekarang</button>
            </form>
            <span class="small text-secondary ms-2">Tabel terkait otomatis dibackup ke storage/backups sebelum import.</span>
        <?php elseif ($isImport): ?>
            <a class="btn btn-light btn-sm" href="<?= e(url('/migration-issues')) ?>">Tinjau Migration Issues</a>
            <a class="btn btn-light btn-sm" href="<?= e(url('/purchase-orders', ['review' => 'needs_review'])) ?>">PO perlu review</a>
        <?php endif; ?>
    </div>
    <?= View::partial('import/_po_report', ['report' => $report]) ?>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7 min-w-0">
        <form class="surface section-gap" method="post" action="<?= e(url('/import/po')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-section">
                <label class="form-label" for="f_file">File database PO (.xlsx)<span class="req">*</span></label>
                <input class="form-control" type="file" id="f_file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <div class="form-text">Batas upload server: <?= e($maxUpload) ?>. File asli tidak diubah.</div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit" name="mode" value="dry"<?= $excelProblem ? ' disabled' : '' ?>><i class="bi bi-search"></i> Cek dulu (dry run)</button>
            </div>
        </form>
    </div>
    <div class="col-xl-5 min-w-0">
        <section class="surface surface-pad small text-secondary section-gap">
            <div class="fw-semibold text-body mb-1">Urutan yang aman</div>
            <ol class="mb-0 ps-3">
                <li>Backup seluruh database (phpMyAdmin › Export).</li>
                <li><strong>Cek dulu</strong>: lihat rencana, rekonsiliasi, dan temuan. Tidak ada data yang diubah.</li>
                <li><strong>Import sekarang</strong>: hanya muncul setelah dry run file yang sama; semua ditulis dalam satu transaksi.</li>
                <li>Tinjau Migration Issues & PO berlabel "Perlu review".</li>
            </ol>
        </section>
        <section class="surface surface-pad small text-secondary">
            <div class="fw-semibold text-body mb-1">Aturan import</div>
            <ul class="mb-0 ps-3">
                <li>PO yang sudah ada (nomor PO sama) dilengkapi nilai & harga — tidak dibuat ganda. Aman dijalankan ulang.</li>
                <li>Nilai yang sudah ada di aplikasi tidak pernah ditimpa; perbedaan dicatat untuk ditinjau.</li>
                <li>Tidak ada fuzzy matching. Data yang ragu (REVIEW REQUIRED, UNREADABLE, AMBIGUOUS, …) ditandai <em>Perlu review</em>.</li>
            </ul>
        </section>
    </div>
</div>

<section class="surface section-gap mt-4">
    <div class="surface-header"><div><h2 class="surface-title">Riwayat import</h2></div></div>
    <?php if ($logs->isEmpty()): ?>
        <div class="surface-pad small text-secondary">Belum ada import database PO.</div>
    <?php else: ?>
        <div class="table-wrap"><table class="table-pik table-compact">
            <thead><tr><th>Log</th><th class="d-none d-md-table-cell">File</th><th>Status</th><th class="num d-none d-sm-table-cell">Baru</th><th class="num d-none d-sm-table-cell">Dilengkapi</th><th class="num d-none d-lg-table-cell">Gagal</th></tr></thead>
            <tbody>
            <?php foreach ($logs->items as $l): ?>
                <tr><td class="nowrap"><a href="<?= e(url('/import/logs/' . $l['id'])) ?>">#<?= (int) $l['id'] ?> · <?= $l['mode'] === 'IMPORT' ? 'Import' : 'Dry run' ?></a>
                        <div class="cell-sub"><?= e(fmt_datetime($l['started_at'])) ?><?= $l['user_name'] ? ' · ' . e($l['user_name']) : '' ?></div></td>
                    <td class="d-none d-md-table-cell text-break"><?= e($l['filename']) ?></td>
                    <td><span class="badge-soft badge-soft-<?= $statusTone((string) $l['status']) ?> no-dot"><?= e($l['status']) ?></span></td>
                    <td class="num d-none d-sm-table-cell"><?= e(fmt_qty($l['inserted_rows'], '0')) ?></td><td class="num d-none d-sm-table-cell"><?= e(fmt_qty($l['updated_rows'], '0')) ?></td>
                    <td class="num d-none d-lg-table-cell"><?= e(fmt_qty($l['failed_rows'], '0')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
