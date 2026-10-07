<?php
/**
 * @var list<array<string,mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 */
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/migration-issues')) ?>">Migration Issues</a><i class="bi bi-chevron-right"></i><span>Delivery legacy</span></div>
<div class="page-header">
    <div>
        <h1 class="page-title">Tinjau Delivery Legacy</h1>
        <p class="page-subtitle">Delivery dari spreadsheet yang produknya tidak cocok persis dengan master, tetapi PO-nya hanya memiliki <strong>satu</strong> baris produk.
            Bandingkan nama produk lalu centang yang memang sama. Tidak ada yang dihubungkan tanpa dicentang.</p>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="surface"><div class="empty-state"><i class="bi bi-check2-circle"></i><div class="empty-title">Tidak ada delivery untuk ditinjau</div>
        <p>Delivery legacy lain (PO dengan beberapa baris atau PO tidak diketahui) dihubungkan satu per satu dari halaman delivery.</p></div></div>
<?php else: ?>
    <form class="surface" method="post" action="<?= e(url('/migration-issues/deliveries')) ?>" data-confirm="Hubungkan delivery yang dicentang ke baris PO-nya?">
        <?= csrf_field() ?>
        <div class="surface-header"><div><h2 class="surface-title"><?= e(fmt_qty($total)) ?> delivery menunggu tinjauan</h2>
            <p class="surface-subtitle">Halaman <?= (int) $page ?> dari <?= (int) $pages ?></p></div></div>
        <div class="table-wrap">
            <table class="table-pik table-compact">
                <thead><tr><th class="col-actions"><input class="form-check-input" type="checkbox" data-check-all="delivery_ids[]" aria-label="Pilih semua di halaman ini"></th>
                    <th>Surat jalan · PO</th><th class="d-none d-md-table-cell">Produk di spreadsheet</th><th class="d-none d-md-table-cell">Produk baris PO</th><th class="num d-none d-sm-table-cell">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="col-actions"><input class="form-check-input" type="checkbox" name="delivery_ids[]" value="<?= (int) $r['id'] ?>" id="dl<?= (int) $r['id'] ?>" aria-label="Hubungkan <?= e($r['sj_number'] ?? $r['code']) ?>"></td>
                        <td class="min-w-0"><label for="dl<?= (int) $r['id'] ?>" class="cell-title"><?= e($r['sj_number'] ?? $r['code']) ?></label>
                            <div class="cell-sub"><?= e(fmt_date($r['delivery_date'])) ?> · <a href="<?= e(url('/purchase-orders/' . $r['po_id'])) ?>"><?= e($r['po_number'] ?? $r['po_code']) ?></a> · <?= e($r['customer_name'] ?? '') ?></div>
                            <div class="cell-sub d-sm-none">Qty <?= e(fmt_qty($r['delivered_qty'])) ?></div>
                            <div class="d-md-none small mt-2"><span class="text-secondary">Spreadsheet:</span> <?= e($r['legacy_product'] ?? '(tidak tercatat)') ?></div>
                            <div class="d-md-none small"><span class="text-secondary">Baris PO:</span> <strong><?= e($r['line_product']) ?></strong></div></td>
                        <td class="small d-none d-md-table-cell"><?= e($r['legacy_product'] ?? '(tidak tercatat)') ?></td>
                        <td class="small fw-semibold d-none d-md-table-cell"><?= e($r['line_product']) ?><div class="text-secondary fw-normal">order <?= e(fmt_qty($r['order_qty'])) ?></div></td>
                        <td class="num d-none d-sm-table-cell"><?= e(fmt_qty($r['delivered_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="surface-footer d-flex flex-wrap gap-2 align-items-center">
            <span class="small text-secondary me-auto">Delivery yang dicentang dihubungkan ke satu-satunya baris PO-nya; outstanding & status PO dihitung ulang.</span>
            <?php if ($page > 1): ?><a class="btn btn-light btn-sm" href="<?= e(url('/migration-issues/deliveries', ['page' => $page - 1])) ?>">Sebelumnya</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="btn btn-light btn-sm" href="<?= e(url('/migration-issues/deliveries', ['page' => $page + 1])) ?>">Berikutnya</a><?php endif; ?>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-link-45deg"></i> Hubungkan yang dicentang</button>
        </div>
    </form>
<?php endif; ?>
