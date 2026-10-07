<?php

use App\Helpers\Form;
use App\Models\Lead;

/** @var array<string,mixed>|null $lead @var array<string,string> $errors @var array<string,mixed> $preset */
$isEdit = $lead !== null;
$record = $lead ?? $preset;
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/leads')) ?>">Leads</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($lead['lead_name']) : 'Buat' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Lead' : 'Buat Lead' ?></h1>
    <?php if ($isEdit): ?><p class="page-subtitle"><span class="code-chip"><?= e($lead['code']) ?></span></p><?php endif; ?></div></div>

<form class="surface" method="post" action="<?= e(url($isEdit ? '/leads/' . $lead['id'] : '/leads')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-section">
        <div class="form-section-title">Peluang</div>
        <div class="form-section-desc">Lead dapat dikaitkan ke customer yang sudah ada, atau ke prospek baru (isi nama perusahaan).</div>
        <div class="row g-3">
            <?= Form::input('lead_name', 'Nama lead', old('lead_name', $record), $errors, ['required' => true, 'maxlength' => 190, 'col' => 'col-md-6', 'placeholder' => 'mis. Botol serum 30ml untuk launching Q1']) ?>
            <?= Form::select('customer_id', 'Customer', $customers, old('customer_id', $record), $errors, ['placeholder' => '— Prospek baru (belum customer) —', 'searchable' => 'Cari customer…', 'col' => 'col-md-6']) ?>
            <?= Form::input('company_name', 'Nama perusahaan prospek', old('company_name', $record), $errors, ['maxlength' => 190, 'col' => 'col-md-6', 'help' => 'Diisi bila belum menjadi customer.']) ?>
            <?= Form::input('product_interest', 'Produk yang diminati', old('product_interest', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-6']) ?>
            <?= Form::select('status', 'Status', Form::list(Lead::STATUSES), old('status', $record, 'New'), $errors, ['required' => true, 'col' => 'col-md-3']) ?>
            <?= Form::select('priority', 'Prioritas', Form::list(Lead::PRIORITIES), old('priority', $record, 'Medium'), $errors, ['required' => true, 'col' => 'col-md-3']) ?>
            <?= Form::input('source', 'Sumber lead', old('source', $record), $errors, ['maxlength' => 100, 'col' => 'col-md-3', 'list' => 'lead-sources']) ?>
            <?= Form::select('pic_user_id', 'PIC', $pics, old('pic_user_id', $record), $errors, ['placeholder' => '— Belum ditentukan —', 'col' => 'col-md-3']) ?>
            <datalist id="lead-sources"><?php foreach (Lead::SOURCES as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        </div>
    </div>
    <div class="form-section">
        <div class="form-section-title">Nilai &amp; target</div>
        <div class="row g-3">
            <?= Form::input('estimated_qty', 'Estimasi qty (pcs)', old('estimated_qty', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => 1, 'col' => 'col-md-4', 'inputmode' => 'numeric']) ?>
            <?= Form::input('potential_value', 'Potential value', old('potential_value', $record), $errors, ['type' => 'number', 'min' => 0, 'step' => '0.01', 'col' => 'col-md-4', 'prefix' => 'Rp', 'inputmode' => 'decimal']) ?>
            <?= Form::input('expected_close_date', 'Target closing', old('expected_close_date', $record), $errors, ['type' => 'date', 'col' => 'col-md-4']) ?>
        </div>
    </div>
    <div class="form-section">
        <div class="form-section-title">Kontak prospek</div>
        <div class="row g-3">
            <?= Form::input('contact_name', 'Contact person', old('contact_name', $record), $errors, ['maxlength' => 120, 'col' => 'col-md-4']) ?>
            <?= Form::input('phone', 'Telepon / WhatsApp', old('phone', $record), $errors, ['type' => 'tel', 'maxlength' => 40, 'col' => 'col-md-4']) ?>
            <?= Form::input('email', 'Email', old('email', $record), $errors, ['type' => 'email', 'maxlength' => 190, 'col' => 'col-md-4']) ?>
            <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 3, 'maxlength' => 5000]) ?>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e(url($isEdit ? '/leads/' . $lead['id'] : '/leads')) ?>" class="btn btn-light">Batal</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan lead' ?></button>
    </div>
</form>
