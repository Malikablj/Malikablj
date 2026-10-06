<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed>|null $product
 * @var array<string,string> $errors
 * @var list<string> $categories
 * @var list<string> $units
 */
$isEdit = $product !== null;
$action = $isEdit ? url('/products/' . $product['id']) : url('/products');
$cancel = $isEdit ? url('/products/' . $product['id']) : url('/products');
?>
<div class="breadcrumb-lite">
    <a href="<?= e(url('/products')) ?>">Products</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e(url('/products/' . $product['id'])) ?>"><?= e($product['name']) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Tambah</span><?php endif; ?>
</div>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit Produk' : 'Tambah Produk' ?></h1>
        <?php if ($isEdit): ?><p class="page-subtitle"><span class="code-chip"><?= e($product['code']) ?></span></p><?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e($action) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="form-section-title">Identitas produk</div>
                <div class="form-section-desc">Kombinasi nama, varian, dan kode produk harus unik.</div>
                <div class="row g-3">
                    <?= Form::input('name', 'Nama produk', old('name', $product), $errors, ['required' => true, 'maxlength' => 190, 'autofocus' => !$isEdit]) ?>
                    <?= Form::input('product_code', 'Kode produk', old('product_code', $product), $errors, ['maxlength' => 60, 'col' => 'col-md-4', 'placeholder' => 'mis. [BTNEA80]']) ?>
                    <?= Form::input('variant', 'Varian', old('variant', $product), $errors, ['maxlength' => 255, 'col' => 'col-md-8', 'placeholder' => 'mis. warna, ukuran, finishing']) ?>
                    <?= Form::input('category', 'Kelompok / kategori', old('category', $product), $errors, ['maxlength' => 100, 'col' => 'col-md-6', 'list' => 'category-list',
                        'placeholder' => 'Kosongkan = otomatis dari nama', 'help' => 'Bila kosong, kelompok ditentukan otomatis dari nama produk (mis. Botol, Pot / Jar, Cap / Tutup).']) ?>
                    <?= Form::input('unit', 'Satuan', old('unit', $product, 'pcs'), $errors, ['required' => true, 'maxlength' => 20, 'col' => 'col-md-6', 'list' => 'unit-list']) ?>
                </div>
                <datalist id="category-list"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
                <datalist id="unit-list"><?php foreach ($units as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::checkbox('is_active', 'Produk aktif (tampil di saran OEF & stok)', old('is_active', $product, '1') === '1', $errors, ['help' => 'Nonaktifkan produk yang tidak dijual lagi. Data historis tetap tersimpan.']) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $product), $errors, ['rows' => 3, 'maxlength' => 5000]) ?>
                </div>
            </div>
            <div class="form-actions">
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan produk' ?></button>
            </div>
        </form>
    </div>
</div>
