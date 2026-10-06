<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed>|null $row
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var list<string> $suppliers
 * @var list<string> $receivers
 * @var list<string> $items
 * @var list<string> $units
 */
$isEdit = $row !== null;
$record = $row ?? $preset;
$cancel = $isEdit ? url('/inbound-supplier/' . $row['id']) : url('/inbound-supplier');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/inbound-supplier')) ?>">Inbound Supplier</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($row['code']) : 'Catat' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Penerimaan Supplier' : 'Catat Penerimaan Supplier' ?></h1>
    <p class="page-subtitle">Diisi manual oleh Purchasing setiap barang dari supplier datang.</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/inbound-supplier/' . $row['id'] : '/inbound-supplier')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="form-section-title">Penerimaan</div>
                <div class="row g-3">
                    <?= Form::input('supplier', 'Supplier', old('supplier', $record), $errors, ['required' => true, 'maxlength' => 150, 'col' => 'col-md-8', 'list' => 'supplier-list', 'autocomplete' => 'off']) ?>
                    <?= Form::input('receive_date', 'Tanggal diterima', old('receive_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('purchase_number', 'No PO pembelian', old('purchase_number', $record), $errors, ['maxlength' => 80, 'col' => 'col-md-4']) ?>
                    <?= Form::input('sj_number', 'No surat jalan supplier', old('sj_number', $record), $errors, ['maxlength' => 80, 'col' => 'col-md-4']) ?>
                    <?= Form::input('receiver', 'Penerima', old('receiver', $record), $errors, ['maxlength' => 120, 'col' => 'col-md-4', 'list' => 'receiver-list', 'autocomplete' => 'off', 'placeholder' => 'mis. Gudang bahan baku']) ?>
                </div>
                <datalist id="supplier-list"><?php foreach ($suppliers as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
                <datalist id="receiver-list"><?php foreach ($receivers as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-section">
                <div class="form-section-title">Barang</div>
                <div class="row g-3">
                    <?= Form::input('item_name', 'Nama barang', old('item_name', $record), $errors, ['required' => true, 'maxlength' => 255, 'list' => 'item-list', 'autocomplete' => 'off']) ?>
                    <?= Form::textarea('specification', 'Spesifikasi', old('specification', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                    <?= Form::input('quantity', 'Qty datang', old('quantity', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'required' => true, 'col' => 'col-6 col-md-4', 'inputmode' => 'numeric']) ?>
                    <?= Form::input('unit', 'Satuan', old('unit', $record, 'pcs'), $errors, ['required' => true, 'maxlength' => 20, 'col' => 'col-6 col-md-4', 'list' => 'unit-list']) ?>
                    <?= Form::input('reject_qty', 'Qty reject', old('reject_qty', $record, '0'), $errors, ['type' => 'number', 'min' => 0, 'step' => 1, 'col' => 'col-md-4', 'inputmode' => 'numeric']) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                </div>
                <datalist id="item-list"><?php foreach ($items as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
                <datalist id="unit-list"><?php foreach ($units as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('inbound_supplier.delete')): ?>
                    <button type="submit" form="delete-inbound-supplier" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
        <?php if ($isEdit && can('inbound_supplier.delete')): ?>
            <form id="delete-inbound-supplier" method="post" action="<?= e(url('/inbound-supplier/' . $row['id'] . '/delete')) ?>" data-confirm="Hapus data penerimaan ini?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
</div>
