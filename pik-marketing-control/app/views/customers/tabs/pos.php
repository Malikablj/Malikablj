<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
use App\Models\PurchaseOrder;

$list = $data['pos'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Order Entry Form</h2><p class="surface-subtitle">Outstanding = Order − Terkirim + Retur</p></div>
        <?php if (can('purchase_orders.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/purchase-orders/create', ['customer_id' => $customer['id']])) ?>"><i class="bi bi-plus-lg"></i> Buat OEF</a>
        <?php endif; ?>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-title">Belum ada OEF</div><p>Order Entry Form untuk customer ini akan muncul di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>No. order</th><th class="d-none d-xl-table-cell">Tanggal</th><th class="d-none d-md-table-cell">Review PPIC</th><th class="d-none d-sm-table-cell">Status</th>
                    <th class="num d-none d-lg-table-cell">Order</th><th class="num d-none d-xl-table-cell">Terkirim</th><th class="num">Outstanding</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $po): ?>
                    <tr>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e(PurchaseOrder::label($po)) ?></a>
                            <div class="cell-sub"><?= $po['order_number'] && $po['po_number'] ? 'PO ' . e($po['po_number']) . ' · ' : '' ?><span class="d-xl-none"><?= e(fmt_date($po['po_date'], 'tanpa tanggal')) ?> · </span><?= (int) $po['line_count'] ?> produk</div>
                            <div class="cell-sub d-lg-none">Order <?= e(fmt_qty($po['total_qty'])) ?></div>
                            <div class="d-md-none mt-1"><?= PurchaseOrder::reviewBadge($po['review_status']) ?> <span class="d-sm-none"><?= status_badge($po['status']) ?></span></div></td>
                        <td class="d-none d-xl-table-cell nowrap text-secondary"><?= e(fmt_date($po['po_date'])) ?></td>
                        <td class="d-none d-md-table-cell"><?= PurchaseOrder::reviewBadge($po['review_status']) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($po['status']) ?></td>
                        <td class="num d-none d-lg-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                        <td class="num d-none d-xl-table-cell"><?= e(fmt_qty($po['delivered_qty'])) ?></td>
                        <td class="num fw-semibold<?= (int) $po['outstanding_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($po['outstanding_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('OEF') ?>
    <?php endif; ?>
</section>
