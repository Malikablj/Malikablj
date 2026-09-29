<?php

use App\Helpers\Form;
use App\Models\ProductReturn;

/** @var App\Helpers\Paginator $returns @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['reason'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['link'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Returns</h1>
        <p class="page-subtitle">Retur customer: Damage, Wrong Product, Quality Issue, Over Delivery, Customer Request, Other.</p>
    </div>
    <?php if (can('returns.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/returns/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Retur</a></div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/returns')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari PO, customer, produk, dokumen…" aria-label="Cari retur"></div>
        <select class="form-select" name="reason" aria-label="Alasan" data-autosubmit><option value="">Semua alasan</option><?= Form::options(ProductReturn::REASON_LABELS, $filters['reason']) ?></select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <select class="form-select" name="link" aria-label="Relasi" data-autosubmit><option value="">Semua relasi</option><option value="unlinked"<?= selected('unlinked', $filters['link']) ?>>Belum terhubung ke PO line</option></select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/returns')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($returns->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-arrow-return-left"></i><div class="empty-title">Tidak ada retur</div><p>Retur dicatat per baris PO.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>PO · Customer</th><th class="d-none d-md-table-cell">Produk</th><th class="d-none d-sm-table-cell">Alasan</th><th class="num">Qty</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($returns->items as $r): ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?></td>
                        <td><?php if ($r['po_id']): ?><a class="cell-title" href="<?= e(url('/purchase-orders/' . $r['po_id'])) ?>"><?= e($r['po_number'] ?? $r['po_code']) ?></a><?php else: ?><?= e($r['po_number_legacy'] ?? '—') ?><?php endif; ?>
                            <div class="cell-sub"><?= e($r['customer_name'] ?? $r['destination'] ?? '') ?></div>
                            <div class="cell-sub d-md-none"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '')) ?></div>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?><?= $r['reason'] ? ' · ' . e(ProductReturn::REASON_LABELS[$r['reason']]) : '' ?></div></td>
                        <td class="d-none d-md-table-cell small"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?>
                            <?php if ($r['po_line_id'] === null): ?><div><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke baris PO</span></div><?php endif; ?></td>
                        <td class="d-none d-sm-table-cell"><?= e($r['reason'] ? ProductReturn::REASON_LABELS[$r['reason']] : '—') ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($r['return_qty'])) ?></td>
                        <td class="col-actions"><?php if (can('returns.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/returns/' . $r['id'] . '/edit', ['return' => '/returns'])) ?>">Edit</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $returns->footer('retur') ?>
    <?php endif; ?>
</div>
