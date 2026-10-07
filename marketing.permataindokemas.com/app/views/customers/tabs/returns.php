<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */

use App\Models\ProductReturn;

$list = $data['returns'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Complaint &amp; Return</h2><p class="surface-subtitle">Keluhan customer, bukti, dan hasil penanganannya</p></div>
        <?php if (can('returns.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/returns/create', ['customer_id' => $customer['id']])) ?>"><i class="bi bi-plus-lg"></i> Catat complaint</a>
        <?php endif; ?>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-exclamation-octagon"></i><div class="empty-title">Belum ada complaint</div><p>Complaint dicatat dari menu Complaint &amp; Return atau halaman order.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Complaint</th><th class="d-none d-md-table-cell">Produk · Order</th><th class="d-none d-sm-table-cell">Status</th><th class="num">Qty retur</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $r): $reason = $r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : null; ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($reason ?? 'Complaint') ?></a>
                            <div class="cell-sub"><span class="code-chip"><?= e($r['code']) ?></span> · <?= e(ProductReturn::TYPE_SHORT[$r['record_type']] ?? '') ?></div>
                            <?php if (!empty($r['complaint_detail'])): ?><div class="cell-sub"><?= e(excerpt($r['complaint_detail'], 80)) ?></div><?php endif; ?>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?> · <?= complaint_badge($r['complaint_status']) ?></div></td>
                        <td class="d-none d-md-table-cell small"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?>
                            <?php if ($r['po_id']): ?><div><a href="<?= e(url('/purchase-orders/' . $r['po_id'])) ?>"><?= e($r['po_number'] ?? $r['po_code']) ?></a></div><?php endif; ?>
                            <?php if (empty($r['po_line_id']) && $r['record_type'] === 'Return'): ?><div><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke order</span></div><?php endif; ?></td>
                        <td class="d-none d-sm-table-cell"><?= complaint_badge($r['complaint_status']) ?></td>
                        <td class="num fw-semibold"><?= e($r['record_type'] === 'Return' ? fmt_qty($r['return_qty']) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('complaint') ?>
    <?php endif; ?>
</section>
