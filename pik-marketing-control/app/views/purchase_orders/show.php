<?php

use App\Models\Delivery;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;

/**
 * Detail Order Entry Form (OEF).
 * @var array<string,mixed> $po
 * @var list<array<string,mixed>> $lines
 * @var list<array<string,mixed>> $deliveries
 * @var list<array<string,mixed>> $returns
 * @var list<array<string,mixed>> $leadtimes
 * @var list<array<string,mixed>> $issues
 * @var list<string> $suggestions
 * @var bool $canReview
 */
$id = (int) $po['id'];
$base = '/purchase-orders/' . $id;
$label = PurchaseOrder::label($po);
$progress = pct((int) $po['delivered_qty'], (int) $po['total_qty']);
$hasLegacy = false;
foreach ($lines as $l) {
    if ($l['legacy_outstanding_qty'] !== null || $l['legacy_delivered_qty'] !== null) {
        $hasLegacy = true;
    }
}
$unlinked = array_filter($deliveries, static fn ($d) => $d['po_line_id'] === null);
$canProduct = can('products.view');
$canEdit = can('purchase_orders.edit');
$review = (string) $po['review_status'];
$isOpen = in_array($po['status'], PurchaseOrder::OPEN_STATUSES, true);
$hasValues = can('po_values.view') && ($po['grand_total'] !== null || $po['subtotal'] !== null || array_filter($lines, static fn ($l) => $l['unit_price'] !== null) !== []);
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Order Entry Form</a><i class="bi bi-chevron-right"></i><span><?= e($label) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-receipt"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($label) ?> <?= status_badge($po['status']) ?> <?= PurchaseOrder::reviewBadge($review) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($po['code']) ?></span>
                <?php if ($po['customer_id']): ?><span><i class="bi bi-buildings"></i><?= can('customers.view') ? '<a href="' . e(url('/customers/' . $po['customer_id'])) . '">' . e($po['customer_name']) . '</a>' : e($po['customer_name']) ?></span>
                <?php else: ?><span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span><?php endif; ?>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($po['po_date'], 'Tanpa tanggal')) ?></span>
                <?php if ($po['sales_name']): ?><span title="Nama sales"><i class="bi bi-person-badge"></i><?= e($po['sales_name']) ?></span><?php endif; ?>
                <?php if ($po['order_number'] && $po['po_number']): ?><span title="No PO dari customer"><i class="bi bi-file-earmark-text"></i>PO <?= e($po['po_number']) ?></span><?php endif; ?>
                <?php if ($po['legacy_status'] && $po['legacy_status'] !== $po['status']): ?><span title="Status di spreadsheet asli"><i class="bi bi-clock-history"></i>Legacy: <?= e($po['legacy_status']) ?></span><?php endif; ?>
                <?php if ($po['import_status'] === 'NEEDS_REVIEW'): ?><span class="badge-soft badge-soft-warning no-dot" title="Data dari import database PO masih punya catatan yang perlu ditinjau"><i class="bi bi-exclamation-triangle me-1"></i>Data import perlu dicek</span><?php endif; ?>
                <?php if ($po['doc_url']): ?><span><i class="bi bi-file-earmark-pdf"></i><?= external_link($po['doc_url'], 'Dokumen PO') ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('deliveries.create') && $lines && $review === 'Approved' && $isOpen): ?><a class="btn btn-light" href="<?= e(url('/deliveries/create', ['po_id' => $id])) ?>"><i class="bi bi-truck"></i> Catat delivery</a><?php endif; ?>
        <?php if (can('returns.create') && $lines): ?><a class="btn btn-light" href="<?= e(url('/returns/create', ['po_id' => $id])) ?>"><i class="bi bi-chat-left-dots"></i> Retur / komplain</a><?php endif; ?>
        <?php if ($canEdit): ?><a class="btn btn-primary" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit OEF</a><?php endif; ?>
        <?php if (can('purchase_orders.delete')): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-icon" type="button" data-bs-toggle="dropdown" aria-label="Aksi lain"><i class="bi bi-three-dots"></i></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="Hapus OEF ini? OEF yang sudah memiliki delivery/retur tidak dapat dihapus (pakai status Cancelled).">
                        <?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus OEF</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<section class="decision-panel section-gap <?= $review === 'Approved' ? 'is-approved' : ($review === 'Rejected' ? 'is-rejected' : 'is-pending') ?>" aria-label="Review PPIC">
    <?php if ($review === 'Pending'): ?>
        <div class="decision-title"><i class="bi bi-hourglass-split me-1"></i>Menunggu review PPIC</div>
        <div class="small text-secondary">PPIC memeriksa OEF ini lalu menandai <strong>bisa diproses</strong> atau <strong>tidak bisa diproses</strong>.
            <?= $po['requested_delivery_date'] ? 'Bila bisa diproses, jadwal delivery tanggal ' . e(fmt_date($po['requested_delivery_date'])) . ' dibuat otomatis.' : '' ?></div>
        <?php if ($canReview): ?>
            <form method="post" action="<?= e(url($base . '/approve')) ?>" class="mt-3">
                <?= csrf_field() ?>
                <label class="form-label small" for="review_note">Catatan PPIC <span class="text-secondary fw-normal">(wajib diisi bila tidak bisa diproses)</span></label>
                <textarea class="form-control" id="review_note" name="review_note" rows="2" maxlength="1000" placeholder="mis. kapasitas mesin penuh sampai tanggal …"></textarea>
                <div class="decision-actions">
                    <button type="submit" class="btn btn-success" data-confirm="Tandai OEF <?= e($label) ?> BISA DIPROSES? Jadwal delivery dibuat otomatis."><i class="bi bi-check2-circle me-1"></i>Bisa diproses</button>
                    <button type="submit" class="btn btn-danger" formaction="<?= e(url($base . '/reject')) ?>" data-confirm="Tandai OEF <?= e($label) ?> TIDAK BISA DIPROSES? Pastikan alasan sudah ditulis."><i class="bi bi-x-circle me-1"></i>Tidak bisa diproses</button>
                </div>
            </form>
        <?php elseif (can('purchase_orders.view') && !can('oef_review.approve')): ?>
            <div class="small text-secondary mt-2"><i class="bi bi-lock me-1"></i>Hanya role PPIC yang dapat mengonfirmasi OEF.</div>
        <?php endif; ?>
    <?php elseif ($review === 'Approved'): ?>
        <div class="decision-title text-success-ink"><i class="bi bi-check2-circle me-1"></i>Bisa diproses</div>
        <div class="small text-secondary"><?= $po['reviewed_at'] ? 'Dikonfirmasi ' . e($po['reviewed_by_name'] ?? 'PPIC') . ' · ' . e(fmt_datetime($po['reviewed_at'])) : 'Data sebelum OEF (otomatis dianggap bisa diproses).' ?></div>
        <?php if ($po['review_note']): ?><div class="small mt-1"><strong>Catatan PPIC:</strong> <?= nl2br(e($po['review_note'])) ?></div><?php endif; ?>
    <?php else: ?>
        <div class="decision-title text-danger-ink"><i class="bi bi-x-circle me-1"></i>Tidak bisa diproses</div>
        <div class="small text-secondary">Dikonfirmasi <?= e($po['reviewed_by_name'] ?? 'PPIC') ?> · <?= e(fmt_datetime($po['reviewed_at'])) ?></div>
        <div class="small mt-1"><strong>Alasan:</strong> <?= nl2br(e($po['review_note'] ?? '—')) ?></div>
        <?php if ($canEdit): ?><div class="small mt-2">Perbaiki OEF lalu simpan, OEF otomatis diajukan ulang ke PPIC. <a href="<?= e(url($base . '/edit')) ?>">Edit OEF</a></div><?php endif; ?>
    <?php endif; ?>
