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
                <thead><tr><th>Tanggal</th><th>PO</th><th class="d-none d-md-table-cell">Produk</th><th>Alasan</th><th class="num">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $r): ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_date($r['return_date'])) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $r['po_id'])) ?>"><?= e($r['po_number'] ?? $r['po_code']) ?></a>
                            <?php if (empty($r['po_line_id'])): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke PO line</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell small"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></td>
                        <td><?= e($r['reason'] ?? '—') ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($r['return_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('retur') ?>
    <?php endif; ?>
</section>
