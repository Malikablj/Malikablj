<?php

use App\Models\LeadTime;

/**
 * @var array<string,mixed> $product
 * @var list<array<string,mixed>> $lines
 * @var list<array<string,mixed>> $stock
 * @var list<array<string,mixed>> $deliveries
 * @var list<array<string,mixed>> $leadtimes
 * @var list<array<string,mixed>> $inbound
 * @var list<array<string,mixed>> $history
 */
$id = (int) $product['id'];
$base = '/products/' . $id;
$active = (int) $product['is_active'] === 1;
$canPo = can('purchase_orders.view');
$canStock = can('stock.view');
$today = today();
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/products')) ?>">Products</a><i class="bi bi-chevron-right"></i><span><?= e($product['name']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-box-seam"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($product['name']) ?> <?= $active ? status_badge('Active', 'Aktif') : status_badge('Inactive', 'Nonaktif') ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($product['code']) ?></span>
                <?php if ($product['product_code']): ?><span><i class="bi bi-upc"></i><?= e($product['product_code']) ?></span><?php endif; ?>
                <?php if ($product['category']): ?><span><i class="bi bi-tag"></i><?= e($product['category']) ?></span><?php endif; ?>
                <span><i class="bi bi-rulers"></i>Satuan <?= e($product['unit']) ?></span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('stock.create')): ?><a class="btn btn-primary" href="<?= e(url('/stock/create', ['product_id' => $id])) ?>"><i class="bi bi-plus-lg"></i> Catat stok</a><?php endif; ?>
        <?php if (can('products.edit')): ?><a class="btn btn-light" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
        <?php if (can('products.delete')): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-icon" type="button" data-bs-toggle="dropdown" aria-label="Aksi lain"><i class="bi bi-three-dots"></i></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="Hapus produk ini? Produk yang sudah dipakai di PO/stok/delivery tidak dapat dihapus.">
                        <?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus produk</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($product['variant']): ?>
    <p class="text-secondary section-gap">Varian: <?= e($product['variant']) ?></p>
<?php endif; ?>
<?php if (!empty($product['spec'])): ?>
    <div class="callout section-gap small"><div class="fw-semibold mb-1">Spesifikasi terakhir (dari Order Entry Form)</div><?= nl2br(e($product['spec'])) ?></div>
<?php endif; ?>

