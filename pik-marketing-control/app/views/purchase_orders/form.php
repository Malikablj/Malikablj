<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/**
 * @var array<string,mixed>|null $po
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var list<array<string,string>> $lines
 * @var array<int,string> $products
 */
$isEdit = $po !== null;
$record = $po ?? $preset;
$lineRow = static function (int|string $i, array $line, array $errors, array $products): string {
    $err = static fn (string $f) => $errors["lines.{$i}.{$f}"] ?? null;
    $pid = (string) ($line['product_id'] ?? '');
    $html = '<div class="po-line row g-2 align-items-start" data-line>';
    $html .= '<div class="col-md-7"><label class="form-label d-md-none">Produk</label><select class="form-select' . ($err('product_id') ? ' is-invalid' : '') . '" name="lines[' . e((string) $i) . '][product_id]" aria-label="Produk">';
    $html .= '<option value="">— Pilih produk —</option>' . Form::options($products, $pid) . '</select>';
    $html .= $err('product_id') ? '<div class="invalid-feedback d-block">' . e($err('product_id')) . '</div>' : '';
    $html .= '</div><div class="col-6 col-md-2"><label class="form-label d-md-none">Qty</label><input type="number" min="1" step="1" inputmode="numeric" class="form-control' . ($err('order_qty') ? ' is-invalid' : '') . '" name="lines[' . e((string) $i) . '][order_qty]" value="' . e($line['order_qty'] ?? '') . '" placeholder="Qty" aria-label="Qty order">';
    $html .= $err('order_qty') ? '<div class="invalid-feedback d-block">' . e($err('order_qty')) . '</div>' : '';
    $html .= '</div><div class="col-6 col-md-2"><label class="form-label d-md-none">Catatan</label><input type="text" maxlength="500" class="form-control" name="lines[' . e((string) $i) . '][remark]" value="' . e($line['remark'] ?? '') . '" placeholder="Catatan" aria-label="Catatan baris"></div>';
    $html .= '<div class="col-md-1 text-md-end"><button type="button" class="btn btn-light btn-icon" data-line-remove aria-label="Hapus baris"><i class="bi bi-x-lg"></i></button></div></div>';
    return $html;
};
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/purchase-orders')) ?>">Purchase Orders</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e($po['po_number'] ?? $po['code']) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Buat</span><?php endif; ?></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit PO' : 'Buat Purchase Order' ?></h1>
    <?php if ($isEdit): ?><p class="page-subtitle">Baris produk diubah dari halaman detail PO.</p><?php endif; ?></div></div>

<form class="surface" method="post" action="<?= e(url($isEdit ? '/purchase-orders/' . $po['id'] : '/purchase-orders')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-section">
        <div class="form-section-title">Header PO</div>
        <div class="row g-3">
            <?= Form::input('po_number', 'Nomor PO', old('po_number', $record), $errors, ['required' => true, 'maxlength' => 80, 'col' => 'col-md-4', 'placeholder' => 'mis. PO/SIT/260901420']) ?>
            <?= Form::select('customer_id', 'Customer', $customers, old('customer_id', $record), $errors, ['required' => true, 'placeholder' => '— Pilih customer —', 'searchable' => 'Cari customer…', 'col' => 'col-md-8']) ?>
            <?= Form::input('po_date', 'Tanggal PO', old('po_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
            <?= Form::input('payment_term', 'Termin pembayaran', old('payment_term', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4', 'list' => 'payment-terms']) ?>
            <?= Form::select('status', 'Status', Form::list(PurchaseOrder::STATUSES), old('status', $record, 'Open'), $errors, ['required' => true, 'col' => 'col-md-4', 'help' => 'Berubah otomatis ke Partial/Closed saat delivery dicatat.']) ?>
            <?= Form::textarea('remark', 'Catatan', old('remark', $record), $errors, ['rows' => 2, 'maxlength' => 5000]) ?>
            <datalist id="payment-terms"><?php foreach (PurchaseOrder::PAYMENT_TERMS as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
        </div>
    </div>
    <?php if (!$isEdit): ?>
        <div class="form-section">
            <div class="d-flex justify-content-between align-items-end mb-3 gap-2 flex-wrap">
                <div><div class="form-section-title">Baris produk</div><div class="form-section-desc mb-0">Satu PO dapat berisi banyak produk. Baris kosong diabaikan.</div></div>
                <button type="button" class="btn btn-light btn-sm" data-line-add><i class="bi bi-plus-lg"></i> Tambah baris</button>
            </div>
            <?php if (isset($errors['lines'])): ?><div class="alert alert-danger py-2"><?= e($errors['lines']) ?></div><?php endif; ?>
            <div class="row g-2 d-none d-md-flex small text-secondary fw-semibold mb-1"><div class="col-md-7">Produk</div><div class="col-md-2">Qty order (pcs)</div><div class="col-md-2">Catatan</div></div>
            <div class="d-grid gap-2" data-line-list>
                <?php foreach ($lines as $i => $line): ?>
                    <?= $lineRow($i, $line, $errors, $products) ?>
                <?php endforeach; ?>
            </div>
            <template data-line-template><?= $lineRow('__INDEX__', [], [], $products) ?></template>
        </div>
    <?php endif; ?>
    <div class="form-actions">
        <a href="<?= e(url($isEdit ? '/purchase-orders/' . $po['id'] : '/purchase-orders')) ?>" class="btn btn-light">Batal</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan PO' ?></button>
    </div>
</form>
