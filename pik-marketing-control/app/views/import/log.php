<?php

use App\Helpers\View;

/**
 * @var array<string,mixed> $log
 * @var array<string,mixed>|null $report
 */
$tone = match ((string) $log['status']) {
    'COMPLETED' => 'success', 'COMPLETED_WITH_WARNING' => 'warning', 'FAILED', 'ROLLED_BACK' => 'danger', default => 'info',
};
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow"><a href="<?= e(url('/import/po')) ?>">Import Database PO</a></div>
        <h1 class="page-title">Log import #<?= (int) $log['id'] ?></h1>
        <p class="page-subtitle"><?= $log['mode'] === 'IMPORT' ? 'Import' : 'Dry run' ?> · <?= e($log['filename']) ?></p>
    </div>
</div>

<section class="surface surface-pad section-gap small">
    <dl class="dl-single mb-0">
        <dt>Status</dt><dd><span class="badge-soft badge-soft-<?= $tone ?> no-dot"><?= e($log['status']) ?></span></dd>
        <dt>Waktu</dt><dd><?= e(fmt_datetime($log['started_at'])) ?> – <?= e(fmt_datetime($log['completed_at'], 'belum selesai')) ?><?= $log['user_name'] ? ' · ' . e($log['user_name']) : '' ?></dd>
        <dt>Baris</dt><dd>total <?= e(fmt_qty($log['total_rows'], '0')) ?> · baru <?= e(fmt_qty($log['inserted_rows'], '0')) ?> · dilengkapi <?= e(fmt_qty($log['updated_rows'], '0')) ?>
            · dilewati <?= e(fmt_qty($log['skipped_rows'], '0')) ?> · gagal <?= e(fmt_qty($log['failed_rows'], '0')) ?> · peringatan <?= e(fmt_qty($log['warning_count'], '0')) ?></dd>
        <dt>File (SHA-256)</dt><dd class="text-break"><span class="code-chip"><?= e($log['file_sha256']) ?></span></dd>
        <?php if ($log['backup_file']): ?><dt>Backup sebelum import</dt><dd><span class="code-chip">storage/backups/<?= e($log['backup_file']) ?></span></dd><?php endif; ?>
        <?php if ($log['error_message']): ?><dt>Error</dt><dd class="text-danger"><?= e($log['error_message']) ?>
            <?= $log['status'] === 'ROLLED_BACK' ? '<div class="text-secondary">Semua perubahan dibatalkan (rollback).</div>' : '' ?></dd><?php endif; ?>
    </dl>
</section>

<?php if ($report && isset($report['purchase_orders'])): ?>
    <?= View::partial('import/_po_report', ['report' => $report + ['mode' => $log['mode']]]) ?>
<?php endif; ?>
