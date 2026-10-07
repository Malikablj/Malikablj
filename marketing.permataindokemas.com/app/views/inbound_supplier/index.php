<?php

use App\Helpers\Form;
use App\Models\InboundSupplier;

/**
 * @var string $mode list|items
 * @var App\Helpers\Paginator $rows
 * @var array<string,int> $summary
 * @var array<string,mixed> $filters
 * @var list<string> $suppliers
 * @var list<string> $categories
 */
$hasFilter = $filters['q'] !== '' || $filters['supplier'] !== '' || $filters['category'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['reject'] !== '';
$keep = array_filter(['q' => $filters['q'], 'supplier' => $filters['supplier'], 'category' => $filters['category'], 'from' => $filters['from'], 'to' => $filters['to'], 'reject' => $filters['reject']]);
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Inventory</div>
        <h1 class="page-title">Inbound Supplier</h1>
        <p class="page-subtitle">Penerimaan barang dari supplier (bahan baku, kemasan, label, dll.), diinput manual oleh Gudang. Total masuk = Qty diterima − Qty reject.</p>
    </div>
    <?php if (can('inbound_supplier.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/inbound-supplier/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Inbound</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Penerimaan<?= $hasFilter ? ' (filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Supplier</div><div class="stat-value"><?= e(fmt_qty($summary['suppliers'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Jenis barang</div><div class="stat-value"><?= e(fmt_qty($summary['items'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Ada reject</div><div class="stat-value<?= ($summary['with_reject'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($summary['with_reject'] ?? 0, '0')) ?></div>
        <?php if (($summary['with_reject'] ?? 0) > 0 && $filters['reject'] === ''): ?><a class="x-small" href="<?= e(url('/inbound-supplier', ['reject' => 1] + ($mode === 'items' ? ['view' => 'items'] : []))) ?>">Lihat</a><?php endif; ?></div>
</div>

<nav class="tabs-pik" aria-label="Tampilan inbound supplier">
    <a href="<?= e(url('/inbound-supplier', $keep)) ?>" class="<?= $mode === 'list' ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> Semua penerimaan</a>
    <a href="<?= e(url('/inbound-supplier', ['view' => 'items'] + $keep)) ?>" class="<?= $mode === 'items' ? 'active' : '' ?>"><i class="bi bi-grid-3x3-gap"></i> Rekap per barang</a>
</nav>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/inbound-supplier')) ?>">
        <?php if ($mode === 'items'): ?><input type="hidden" name="view" value="items"><?php endif; ?>
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari barang, supplier, SJ, PO…" aria-label="Cari inbound supplier"></div>
        <?php if ($suppliers): ?><select class="form-select" name="supplier" aria-label="Supplier" data-autosubmit><option value="">Semua supplier</option><?= Form::options(Form::list($suppliers), $filters['supplier']) ?></select><?php endif; ?>
        <?php if ($categories): ?><select class="form-select" name="category" aria-label="Jenis barang" data-autosubmit><option value="">Semua jenis</option><?= Form::options(Form::list($categories), $filters['category']) ?></select><?php endif; ?>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <?php if ($filters['reject'] !== ''): ?><input type="hidden" name="reject" value="1"><?php endif; ?>
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/inbound-supplier', $mode === 'items' ? ['view' => 'items'] : [])) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($rows->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-truck-flatbed"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada data yang cocok' : 'Belum ada inbound supplier' ?></div>
            <p>Catat setiap barang yang diterima dari supplier beserta qty reject.</p>
            <?php if (!$hasFilter && can('inbound_supplier.create')): ?><a href="<?= e(url('/inbound-supplier/create')) ?>" class="btn btn-primary btn-sm">Catat Inbound</a><?php endif; ?></div>
    <?php elseif ($mode === 'items'): ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Barang</th><th class="num d-none d-md-table-cell">Penerimaan</th><th class="num d-none d-md-table-cell">Diterima</th><th class="num d-none d-sm-table-cell">Reject</th>
                    <th class="num">Total masuk</th><th class="d-none d-lg-table-cell">Terakhir masuk</th></tr></thead>
                <tbody>
                <?php foreach ($rows->items as $r): ?>
                    <tr>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/inbound-supplier', ['q' => $r['item_name']])) ?>"><?= e($r['item_name']) ?></a>
                            <div class="cell-sub"><?= e($r['unit']) ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?> · <?= (int) $r['suppliers'] ?> supplier</div>
                            <div class="cell-sub d-md-none"><?= (int) $r['receipts'] ?>× diterima</div></td>
                        <td class="num d-none d-md-table-cell"><?= (int) $r['receipts'] ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(InboundSupplier::formatQty($r['quantity'])) ?></td>
                        <td class="num d-none d-sm-table-cell<?= (float) $r['reject_qty'] > 0 ? ' text-warning-ink fw-semibold' : '' ?>"><?= e(InboundSupplier::formatQty($r['reject_qty'], '0')) ?></td>
                        <td class="num fw-semibold"><?= e(InboundSupplier::formatQty($r['total_in'])) ?> <span class="x-small text-secondary"><?= e($r['unit']) ?></span></td>
                        <td class="d-none d-lg-table-cell nowrap text-secondary"><?= e(fmt_date($r['last_date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('barang') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Barang · Supplier</th><th class="d-none d-lg-table-cell">Surat jalan / PO</th>
                    <th class="num d-none d-md-table-cell">Diterima</th><th class="num d-none d-md-table-cell">Reject</th><th class="num">Total masuk</th></tr></thead>
                <tbody>
                <?php foreach ($rows->items as $r): $reject = (float) ($r['reject_qty'] ?? 0); ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['inbound_date'])) ?></td>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/inbound-supplier/' . $r['id'])) ?>"><?= e($r['item_name']) ?></a>
                            <div class="cell-sub"><?= e($r['supplier']) ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?></div>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['inbound_date'])) ?><?= $r['sj_number'] ? ' · SJ ' . e($r['sj_number']) : '' ?></div>
                            <div class="cell-sub d-md-none">Diterima <?= e(InboundSupplier::formatQty($r['quantity'])) ?><?= $reject > 0 ? ' · reject ' . e(InboundSupplier::formatQty($r['reject_qty'])) : '' ?></div></td>
                        <td class="d-none d-lg-table-cell small"><?= e($r['sj_number'] ?? '—') ?><?= $r['po_reference'] ? '<div class="text-secondary">PO ' . e($r['po_reference']) . '</div>' : '' ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(InboundSupplier::formatQty($r['quantity'])) ?></td>
                        <td class="num d-none d-md-table-cell<?= $reject > 0 ? ' text-warning-ink fw-semibold' : '' ?>"><?= e(InboundSupplier::formatQty($r['reject_qty'], '0')) ?></td>
                        <td class="num fw-semibold"><?= e(InboundSupplier::formatQty($r['total_in'])) ?> <span class="x-small text-secondary"><?= e($r['unit']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('penerimaan') ?>
    <?php endif; ?>
</div>
