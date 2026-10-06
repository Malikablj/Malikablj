<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/** @var array<string,mixed> $line @var list<string> $suggestions @var array<string,string> $errors @var bool $locked */
$oefLabel = PurchaseOrder::label(['order_number' => $line['order_number'] ?? null, 'po_number' => $line['po_number'], 'code' => $line['po_code']]);
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Order Entry Form</a><i class="bi bi-chevron-right"></i>
    <a href="<?= e(url('/purchase-orders/' . $line['po_id'])) ?>"><?= e($oefLabel) ?></a><i class="bi bi-chevron-right"></i><span>Edit produk</span></div>
<div class="page-header"><div><h1 class="page-title">Edit Produk OEF</h1>
    <p class="page-subtitle"><?= e($line['customer_name'] ?? '') ?> · <span class="code-chip"><?= e($line['code']) ?></span></p></div></div>

<div class="row g-4">
    <div class="col-lg-8">
        <form class="surface" method="post" action="<?= e(url('/po-lines/' . $line['id'])) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::input('product_name', 'Nama produk', old('product_name', ['product_name' => $line['product_name_typed']]), $errors, ['required' => true, 'maxlength' => 190, 'list' => 'product-suggestions', 'autocomplete' => 'off',
                        'readonly' => $locked, 'help' => $locked ? 'Produk dikunci karena baris ini sudah memiliki delivery/retur.' : 'Nama baru otomatis ditambahkan ke menu Produk.']) ?>
                    <?= Form::textarea('item_description', 'Spesifikasi produk', old('item_description', $line), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                    <?= Form::input('order_qty', 'Qty', old('order_qty', $line), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'required' => true, 'col' => 'col-6 col-md-4']) ?>
                    <?= Form::input('unit', 'Satuan', old('unit', $line), $errors, ['maxlength' => 20, 'col' => 'col-6 col-md-3', 'placeholder' => 'pcs']) ?>
                    <?= Form::input('subcont_supplier', 'Supplier (jika subcont)', old('subcont_supplier', $line), $errors, ['maxlength' => 150, 'col' => 'col-md-5']) ?>
                </div>
                <datalist id="product-suggestions"><?php foreach ($suggestions as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-actions">
                <?php if (!$locked): ?>
                    <button type="submit" form="delete-line" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus produk</button>
                <?php endif; ?>
                <a href="<?= e(url('/purchase-orders/' . $line['po_id'])) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
        <?php if (!$locked): ?>
            <form id="delete-line" method="post" action="<?= e(url('/po-lines/' . $line['id'] . '/delete')) ?>" data-confirm="Hapus produk ini dari OEF?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="surface surface-pad">
            <dl class="dl-grid dl-single">
                <div><dt>Terkirim</dt><dd><?= e(fmt_qty($line['delivered_qty'], '0')) ?></dd></div>
                <div><dt>Retur</dt><dd><?= e(fmt_qty($line['return_qty'], '0')) ?></dd></div>
                <div><dt>Outstanding saat ini</dt><dd class="fw-semibold"><?= e(fmt_qty($line['outstanding_qty'], '0')) ?></dd></div>
                <?php if ($line['item_code']): ?><div><dt>Kode item di dokumen PO</dt><dd class="small"><?= e($line['item_code']) ?></dd></div><?php endif; ?>
                <?php if ($line['product_name_legacy']): ?><div><dt>Nama produk di spreadsheet</dt><dd class="small"><?= e($line['product_name_legacy']) ?><?= $line['variant_legacy'] ? ' — ' . e($line['variant_legacy']) : '' ?></dd></div><?php endif; ?>
            </dl>
        </div>
    </div>
</div>
