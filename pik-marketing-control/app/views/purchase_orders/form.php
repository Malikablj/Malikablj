<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/**
 * Form Order Entry Form (OEF).
 * @var array<string,mixed>|null $po
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var list<array<string,string>> $lines
 * @var list<string> $customers saran nama customer
 * @var list<string> $sales saran nama sales
 * @var list<string> $suggestions saran nama produk
 */
$isEdit = $po !== null;
$record = $po ?? $preset;
$legacy = $isEdit && trim((string) $po['order_number']) === '';
$lineRow = static function (int|string $i, array $line, array $errors): string {
    $err = static fn (string $f) => $errors["lines.{$i}.{$f}"] ?? null;
    $name = static fn (string $f) => 'lines[' . e((string) $i) . '][' . $f . ']';
    $field = static function (string $f, string $label, array $attrs, string $col) use ($line, $err, $name, $i): string {
        $id = 'l_' . $i . '_' . $f;
        $html = '<div class="' . $col . '"><label class="form-label small mb-1" for="' . e($id) . '">' . $label . '</label>'
            . '<input class="form-control' . ($err($f) ? ' is-invalid' : '') . '" id="' . e($id) . '" name="' . $name($f) . '" value="' . e($line[$f] ?? '') . '"';
        foreach ($attrs as $k => $v) {
            $html .= ' ' . $k . '="' . e((string) $v) . '"';
        }
        return $html . '>' . ($err($f) ? '<div class="invalid-feedback d-block">' . e($err($f)) . '</div>' : '') . '</div>';
    };
    return '<div class="oef-line" data-line>'
        . '<div class="oef-line-head"><span class="oef-line-no"></span><button type="button" class="btn btn-light btn-sm btn-icon" data-line-remove aria-label="Hapus produk"><i class="bi bi-x-lg"></i></button></div>'
        . '<div class="row g-2">'
        . $field('product_name', 'Nama produk<span class="req">*</span>', ['type' => 'text', 'maxlength' => 190, 'list' => 'product-suggestions', 'autocomplete' => 'off', 'placeholder' => 'Ketik nama produk'], 'col-md-6')
        . $field('item_description', 'Spesifikasi produk', ['type' => 'text', 'maxlength' => 2000, 'placeholder' => 'mis. warna, bahan, ukuran, printing'], 'col-md-6')
        . $field('order_qty', 'Qty<span class="req">*</span>', ['type' => 'number', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric'], 'col-6 col-md-3')
        . $field('unit', 'Satuan', ['type' => 'text', 'maxlength' => 20, 'placeholder' => 'pcs', 'list' => 'unit-suggestions'], 'col-6 col-md-3')
        . $field('subcont_supplier', 'Supplier (jika subcont)', ['type' => 'text', 'maxlength' => 150, 'placeholder' => 'Kosongkan bila produksi sendiri'], 'col-md-6')
        . '</div></div>';
};
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Order Entry Form</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e(PurchaseOrder::label($po)) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Buat</span><?php endif; ?></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit OEF' : 'Buat Order Entry Form' ?></h1>
    <p class="page-subtitle"><?= $isEdit ? 'Produk diubah dari halaman detail OEF. Perubahan isi OEF membuatnya kembali menunggu review PPIC.' : 'Customer dan produk diketik manual. Bila belum ada, otomatis ditambahkan ke menu Customer dan Produk. Setelah disimpan, OEF direview oleh PPIC.' ?></p></div></div>

<?php if ($isEdit && $po['review_status'] !== 'Pending'): ?>
    <div class="callout callout-info section-gap small"><i class="bi bi-info-circle me-1"></i>OEF ini sudah direview PPIC (<?= e(PurchaseOrder::REVIEW_LABELS[$po['review_status']] ?? $po['review_status']) ?>).
        Bila isinya diubah (selain status), OEF kembali <strong>menunggu review PPIC</strong>.</div>
<?php endif; ?>

<form class="surface" method="post" action="<?= e(url($isEdit ? '/purchase-orders/' . $po['id'] : '/purchase-orders')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-section">
        <div class="form-section-title">Order</div>
        <div class="row g-3">
            <?= Form::input('order_number', 'No order', old('order_number', $record), $errors, ['required' => !$legacy, 'maxlength' => 60, 'col' => 'col-md-4', 'placeholder' => 'mis. OEF/2610/001',
                'help' => $legacy ? 'Data lama (sebelum OEF) boleh tanpa No order.' : 'Diisi manual, tidak boleh sama dengan OEF lain.']) ?>
            <?= Form::input('po_date', 'Tanggal order', old('po_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-6 col-md-4']) ?>
            <?= Form::input('sales_name', 'Nama sales', old('sales_name', $record), $errors, ['required' => !$legacy, 'maxlength' => 120, 'col' => 'col-6 col-md-4', 'list' => 'sales-suggestions', 'autocomplete' => 'off']) ?>
            <?= Form::input('customer_name', 'Nama customer', old('customer_name', $record), $errors, ['required' => true, 'maxlength' => 190, 'col' => 'col-md-8', 'list' => 'customer-suggestions', 'autocomplete' => 'off',
                'placeholder' => 'Ketik nama customer', 'help' => 'Pilih dari saran bila sudah ada. Nama baru otomatis ditambahkan ke menu Customer.']) ?>
            <?= Form::input('po_number', 'No PO dari customer', old('po_number', $record), $errors, ['maxlength' => 80, 'col' => 'col-md-4', 'placeholder' => 'mis. PO/SIT/260901420']) ?>
            <?php if ($isEdit): ?>
                <?= Form::select('status', 'Status', Form::list(PurchaseOrder::STATUSES), old('status', $record, 'Open'), $errors, ['required' => true, 'col' => 'col-md-4',
                    'help' => 'Berubah otomatis ke Partial/Closed saat delivery dicatat. Pakai Cancelled untuk membatalkan.']) ?>
            <?php endif; ?>
        </div>
        <datalist id="customer-suggestions"><?php foreach ($customers as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
        <datalist id="sales-suggestions"><?php foreach ($sales as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
    </div>

    <?php if (!$isEdit): ?>
        <div class="form-section">
            <div class="d-flex justify-content-between align-items-end mb-3 gap-2 flex-wrap">
                <div><div class="form-section-title">Produk</div><div class="form-section-desc mb-0">Satu OEF dapat berisi beberapa produk. Produk baru otomatis masuk menu Produk.</div></div>
                <button type="button" class="btn btn-light btn-sm" data-line-add><i class="bi bi-plus-lg"></i> Tambah produk</button>
            </div>
            <?php if (isset($errors['lines'])): ?><div class="alert alert-danger py-2"><?= e($errors['lines']) ?></div><?php endif; ?>
            <div class="d-grid gap-3 oef-lines" data-line-list>
                <?php foreach ($lines as $i => $line): ?>
                    <?= $lineRow($i, $line, $errors) ?>
                <?php endforeach; ?>
            </div>
            <template data-line-template><?= $lineRow('__INDEX__', [], []) ?></template>
            <datalist id="product-suggestions"><?php foreach ($suggestions as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
            <datalist id="unit-suggestions"><?php foreach (App\Models\Product::UNITS as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
        </div>
    <?php endif; ?>

    <div class="form-section">
        <div class="form-section-title">Pengiriman</div>
        <div class="form-section-desc">Setelah PPIC menyetujui OEF, jadwal di menu Delivery dibuat otomatis pada tanggal permintaan ini (bisa diubah bila jadwal berubah).</div>
        <div class="row g-3">
            <?= Form::input('requested_delivery_date', 'Permintaan selesai/kirim', old('requested_delivery_date', $record), $errors, ['type' => 'date', 'required' => !$legacy, 'col' => 'col-md-4']) ?>
            <?= Form::textarea('delivery_address', 'Tujuan kirim', old('delivery_address', $record), $errors, ['rows' => 2, 'maxlength' => 2000, 'required' => !$legacy, 'col' => 'col-md-8', 'placeholder' => 'Nama penerima / alamat gudang customer']) ?>
            <?= Form::textarea('remark', 'Keterangan', old('remark', $record), $errors, ['rows' => 2, 'maxlength' => 5000]) ?>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e(url($isEdit ? '/purchase-orders/' . $po['id'] : '/purchase-orders')) ?>" class="btn btn-light">Batal</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan &amp; kirim ke PPIC' ?></button>
    </div>
</form>
