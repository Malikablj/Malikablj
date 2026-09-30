<?php
/**
 * @var array<string,mixed> $issue
 * @var string|null $link
 * @var array{table:string,type:string,entity:string,column:string}|null $target
 * @var string $return
 */
$id = (int) $issue['id'];
$open = in_array($issue['resolution_status'], ['Needs Review', 'Auto-Corrected'], true);
$back = $return !== '' ? to($return) : url('/migration-issues');
$canEdit = can('migration.edit');
?>
<div class="breadcrumb-lite"><a href="<?= e($back) ?>">Migration Issues</a><i class="bi bi-chevron-right"></i><span><?= e($issue['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-exclamation-diamond"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($issue['issue_type']) ?> <?= status_badge($issue['resolution_status']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($issue['code']) ?></span>
                <span><i class="bi bi-table"></i><?= e($issue['table_name']) ?><?= $issue['record_code'] ? ' · ' . e($issue['record_code']) : '' ?></span>
                <?php if ($issue['legacy_row']): ?><span><i class="bi bi-file-earmark-spreadsheet"></i><?= e($issue['source_sheet'] ?? '') ?> baris <?= (int) $issue['legacy_row'] ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($link): ?><a class="btn btn-primary" href="<?= e(url($link)) ?>"><i class="bi bi-box-arrow-up-right"></i> Buka record</a><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7 min-w-0">
        <section class="surface surface-pad section-gap">
            <p class="mb-3"><?= nl2br(e($issue['description'] ?? '')) ?></p>
            <dl class="dl-single mb-0 small">
                <?php if ($issue['field_name']): ?>
                    <dt>Kolom</dt><dd><span class="code-chip"><?= e($issue['field_name']) ?></span></dd>
                    <dt>Nilai di master workbook</dt><dd><?= e($issue['master_value'] ?? '—') ?></dd>
                    <dt>Nilai di spreadsheet legacy</dt><dd><?= e($issue['suggested_value'] ?? '—') ?></dd>
                <?php endif; ?>
                <?php if ($issue['legacy_po']): ?><dt>PO di spreadsheet</dt><dd><?= e($issue['legacy_po']) ?></dd><?php endif; ?>
                <?php if ($issue['legacy_product']): ?><dt>Produk di spreadsheet</dt><dd><?= e($issue['legacy_product']) ?></dd><?php endif; ?>
                <?php if ($issue['source_file']): ?><dt>Sumber</dt><dd class="text-secondary"><?= e($issue['source_file']) ?> › <?= e($issue['source_sheet'] ?? '') ?></dd><?php endif; ?>
                <?php if ($issue['resolution_note'] || $issue['resolved_at']): ?>
                    <dt>Penyelesaian</dt><dd><?= e($issue['resolution_note'] ?? '—') ?>
                        <?php if ($issue['resolved_at']): ?><div class="text-secondary"><?= e(fmt_datetime($issue['resolved_at'])) ?><?= $issue['resolved_by_name'] ? ' oleh ' . e($issue['resolved_by_name']) : '' ?></div><?php endif; ?></dd>
                <?php endif; ?>
            </dl>
        </section>
    </div>

    <div class="col-lg-5 min-w-0">
        <?php if ($canEdit): ?>
            <?php if ($target && $open): ?>
                <section class="surface section-gap">
                    <div class="surface-header"><div><h2 class="surface-title">Pilih nilai yang benar</h2>
                        <p class="surface-subtitle">Kolom <?= e($target['column']) ?> pada record akan diisi nilai yang dipilih, lalu issue ditandai selesai.</p></div></div>
                    <div class="surface-pad d-grid gap-2">
                        <?php foreach (['master' => ['Pakai nilai master', $issue['master_value']], 'suggested' => ['Pakai nilai spreadsheet legacy', $issue['suggested_value']]] as $which => [$label, $value]): ?>
                            <?php if ($value !== null && $value !== ''): ?>
                                <form method="post" action="<?= e(url('/migration-issues/' . $id . '/apply')) ?>" data-confirm="<?= e($label . ': ' . $value . '?') ?>">
                                    <?= csrf_field() ?><input type="hidden" name="which" value="<?= e($which) ?>">
                                    <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
                                    <button class="btn btn-light w-100 text-start" type="submit"><span class="fw-semibold"><?= e($label) ?></span> <span class="text-secondary">— <?= e($value) ?></span></button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <form class="surface section-gap" method="post" action="<?= e(url('/migration-issues/' . $id . '/status')) ?>">
                <?= csrf_field() ?>
                <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
                <div class="surface-header"><h2 class="surface-title"><?= $open ? 'Tandai issue' : 'Ubah status' ?></h2></div>
                <div class="surface-pad">
                    <label class="form-label" for="f_note">Catatan penyelesaian</label>
                    <textarea class="form-control mb-3" id="f_note" name="note" rows="2" maxlength="1000" placeholder="mis. sudah dicek dengan dokumen fisik"></textarea>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($open): ?>
                            <button class="btn btn-primary" type="submit" name="status" value="Resolved"><i class="bi bi-check2"></i> Selesai</button>
                            <button class="btn btn-light" type="submit" name="status" value="Ignored"><i class="bi bi-eye-slash"></i> Abaikan</button>
                        <?php else: ?>
                            <button class="btn btn-light" type="submit" name="status" value="Needs Review"><i class="bi bi-arrow-counterclockwise"></i> Buka kembali</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($link && $open): ?><p class="small text-secondary mt-3 mb-0">Tip: perbaiki data di halaman record terlebih dahulu (tombol "Buka record"); beberapa issue otomatis selesai saat datanya dilengkapi.</p><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
