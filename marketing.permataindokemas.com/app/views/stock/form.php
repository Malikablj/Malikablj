<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed>|null $row
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var list<string> $productNames
 * @var array<string,string> $types
 * @var list<string> $statuses
 * @var list<array<string,mixed>> $issues
 * @var bool $askConfirm
 * @var string $return
 */
$isEdit = $row !== null;
$record = $row ?? $preset;
$legacyUnlinked = $isEdit && $row['product_id'] === null;
$cancel = $return !== '' ? to($return) : url('/stock');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/stock')) ?>">Stock</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($row['code']) : 'Catat' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Entri Stok' : 'Catat Stok' ?></h1>
    <p class="page-subtitle">Ketik nama produk secara manual — nama yang sama otomatis dikelompokkan menjadi satu produk. Isi Jumlah box dan Qty per box, maka Qty dihitung otomatis, atau isi Qty langsung.</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/stock/' . $row['id'] : '/stock')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <?php if ($legacyUnlinked || $issues): ?>
                <div class="form-section">
                    <?php if ($legacyUnlinked): ?>
                        <div class="callout callout-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>
                            Entri stok legacy ini belum terhubung ke master produk<?= $row['product_legacy'] ? ' (nama di spreadsheet: <strong>' . e($row['product_legacy']) . '</strong>)' : '' ?>.
                            Ketik nama produk yang sesuai agar stok ikut dikelompokkan dan dihitung per produk.</div>
                    <?php endif; ?>
                    <?php foreach ($issues as $i): ?>
                        <div class="small text-secondary"><?= status_badge($i['resolution_status']) ?> <strong><?= e($i['issue_type']) ?></strong> — <?= e(excerpt($i['description'] ?? '', 160)) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::input('product_name', 'Nama produk', old('product_name', $record), $errors, [
                        'required' => !$legacyUnlinked, 'maxlength' => 190, 'list' => 'stock-product-names', 'autocomplete' => 'off', 'autofocus' => !$isEdit,
                        'placeholder' => 'mis. Botol PET 100ml Bening',
                        'help' => 'Ketik manual. Nama yang sama (tidak peka huruf besar/kecil) otomatis masuk ke kelompok produk yang sama; nama baru dicatat sebagai produk baru.',
                    ]) ?>
                    <datalist id="stock-product-names"><?php foreach ($productNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
                    <?= Form::select('stock_type', 'Tipe stok', $types, old('stock_type', $record, 'FG'), $errors, ['required' => true, 'col' => 'col-md-6']) ?>
                    <?= Form::input('status', 'Status / lokasi', old('status', $record), $errors, ['maxlength' => 40, 'col' => 'col-md-6', 'list' => 'stock-status-list', 'placeholder' => 'mis. Ready, QC, Gudang A']) ?>
                    <?= Form::input('box', 'Jumlah box', old('box', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => 1, 'col' => 'col-md-4', 'id' => 'f_box']) ?>
                    <?= Form::input('qty_per_box', 'Qty per box', old('qty_per_box', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'col' => 'col-md-4', 'id' => 'f_qty_per_box']) ?>
                    <?= Form::input('quantity', 'Qty (pcs)', old('quantity', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => 1, 'col' => 'col-md-4', 'id' => 'f_quantity',
                        'help' => 'Kosongkan untuk dihitung dari Box × Qty per box.']) ?>
                    <?php if ($askConfirm): ?>
                        <?= Form::checkbox('confirm_qty_mismatch', 'Qty memang berbeda dari Box × Qty per box (ada sisa di luar box)', old('confirm_qty_mismatch') === '1', []) ?>
                    <?php endif; ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                </div>
                <datalist id="stock-status-list"><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('stock.delete')): ?>
                    <button type="submit" form="delete-stock" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan stok</button>
            </div>
        </form>
        <?php if ($isEdit && can('stock.delete')): ?>
            <form id="delete-stock" method="post" action="<?= e(url('/stock/' . $row['id'] . '/delete')) ?>" data-confirm="Hapus entri stok ini?">
                <?= csrf_field() ?><?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
