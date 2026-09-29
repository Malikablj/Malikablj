<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['returns'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Returns</h2><p class="surface-subtitle">Barang yang dikembalikan customer (menambah outstanding)</p></div>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-arrow-return-left"></i><div class="empty-title">Belum ada retur</div><p>Retur dicatat dari halaman PO atau menu Returns.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>PO</th><th class="d-none d-md-table-cell">Produk</th><th class="d-none d-sm-table-cell">Alasan</th><th class="num">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $r): $reason = $r['reason'] ? (App\Models\ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : null; ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $r['po_id'])) ?>"><?= e($r['po_number'] ?? $r['po_code']) ?></a>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?><?= $reason ? ' · ' . e($reason) : '' ?></div>
                            <div class="cell-sub d-md-none"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '')) ?></div>
                            <?php if (empty($r['po_line_id'])): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke PO line</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell small"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></td>
                        <td class="d-none d-sm-table-cell"><?= e($reason ?? '—') ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($r['return_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('retur') ?>
    <?php endif; ?>
</section>