</section>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Total order</div><div class="stat-value"><?= e(fmt_qty($po['total_qty'], '0')) ?></div><div class="x-small text-secondary"><?= (int) $po['line_count'] ?> produk</div></div>
    <div><div class="stat-label">Terkirim</div><div class="stat-value"><?= e(fmt_qty($po['delivered_qty'], '0')) ?></div>
        <div class="progress-thin mt-2<?= $progress >= 100 ? ' is-done' : '' ?>"><span style="width: <?= $progress ?>%"></span></div></div>
    <div><div class="stat-label">Retur</div><div class="stat-value"><?= e(fmt_qty($po['return_qty'], '0')) ?></div></div>
    <div><div class="stat-label">Outstanding</div><div class="stat-value<?= (int) $po['outstanding_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($po['outstanding_qty'], '0')) ?></div>
        <div class="x-small text-secondary">Order − Terkirim + Retur</div></div>
</div>

<?php if ($unlinked || $issues): ?>
    <div class="callout callout-warning section-gap small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?php if ($unlinked): ?><strong><?= count($unlinked) ?> delivery legacy</strong> belum terhubung ke produk OEF, sehingga belum mengurangi outstanding. <?php endif; ?>
        <?php if ($issues): ?><strong><?= count($issues) ?> migration issue</strong> terbuka untuk data ini. <?php endif; ?>
        <?php if (can('migration.view')): ?><a href="<?= e(url('/migration-issues', ['q' => $po['po_number'] ?? $po['code']])) ?>">Tinjau di Migration Issues</a><?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8 min-w-0">
        <section class="surface section-gap">
            <div class="surface-header"><div><h2 class="surface-title">Produk</h2><p class="surface-subtitle">Terkirim hanya menghitung delivery berstatus Delivered/Partial.</p></div></div>
            <div class="table-wrap">
                <table class="table-pik">
                    <thead><tr><th>Produk &amp; spesifikasi</th><th class="num d-none d-md-table-cell">Qty</th>
                        <th class="num d-none d-md-table-cell">Terkirim</th><th class="num d-none d-lg-table-cell">Retur</th><th class="num">Outstanding</th>
                        <?php if ($hasLegacy): ?><th class="num d-none d-xxl-table-cell" title="Nilai di spreadsheet asli">Outstanding legacy</th><?php endif; ?>
                        <th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $l): $out = (int) $l['outstanding_qty']; ?>
                        <tr>
                            <td class="min-w-0"><?php if ($canProduct): ?><a class="cell-title" href="<?= e(url('/products/' . $l['product_id'])) ?>"><?= e($l['product_name']) ?></a><?php else: ?><span class="cell-title"><?= e($l['product_name']) ?></span><?php endif; ?>
                                <?php if ($l['variant'] || $l['product_code']): ?><div class="cell-sub"><?= e(trim(excerpt($l['variant'] ?? '', 90) . ' ' . ($l['product_code'] ?? ''))) ?></div><?php endif; ?>
                                <?php if ($l['item_description']): ?><div class="cell-sub text-body"><?= e(excerpt($l['item_description'], 160)) ?></div><?php endif; ?>
                                <?php if ($l['subcont_supplier']): ?><div class="cell-sub"><span class="badge-soft badge-soft-info no-dot">Subcont</span> <?= e($l['subcont_supplier']) ?></div><?php endif; ?>
                                <div class="cell-sub d-md-none">Qty <?= e(fmt_qty($l['order_qty'])) ?> <?= e($l['unit'] ?? '') ?> · Terkirim <?= e(fmt_qty($l['delivered_qty'])) ?><?= (int) $l['return_qty'] > 0 ? ' · Retur ' . e(fmt_qty($l['return_qty'])) : '' ?></div></td>
                            <td class="num d-none d-md-table-cell"><?= e(fmt_qty($l['order_qty'])) ?><?= $l['unit'] ? '<div class="x-small text-secondary">' . e($l['unit']) . '</div>' : '' ?></td>
                            <td class="num d-none d-md-table-cell"><?= e(fmt_qty($l['delivered_qty'])) ?></td>
                            <td class="num d-none d-lg-table-cell"><?= e(fmt_qty($l['return_qty'])) ?></td>
                            <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out)) ?><?= $out < 0 ? '<div class="x-small">over delivery</div>' : '' ?></td>
                            <?php if ($hasLegacy): ?>
                                <td class="num d-none d-xxl-table-cell text-secondary"><?= e(fmt_qty($l['legacy_outstanding_qty'])) ?></td>
                            <?php endif; ?>
                            <td class="col-actions"><?php if ($canEdit): ?><a class="btn btn-light btn-sm" href="<?= e(url('/po-lines/' . $l['id'] . '/edit')) ?>" title="Edit produk"><i class="bi bi-pencil"></i></a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canEdit): ?>
                <form class="surface-footer" method="post" action="<?= e(url($base . '/lines')) ?>">
                    <?= csrf_field() ?>
                    <div class="small fw-semibold mb-2">Tambah produk</div>
                    <div class="row g-2 align-items-start">
                        <div class="col-md-6"><input type="text" class="form-control" name="product_name" maxlength="190" list="product-suggestions" autocomplete="off" placeholder="Nama produk" required aria-label="Nama produk"></div>
                        <div class="col-md-6"><input type="text" class="form-control" name="item_description" maxlength="2000" placeholder="Spesifikasi produk" aria-label="Spesifikasi produk"></div>
                        <div class="col-4 col-md-2"><input type="number" class="form-control" name="order_qty" min="1" step="1" placeholder="Qty" required aria-label="Qty"></div>
                        <div class="col-3 col-md-2"><input type="text" class="form-control" name="unit" maxlength="20" placeholder="pcs" aria-label="Satuan"></div>
                        <div class="col-5 col-md-6"><input type="text" class="form-control" name="subcont_supplier" maxlength="150" placeholder="Supplier (jika subcont)" aria-label="Supplier subcont"></div>
                        <div class="col-md-2 d-grid"><button class="btn btn-light" type="submit"><i class="bi bi-plus-lg"></i> Tambah</button></div>
                    </div>
                    <datalist id="product-suggestions"><?php foreach ($suggestions as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
                </form>
            <?php endif; ?>
        </section>

        <section class="surface section-gap">
            <div class="surface-header"><div><h2 class="surface-title">Jadwal &amp; pengiriman <span class="tab-count"><?= count($deliveries) ?></span></h2>
                <p class="surface-subtitle">Jadwal dibuat otomatis saat PPIC menyetujui OEF; perubahan jadwal & Surat Jalan dilakukan PPIC di menu Delivery.</p></div></div>
            <?php if (!$deliveries): ?>
                <div class="empty-inline"><?= $review === 'Approved' ? 'Belum ada jadwal delivery.' : 'Jadwal delivery dibuat setelah OEF disetujui PPIC.' ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-pik table-compact">
                        <thead><tr><th>Tanggal</th><th>Surat Jalan</th><th class="d-none d-md-table-cell">Produk</th><th class="d-none d-sm-table-cell">Status</th><th class="num">Qty</th></tr></thead>
                        <tbody>
                        <?php foreach ($deliveries as $d): ?>
                            <tr>
                                <td class="nowrap"><?= can('deliveries.view') ? '<a href="' . e(url('/deliveries/' . $d['id'])) . '">' . e(fmt_date($d['delivery_date'], 'Belum dijadwalkan')) . '</a>' : e(fmt_date($d['delivery_date'])) ?>
                                    <?php if ($d['schedule_source'] === Delivery::SOURCE_OEF): ?><div class="cell-sub">otomatis dari OEF</div><?php endif; ?></td>
                                <td><?= $d['sj_number'] ? e($d['sj_number']) : '<span class="text-subtle">Belum ada SJ</span>' ?>
                                    <div class="cell-sub d-sm-none"><?= status_badge($d['status']) ?></div>
                                    <?php if ($d['product_name'] !== null): ?><div class="cell-sub d-md-none"><?= e($d['product_name']) ?></div><?php endif; ?>
                                    <?php if ($d['po_line_id'] === null): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke produk</span></div><?php endif; ?></td>
                                <td class="d-none d-md-table-cell small"><?= e($d['product_name'] ?? '—') ?></td>
                                <td class="d-none d-sm-table-cell"><?= status_badge($d['status']) ?></td>
                                <td class="num fw-semibold"><?= e(fmt_qty($d['delivered_qty'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if (can('returns.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Retur &amp; komplain <span class="tab-count"><?= count($returns) ?></span></h2></div>
                <?php if (!$returns): ?>
                    <div class="empty-inline">Belum ada retur atau komplain.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table-pik table-compact">
                            <thead><tr><th>Kasus</th><th class="d-none d-sm-table-cell">Alasan</th><th class="num">Qty</th><th>Hasil</th></tr></thead>
                            <tbody>
                            <?php foreach ($returns as $r): ?>
                                <tr>
                                    <td><a class="cell-title" href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($r['case_type']) ?> · <?= e(fmt_date($r['return_date'], 'tanpa tanggal')) ?></a>
                                        <div class="cell-sub"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></div>
                                        <div class="cell-sub d-sm-none"><?= $r['reason'] ? e(ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '' ?></div></td>
                                    <td class="d-none d-sm-table-cell"><?= e($r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—') ?></td>
                                    <td class="num fw-semibold"><?= e(fmt_qty($r['return_qty'] ?? $r['affected_qty'])) ?></td>
                                    <td><?= status_badge($r['resolution_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4 min-w-0">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Pengiriman</h2></div>
            <dl class="dl-single small surface-pad mb-0">
                <dt>Permintaan selesai/kirim</dt><dd><?= e(fmt_date($po['requested_delivery_date'], '—')) ?></dd>
                <dt>Tujuan kirim</dt><dd class="text-break"><?= $po['delivery_address'] ? nl2br(e($po['delivery_address'])) : '—' ?></dd>
                <dt>No PO dari customer</dt><dd><?= e($po['po_number'] ?? '—') ?></dd>
                <dt>Nama sales</dt><dd><?= e($po['sales_name'] ?? '—') ?></dd>
                <dt>Dibuat</dt><dd><?= e($po['created_by_name'] ?? '—') ?> · <?= e(fmt_datetime($po['created_at'])) ?></dd>
            </dl>
            <?php if ($po['remark']): ?><div class="surface-pad border-top small"><strong>Keterangan:</strong> <?= nl2br(e($po['remark'])) ?></div><?php endif; ?>
        </section>

        <?php if (can('leadtime.view') && ($leadtimes || (can('leadtime.create') && $lines && $isOpen))): ?>
            <section class="surface section-gap">
                <div class="surface-header"><div><h2 class="surface-title">Estimasi lead time <span class="tab-count"><?= count($leadtimes) ?></span></h2></div>
                    <?php if (can('leadtime.create') && $lines && $isOpen): ?>
                        <a class="btn btn-light btn-sm" href="<?= e(url('/lead-times/create', ['po_id' => $id, 'return' => $base])) ?>"><i class="bi bi-plus-lg"></i> Estimasi</a>
                    <?php endif; ?></div>
                <?php if (!$leadtimes): ?>
                    <div class="empty-inline">Belum ada estimasi tanggal delivery.</div>
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

        <?php if ($hasValues): ?>
            <section class="surface section-gap">
                <div class="surface-header"><div><h2 class="surface-title">Nilai PO (arsip import)</h2>
                    <p class="surface-subtitle">Dari dokumen PO customer. Hanya terlihat oleh role dengan akses data keuangan.</p></div></div>
                <dl class="dl-single small surface-pad mb-0">
                    <dt>Subtotal (DPP)</dt><dd><?= e(fmt_money($po['subtotal'])) ?></dd>
                    <dt>PPN</dt><dd><?= e(fmt_money($po['tax_amount'], '—')) ?></dd>
                    <dt>Grand total</dt><dd class="fw-semibold"><?= e(fmt_money($po['grand_total'], 'belum ada')) ?> <?= e($po['currency'] ?? '') ?></dd>
                    <?php if ($po['payment_term']): ?><dt>Termin</dt><dd><?= e($po['payment_term']) ?></dd><?php endif; ?>
                </dl>
            </section>
        <?php endif; ?>

        <?php if ($po['source_file']): ?>
            <section class="surface surface-pad small text-secondary section-gap">Sumber data: <?= e($po['source_file']) ?> › <?= e($po['source_sheet']) ?> baris <?= (int) $po['legacy_row'] ?></section>
        <?php endif; ?>
    </div>
</div>
