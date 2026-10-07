<?php

use App\Helpers\Number;
use App\Models\PoFinancial;

/**
 * @var array<string,mixed> $row
 * @var array<string,mixed>|null $po
 * @var list<array<string,mixed>> $invoices
 * @var float $ppnRate
 * @var list<array<string,mixed>> $issues
 * @var list<array<string,mixed>> $history
 */
$id = (int) $row['id'];
$computed = PoFinancial::computeAmounts($row['order_qty'] !== null ? (int) $row['order_qty'] : null, $row['unit_price'], $ppnRate);
$mismatch = $computed !== null && $row['total_order_amount'] !== null && Number::toCents($computed['total_order_amount']) !== Number::toCents($row['total_order_amount']);
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/po-financials')) ?>">PO Financials</a><i class="bi bi-chevron-right"></i><span><?= e($row['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-graph-up-arrow"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($row['po_number'] ?? $row['po_number_legacy'] ?? '(tanpa PO)') ?> <?= status_badge($row['payment_status']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($row['code']) ?></span>
                <?php if ($row['customer_name']): ?><span><i class="bi bi-buildings"></i><?= e($row['customer_name']) ?></span><?php endif; ?>
                <?php if ($row['brand']): ?><span><i class="bi bi-tag"></i><?= e($row['brand']) ?></span><?php endif; ?>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($row['po_date'], 'Tanpa tanggal')) ?></span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($row['po_id'] && can('purchase_orders.view')): ?><a class="btn btn-light" href="<?= e(url('/purchase-orders/' . $row['po_id'])) ?>"><i class="bi bi-receipt"></i> Buka PO</a><?php endif; ?>
        <?php if (can('finance.edit')): ?><a class="btn btn-light" href="<?= e(url('/po-financials/' . $id . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<p class="text-secondary section-gap"><?= e($row['product_legacy'] ?? '—') ?><?= $row['product_code_legacy'] ? ' · ' . e($row['product_code_legacy']) : '' ?></p>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Qty × Harga satuan</div><div class="stat-value"><?= e(fmt_qty($row['order_qty'])) ?></div><div class="x-small text-secondary">× Rp <?= e(Number::decimal($row['unit_price'])) ?></div></div>
    <div><div class="stat-label">Total sebelum PPN</div><div class="stat-value"><?= e(fmt_money($row['total_order_amount'])) ?></div></div>
    <div><div class="stat-label">PPN</div><div class="stat-value"><?= e(fmt_money($row['ppn'])) ?></div></div>
    <div><div class="stat-label">Total + PPN</div><div class="stat-value"><?= e(fmt_money($row['total_incl_ppn'])) ?></div></div>
</div>

<?php if ($mismatch): ?>
    <div class="callout callout-warning section-gap small"><i class="bi bi-exclamation-triangle me-1"></i>
        Total tersimpan (<?= e(fmt_money($row['total_order_amount'])) ?>) berbeda dengan Qty × Harga satuan (<?= e(fmt_money($computed['total_order_amount'])) ?>). Periksa harga satuan;
        menyimpan ulang form akan menghitung total dari Qty × Harga satuan.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7 min-w-0">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Pengiriman &amp; pembayaran</h2></div>
            <div class="surface-pad small">
                <dl class="dl-single mb-0">
                    <?php if ($po): ?>
                        <dt>Terkirim saat ini (dari PO)</dt><dd><?= e(fmt_qty($po['delivered_qty'], '0')) ?> dari <?= e(fmt_qty($po['total_qty'], '0')) ?> · outstanding <?= e(fmt_qty($po['outstanding_qty'], '0')) ?></dd>
                    <?php endif; ?>
                    <dt>Terkirim (snapshot spreadsheet)</dt><dd><?= e(fmt_qty($row['delivered_qty'])) ?> · belum terkirim <?= e(fmt_qty($row['undelivered_qty'])) ?></dd>
                    <dt>Outstanding amount (snapshot spreadsheet)</dt><dd><?= e(fmt_money($row['outstanding_amount'])) ?></dd>
                    <?php if ($row['legacy_status']): ?><dt>Status di spreadsheet</dt><dd><?= e($row['legacy_status']) ?></dd><?php endif; ?>
                </dl>
            </div>
            <?php if ($invoices): ?>
                <div class="table-wrap">
                    <table class="table-pik table-compact">
                        <thead><tr><th>Invoice PO ini</th><th class="d-none d-sm-table-cell">Status</th><th class="num">Tagihan</th><th class="num">Sisa</th></tr></thead>
                        <tbody>
                        <?php foreach ($invoices as $i): ?>
                            <tr><td><a class="cell-title" href="<?= e(url('/invoices/' . $i['id'])) ?>"><?= e($i['invoice_number'] ?? $i['code']) ?></a><div class="cell-sub"><?= e(fmt_date($i['invoice_date'])) ?></div>
                                    <div class="cell-sub d-sm-none"><?= status_badge($i['status']) ?></div></td>
                                <td class="d-none d-sm-table-cell"><?= status_badge($i['status']) ?></td>
                                <td class="num"><?= e(fmt_money($i['invoice_amount'])) ?></td><td class="num fw-semibold"><?= e(fmt_money(max(0, (float) $i['outstanding_amount']))) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($row['po_id']): ?>
                <div class="empty-inline">Belum ada invoice untuk PO ini.<?php if (can('finance.create')): ?> <a href="<?= e(url('/invoices/create', ['po_id' => $row['po_id']])) ?>">Buat invoice</a><?php endif; ?></div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5 min-w-0">
        <section class="surface surface-pad section-gap small">
            <dl class="dl-single mb-0">
                <dt>Lampiran</dt><dd><?= external_link($row['attachment'], 'Buka lampiran') ?></dd>
                <dt>Catatan</dt><dd><?= $row['notes'] ? nl2br(e($row['notes'])) : '—' ?></dd>
                <?php if ($row['source_file']): ?><dt>Sumber data</dt><dd class="text-secondary"><?= e($row['source_file']) ?> › <?= e($row['source_sheet']) ?> baris <?= (int) $row['legacy_row'] ?></dd><?php endif; ?>
            </dl>
        </section>
        <?php if ($issues): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Migration issue</h2></div>
                <ul class="list-lite">
                    <?php foreach ($issues as $i): ?>
                        <li><div class="li-main"><span class="li-title text-wrap"><?= e($i['issue_type']) ?></span><div class="li-sub text-wrap"><?= e(excerpt($i['description'] ?? '', 160)) ?></div></div>
                            <div class="li-end"><?= status_badge($i['resolution_status']) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php if ($history): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Riwayat perubahan</h2></div>
                <ul class="list-lite">
                    <?php foreach ($history as $h): $changes = json_decode((string) $h['changes'], true); ?>
                        <li><div class="li-main"><span class="li-title"><?= e(ucfirst((string) $h['action'])) ?> oleh <?= e($h['user_name'] ?? 'sistem') ?></span>
                            <div class="li-sub"><?= is_array($changes) ? e(implode(', ', array_slice(array_keys($changes), 0, 5))) : '' ?></div></div>
                            <div class="li-end text-secondary x-small"><?= e(fmt_datetime($h['created_at'])) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
