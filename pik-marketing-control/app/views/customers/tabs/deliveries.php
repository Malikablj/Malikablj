<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['deliveries'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Deliveries</h2><p class="surface-subtitle">Pengiriman (Surat Jalan) untuk PO customer ini</p></div>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-truck"></i><div class="empty-title">Belum ada delivery</div><p>Pengiriman dibuat dari halaman PO.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Surat Jalan</th><th class="d-none d-md-table-cell">PO</th><th class="d-none d-lg-table-cell">Produk</th><th class="d-none d-sm-table-cell">Status</th><th class="num">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $d): ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($d['delivery_date'])) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?? $d['code']) ?></a>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($d['delivery_date'])) ?> · <?= status_badge($d['status']) ?></div>
                            <div class="cell-sub d-md-none">PO <?= e($d['po_number'] ?? $d['po_code']) ?></div>
                            <?php if (!empty($d['product_name'])): ?><div class="cell-sub d-lg-none"><?= e($d['product_name']) ?></div><?php endif; ?>
                            <?php if (empty($d['po_line_id'])): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke PO line</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell"><a href="<?= e(url('/purchase-orders/' . $d['po_id'])) ?>"><?= e($d['po_number'] ?? $d['po_code']) ?></a></td>
                        <td class="d-none d-lg-table-cell small"><?= e($d['product_name'] ?? '—') ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($d['status']) ?></td>
                        <td class="num fw-semibold<?= (int) $d['delivered_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($d['delivered_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('delivery') ?>
    <?php endif; ?>
</section>
