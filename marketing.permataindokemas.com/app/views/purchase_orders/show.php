<?php

use App\Models\ProductReturn;
use App\Models\PurchaseOrder;

/**
 * @var array<string,mixed> $po
 * @var list<array<string,mixed>> $lines
 * @var list<array<string,mixed>> $deliveries
 * @var list<array<string,mixed>> $returns
 * @var list<array<string,mixed>> $leadtimes
 * @var list<array<string,mixed>> $issues
 * @var list<string> $productNames
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
$number = PurchaseOrder::displayNumber($po);
$isOef = $po['ppic_status'] !== null;
$canPpic = $isOef && can('ppic.approve');
$canCustomer = can('customers.view');
$customerLabel = $po['customer_id'] ? ($canCustomer ? '<a href="' . e(url('/customers/' . $po['customer_id'])) . '">' . e($po['customer_name']) . '</a>' : e($po['customer_name'])) : null;
$canReschedule = !empty($po['schedule_delivery_id']) && in_array($po['schedule_status'], ['Scheduled', 'On Delivery'], true) && can('deliveries.edit');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Order Entry Form</a><i class="bi bi-chevron-right"></i><span><?= e($number) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-receipt"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($number) ?> <?= status_badge($po['status']) ?> <?= ppic_badge($po['ppic_status']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($po['code']) ?></span>
                <?php if ($po['order_number'] && $po['po_number']): ?><span title="No. PO dari customer"><i class="bi bi-file-earmark-text"></i>PO <?= e($po['po_number']) ?></span><?php endif; ?>
                <?php if ($po['customer_id']): ?><span><i class="bi bi-buildings"></i><?= $customerLabel ?></span>
                <?php else: ?><span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span><?php endif; ?>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($po['po_date'], 'Tanpa tanggal')) ?></span>
                <?php if ($po['payment_term']): ?><span><i class="bi bi-credit-card"></i><?= e($po['payment_term']) ?></span><?php endif; ?>
                <?php if ($po['legacy_status'] && $po['legacy_status'] !== $po['status']): ?><span title="Status di spreadsheet asli"><i class="bi bi-clock-history"></i>Legacy: <?= e($po['legacy_status']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('deliveries.create') && $lines): ?><a class="btn btn-primary" href="<?= e(url('/deliveries/create', ['po_id' => $id])) ?>"><i class="bi bi-truck"></i> Catat delivery</a><?php endif; ?>
        <?php if (can('returns.create') && $lines): ?><a class="btn btn-light" href="<?= e(url('/returns/create', ['po_id' => $id])) ?>"><i class="bi bi-exclamation-octagon"></i> Complaint / retur</a><?php endif; ?>
        <?php if (can('purchase_orders.edit') || can('purchase_orders.delete')): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-icon" type="button" data-bs-toggle="dropdown" aria-label="Aksi lain"><i class="bi bi-three-dots"></i></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <?php if (can('purchase_orders.edit')): ?><a class="dropdown-item" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil me-2"></i>Edit order</a><?php endif; ?>
                    <?php if (can('purchase_orders.delete')): ?>
                        <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="Hapus order ini? Order yang sudah memiliki pengiriman/retur tidak dapat dihapus.">
                            <?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus order</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isOef || $po['product_spec'] || $po['requested_date'] || $po['sales_name']): ?>
<div class="row g-4 section-gap">
    <div class="col-xl-8 min-w-0">
        <section class="surface h-100">
            <div class="surface-header"><h2 class="surface-title">Order Entry Form</h2></div>
            <div class="surface-body">
                <dl class="dl-grid">
                    <div><dt>No. order</dt><dd class="fw-semibold"><?= e($po['order_number'] ?? '—') ?></dd></div>
                    <div><dt>Nama sales</dt><dd><?= e($po['sales_name'] ?? '—') ?></dd></div>
                    <div><dt>Nama customer</dt><dd><?= $customerLabel ?? '—' ?></dd></div>
                    <div><dt>No. PO dari customer</dt><dd><?= e($po['po_number'] ?? '—') ?></dd></div>
                    <div><dt>Nama produk</dt><dd><?= e(implode(', ', array_map(static fn ($l) => $l['product_name'], $lines)) ?: '—') ?></dd></div>
                    <div><dt>Qty produk</dt><dd class="fw-semibold"><?= e(fmt_qty($po['total_qty'], '0')) ?> pcs</dd></div>
                    <div><dt>Supplier (subcont)</dt><dd><?= (int) $po['is_subcont'] === 1 ? e($po['supplier'] ?? '—') : '<span class="text-subtle">Tidak subcont</span>' ?></dd></div>
                    <div><dt>Permintaan selesai / kirim</dt><dd><?= e(fmt_date($po['requested_date'])) ?></dd></div>
                    <div><dt>Tujuan kirim</dt><dd><?= e($po['ship_to'] ?? ($po['customer_name'] ?? '—')) ?></dd></div>
                </dl>
                <div class="mt-3"><div class="small text-secondary fw-medium mb-1">Spesifikasi produk</div>
                    <div class="spec-box"><?= $po['product_spec'] ? nl2br(e($po['product_spec'])) : '<span class="text-subtle">Belum diisi</span>' ?></div></div>
                <?php if ($po['remark']): ?><div class="mt-3"><div class="small text-secondary fw-medium mb-1">Keterangan</div><div><?= nl2br(e($po['remark'])) ?></div></div><?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-xl-4 min-w-0">
        <?php if ($isOef): ?>
            <section class="surface section-gap ppic-panel ppic-<?= e(strtolower((string) $po['ppic_status'])) ?>">
                <div class="surface-header"><div><h2 class="surface-title">Konfirmasi PPIC</h2>
                    <p class="surface-subtitle">Cek spesifikasi, qty, subcont & tanggal permintaan.</p></div><?= ppic_badge($po['ppic_status']) ?></div>
                <div class="surface-body">
                    <?php if ($po['ppic_status'] !== 'Pending'): ?>
                        <div class="callout <?= $po['ppic_status'] === 'Approved' ? 'callout-success' : 'callout-danger' ?> mb-3">
                            <div class="fw-semibold"><i class="bi <?= $po['ppic_status'] === 'Approved' ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?> me-1"></i><?= e(PurchaseOrder::PPIC_LABELS[$po['ppic_status']]) ?></div>
                            <div class="small text-secondary"><?= e($po['ppic_by_name'] ?? '—') ?> · <?= e(fmt_datetime($po['ppic_at'])) ?></div>
                            <?php if ($po['ppic_note']): ?><div class="mt-2"><?= $po['ppic_status'] === 'Rejected' ? '<strong>Alasan:</strong> ' : '' ?><?= nl2br(e($po['ppic_note'])) ?></div><?php endif; ?>
                        </div>
                        <?php if ($po['ppic_status'] === 'Rejected' && can('purchase_orders.edit')): ?>
                            <p class="small text-secondary mb-3">Perbaiki order lalu simpan — order otomatis dikirim ulang ke PPIC. <a href="<?= e(url($base . '/edit')) ?>">Revisi order</a></p>
                        <?php endif; ?>
                    <?php elseif (!$canPpic): ?>
                        <p class="small text-secondary mb-0"><i class="bi bi-hourglass-split me-1"></i>Menunggu PPIC menandai order ini bisa / tidak bisa diproses.</p>
                    <?php endif; ?>
                    <?php if ($canPpic): ?>
                        <?php if ($po['ppic_status'] !== 'Pending'): ?><details class="small"><summary class="text-secondary mb-2">Ubah keputusan PPIC</summary><?php endif; ?>
                        <form method="post" action="<?= e(url($base . '/ppic')) ?>" data-allow-resubmit>
                            <?= csrf_field() ?>
                            <label class="form-label small" for="ppic_note">Catatan PPIC <span class="text-secondary fw-normal">(wajib bila tidak bisa diproses)</span></label>
                            <textarea class="form-control mb-3" id="ppic_note" name="ppic_note" rows="3" maxlength="2000" placeholder="mis. spesifikasi material tidak tersedia, jadwal produksi penuh sampai…"></textarea>
                            <div class="d-grid gap-2">
                                <button type="submit" name="decision" value="approve" class="btn btn-success"><i class="bi bi-check-lg"></i> Bisa diproses</button>
                                <button type="submit" name="decision" value="reject" class="btn btn-danger" data-confirm="Tandai order ini TIDAK bisa diproses? Jadwal delivery akan dibatalkan."><i class="bi bi-x-lg"></i> Tidak bisa diproses</button>
                            </div>
                        </form>
                        <?php if ($po['ppic_status'] !== 'Pending'): ?></details><?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php if (!empty($po['schedule_delivery_id']) && can('deliveries.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header"><div><h2 class="surface-title">Jadwal delivery</h2><p class="surface-subtitle">Dibuat otomatis dari permintaan kirim.</p></div><?= status_badge($po['schedule_status']) ?></div>
                <div class="surface-body">
                    <div class="d-flex align-items-baseline gap-2 mb-1"><span class="fs-5 fw-semibold"><?= e(fmt_date($po['schedule_date'], 'Belum dijadwalkan', true)) ?></span>
                        <?php if ($po['schedule_status'] === 'Scheduled'): ?><span class="small text-secondary"><?= e(relative_day($po['schedule_date'])) ?></span><?php endif; ?></div>
                    <a class="small" href="<?= e(url('/deliveries/' . $po['schedule_delivery_id'])) ?>">Lihat di menu Delivery</a>
                    <?php if ($canReschedule): ?>
                        <details class="mt-3"><summary class="small text-secondary">Ubah jadwal</summary>
                            <form class="mt-2" method="post" action="<?= e(url($base . '/schedule')) ?>">
                                <?= csrf_field() ?>
                                <label class="form-label small" for="reschedule_date">Tanggal kirim baru</label>
                                <input type="date" class="form-control mb-2" id="reschedule_date" name="delivery_date" value="<?= e($po['schedule_date']) ?>" required>
                                <label class="form-label small" for="reschedule_reason">Alasan perubahan</label>
                                <input type="text" class="form-control mb-2" id="reschedule_reason" name="reason" maxlength="300" placeholder="mis. bahan baku terlambat">
                                <button type="submit" class="btn btn-light btn-sm"><i class="bi bi-calendar-check"></i> Simpan jadwal</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
                <div class="col-md-6"><input type="text" class="form-control" name="product_name" maxlength="190" list="add-line-products" autocomplete="off" required placeholder="+ Tambah produk (ketik nama produk)…" aria-label="Nama produk">
                    <datalist id="add-line-products"><?php foreach ($productNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist></div>
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
        <div class="surface-header"><h2 class="surface-title">Complaint &amp; retur <span class="tab-count"><?= count($returns) ?></span></h2></div>
        <?php if (!$returns): ?>
            <div class="empty-inline">Belum ada complaint atau retur.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-pik table-compact">
                    <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Produk</th><th class="d-none d-sm-table-cell">Alasan</th><th class="d-none d-md-table-cell">Status</th><th class="num">Qty retur</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($returns as $r): ?>
                        <tr>
                            <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['return_date'])) ?></td>
                            <td><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?>
                                <div class="cell-sub d-sm-none"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?><?= $r['reason'] ? ' · ' . e(ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '' ?></div>
                                <?php if ($r['po_line_id'] === null): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke baris</span></div><?php endif; ?></td>
                            <td class="d-none d-sm-table-cell"><?= e($r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—') ?><div class="cell-sub"><?= e(ProductReturn::TYPE_LABELS[$r['record_type']] ?? '') ?></div></td>
                            <td class="d-none d-md-table-cell"><?= complaint_badge($r['complaint_status']) ?></td>
                            <td class="num fw-semibold"><?= e($r['record_type'] === 'Return' ? fmt_qty($r['return_qty']) : '—') ?></td>
                            <td class="col-actions"><a class="btn btn-light btn-sm" href="<?= e(url('/returns/' . $r['id'])) ?>">Detail</a></td>
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

<?php if (($po['remark'] && !$isOef && !$po['product_spec']) || $po['source_file']): ?>
    <section class="surface surface-pad small text-secondary">
        <?php if ($po['remark'] && !$isOef && !$po['product_spec']): ?><div class="mb-1"><strong class="text-body">Catatan:</strong> <?= nl2br(e($po['remark'])) ?></div><?php endif; ?>
        <?php if ($po['source_file']): ?><div>Sumber data: <?= e($po['source_file']) ?> › <?= e($po['source_sheet']) ?> baris <?= (int) $po['legacy_row'] ?></div><?php endif; ?>
    </section>
<?php endif; ?>
