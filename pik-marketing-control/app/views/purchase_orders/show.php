<?php

use App\Helpers\Form;
use App\Models\ProductReturn;

/**
 * @var array<string,mixed> $po
 * @var list<array<string,mixed>> $lines
 * @var list<array<string,mixed>> $deliveries
 * @var list<array<string,mixed>> $returns
 * @var list<array<string,mixed>> $invoices
 * @var list<array<string,mixed>> $financials
 * @var list<array<string,mixed>> $leadtimes
 * @var list<array<string,mixed>> $issues
 * @var array<int,string> $products
 */
$id = (int) $po['id'];
$base = '/purchase-orders/' . $id;
$progress = pct((int) $po['delivered_qty'], (int) $po['total_qty']);
$hasLegacy = false;
foreach ($lines as $l) {
    if ($l['legacy_outstanding_qty'] !== null || $l['legacy_delivered_qty'] !== null) {
        $hasLegacy = true;
    }
}
$unlinked = array_filter($deliveries, static fn ($d) => $d['po_line_id'] === null);
$canProduct = can('products.view');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Purchase Orders</a><i class="bi bi-chevron-right"></i><span><?= e($po['po_number'] ?? $po['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-receipt"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($po['po_number'] ?? '(tanpa nomor PO)') ?> <?= status_badge($po['status']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($po['code']) ?></span>
                <?php if ($po['customer_id']): ?><span><i class="bi bi-buildings"></i><a href="<?= e(url('/customers/' . $po['customer_id'])) ?>"><?= e($po['customer_name']) ?></a></span>
                <?php else: ?><span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span><?php endif; ?>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($po['po_date'], 'Tanpa tanggal')) ?></span>
                <?php if ($po['payment_term']): ?><span><i class="bi bi-credit-card"></i><?= e($po['payment_term']) ?></span><?php endif; ?>
                <?php if ($po['legacy_status'] && $po['legacy_status'] !== $po['status']): ?><span title="Status di spreadsheet asli"><i class="bi bi-clock-history"></i>Legacy: <?= e($po['legacy_status']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('deliveries.create') && $lines): ?><a class="btn btn-primary" href="<?= e(url('/deliveries/create', ['po_id' => $id])) ?>"><i class="bi bi-truck"></i> Catat delivery</a><?php endif; ?>
        <?php if (can('returns.create') && $lines): ?><a class="btn btn-light" href="<?= e(url('/returns/create', ['po_id' => $id])) ?>"><i class="bi bi-arrow-return-left"></i> Catat retur</a><?php endif; ?>
        <?php if (can('purchase_orders.edit') || can('purchase_orders.delete')): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-icon" type="button" data-bs-toggle="dropdown" aria-label="Aksi lain"><i class="bi bi-three-dots"></i></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <?php if (can('purchase_orders.edit')): ?><a class="dropdown-item" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil me-2"></i>Edit header PO</a><?php endif; ?>
                    <?php if (can('purchase_orders.delete')): ?>
                        <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="Hapus PO ini? PO yang sudah memiliki delivery/retur/invoice tidak dapat dihapus.">
                            <?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus PO</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Total order</div><div class="stat-value"><?= e(fmt_qty($po['total_qty'], '0')) ?></div><div class="x-small text-secondary"><?= (int) $po['line_count'] ?> baris produk</div></div>
    <div><div class="stat-label">Terkirim</div><div class="stat-value"><?= e(fmt_qty($po['delivered_qty'], '0')) ?></div>
        <div class="progress-thin mt-2<?= $progress >= 100 ? ' is-done' : '' ?>"><span style="width: <?= $progress ?>%"></span></div></div>
    <div><div class="stat-label">Retur</div><div class="stat-value"><?= e(fmt_qty($po['return_qty'], '0')) ?></div></div>
    <div><div class="stat-label">Outstanding</div><div class="stat-value<?= (int) $po['outstanding_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($po['outstanding_qty'], '0')) ?></div>
        <div class="x-small text-secondary">Order − Terkirim + Retur</div></div>
</div>

<?php if ($unlinked || $issues): ?>
    <div class="callout callout-warning section-gap small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?php if ($unlinked): ?><strong><?= count($unlinked) ?> delivery legacy</strong> di PO ini belum terhubung ke baris PO, sehingga belum mengurangi outstanding. <?php endif; ?>
        <?php if ($issues): ?><strong><?= count($issues) ?> migration issue</strong> terbuka untuk PO ini. <?php endif; ?>
        <?php if (can('migration.view')): ?><a href="<?= e(url('/migration-issues', ['q' => $po['po_number'] ?? $po['code']])) ?>">Tinjau di Migration Issues</a><?php endif; ?>
    </div>
<?php endif; ?>

<section class="surface section-gap">
    <div class="surface-header"><div><h2 class="surface-title">Baris produk</h2><p class="surface-subtitle">Delivered hanya menghitung delivery berstatus Delivered/Partial yang terhubung ke baris.</p></div></div>
    <div class="table-wrap">
        <table class="table-pik">
            <thead><tr><th>Produk</th><th class="num d-none d-md-table-cell">Order</th><th class="num d-none d-md-table-cell">Terkirim</th><th class="num d-none d-md-table-cell">Retur</th><th class="num">Outstanding</th>
                <?php if ($hasLegacy): ?><th class="num d-none d-xl-table-cell" title="Nilai di spreadsheet asli">Outstanding legacy</th><?php endif; ?>
                <th class="col-actions"></th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): $out = (int) $l['outstanding_qty']; ?>
                <tr>
                    <td><?php if ($canProduct): ?><a class="cell-title" href="<?= e(url('/products/' . $l['product_id'])) ?>"><?= e($l['product_name']) ?></a><?php else: ?><span class="cell-title"><?= e($l['product_name']) ?></span><?php endif; ?>
                        <div class="cell-sub"><?= e(trim(($l['product_code'] ?? '') . ' ' . ($l['variant'] ?? ''))) ?: '<span class="code-chip">' . e($l['code']) . '</span>' ?>
                            <?= $l['remark'] ? ' · ' . e($l['remark']) : '' ?></div>
                        <div class="cell-sub d-md-none">Order <?= e(fmt_qty($l['order_qty'])) ?> · Terkirim <?= e(fmt_qty($l['delivered_qty'])) ?><?= (int) $l['return_qty'] > 0 ? ' · Retur ' . e(fmt_qty($l['return_qty'])) : '' ?></div></td>
                    <td class="num d-none d-md-table-cell"><?= e(fmt_qty($l['order_qty'])) ?></td>
                    <td class="num d-none d-md-table-cell"><?= e(fmt_qty($l['delivered_qty'])) ?></td>
                    <td class="num d-none d-md-table-cell"><?= e(fmt_qty($l['return_qty'])) ?></td>
                    <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out)) ?><?= $out < 0 ? '<div class="x-small">over delivery</div>' : '' ?></td>
                    <?php if ($hasLegacy): ?>
                        <td class="num d-none d-xl-table-cell text-secondary<?= $l['legacy_outstanding_qty'] !== null && (int) $l['legacy_outstanding_qty'] !== $out ? ' fw-semibold' : '' ?>"><?= e(fmt_qty($l['legacy_outstanding_qty'])) ?></td>
                    <?php endif; ?>
                    <td class="col-actions">
                        <?php if (can('deliveries.create')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/deliveries/create', ['po_line_id' => $l['id']])) ?>" title="Catat delivery untuk baris ini"><i class="bi bi-truck"></i></a><?php endif; ?>
                        <?php if (can('purchase_orders.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/po-lines/' . $l['id'] . '/edit')) ?>" title="Edit baris"><i class="bi bi-pencil"></i></a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (can('purchase_orders.edit')): ?>
        <form class="surface-footer" method="post" action="<?= e(url($base . '/lines')) ?>">
            <?= csrf_field() ?>
            <div class="row g-2 align-items-center">
                <div class="col-md-6"><select class="form-select" name="product_id" required aria-label="Produk" data-searchable="Cari produk…"><option value="">+ Tambah produk ke PO…</option><?= Form::options($products, null) ?></select></div>
                <div class="col-6 col-md-2"><input type="number" class="form-control" name="order_qty" min="1" step="1" placeholder="Qty" required aria-label="Qty"></div>
                <div class="col-6 col-md-2"><input type="text" class="form-control" name="remark" maxlength="500" placeholder="Catatan" aria-label="Catatan"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-light" type="submit"><i class="bi bi-plus-lg"></i> Tambah</button></div>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php if (can('deliveries.view')): ?>
    <section class="surface section-gap">
        <div class="surface-header"><h2 class="surface-title">Deliveries <span class="tab-count"><?= count($deliveries) ?></span></h2></div>
        <?php if (!$deliveries): ?>
            <div class="empty-inline">Belum ada delivery untuk PO ini.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-pik table-compact">
                    <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Surat Jalan</th><th class="d-none d-md-table-cell">Produk</th><th class="d-none d-sm-table-cell">Status</th><th class="num">Qty</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveries as $d): ?>
                        <tr>
                            <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($d['delivery_date'])) ?></td>
                            <td><a class="cell-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?? $d['code']) ?></a>
                                <div class="cell-sub d-sm-none"><?= e(fmt_date($d['delivery_date'])) ?> · <?= status_badge($d['status']) ?></div>
                                <?php if ($d['product_name'] !== null): ?><div class="cell-sub d-md-none"><?= e($d['product_name']) ?></div><?php endif; ?>
                                <?php if ($d['po_line_id'] === null): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke baris</span></div><?php endif; ?></td>
                            <td class="d-none d-md-table-cell small"><?= e($d['product_name'] ?? '—') ?></td>
                            <td class="d-none d-sm-table-cell"><?= status_badge($d['status']) ?></td>
                            <td class="num fw-semibold<?= (int) $d['delivered_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($d['delivered_qty'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (can('returns.view')): ?>
    <section class="surface section-gap">
        <div class="surface-header"><h2 class="surface-title">Retur <span class="tab-count"><?= count($returns) ?></span></h2></div>
        <?php if (!$returns): ?>
            <div class="empty-inline">Belum ada retur.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-pik table-compact">
                    <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Produk</th><th class="d-none d-sm-table-cell">Alasan</th><th class="num">Qty</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($returns as $r): ?>
                        <tr>
                            <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['return_date'])) ?></td>
                            <td><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?>
                                <div class="cell-sub d-sm-none"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?><?= $r['reason'] ? ' · ' . e(ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '' ?></div>
                                <?php if ($r['po_line_id'] === null): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke baris</span></div><?php endif; ?></td>
                            <td class="d-none d-sm-table-cell"><?= e($r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—') ?></td>
                            <td class="num fw-semibold"><?= e(fmt_qty($r['return_qty'])) ?></td>
                            <td class="col-actions"><?php if (can('returns.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/returns/' . $r['id'] . '/edit')) ?>">Edit</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (can('leadtime.view') && ($leadtimes || (can('leadtime.create') && $lines && in_array($po['status'], App\Models\PurchaseOrder::OPEN_STATUSES, true)))): ?>
    <section class="surface section-gap">
        <div class="surface-header"><div><h2 class="surface-title">Estimasi lead time delivery <span class="tab-count"><?= count($leadtimes) ?></span></h2></div>
            <?php if (can('leadtime.create') && $lines && in_array($po['status'], App\Models\PurchaseOrder::OPEN_STATUSES, true)): ?>
                <a class="btn btn-light btn-sm" href="<?= e(url('/lead-times/create', ['po_id' => $id, 'return' => $base])) ?>"><i class="bi bi-plus-lg"></i> Estimasi</a>
            <?php endif; ?></div>
        <?php if (!$leadtimes): ?>
            <div class="empty-inline">Belum ada estimasi tanggal delivery untuk PO ini.</div>
        <?php else: ?>
            <ul class="list-lite">
                <?php foreach ($leadtimes as $lt): $late = App\Models\LeadTime::isLate($lt, today()); ?>
                    <li><div class="li-main"><span class="li-title"><?= e($lt['product_name'] ?? ($lt['product_legacy'] ?? '—')) ?></span>
                        <div class="li-sub"><?= e(fmt_qty($lt['quantity'])) ?> pcs<?= $lt['notes'] ? ' · ' . e(excerpt($lt['notes'], 60)) : '' ?></div></div>
                        <div class="li-end"><div class="<?= $late ? 'text-danger fw-semibold' : '' ?>"><?= e(fmt_date($lt['delivery_date'], 'Belum ada')) ?></div>
                            <?= $late ? status_badge('Overdue', 'Terlambat') : status_badge($lt['status']) ?>
                            <?php if (can('leadtime.edit')): ?><a class="x-small ms-1" href="<?= e(url('/lead-times/' . $lt['id'] . '/edit', ['return' => $base])) ?>">Edit</a><?php endif; ?></div></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (can('finance.view')): ?>
    <section class="surface section-gap">
        <div class="surface-header"><h2 class="surface-title">Finance</h2>
            <?php if (can('finance.create')): ?><div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-light btn-sm" href="<?= e(url('/invoices/create', ['po_id' => $id])) ?>"><i class="bi bi-plus-lg"></i> Invoice</a>
                <a class="btn btn-light btn-sm" href="<?= e(url('/po-financials/create', ['po_id' => $id])) ?>"><i class="bi bi-plus-lg"></i> Nilai PO</a></div><?php endif; ?></div>
        <?php if (!$invoices && !$financials): ?><div class="empty-inline">Belum ada invoice atau nilai PO untuk PO ini.</div><?php endif; ?>
        <?php if ($financials): ?>
            <div class="table-wrap">
                <table class="table-pik table-compact">
                    <thead><tr><th>Ringkasan PO (legacy)</th><th class="num d-none d-md-table-cell">Qty</th><th class="num d-none d-md-table-cell">Harga satuan</th><th class="num">Total + PPN</th><th class="d-none d-sm-table-cell">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($financials as $f): ?>
                        <tr><td><a class="cell-title" href="<?= e(url('/po-financials/' . $f['id'])) ?>"><?= e($f['product_legacy'] ?? '—') ?></a><div class="cell-sub"><?= e($f['brand'] ?? '') ?></div><div class="cell-sub d-sm-none"><?= status_badge($f['payment_status']) ?></div></td>
                            <td class="num d-none d-md-table-cell"><?= e(fmt_qty($f['order_qty'])) ?></td><td class="num d-none d-md-table-cell"><?= e(App\Helpers\Number::decimal($f['unit_price'])) ?></td>
                            <td class="num"><?= e(fmt_money($f['total_incl_ppn'])) ?></td><td class="d-none d-sm-table-cell"><?= status_badge($f['payment_status']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($invoices): ?>
            <div class="table-wrap">
                <table class="table-pik table-compact">
                    <thead><tr><th>Invoice</th><th>Status</th><th class="num d-none d-sm-table-cell">Tagihan</th><th class="num">Sisa</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $i): ?>
                        <tr><td><a class="cell-title" href="<?= e(url('/invoices/' . $i['id'])) ?>"><?= e($i['invoice_number'] ?? $i['code']) ?></a><div class="cell-sub"><?= e(fmt_date($i['invoice_date'])) ?></div></td>
                            <td><?= status_badge($i['status']) ?></td><td class="num d-none d-sm-table-cell"><?= e(fmt_money($i['invoice_amount'])) ?></td><td class="num fw-semibold"><?= e(fmt_money($i['outstanding_amount'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($po['remark'] || $po['source_file']): ?>
    <section class="surface surface-pad small text-secondary">
        <?php if ($po['remark']): ?><div class="mb-1"><strong class="text-body">Catatan:</strong> <?= nl2br(e($po['remark'])) ?></div><?php endif; ?>
        <?php if ($po['source_file']): ?><div>Sumber data: <?= e($po['source_file']) ?> › <?= e($po['source_sheet']) ?> baris <?= (int) $po['legacy_row'] ?></div><?php endif; ?>
    </section>
<?php endif; ?>
