<?php

use App\Helpers\Form;
use App\Models\Contact;

/** @var array<string,mixed>|null $contact @var array<string,string> $errors @var array<string,mixed> $preset @var string $return */
$isEdit = $contact !== null;
$record = $contact ?? $preset;
$action = $isEdit ? url('/contacts/' . $contact['id']) : url('/contacts');
$cancel = $return !== '' ? to($return) : url('/contacts');
?>
<div class="breadcrumb-lite">
    <a href="<?= e(url('/contacts')) ?>">Contacts</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($contact['name']) : 'Tambah' ?></span>
</div>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit Kontak' : 'Tambah Kontak' ?></h1>
        <?php if ($isEdit): ?><p class="page-subtitle"><?= e($contact['customer_name']) ?> · <span class="code-chip"><?= e($contact['code']) ?></span></p><?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e($action) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::select('customer_id', 'Customer', $customers, old('customer_id', $record), $errors, ['required' => true, 'placeholder' => '— Pilih customer —', 'searchable' => 'Cari customer…', 'col' => 'col-md-6']) ?>
                    <?= Form::input('name', 'Nama kontak', old('name', $record), $errors, ['required' => true, 'maxlength' => 150, 'col' => 'col-md-6']) ?>
                    <?= Form::input('position', 'Jabatan', old('position', $record), $errors, ['maxlength' => 100, 'col' => 'col-md-6', 'placeholder' => 'mis. Purchasing, Owner, R&D']) ?>
                    <?= Form::select('status', 'Status', ['Active' => 'Aktif', 'Inactive' => 'Nonaktif'], old('status', $record, 'Active'), $errors, ['required' => true, 'col' => 'col-md-6']) ?>
                    <?= Form::input('phone', 'Telepon', old('phone', $record), $errors, ['type' => 'tel', 'maxlength' => 40, 'col' => 'col-md-4', 'inputmode' => 'tel']) ?>
                    <?= Form::input('whatsapp', 'WhatsApp', old('whatsapp', $record), $errors, ['type' => 'tel', 'maxlength' => 40, 'col' => 'col-md-4', 'inputmode' => 'tel', 'help' => 'Kosongkan bila sama dengan telepon.']) ?>
                    <?= Form::input('email', 'Email', old('email', $record), $errors, ['type' => 'email', 'maxlength' => 190, 'col' => 'col-md-4']) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                    <?= Form::checkbox('is_primary', 'Jadikan kontak utama customer ini', old('is_primary', $record, '0') === '1', $errors) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('contacts.delete')): ?>
                    <button type="submit" form="delete-contact" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan kontak</button>
            </div>
        </form>
        <?php if ($isEdit && can('contacts.delete')): ?>
            <form id="delete-contact" method="post" action="<?= e(url('/contacts/' . $contact['id'] . '/delete')) ?>" data-confirm="Hapus kontak <?= e($contact['name']) ?>?">
                <?= csrf_field() ?>
                <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
