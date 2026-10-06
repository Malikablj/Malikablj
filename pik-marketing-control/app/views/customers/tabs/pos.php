<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['pos'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Purchase Orders</h2><p class="surface-subtitle">Outstanding = Order − Delivered + Return</p></div>
        <?php if (can('purchase_orders.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/purchase-orders/create', ['customer_id' => $customer['id']])) ?>"><i class="bi bi-plus-lg"></i> Buat PO</a>
        <?php endif; ?>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-title">Belum ada PO</div><p>PO dari customer ini akan muncul di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>No. PO</th><th class="d-none d-xl-table-cell">Tanggal</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-xxl-table-cell">Item</th><th class="num d-none d-sm-table-cell">Order</th><th class="num d-none d-xl-table-cell">Terkirim</th><th class="num d-none d-xxl-table-cell">Retur</th><th class="num">Outstanding</th><th class="num d-none d-md-table-cell">Nilai</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $po): ?>
                    <tr>
                        <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e($po['po_number'] ?? '(tanpa nomor)') ?></a>
                            <div class="cell-sub"><span class="code-chip"><?= e($po['code']) ?></span><span class="d-xl-none"> · <?= e(fmt_date($po['po_date'], 'tanpa tanggal')) ?></span><?= $po['payment_term'] ? ' · ' . e($po['payment_term']) : '' ?></div>
                            <div class="cell-sub d-sm-none">Order <?= e(fmt_qty($po['total_qty'])) ?> · <?= status_badge($po['status']) ?></div>
                            <?php if ($po['grand_total'] !== null): ?><div class="cell-sub d-md-none"><?= e(fmt_money($po['grand_total'])) ?></div><?php endif; ?></td>
                        <td class="d-none d-xl-table-cell nowrap text-secondary"><?= e(fmt_date($po['po_date'])) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($po['status']) ?></td>
                        <td class="num d-none d-xxl-table-cell"><?= (int) $po['line_count'] ?></td>
                        <td class="num d-none d-sm-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                        <td class="num d-none d-xl-table-cell"><?= e(fmt_qty($po['delivered_qty'])) ?></td>
                        <td class="num d-none d-xxl-table-cell"><?= e(fmt_qty($po['return_qty'])) ?></td>
                        <td class="num fw-semibold<?= (int) $po['outstanding_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($po['outstanding_qty'])) ?></td>
                        <td class="num d-none d-md-table-cell nowrap"><?= e(fmt_money($po['grand_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('PO') ?>
    <?php endif; ?>
</section>