<div class="stat-strip section-gap">
    <?php if ($canPo): ?>
        <div><div class="stat-label">Outstanding PO terbuka</div><div class="stat-value"><?= e(fmt_qty($product['open_outstanding'], '0')) ?></div><div class="x-small text-secondary"><?= e($product['unit']) ?></div></div>
        <div><div class="stat-label">Baris PO</div><div class="stat-value"><?= e(fmt_qty($product['line_count'], '0')) ?></div></div>
        <div><div class="stat-label">Total terkirim</div><div class="stat-value"><?= e(fmt_qty($product['delivered_qty'], '0')) ?></div><div class="x-small text-secondary">delivery Delivered/Partial</div></div>
    <?php endif; ?>
    <?php if ($canStock): ?>
        <div><div class="stat-label">Stok FG</div><div class="stat-value"><?= e(fmt_qty($product['stock_fg'], '0')) ?></div>
            <div class="x-small text-secondary">Ready <?= e(fmt_qty($product['stock_ready'], '0')) ?> · WIP <?= e(fmt_qty($product['stock_wip'], '0')) ?> · Reserved <?= e(fmt_qty($product['stock_reserved'], '0')) ?></div></div>
    <?php endif; ?>
    <?php if ($product['capacity_per_day'] !== null): ?>
        <div><div class="stat-label">Kapasitas / hari</div><div class="stat-value"><?= e(fmt_qty($product['capacity_per_day'], '0')) ?></div><div class="x-small text-secondary"><?= e($product['unit']) ?></div></div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-xl-8 min-w-0">
        <?php if ($canPo): ?>
            <section class="surface section-gap">
                <div class="surface-header"><div><h2 class="surface-title">Order (OEF/PO) <span class="tab-count"><?= count($lines) ?></span></h2>
                    <p class="surface-subtitle">PO terbuka ditampilkan lebih dulu. Outstanding = Order − Terkirim + Retur.</p></div></div>
                <?php if (!$lines): ?>
                    <div class="empty-inline">Produk ini belum pernah dipesan.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table-pik table-compact">
                            <thead><tr><th>Order · Customer</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-md-table-cell">Order</th><th class="num d-none d-md-table-cell">Terkirim</th><th class="num">Outstanding</th></tr></thead>
                            <tbody>
                            <?php foreach ($lines as $l): $out = (int) $l['outstanding_qty']; ?>
                                <tr>
                                    <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $l['po_id'])) ?>"><?= e($l['po_number'] ?? $l['po_code']) ?></a>
                                        <div class="cell-sub"><?= e($l['customer_name'] ?? 'Customer belum terhubung') ?> · <?= e(fmt_date($l['po_date'], 'Tanpa tanggal')) ?></div>
                                        <div class="cell-sub d-md-none">Order <?= e(fmt_qty($l['order_qty'])) ?> · Terkirim <?= e(fmt_qty($l['delivered_qty'])) ?></div>
                                        <div class="cell-sub d-sm-none"><?= status_badge($l['po_status']) ?></div></td>
                                    <td class="d-none d-sm-table-cell"><?= status_badge($l['po_status']) ?></td>
                                    <td class="num d-none d-md-table-cell"><?= e(fmt_qty($l['order_qty'])) ?></td>
                                    <td class="num d-none d-md-table-cell"><?= e(fmt_qty($l['delivered_qty'])) ?></td>
                                    <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($canStock): ?>
            <section class="surface section-gap">
                <div class="surface-header"><div><h2 class="surface-title">Stok <span class="tab-count"><?= count($stock) ?></span></h2>
                    <p class="surface-subtitle">Posisi stok per tipe. Satu produk boleh punya beberapa entri (batch/lokasi).</p></div>
                    <?php if (can('stock.create')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/stock/create', ['product_id' => $id])) ?>"><i class="bi bi-plus-lg"></i> Entri stok</a><?php endif; ?></div>
                <?php if (!$stock): ?>
                    <div class="empty-inline">Belum ada data stok untuk produk ini.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table-pik table-compact">
                            <thead><tr><th>Tipe</th><th class="num">Qty</th><th class="num d-none d-sm-table-cell">Box × isi</th><th class="d-none d-md-table-cell">Status / catatan</th><th class="col-actions"></th></tr></thead>
                            <tbody>
                            <?php foreach ($stock as $s): ?>
                                <tr>
                                    <td><span class="chip"><?= e($s['stock_type']) ?></span><div class="cell-sub d-md-none"><?= e(trim(($s['status'] ?? '') . ' ' . excerpt($s['notes'] ?? '', 40))) ?></div></td>
                                    <td class="num fw-semibold"><?= e(fmt_qty($s['quantity'])) ?></td>
                                    <td class="num d-none d-sm-table-cell text-secondary"><?= $s['box'] !== null && $s['qty_per_box'] !== null ? e(fmt_qty($s['box']) . ' × ' . fmt_qty($s['qty_per_box'])) : '—' ?></td>
                                    <td class="d-none d-md-table-cell small"><?= e($s['status'] ?? '') ?><?= $s['notes'] ? '<div class="text-secondary">' . e(excerpt($s['notes'], 80)) . '</div>' : '' ?></td>
                                    <td class="col-actions"><?php if (can('stock.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/stock/' . $s['id'] . '/edit', ['return' => $base])) ?>" title="Edit entri stok"><i class="bi bi-pencil"></i></a><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can('deliveries.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Delivery terakhir</h2></div>
                <?php if (!$deliveries): ?>
                    <div class="empty-inline">Belum ada delivery untuk produk ini.</div>
                <?php else: ?>
                    <ul class="list-lite">
                        <?php foreach ($deliveries as $d): ?>
                            <li><div class="li-main"><a class="li-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?? $d['code']) ?></a>
                                <div class="li-sub"><?= e(fmt_date($d['delivery_date'])) ?> · <?= e($d['po_number'] ?? $d['po_code'] ?? '') ?><?= $d['customer_name'] ? ' · ' . e($d['customer_name']) : '' ?></div></div>
                                <div class="li-end"><div class="fw-semibold"><?= e(fmt_qty($d['delivered_qty'])) ?></div><?= status_badge($d['status']) ?></div></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4 min-w-0">
        <?php if (can('leadtime.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Lead time</h2></div>
                <?php if (!$leadtimes): ?>
                    <div class="empty-inline">Belum ada estimasi lead time.</div>
                <?php else: ?>
                    <ul class="list-lite">
                        <?php foreach ($leadtimes as $lt): $late = LeadTime::isLate($lt, $today); ?>
                            <li><div class="li-main"><span class="li-title"><?= e($lt['po_number'] ?? $lt['po_number_legacy'] ?? 'Tanpa PO') ?></span>
                                <div class="li-sub"><?= e(fmt_qty($lt['quantity'])) ?> <?= e($product['unit']) ?> · estimasi <?= e(fmt_date($lt['delivery_date'], 'belum ada')) ?></div></div>
                                <div class="li-end"><?= $late ? status_badge('Overdue', 'Terlambat') : status_badge($lt['status']) ?></div></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can('inbound.view') && $inbound): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Inbound maklon</h2></div>
                <ul class="list-lite">
                    <?php foreach ($inbound as $ib): ?>
                        <li><div class="li-main"><a class="li-title" href="<?= e(url('/inbound/' . $ib['id'])) ?>"><?= e($ib['sj_number'] ?? $ib['code']) ?></a>
                            <div class="li-sub"><?= e(fmt_date($ib['actual_inbound_date'])) ?> · <?= e($ib['vendor'] ?? '') ?></div></div>
                            <div class="li-end fw-semibold"><?= e(fmt_qty($ib['total_in'] ?? $ib['quantity'])) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <section class="surface surface-pad section-gap small">
            <dl class="dl-single mb-0">
                <dt>Catatan</dt><dd><?= $product['notes'] ? nl2br(e($product['notes'])) : '<span class="text-subtle">—</span>' ?></dd>
                <dt>Dibuat</dt><dd><?= e(fmt_datetime($product['created_at'])) ?><?= $product['created_by_name'] ? ' oleh ' . e($product['created_by_name']) : '' ?></dd>
                <?php if ($product['updated_at']): ?><dt>Diperbarui</dt><dd><?= e(fmt_datetime($product['updated_at'])) ?><?= $product['updated_by_name'] ? ' oleh ' . e($product['updated_by_name']) : '' ?></dd><?php endif; ?>
                <?php if ($product['source']): ?><dt>Sumber</dt><dd><?= e($product['source'] === 'OEF' ? 'Dicatat otomatis dari Order Entry Form' : $product['source']) ?></dd><?php endif; ?>
            </dl>
        </section>

        <?php if ($history): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Riwayat perubahan</h2></div>
                <ul class="list-lite">
                    <?php foreach ($history as $h): $changes = json_decode((string) $h['changes'], true); ?>
                        <li><div class="li-main"><span class="li-title"><?= e(ucfirst((string) $h['action'])) ?> oleh <?= e($h['user_name'] ?? 'sistem') ?></span>
                            <div class="li-sub"><?= is_array($changes) ? e(implode(', ', array_slice(array_keys($changes), 0, 5))) : '' ?></div></div>
                            <div class="li-end text-secondary x-small"><?= e(fmt_datetime($h['created_at'])) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
