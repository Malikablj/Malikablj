<?php

use App\Helpers\Form;

/**
 * @var App\Helpers\Paginator $products
 * @var array<string,mixed> $filters
 * @var array<string,mixed> $summary
 * @var list<string> $categories
 * @var bool $showStock
 */
$hasFilter = $filters['q'] !== '' || $filters['category'] !== '' || $filters['status'] !== '' || $filters['open'] !== '';
$showPo = can('purchase_orders.view');
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Inventory</div>
        <h1 class="page-title">Products</h1>
        <p class="page-subtitle">Arsip produk: otomatis bertambah saat produk baru diketik di OEF atau stok. Kelompok dibuat otomatis dari nama produk.</p>
    </div>
    <?php if (can('products.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/products/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Tambah Produk</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Produk<?= $hasFilter ? ' (sesuai filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Aktif</div><div class="stat-value"><?= e(fmt_qty($summary['active'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Qty di OEF</div><div class="stat-value"><?= e(fmt_qty($summary['oef_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary"><?= e(fmt_qty($summary['from_oef'] ?? 0, '0')) ?> produk dibuat dari OEF</div></div>
    <?php if ($showPo): ?>
        <div><div class="stat-label">Punya outstanding PO</div><div class="stat-value"><?= e(fmt_qty($summary['with_open_po'] ?? 0, '0')) ?></div>
            <?php if (($summary['with_open_po'] ?? 0) > 0 && $filters['open'] === ''): ?><a class="x-small" href="<?= e(url('/products', ['open' => 1])) ?>">Lihat</a><?php endif; ?></div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/products')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama, kode, varian, kategori…" aria-label="Cari produk"></div>
        <select class="form-select" name="category" aria-label="Kelompok" data-autosubmit><option value="">Semua kelompok</option><?= Form::options(Form::list($categories), $filters['category']) ?></select>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Aktif &amp; nonaktif</option>
            <option value="active"<?= selected('active', $filters['status']) ?>>Hanya aktif</option>
            <option value="inactive"<?= selected('inactive', $filters['status']) ?>>Hanya nonaktif</option>
        </select>
        <?php if ($showPo): ?>
            <select class="form-select" name="open" aria-label="Outstanding" data-autosubmit>
                <option value="">Semua produk</option>
                <option value="1"<?= selected('1', $filters['open']) ?>>Punya outstanding OEF berjalan</option>
            </select>
        <?php endif; ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/products')) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($products->isEmpty()): ?>
        <div class="empty-state">
            <i class="bi bi-box-seam"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada produk yang cocok' : 'Belum ada produk' ?></div>
            <p><?= $hasFilter ? 'Coba kata kunci atau filter lain.' : 'Tambahkan produk atau impor data dari workbook.' ?></p>
            <?php if (!$hasFilter && can('products.create')): ?><a href="<?= e(url('/products/create')) ?>" class="btn btn-primary btn-sm">Tambah Produk</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr>
                    <th><?= sort_link('name', 'Produk', $sort, $dir) ?></th>
                    <th class="d-none d-lg-table-cell"><?= sort_link('category', 'Kelompok', $sort, $dir) ?></th>
                    <th class="num d-none d-sm-table-cell"><?= sort_link('oef_qty', 'Qty OEF', $sort, $dir) ?></th>
                    <?php if ($showPo): ?>
                        <th class="num"><?= sort_link('outstanding', 'Outstanding', $sort, $dir) ?></th>
                    <?php endif; ?>
                    <?php if ($showStock): ?>
                        <th class="num d-none d-xl-table-cell">Stok FG</th>
                        <th class="num d-none d-xl-table-cell">Ready</th>
                    <?php endif; ?>
                    <th class="d-none d-sm-table-cell">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($products->items as $p): ?>
                    <tr class="<?= (int) $p['is_active'] === 1 ? '' : 'row-muted' ?>">
                        <td class="min-w-0">
                            <a class="cell-title" href="<?= e(url('/products/' . $p['id'])) ?>"><?= e($p['name']) ?></a>
                            <div class="cell-sub">
                                <span class="code-chip"><?= e($p['code']) ?></span>
                                <?= $p['product_code'] ? ' · ' . e($p['product_code']) : '' ?>
                                <?= $p['variant'] ? ' · ' . e(excerpt($p['variant'], 60)) : '' ?>
                                <span class="d-lg-none"> · <?= e($p['product_group']) ?></span>
                            </div>
                            <?php if ((int) $p['is_active'] !== 1): ?><div class="cell-sub d-sm-none"><?= status_badge('Inactive', 'Nonaktif') ?></div><?php endif; ?>
                        </td>
                        <td class="d-none d-lg-table-cell text-secondary"><?= e($p['product_group']) ?></td>
                        <td class="num d-none d-sm-table-cell"><?= (int) $p['oef_count'] > 0 ? e(fmt_qty($p['oef_qty'], '0')) . '<div class="x-small text-secondary">' . (int) $p['oef_count'] . ' OEF</div>' : '<span class="text-subtle">—</span>' ?></td>
                        <?php if ($showPo): ?>
                            <td class="num fw-semibold"><?= e(fmt_qty($p['open_outstanding'], '0')) ?></td>
                        <?php endif; ?>
                        <?php if ($showStock): ?>
                            <td class="num d-none d-xl-table-cell"><?= (int) $p['stock_entries'] > 0 ? e(fmt_qty($p['stock_fg'], '0')) : '<span class="text-subtle">—</span>' ?></td>
                            <td class="num d-none d-xl-table-cell"><?= (int) $p['stock_entries'] > 0 ? e(fmt_qty($p['stock_ready'], '0')) : '<span class="text-subtle">—</span>' ?></td>
                        <?php endif; ?>
                        <td class="d-none d-sm-table-cell"><?= (int) $p['is_active'] === 1 ? status_badge('Active', 'Aktif') : status_badge('Inactive', 'Nonaktif') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $products->footer('produk') ?>
    <?php endif; ?>
</div>
