<?php

use App\Helpers\Form;

/**
 * Inbound Supplier — catat / edit (diinput manual oleh Gudang).
 *
 * @var array<string,mixed>|null $row
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var list<string> $suppliers
 * @var list<string> $items
 * @var list<string> $categories
 * @var list<string> $units
 * @var list<string> $receivers
 * @var list<string> $locations
 */
$isEdit = $row !== null;
$record = $row ?? $preset;
$cancel = $isEdit ? url('/inbound-supplier/' . $row['id']) : url('/inbound-supplier');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/inbound-supplier')) ?>">Inbound Supplier</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($row['item_name']) : 'Catat' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Inbound Supplier' : 'Catat Inbound Supplier' ?></h1>
    <p class="page-subtitle">Penerimaan barang dari supplier (bahan baku, kemasan, label, dll.). Semua isian diketik manual; total masuk dihitung otomatis: Qty diterima − Qty reject.</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/inbound-supplier/' . $row['id'] : '/inbound-supplier')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="form-section-title">Penerimaan</div>
                <div class="row g-3">
                    <?= Form::input('supplier', 'Supplier', old('supplier', $record), $errors, ['required' => true, 'maxlength' => 150, 'col' => 'col-md-6', 'list' => 'supplier-list', 'autocomplete' => 'off', 'autofocus' => !$isEdit]) ?>
                    <?= Form::input('inbound_date', 'Tanggal barang masuk', old('inbound_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-6']) ?>
                    <?= Form::input('sj_number', 'No. surat jalan supplier', old('sj_number', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4']) ?>
                    <?= Form::input('sj_date', 'Tanggal surat jalan', old('sj_date', $record), $errors, ['type' => 'date', 'col' => 'col-md-4']) ?>
                    <?= Form::input('po_reference', 'No. PO pembelian (opsional)', old('po_reference', $record), $errors, ['maxlength' => 80, 'col' => 'col-md-4', 'placeholder' => 'PO ke supplier']) ?>
                </div>
                <datalist id="supplier-list"><?php foreach ($suppliers as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-section">
                <div class="form-section-title">Barang</div>
                <div class="row g-3">
                    <?= Form::input('item_name', 'Nama barang', old('item_name', $record), $errors, ['required' => true, 'maxlength' => 255, 'col' => 'col-md-8', 'list' => 'item-list', 'autocomplete' => 'off',
                        'placeholder' => 'mis. Biji plastik PET, Label 50x30, Karton 40x30x30']) ?>
                    <?= Form::input('item_code', 'Kode barang (opsional)', old('item_code', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4']) ?>
                    <?= Form::input('category', 'Jenis barang', old('category', $record), $errors, ['maxlength' => 80, 'col' => 'col-md-6', 'list' => 'category-list', 'autocomplete' => 'off']) ?>
                    <?= Form::input('location', 'Lokasi simpan', old('location', $record), $errors, ['maxlength' => 120, 'col' => 'col-md-6', 'list' => 'location-list', 'autocomplete' => 'off', 'placeholder' => 'mis. Gudang A, Rak 3']) ?>
                </div>
                <datalist id="item-list"><?php foreach ($items as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
                <datalist id="category-list"><?php foreach ($categories as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
                <datalist id="location-list"><?php foreach ($locations as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-section">
                <div class="form-section-title">Jumlah</div>
                <div class="row g-3">
                    <?= Form::input('quantity', 'Qty diterima', old('quantity', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => '0.01', 'required' => true, 'col' => 'col-md-4', 'inputmode' => 'decimal']) ?>
                    <?= Form::input('reject_qty', 'Qty reject', old('reject_qty', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => '0.01', 'col' => 'col-md-4', 'inputmode' => 'decimal']) ?>
                    <?= Form::input('unit', 'Satuan', old('unit', $record, 'pcs'), $errors, ['required' => true, 'maxlength' => 20, 'col' => 'col-md-4', 'list' => 'unit-list', 'autocomplete' => 'off']) ?>
                    <?= Form::input('receiver', 'Penerima (petugas gudang)', old('receiver', $record), $errors, ['maxlength' => 120, 'col' => 'col-md-6', 'list' => 'receiver-list', 'autocomplete' => 'off']) ?>
                    <?= Form::input('attachment', 'Link lampiran (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500, 'col' => 'col-md-6', 'placeholder' => 'https://… (foto surat jalan / COA)']) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 2000, 'placeholder' => 'mis. kondisi barang, alasan reject']) ?>
                </div>
                <datalist id="unit-list"><?php foreach ($units as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
                <datalist id="receiver-list"><?php foreach ($receivers as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
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
            <form id="delete-inbound-supplier" method="post" action="<?= e(url('/inbound-supplier/' . $row['id'] . '/delete')) ?>" data-confirm="Hapus data inbound supplier ini?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
</div>
