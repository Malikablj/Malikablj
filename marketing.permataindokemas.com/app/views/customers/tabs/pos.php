<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['pos'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Order Entry Form</h2><p class="surface-subtitle">Outstanding = Order − Delivered + Return</p></div>
        <?php if (can('purchase_orders.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/purchase-orders/create', ['customer_id' => $customer['id']])) ?>"><i class="bi bi-plus-lg"></i> Buat OEF</a>
        <?php endif; ?>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-title">Belum ada order</div><p>Order Entry Form customer ini akan muncul di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>No. order</th><th class="d-none d-md-table-cell">Tanggal</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-lg-table-cell">Item</th><th class="num d-none d-sm-table-cell">Order</th><th class="num d-none d-md-table-cell">Terkirim</th><th class="num d-none d-lg-table-cell">Retur</th><th class="num">Outstanding</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $po): ?>
                    <tr>
                        <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e_wrap(App\Models\PurchaseOrder::displayNumber($po)) ?></a>
                            <div class="cell-sub"><?= $po['order_number'] && $po['po_number'] ? 'PO ' . e($po['po_number']) . ' · ' : '' ?><?= $po['ppic_status'] ? ppic_badge($po['ppic_status']) : '<span class="code-chip">' . e($po['code']) . '</span>' ?></div>
                            <div class="cell-sub d-sm-none">Order <?= e(fmt_qty($po['total_qty'])) ?> · <?= status_badge($po['status']) ?></div></td>
                        <td class="d-none d-md-table-cell nowrap text-secondary"><?= e(fmt_date($po['po_date'])) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($po['status']) ?></td>
                        <td class="num d-none d-lg-table-cell"><?= (int) $po['line_count'] ?></td>
                        <td class="num d-none d-sm-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($po['delivered_qty'])) ?></td>
                        <td class="num d-none d-lg-table-cell"><?= e(fmt_qty($po['return_qty'])) ?></td>
                        <td class="num fw-semibold<?= (int) $po['outstanding_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($po['outstanding_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('order') ?>
    <?php endif; ?>
</section>
