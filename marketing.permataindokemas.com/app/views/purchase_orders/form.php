<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/**
 * Order Entry Form (OEF) — buat / edit.
 *
 * @var array<string,mixed>|null $po
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<int,string> $customers
 * @var list<string> $productNames
 * @var list<string> $salesNames
 * @var bool $productEditable
 * @var bool $productLocked
 * @var string $currentProduct
 * @var string $currentQty
 */
$isEdit = $po !== null;
$isOef = !$isEdit || $po['ppic_status'] !== null;
$record = $po ?? $preset;
if ($isEdit) {
    $record['product_name'] = $currentProduct;
    $record['order_qty'] = $currentQty;
}
$subcont = old('is_subcont', $record, '0') === '1';
$number = $isEdit ? PurchaseOrder::displayNumber($po) : null;
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Order Entry Form</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e($number) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Buat</span><?php endif; ?></div>
<div class="page-header"><div>
    <div class="page-eyebrow">Operations</div>
    <h1 class="page-title"><?= $isEdit ? 'Edit Order ' . e($number) : 'Order Entry Form' ?></h1>
    <p class="page-subtitle"><?php if ($isEdit && $isOef && in_array($po['ppic_status'], ['Approved', 'Rejected'], true)): ?>
        Mengubah spesifikasi, qty, produk, subcont, atau tanggal permintaan akan mengirim ulang order ke PPIC untuk konfirmasi.
    <?php elseif ($isOef): ?>
        Setelah disimpan, order dikirim ke PPIC untuk dikonfirmasi dan jadwal kirim otomatis masuk ke menu Delivery.
    <?php else: ?>
        Data PO lama (hasil migrasi). Baris produk dikelola dari halaman detail.
    <?php endif; ?></p>
</div></div>

<div class="row">
<div class="col-xl-10">
<form class="surface" method="post" action="<?= e(url($isEdit ? '/purchase-orders/' . $po['id'] : '/purchase-orders')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-section">
        <div class="form-section-title">Order</div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="f_order_number">No. order</label>
                <input type="text" class="form-control" id="f_order_number" value="<?= e($number ?? 'Otomatis (OEF-' . date('ym') . '-…)') ?>" readonly>
            </div>
            <?= Form::input('po_date', 'Tanggal order', old('po_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
            <?= Form::input('sales_name', 'Nama sales', old('sales_name', $record), $errors, ['required' => $isOef, 'maxlength' => 120, 'col' => 'col-md-4', 'list' => 'sales-names', 'autocomplete' => 'off']) ?>
            <datalist id="sales-names"><?php foreach ($salesNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
            <?= Form::select('customer_id', 'Nama customer', $customers, old('customer_id', $record), $errors, ['required' => true, 'placeholder' => '— Pilih customer —', 'searchable' => 'Cari customer…', 'col' => 'col-md-8']) ?>
            <?= Form::input('po_number', 'No. PO dari customer', old('po_number', $record), $errors, ['maxlength' => 80, 'col' => 'col-md-4', 'placeholder' => 'mis. PO/SIT/260901420', 'help' => 'Kosongkan bila customer belum mengirim PO.']) ?>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Produk</div>
        <?php if ($productEditable): ?>
            <div class="row g-3">
                <?= Form::input('product_name', 'Nama produk', old('product_name', $record), $errors, [
                    'required' => true, 'maxlength' => 190, 'col' => 'col-md-8', 'list' => 'product-names', 'autocomplete' => 'off', 'readonly' => $productLocked,
                    'help' => $productLocked ? 'Produk tidak dapat diganti karena order sudah memiliki pengiriman/retur.' : 'Ketik manual. Bila belum ada di menu Products, produk dicatat otomatis saat disimpan.',
                ]) ?>
                <?= Form::input('order_qty', 'Qty produk', old('order_qty', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric', 'required' => true, 'col' => 'col-md-4', 'suffix' => 'pcs']) ?>
                <datalist id="product-names"><?php foreach ($productNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
                <?= Form::textarea('product_spec', 'Spesifikasi produk', old('product_spec', $record), $errors, ['rows' => 4, 'maxlength' => 5000, 'required' => $isOef,
                    'placeholder' => 'mis. material, ukuran, warna, cetak/finishing, kemasan…', 'help' => 'Dicek dan dikonfirmasi oleh PPIC.']) ?>
            </div>
        <?php else: ?>
            <div class="callout callout-info small mb-3"><i class="bi bi-info-circle me-1"></i>Order ini berisi lebih dari satu baris produk. Produk & qty diubah dari halaman detail order.</div>
            <div class="row g-3">
                <?= Form::textarea('product_spec', 'Spesifikasi produk', old('product_spec', $record), $errors, ['rows' => 3, 'maxlength' => 5000]) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-section">
        <div class="form-section-title">Produksi &amp; pengiriman</div>
        <div class="row g-3">
            <?= Form::checkbox('is_subcont', 'Dikerjakan subcont (maklon ke supplier)', $subcont, $errors, ['col' => 'col-md-4', 'id' => 'f_is_subcont']) ?>
            <div class="col-md-8" data-subcont-field<?= $subcont || isset($errors['supplier']) ? '' : ' hidden' ?>>
                <label class="form-label" for="f_supplier">Supplier (jika subcont)</label>
                <input type="text" class="form-control<?= invalid($errors, 'supplier') ?>" id="f_supplier" name="supplier" maxlength="190" value="<?= e(old('supplier', $record)) ?>">
                <?= field_error($errors, 'supplier') ?>
            </div>
            <?= Form::input('requested_date', 'Permintaan selesai / kirim', old('requested_date', $record), $errors, ['type' => 'date', 'required' => $isOef, 'col' => 'col-md-4',
                'help' => $isOef ? 'Otomatis dijadwalkan di menu Delivery; jadwal bisa diubah bila ada perubahan.' : null]) ?>
            <?= Form::input('ship_to', 'Tujuan kirim', old('ship_to', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-8', 'placeholder' => 'Alamat / gudang tujuan (kosong = nama customer)']) ?>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Lainnya</div>
        <div class="row g-3">
            <?= Form::textarea('remark', 'Keterangan', old('remark', $record), $errors, ['rows' => 3, 'maxlength' => 5000]) ?>
            <?= Form::input('payment_term', 'Termin pembayaran (opsional)', old('payment_term', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-6', 'list' => 'payment-terms']) ?>
            <datalist id="payment-terms"><?php foreach (PurchaseOrder::PAYMENT_TERMS as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
            <?php if ($isEdit): ?>
                <?= Form::select('status', 'Status order', Form::list(PurchaseOrder::STATUSES), old('status', $record, 'Open'), $errors, ['required' => true, 'col' => 'col-md-6', 'help' => 'Berubah otomatis ke Partial/Closed saat delivery dicatat.']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e(url($isEdit ? '/purchase-orders/' . $po['id'] : '/purchase-orders')) ?>" class="btn btn-light">Batal</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan &amp; kirim ke PPIC' ?></button>
    </div>
</form>
</div>
</div>
