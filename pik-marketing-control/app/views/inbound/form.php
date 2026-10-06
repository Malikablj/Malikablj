<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed>|null $row
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<int,string> $pos
 * @var array<int,string> $products
 * @var list<string> $vendors
 * @var list<string> $receivers
 * @var list<string> $types
 */
$isEdit = $row !== null;
$record = $row ?? $preset;
$cancel = $isEdit ? url('/inbound/' . $row['id']) : url('/inbound');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/inbound')) ?>">Inbound Maklon</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($row['sj_number'] ?? $row['code']) : 'Catat' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Inbound Maklon' : 'Catat Inbound Maklon' ?></h1>
    <p class="page-subtitle">Total masuk dihitung otomatis: Qty diterima − Qty reject.</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/inbound/' . $row['id'] : '/inbound')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="form-section-title">Penerimaan</div>
                <div class="row g-3">
                    <?= Form::input('vendor', 'Vendor', old('vendor', $record), $errors, ['required' => true, 'maxlength' => 120, 'col' => 'col-md-6', 'list' => 'vendor-list']) ?>
                    <?= Form::input('receiver', 'Penerima (pabrik/gudang)', old('receiver', $record), $errors, ['maxlength' => 120, 'col' => 'col-md-6', 'list' => 'receiver-list']) ?>
                    <?= Form::input('actual_inbound_date', 'Tanggal barang masuk', old('actual_inbound_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('sj_number', 'Nomor surat jalan', old('sj_number', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4']) ?>
                    <?= Form::input('sj_date', 'Tanggal surat jalan', old('sj_date', $record), $errors, ['type' => 'date', 'col' => 'col-md-4']) ?>
                </div>
                <datalist id="vendor-list"><?php foreach ($vendors as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
                <datalist id="receiver-list"><?php foreach ($receivers as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-section">
                <div class="form-section-title">Barang</div>
                <div class="form-section-desc">Isi nama komponen, atau pilih produk bila barang ada di master produk.</div>
                <div class="row g-3">
                    <?= Form::select('po_id', 'OEF / PO terkait (opsional)', $pos, old('po_id', $record), $errors, ['placeholder' => '— Tanpa OEF —', 'searchable' => 'Cari nomor PO atau customer…', 'col' => 'col-md-6']) ?>
                    <?= Form::select('product_id', 'Produk (opsional)', $products, old('product_id', $record), $errors, ['placeholder' => '— Tidak dipilih —', 'searchable' => 'Cari produk…', 'col' => 'col-md-6']) ?>
                    <?= Form::input('component_name', 'Nama komponen', old('component_name', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-8']) ?>
                    <?= Form::input('type', 'Tipe', old('type', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4', 'list' => 'type-list']) ?>
                    <?= Form::input('internal_component_code', 'Kode komponen internal', old('internal_component_code', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-6']) ?>
                    <?= Form::input('factory_component_code', 'Kode komponen pabrik', old('factory_component_code', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-6']) ?>
                </div>
                <datalist id="type-list"><?php foreach ($types as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-section">
                <div class="form-section-title">Jumlah</div>
                <div class="row g-3">
                    <?= Form::input('quantity', 'Qty diterima', old('quantity', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('reject_qty', 'Qty reject', old('reject_qty', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => 1, 'col' => 'col-md-4']) ?>
                    <?= Form::input('odoo_checklist', 'Checklist Odoo', old('odoo_checklist', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4', 'help' => 'Catatan status input ke Odoo, bila ada.']) ?>
                    <?= Form::input('attachment', 'Link lampiran (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500]) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('inbound.delete')): ?>
                    <button type="submit" form="delete-inbound" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
        <?php if ($isEdit && can('inbound.delete')): ?>
            <form id="delete-inbound" method="post" action="<?= e(url('/inbound/' . $row['id'] . '/delete')) ?>" data-confirm="Hapus data inbound ini?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
</div>
