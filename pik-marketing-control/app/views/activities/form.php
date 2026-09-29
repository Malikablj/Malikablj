<?php

use App\Helpers\Form;
use App\Models\Activity;

/** @var array<string,mixed>|null $activity @var array<string,string> $errors @var array<string,mixed> $preset @var string $return */
$isEdit = $activity !== null;
$record = $activity ?? $preset;
$cancel = $return !== '' ? to($return) : url('/activities');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/activities')) ?>">Activities</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? 'Edit' : 'Log aktivitas' ?></span></div>
<div class="page-header"><div>
    <h1 class="page-title"><?= $isEdit ? 'Edit Aktivitas' : 'Log Aktivitas' ?></h1>
    <p class="page-subtitle">Catat setiap interaksi: WhatsApp, telepon, email, meeting, visit, quotation, sample, dan lainnya.</p>
</div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/activities/' . $activity['id'] : '/activities')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::select('customer_id', 'Customer', $customers, old('customer_id', $record), $errors, ['placeholder' => '— Pilih customer —', 'searchable' => 'Cari customer…', 'col' => 'col-md-6']) ?>
                    <?= Form::select('lead_id', 'Lead (opsional)', $leads, old('lead_id', $record), $errors, ['placeholder' => '— Tidak terkait lead —', 'searchable' => 'Cari lead…', 'col' => 'col-md-6']) ?>
                    <?= Form::select('activity_type', 'Jenis aktivitas', Form::list(Activity::TYPES), old('activity_type', $record, 'WhatsApp'), $errors, ['required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('activity_date', 'Tanggal & jam', old('activity_date', $record), $errors, ['type' => 'datetime-local', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::select('pic_user_id', 'PIC', $pics, old('pic_user_id', $record), $errors, ['placeholder' => '— Pilih PIC —', 'col' => 'col-md-4']) ?>
                    <?= Form::input('subject', 'Judul / ringkasan', old('subject', $record), $errors, ['required' => true, 'maxlength' => 190, 'placeholder' => 'mis. Kirim quotation botol 30ml']) ?>
                    <?= Form::textarea('description', 'Deskripsi', old('description', $record), $errors, ['rows' => 4, 'maxlength' => 10000, 'placeholder' => 'Hasil pembicaraan, permintaan customer, keberatan, dll.']) ?>
                    <?= Form::input('attachment', 'Link lampiran (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://drive.google.com/…', 'help' => 'Tautan dokumen (quotation, foto sample, dll.).']) ?>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Tindak lanjut</div>
                <div class="form-section-desc">Bila tanggal follow up diisi, sistem otomatis membuat jadwal di modul Follow Up.</div>
                <div class="row g-3">
                    <?= Form::input('next_action', 'Rencana tindak lanjut', old('next_action', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-8', 'placeholder' => 'mis. Konfirmasi harga final']) ?>
                    <?= Form::input('next_follow_up', 'Tanggal follow up', old('next_follow_up', $record), $errors, ['type' => 'date', 'col' => 'col-md-4']) ?>
                    <?= Form::checkbox('create_follow_up', 'Buat jadwal follow up otomatis', old('create_follow_up', $record, '0') === '1', $errors) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('activities.delete')): ?>
                    <button type="submit" form="delete-activity" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan aktivitas</button>
            </div>
        </form>
        <?php if ($isEdit && can('activities.delete')): ?>
            <form id="delete-activity" method="post" action="<?= e(url('/activities/' . $activity['id'] . '/delete')) ?>" data-confirm="Hapus aktivitas ini?">
                <?= csrf_field() ?><?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
