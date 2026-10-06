<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['returns'];
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Retur &amp; Komplain</h2><p class="surface-subtitle">Retur barang menambah outstanding; komplain dicatat beserta bukti & hasilnya</p></div>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-arrow-return-left"></i><div class="empty-title">Belum ada retur atau komplain</div><p>Dicatat dari halaman OEF atau menu Retur &amp; Komplain.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Kasus · OEF</th><th class="d-none d-md-table-cell">Produk</th><th class="d-none d-sm-table-cell">Alasan</th><th class="num d-none d-sm-table-cell">Qty</th><th>Hasil</th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $r): $reason = $r['reason'] ? (App\Models\ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : null; ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($r['case_type']) ?> · <?= e($r['order_ref']) ?></a>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?><?= $reason ? ' · ' . e($reason) : '' ?></div>
                            <div class="cell-sub d-md-none"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '')) ?></div>
                            <?php if (empty($r['po_line_id'])): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke produk OEF</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell small"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></td>
                        <td class="d-none d-sm-table-cell"><?= e($reason ?? '—') ?></td>
                        <td class="num fw-semibold d-none d-sm-table-cell"><?= e(fmt_qty($r['return_qty'] ?? $r['affected_qty'])) ?></td>
                        <td><?= status_badge($r['resolution_status'], App\Models\ProductReturn::RESOLUTION_LABELS[$r['resolution_status']] ?? $r['resolution_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('retur') ?>
    <?php endif; ?>
</section>
