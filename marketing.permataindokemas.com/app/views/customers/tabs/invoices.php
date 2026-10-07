<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['invoices'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Invoice &amp; pembayaran</h2></div>
        <?php if (can('finance.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/invoices/create', ['customer_id' => $customer['id']])) ?>"><i class="bi bi-plus-lg"></i> Buat invoice</a>
        <?php endif; ?>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-cash-coin"></i><div class="empty-title">Belum ada invoice</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Invoice</th><th class="d-none d-md-table-cell">Jatuh tempo</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-sm-table-cell">Tagihan</th><th class="num d-none d-md-table-cell">Dibayar</th><th class="num">Sisa</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $i): ?>
                    <tr>
                        <td><a class="cell-title" href="<?= e(url('/invoices/' . $i['id'])) ?>"><?= e($i['invoice_number'] ?? $i['code']) ?></a>
                            <div class="cell-sub"><?= e(fmt_date($i['invoice_date'])) ?><?= $i['po_number'] ? ' · PO ' . e($i['po_number']) : '' ?></div>
                            <div class="cell-sub d-sm-none"><?= status_badge($i['status']) ?></div></td>
                        <td class="d-none d-md-table-cell nowrap"><?= e(fmt_date($i['due_date'])) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($i['status']) ?></td>
                        <td class="num d-none d-sm-table-cell"><?= e(fmt_money($i['invoice_amount'])) ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_money($i['paid_amount'])) ?></td>
                        <td class="num fw-semibold"><?= e(fmt_money($i['outstanding_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('invoice') ?>
    <?php endif; ?>
</section>
