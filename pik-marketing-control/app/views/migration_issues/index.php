<?php

use App\Helpers\Form;
use App\Models\MigrationIssue;

/**
 * @var App\Helpers\Paginator $issues
 * @var array<string,string> $filters
 * @var array<string,int> $counts
 * @var list<array{table_name:string,issue_type:string,n:int}> $summary
 * @var list<string> $tables
 * @var list<string> $types
 * @var int $candidates
 */
$hasFilter = $filters['q'] !== '' || $filters['table'] !== '' || $filters['type'] !== '';
$returnPath = '/migration-issues' . (($qs = http_build_query(array_filter($filters + ['page' => $issues->page > 1 ? $issues->page : null]))) !== '' ? '?' . $qs : '');
$canEdit = can('migration.edit');
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Migration Issues</h1>
        <p class="page-subtitle">Catatan dari import workbook yang perlu ditinjau. Data tidak pernah dipetakan dengan tebakan: record yang tidak pasti ditandai di sini.</p>
    </div>
    <?php if ($candidates > 0 && can('deliveries.edit')): ?>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(url('/migration-issues/deliveries')) ?>"><i class="bi bi-link-45deg"></i> Tinjau <?= e(fmt_qty($candidates)) ?> delivery legacy</a></div>
    <?php endif; ?>
</div>

<nav class="tabs-pik" aria-label="Status issue">
    <?php foreach (MigrationIssue::STATUSES as $s): ?>
        <a href="<?= e(url('/migration-issues', array_filter(['status' => $s, 'table' => $filters['table'], 'type' => $filters['type'], 'q' => $filters['q']]))) ?>" class="<?= $filters['status'] === $s ? 'active' : '' ?>"><?= e($s) ?> <span class="tab-count"><?= e(fmt_qty($counts[$s] ?? 0, '0')) ?></span></a>
    <?php endforeach; ?>
    <a href="<?= e(url('/migration-issues', array_filter(['status' => 'all', 'table' => $filters['table'], 'type' => $filters['type'], 'q' => $filters['q']]))) ?>" class="<?= $filters['status'] === 'all' ? 'active' : '' ?>">Semua</a>
</nav>

<?php if ($summary && !$hasFilter): ?>
    <section class="surface section-gap">
        <div class="surface-header"><h2 class="surface-title">Ringkasan per jenis</h2></div>
        <div class="table-wrap">
            <table class="table-pik table-compact">
                <thead><tr><th>Jenis issue</th><th class="d-none d-sm-table-cell">Sheet</th><th class="num">Jumlah</th></tr></thead>
                <tbody>
                <?php foreach ($summary as $s): ?>
                    <tr><td><a class="cell-title" href="<?= e(url('/migration-issues', ['status' => $filters['status'], 'table' => $s['table_name'], 'type' => $s['issue_type']])) ?>"><?= e($s['issue_type']) ?></a>
                            <div class="cell-sub d-sm-none"><?= e($s['table_name']) ?></div></td>
                        <td class="d-none d-sm-table-cell text-secondary small"><?= e($s['table_name']) ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($s['n'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/migration-issues')) ?>">
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari kode record, PO / produk legacy, deskripsi…" aria-label="Cari issue"></div>
        <select class="form-select" name="table" aria-label="Sheet" data-autosubmit><option value="">Semua sheet</option><?= Form::options(Form::list($tables), $filters['table']) ?></select>
        <select class="form-select" name="type" aria-label="Jenis" data-autosubmit><option value="">Semua jenis</option><?= Form::options(Form::list($types), $filters['type']) ?></select>
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/migration-issues', ['status' => $filters['status']])) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($issues->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-check2-circle"></i><div class="empty-title">Tidak ada issue <?= e(mb_strtolower($filters['status'] === 'all' ? '' : $filters['status'])) ?></div>
            <p><?= $hasFilter ? 'Coba filter lain.' : 'Semua catatan migrasi pada status ini sudah ditangani.' ?></p></div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/migration-issues/bulk')) ?>" id="bulk-issues">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= e($returnPath) ?>">
            <div class="table-wrap">
                <table class="table-pik">
                    <thead><tr>
                        <?php if ($canEdit): ?><th class="col-actions"><input class="form-check-input" type="checkbox" data-check-all="ids[]" aria-label="Pilih semua di halaman ini"></th><?php endif; ?>
                        <th>Issue</th><th class="d-none d-md-table-cell">Record</th><th class="d-none d-lg-table-cell">Nilai master → legacy</th><th class="d-none d-sm-table-cell">Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($issues->items as $i): ?>
                        <tr>
                            <?php if ($canEdit): ?><td class="col-actions"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $i['id'] ?>" aria-label="Pilih <?= e($i['code']) ?>"></td><?php endif; ?>
                            <td class="min-w-0"><a class="cell-title" href="<?= e(url('/migration-issues/' . $i['id'], ['return' => $returnPath])) ?>"><?= e($i['issue_type']) ?></a>
                                <div class="cell-sub text-wrap"><?= e(excerpt($i['description'] ?? '', 140)) ?></div>
                                <div class="cell-sub d-md-none"><?= e($i['table_name']) ?> · <?= e($i['record_code'] ?? '—') ?></div>
                                <div class="cell-sub d-sm-none"><?= status_badge($i['resolution_status']) ?></div></td>
                            <td class="d-none d-md-table-cell small"><span class="code-chip"><?= e($i['record_code'] ?? '—') ?></span><div class="text-secondary"><?= e($i['table_name']) ?><?= $i['legacy_row'] ? ' · baris ' . (int) $i['legacy_row'] : '' ?></div>
                                <?php if ($i['legacy_po'] || $i['legacy_product']): ?><div class="text-secondary"><?= e(excerpt(trim(($i['legacy_po'] ?? '') . ' · ' . ($i['legacy_product'] ?? ''), ' ·'), 60)) ?></div><?php endif; ?></td>
                            <td class="d-none d-lg-table-cell small"><?php if ($i['field_name']): ?><span class="text-secondary"><?= e($i['field_name']) ?>:</span> <?= e($i['master_value'] ?? '—') ?> → <?= e($i['suggested_value'] ?? '—') ?><?php else: ?><span class="text-subtle">—</span><?php endif; ?></td>
                            <td class="d-none d-sm-table-cell"><?= status_badge($i['resolution_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canEdit): ?>
                <div class="surface-footer d-flex flex-wrap gap-2 align-items-center">
                    <span class="small text-secondary me-auto">Untuk yang dicentang:</span>
                    <input type="text" class="form-control form-control-sm w-auto" name="note" maxlength="1000" placeholder="Catatan (opsional)" aria-label="Catatan">
                    <button class="btn btn-light btn-sm" type="submit" name="status" value="Resolved" data-confirm="Tandai issue yang dicentang sebagai selesai?"><i class="bi bi-check2"></i> Tandai selesai</button>
                    <button class="btn btn-light btn-sm" type="submit" name="status" value="Ignored" data-confirm="Abaikan issue yang dicentang?"><i class="bi bi-eye-slash"></i> Abaikan</button>
                    <button class="btn btn-light btn-sm" type="submit" name="status" value="apply_suggested" data-confirm="Terapkan nilai usulan (dokumen PO / spreadsheet legacy) untuk issue nilai yang dicentang?" title="Hanya untuk issue tanggal/angka"><i class="bi bi-arrow-left-right"></i> Pakai nilai usulan</button>
                </div>
            <?php endif; ?>
        </form>
        <?= $issues->footer('issue') ?>
    <?php endif; ?>
</div>
