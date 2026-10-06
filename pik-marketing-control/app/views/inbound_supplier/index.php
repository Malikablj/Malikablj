<?php

use App\Helpers\Form;

/**
 * @var App\Helpers\Paginator $rows
 * @var array<string,int> $summary
 * @var array<string,mixed> $filters
 * @var list<string> $suppliers
 */
$hasFilter = $filters['q'] !== '' || $filters['supplier'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['reject'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Inventory</div>
        <h1 class="page-title">Inbound Supplier</h1>
        <p class="page-subtitle">Penerimaan barang dari supplier, diinput manual oleh Purchasing. Diterima bersih = Qty datang − Qty reject.</p>
    </div>
    <?php if (can('inbound_supplier.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/inbound-supplier/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Penerimaan</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Penerimaan<?= $hasFilter ? ' (filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div>
        <div class="x-small text-secondary"><?= (int) ($summary['suppliers'] ?? 0) ?> supplier</div></div>
    <div><div class="stat-label">Qty datang</div><div class="stat-value"><?= e(fmt_qty($summary['qty'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Reject</div><div class="stat-value<?= ($summary['reject'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($summary['reject'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Diterima bersih</div><div class="stat-value"><?= e(fmt_qty($summary['accepted'] ?? 0, '0')) ?></div></div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/inbound-supplier')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari supplier, barang, no PO, no SJ…" aria-label="Cari inbound supplier"></div>
        <?php if ($suppliers): ?><select class="form-select" name="supplier" aria-label="Supplier" data-autosubmit><option value="">Semua supplier</option><?= Form::options(Form::list($suppliers), $filters['supplier']) ?></select><?php endif; ?>
        <select class="form-select" name="reject" aria-label="Reject" data-autosubmit><option value="">Semua penerimaan</option><option value="1"<?= selected('1', $filters['reject']) ?>>Ada reject</option></select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/inbound-supplier')) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($rows->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-truck-flatbed"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada data yang cocok' : 'Belum ada penerimaan dari supplier' ?></div>
            <p>Catat setiap barang yang datang dari supplier beserta qty reject.</p>
            <?php if (!$hasFilter && can('inbound_supplier.create')): ?><a href="<?= e(url('/inbound-supplier/create')) ?>" class="btn btn-primary btn-sm">Catat Penerimaan</a><?php endif; ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Barang · Supplier</th><th class="d-none d-lg-table-cell">No PO · SJ</th>
                    <th class="num d-none d-md-table-cell">Datang</th><th class="num d-none d-md-table-cell">Reject</th><th class="num">Diterima</th></tr></thead>
                <tbody>
                <?php foreach ($rows->items as $r): ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['receive_date'])) ?></td>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/inbound-supplier/' . $r['id'])) ?>"><?= e($r['item_name']) ?></a>
                            <div class="cell-sub"><?= e($r['supplier']) ?><?= $r['specification'] ? ' · ' . e(excerpt($r['specification'], 60)) : '' ?></div>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['receive_date'])) ?></div>
                            <div class="cell-sub d-lg-none"><?= e(trim(($r['purchase_number'] ? 'PO ' . $r['purchase_number'] : '') . ($r['sj_number'] ? ' · SJ ' . $r['sj_number'] : ''), ' ·')) ?></div>
                            <div class="cell-sub d-md-none">Datang <?= e(fmt_qty($r['quantity'])) ?> <?= e($r['unit']) ?><?= (int) $r['reject_qty'] > 0 ? ' · reject ' . e(fmt_qty($r['reject_qty'])) : '' ?></div></td>
                        <td class="d-none d-lg-table-cell small"><?= e($r['purchase_number'] ?? '—') ?><div class="text-secondary"><?= e($r['sj_number'] ?? '') ?></div></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($r['quantity'])) ?> <span class="x-small text-secondary"><?= e($r['unit']) ?></span></td>
                        <td class="num d-none d-md-table-cell<?= (int) $r['reject_qty'] > 0 ? ' text-warning-ink fw-semibold' : '' ?>"><?= e(fmt_qty($r['reject_qty'], '0')) ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($r['accepted_qty'], '0')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('penerimaan') ?>
    <?php endif; ?>
</div>
