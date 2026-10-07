<?php

use App\Helpers\Form;
use App\Models\PoFinancial;

/**
 * @var array<string,mixed>|null $row
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<int,string> $pos
 * @var list<string> $brands
 * @var float $ppnRate
 * @var list<array<string,mixed>> $poLines
 */
$isEdit = $row !== null;
$record = $row ?? $preset;
if ($isEdit && $record['unit_price'] !== null) {
    $record['unit_price'] = App\Helpers\Number::decimal($record['unit_price'], 6);
}
$cancel = $isEdit ? url('/po-financials/' . $row['id']) : url('/po-financials');
$rateLabel = rtrim(rtrim(number_format($ppnRate, 2, ',', '.'), '0'), ',');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/po-financials')) ?>">PO Financials</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($row['code']) : 'Tambah' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit PO Financial' : 'Tambah PO Financial' ?></h1>
    <p class="page-subtitle">Total = Qty × Harga satuan · PPN <?= e($rateLabel) ?>% · dihitung otomatis saat disimpan (bila Qty dan Harga satuan terisi).</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/po-financials/' . $row['id'] : '/po-financials')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::select('po_id', 'PO', $pos, old('po_id', $record), $errors, ['placeholder' => '— Belum terhubung ke PO —', 'searchable' => 'Cari nomor PO atau customer…', 'col' => 'col-md-8']) ?>
                    <?= Form::input('po_date', 'Tanggal PO', old('po_date', $record), $errors, ['type' => 'date', 'col' => 'col-md-4', 'help' => 'Kosongkan untuk memakai tanggal PO terpilih.']) ?>
                    <?php if ($poLines): ?>
                        <div class="col-12 small text-secondary">Produk di PO ini:
                            <?php foreach ($poLines as $i => $l): ?><?= $i > 0 ? ' · ' : '' ?><?= e($l['name']) ?> (<?= e(fmt_qty($l['order_qty'])) ?>)<?php endforeach; ?></div>
                    <?php endif; ?>
                    <?= Form::input('product_legacy', 'Produk / deskripsi', old('product_legacy', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-8']) ?>
                    <?= Form::input('product_code_legacy', 'Kode produk', old('product_code_legacy', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4']) ?>
                    <?= Form::input('brand', 'Brand', old('brand', $record), $errors, ['maxlength' => 100, 'col' => 'col-md-4', 'list' => 'brand-list']) ?>
                    <?= Form::input('order_qty', 'Qty', old('order_qty', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => 1, 'col' => 'col-md-4']) ?>
                    <?= Form::input('unit_price', 'Harga satuan (sebelum PPN)', old('unit_price', $record), $errors, ['col' => 'col-md-4', 'prefix' => 'Rp', 'inputmode' => 'decimal']) ?>
                    <?= Form::select('payment_status', 'Status pembayaran', Form::list(PoFinancial::PAYMENT_STATUSES), old('payment_status', $record, 'Unpaid'), $errors, ['required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('attachment', 'Link lampiran (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500, 'col' => 'col-md-8']) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 5000]) ?>
                </div>
                <datalist id="brand-list"><?php foreach ($brands as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('finance.delete')): ?>
                    <button type="submit" form="delete-pof" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
        <?php if ($isEdit && can('finance.delete')): ?>
            <form id="delete-pof" method="post" action="<?= e(url('/po-financials/' . $row['id'] . '/delete')) ?>" data-confirm="Hapus ringkasan finansial PO ini?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
</div>
