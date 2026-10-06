<?php

use App\Helpers\Form;
use App\Models\Stock;

/**
 * @var string $mode product|entries
 * @var App\Helpers\Paginator $rows
 * @var array<string,int> $summary
 * @var array<string,mixed> $filters
 * @var bool $canProduct
 * @var array<string,array<string,int>> $groups total per kelompok
 * @var list<string> $groupList
 */
$hasFilter = $filters['q'] !== '' || $filters['type'] !== '' || $filters['link'] !== '' || $filters['group'] !== '';
$productLink = static function (?int $productId, string $label) use ($canProduct): string {
    if ($productId !== null && $canProduct) {
        return '<a class="cell-title" href="' . e(url('/products/' . $productId)) . '">' . e($label) . '</a>';
    }
    return '<span class="cell-title">' . e($label) . '</span>';
};
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Inventory</div>
        <h1 class="page-title">Stock</h1>
        <p class="page-subtitle">Diisi Produksi &amp; Gudang. Produk diketik manual lalu otomatis dikelompokkan per produk dan per kelompok. FG = barang jadi, WIP = dalam produksi, Ready = siap kirim, Reserved = dialokasikan.</p>
    </div>
    <?php if (can('stock.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/stock/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Stok</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <?php foreach (Stock::TYPES as $t): ?>
        <div><div class="stat-label"><?= e($t) ?><?= $hasFilter ? ' (filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary[strtolower($t)] ?? 0, '0')) ?></div></div>
    <?php endforeach; ?>
    <?php if (($summary['unlinked'] ?? 0) > 0): ?>
        <div><div class="stat-label">Belum terhubung ke produk</div><div class="stat-value text-warning-ink"><?= e(fmt_qty($summary['unlinked'], '0')) ?></div>
            <a class="x-small" href="<?= e(url('/stock', ['view' => 'entries', 'link' => 'unlinked'])) ?>">Lihat entri</a></div>
    <?php endif; ?>
</div>

<nav class="tabs-pik" aria-label="Tampilan stok">
    <a href="<?= e(url('/stock', array_filter(['q' => $filters['q'], 'type' => $filters['type'], 'group' => $filters['group']]))) ?>" class="<?= $mode === 'product' ? 'active' : '' ?>"><i class="bi bi-grid-3x3-gap"></i> Per kelompok &amp; produk</a>
    <a href="<?= e(url('/stock', array_filter(['view' => 'entries', 'q' => $filters['q'], 'type' => $filters['type'], 'link' => $filters['link'], 'group' => $filters['group']]))) ?>" class="<?= $mode === 'entries' ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> Semua entri</a>
</nav>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/stock')) ?>">
        <?php if ($mode === 'entries'): ?><input type="hidden" name="view" value="entries"><?php endif; ?>
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari produk, kode, status, catatan…" aria-label="Cari stok"></div>
        <select class="form-select" name="group" aria-label="Kelompok produk" data-autosubmit><option value="">Semua kelompok</option><?= Form::options(Form::list($groupList), $filters['group']) ?></select>
        <select class="form-select" name="type" aria-label="Tipe stok" data-autosubmit><option value="">Semua tipe</option><?= Form::options(Form::list(Stock::TYPES), $filters['type']) ?></select>
        <?php if ($mode === 'entries'): ?>
            <select class="form-select" name="link" aria-label="Relasi" data-autosubmit><option value="">Semua entri</option><option value="unlinked"<?= selected('unlinked', $filters['link']) ?>>Belum terhubung ke produk</option></select>
        <?php endif; ?>
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/stock', $mode === 'entries' ? ['view' => 'entries'] : [])) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($rows->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-boxes"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada stok yang cocok' : 'Belum ada data stok' ?></div>
            <p><?= $hasFilter ? 'Coba kata kunci atau filter lain.' : 'Catat stok: ketik nama produk, pilih tipe (FG, WIP, Ready, Reserved), isi qty.' ?></p>
        </div>
    <?php elseif ($mode === 'product'): ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Produk</th><th class="num">FG</th><th class="num d-none d-md-table-cell">WIP</th><th class="num">Ready</th><th class="num d-none d-md-table-cell">Reserved</th>
                    <th class="num d-none d-lg-table-cell">Entri</th><th class="d-none d-xl-table-cell">Update terakhir</th></tr></thead>
                <tbody>
                <?php $currentGroup = null; foreach ($rows->items as $r): ?>
                    <?php if ($r['product_group'] !== $currentGroup): $currentGroup = $r['product_group']; $g = $groups[$currentGroup] ?? null; ?>
                        <tr class="group-row"><td><i class="bi bi-collection me-1"></i><?= e($currentGroup) ?><?= $g ? ' · ' . $g['products'] . ' produk' : '' ?></td>
                            <td class="num"><?= $g ? e(fmt_qty($g['fg'], '0')) : '' ?></td><td class="num d-none d-md-table-cell"><?= $g ? e(fmt_qty($g['wip'], '0')) : '' ?></td>
                            <td class="num"><?= $g ? e(fmt_qty($g['ready'], '0')) : '' ?></td><td class="num d-none d-md-table-cell"><?= $g ? e(fmt_qty($g['reserved'], '0')) : '' ?></td>
                            <td class="d-none d-lg-table-cell"></td><td class="d-none d-xl-table-cell"></td></tr>
                    <?php endif; ?>
                    <tr>
                        <td class="min-w-0"><?= $productLink((int) $r['product_id'], (string) $r['product_name']) ?>
                            <div class="cell-sub"><?= e(trim(($r['product_code'] ?? '') . ' ' . excerpt($r['variant'] ?? '', 50))) ?: '<span class="code-chip">' . e($r['product_id_code']) . '</span>' ?></div>
                            <div class="cell-sub d-md-none">WIP <?= e(fmt_qty($r['wip'], '0')) ?> · Reserved <?= e(fmt_qty($r['reserved'], '0')) ?></div>
                            <?php if ((int) $r['qty_missing'] > 0): ?><div class="cell-sub text-warning-ink"><i class="bi bi-exclamation-circle"></i> <?= (int) $r['qty_missing'] ?> entri tanpa qty (tidak ikut dijumlah)</div><?php endif; ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($r['fg'], '0')) ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($r['wip'], '0')) ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($r['ready'], '0')) ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($r['reserved'], '0')) ?></td>
                        <td class="num d-none d-lg-table-cell"><a href="<?= e(url('/stock', ['view' => 'entries', 'q' => $r['product_name']])) ?>"><?= (int) $r['entries'] ?></a></td>
                        <td class="d-none d-xl-table-cell text-secondary small"><?= e(fmt_datetime($r['last_update'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('produk') ?>
        <?php if (($summary['unlinked'] ?? 0) > 0): ?>
            <div class="surface-footer small text-secondary"><i class="bi bi-info-circle me-1"></i><?= (int) $summary['unlinked'] ?> entri stok legacy belum terhubung ke master produk dan tidak ikut di tabel ini. <a href="<?= e(url('/stock', ['view' => 'entries', 'link' => 'unlinked'])) ?>">Hubungkan sekarang</a>.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Produk</th><th class="d-none d-sm-table-cell">Tipe</th><th class="num">Qty</th><th class="num d-none d-md-table-cell">Box × isi</th><th class="d-none d-lg-table-cell">Status / catatan</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($rows->items as $s): ?>
                    <tr>
                        <td class="min-w-0">
                            <?php if ($s['product_id'] !== null): ?>
                                <?= $productLink((int) $s['product_id'], (string) $s['product_name']) ?>
                                <div class="cell-sub"><?= e(trim(($s['product_code'] ?? '') . ' ' . excerpt($s['variant'] ?? '', 50))) ?></div>
                            <?php else: ?>
                                <span class="cell-title"><?= e($s['product_legacy'] ?? '(tanpa nama)') ?></span>
                                <div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke produk</span></div>
                            <?php endif; ?>
                            <div class="cell-sub d-sm-none"><span class="chip"><?= e($s['stock_type']) ?></span> <?= e($s['status'] ?? '') ?></div>
                        </td>
                        <td class="d-none d-sm-table-cell"><span class="chip"><?= e($s['stock_type']) ?></span></td>
                        <td class="num fw-semibold<?= $s['quantity'] !== null && (int) $s['quantity'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($s['quantity'])) ?></td>
                        <td class="num d-none d-md-table-cell text-secondary"><?= $s['box'] !== null && $s['qty_per_box'] !== null ? e(fmt_qty($s['box']) . ' × ' . fmt_qty($s['qty_per_box'])) : '—' ?></td>
                        <td class="d-none d-lg-table-cell small"><?= e($s['status'] ?? '') ?><?= $s['notes'] ? '<div class="text-secondary">' . e(excerpt($s['notes'], 80)) . '</div>' : '' ?></td>
                        <td class="col-actions"><?php if (can('stock.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/stock/' . $s['id'] . '/edit', ['return' => '/stock?view=entries'])) ?>" title="<?= $s['product_id'] === null ? 'Hubungkan ke produk' : 'Edit' ?>"><i class="bi <?= $s['product_id'] === null ? 'bi-link-45deg' : 'bi-pencil' ?>"></i><span class="d-none d-md-inline"> <?= $s['product_id'] === null ? 'Hubungkan' : 'Edit' ?></span></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('entri') ?>
    <?php endif; ?>
</div>
