<?php

use App\Helpers\Form;

/** @var array<string,mixed> $line @var array<int,string> $products @var array<string,string> $errors @var bool $locked */
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Purchase Orders</a><i class="bi bi-chevron-right"></i>
    <a href="<?= e(url('/purchase-orders/' . $line['po_id'])) ?>"><?= e($line['po_number'] ?? $line['po_code']) ?></a><i class="bi bi-chevron-right"></i><span>Edit baris</span></div>
<div class="page-header"><div><h1 class="page-title">Edit Baris PO</h1>
    <p class="page-subtitle"><?= e($line['customer_name'] ?? '') ?> · <span class="code-chip"><?= e($line['code']) ?></span></p></div></div>

<div class="row g-4">
    <div class="col-lg-8">
        <form class="surface" method="post" action="<?= e(url('/po-lines/' . $line['id'])) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::select('product_id', 'Produk', $products, old('product_id', $line), $errors, ['required' => true, 'searchable' => 'Cari produk…', 'disabled' => false, 'help' => $locked ? 'Produk dikunci karena baris ini sudah memiliki delivery/retur.' : null]) ?>
                    <?= Form::input('order_qty', 'Qty order', old('order_qty', $line), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('unit', 'Satuan', old('unit', $line), $errors, ['maxlength' => 20, 'col' => 'col-md-3', 'placeholder' => 'pcs']) ?>
                    <?= Form::input('unit_price', 'Harga satuan', ($v = old('unit_price', $line)) !== '' && preg_match('/^\d+\.\d{2}$/', $v) ? App\Helpers\Number::money($v, '', false) : $v, $errors, ['inputmode' => 'decimal', 'col' => 'col-md-5', 'prefix' => 'Rp',
                        'help' => $line['price_includes_tax'] ? 'Harga PO ini sudah termasuk PPN; subtotal (DPP) dihitung otomatis.' : 'Subtotal = qty × harga satuan.']) ?>
                    <?= Form::input('remark', 'Catatan', old('remark', $line), $errors, ['maxlength' => 500]) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if (can('purchase_orders.edit') && !$locked): ?>
                    <button type="submit" form="delete-line" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus baris</button>
                <?php endif; ?>
                <a href="<?= e(url('/purchase-orders/' . $line['po_id'])) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
        <?php if (!$locked): ?>
            <form id="delete-line" method="post" action="<?= e(url('/po-lines/' . $line['id'] . '/delete')) ?>" data-confirm="Hapus baris produk ini dari PO?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="surface surface-pad">
            <dl class="dl-grid dl-single">
                <div><dt>Terkirim</dt><dd><?= e(fmt_qty($line['delivered_qty'], '0')) ?></dd></div>
                <div><dt>Retur</dt><dd><?= e(fmt_qty($line['return_qty'], '0')) ?></dd></div>
                <div><dt>Outstanding saat ini</dt><dd class="fw-semibold"><?= e(fmt_qty($line['outstanding_qty'], '0')) ?></dd></div>
                <div><dt>Subtotal (DPP)</dt><dd><?= e(fmt_money($line['line_subtotal'])) ?></dd></div>
                <?php if ($line['item_code'] || $line['item_description']): ?><div><dt>Item di dokumen PO</dt><dd class="small"><?= e(trim(($line['item_code'] ?? '') . ' ' . ($line['item_description'] ?? ''))) ?></dd></div><?php endif; ?>
                <?php if ($line['product_name_legacy']): ?><div><dt>Nama produk di spreadsheet</dt><dd class="small"><?= e($line['product_name_legacy']) ?><?= $line['variant_legacy'] ? ' — ' . e($line['variant_legacy']) : '' ?></dd></div><?php endif; ?>
            </dl>
        </div>
    </div>
</div>
