<?php

use App\Helpers\Form;
use App\Models\Customer;

/** @var array<string,mixed>|null $customer @var array<string,string> $errors @var list<array<string,mixed>> $similar */
$isEdit = $customer !== null;
$action = $isEdit ? url('/customers/' . $customer['id']) : url('/customers');
$cancel = $isEdit ? url('/customers/' . $customer['id']) : url('/customers');
?>
<div class="breadcrumb-lite">
    <a href="<?= e(url('/customers')) ?>">Customers</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e(url('/customers/' . $customer['id'])) ?>"><?= e($customer['name']) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Tambah</span><?php endif; ?>
</div>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit Customer' : 'Tambah Customer' ?></h1>
        <?php if ($isEdit): ?><p class="page-subtitle"><span class="code-chip"><?= e($customer['code']) ?></span></p><?php endif; ?>
    </div>
</div>

<form class="surface" method="post" action="<?= e($action) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if (!empty($similar)): ?>
        <div class="form-section">
            <div class="callout callout-warning">
                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Kemungkinan duplikat</div>
                <div class="small mb-2">Customer berikut memiliki nama yang sama (setelah mengabaikan tanda baca &amp; huruf besar/kecil):</div>
                <ul class="small mb-2">
                    <?php foreach ($similar as $s): ?>
                        <li><a href="<?= e(url('/customers/' . $s['id'])) ?>" target="_blank" rel="noopener"><?= e($s['name']) ?></a> <span class="code-chip"><?= e($s['code']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?= Form::checkbox('confirm_not_duplicate', 'Saya sudah memeriksa — ini customer yang berbeda', old('confirm_not_duplicate') === '1', []) ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="form-section">
        <div class="form-section-title">Identitas</div>
        <div class="form-section-desc">Nama customer dipakai di seluruh modul (OEF, delivery, retur &amp; komplain).</div>
        <div class="row g-3">
            <?= Form::input('name', 'Nama customer', old('name', $customer), $errors, ['required' => true, 'maxlength' => 190, 'col' => 'col-md-6', 'autofocus' => !$isEdit]) ?>
            <?= Form::input('company', 'Nama perusahaan / badan usaha', old('company', $customer), $errors, ['maxlength' => 190, 'col' => 'col-md-6', 'help' => 'Kosongkan bila sama dengan nama customer.']) ?>
            <?= Form::select('status', 'Status', Form::list(Customer::STATUSES), old('status', $customer, 'Active'), $errors, ['required' => true, 'col' => 'col-md-4']) ?>
            <?= Form::input('industry', 'Industri', old('industry', $customer), $errors, ['maxlength' => 100, 'col' => 'col-md-4', 'list' => 'industry-list', 'placeholder' => 'mis. Skincare, Kosmetik']) ?>
            <?= Form::input('source', 'Sumber customer', old('source', $customer), $errors, ['maxlength' => 100, 'col' => 'col-md-4', 'list' => 'source-list']) ?>
        </div>
        <datalist id="industry-list"><?php foreach ($industries as $i): ?><option value="<?= e($i) ?>"><?php endforeach; ?></datalist>
        <datalist id="source-list"><?php foreach ($sources as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
    </div>
    <div class="form-section">
        <div class="form-section-title">Kontak &amp; alamat</div>
        <div class="form-section-desc">Kontak utama di sisi customer. Kontak lain dapat ditambahkan di tab Contacts.</div>
        <div class="row g-3">
            <?= Form::input('pic', 'Contact person', old('pic', $customer), $errors, ['maxlength' => 120, 'col' => 'col-md-4']) ?>
            <?= Form::input('phone', 'Telepon / WhatsApp', old('phone', $customer), $errors, ['type' => 'tel', 'maxlength' => 40, 'col' => 'col-md-4', 'inputmode' => 'tel']) ?>
            <?= Form::input('email', 'Email', old('email', $customer), $errors, ['type' => 'email', 'maxlength' => 190, 'col' => 'col-md-4']) ?>
            <?= Form::textarea('address', 'Alamat', old('address', $customer), $errors, ['rows' => 2, 'maxlength' => 1000]) ?>
        </div>
    </div>
    <div class="form-section">
        <div class="form-section-title">Pengelolaan</div>
        <div class="row g-3">
            <?= Form::select('marketing_pic_id', 'PIC Marketing (internal)', $pics, old('marketing_pic_id', $customer), $errors, ['placeholder' => '— Belum ditentukan —', 'col' => 'col-md-6']) ?>
            <?= Form::textarea('notes', 'Catatan', old('notes', $customer), $errors, ['rows' => 3, 'maxlength' => 5000]) ?>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan customer' ?></button>
    </div>
</form>
