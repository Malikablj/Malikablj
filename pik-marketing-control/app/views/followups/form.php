<?php

use App\Helpers\Form;
use App\Models\FollowUp;

/** @var array<string,mixed>|null $followup @var array<string,string> $errors @var array<string,mixed> $preset @var string $return @var string $today */
$isEdit = $followup !== null;
$record = $followup ?? $preset;
$cancel = $return !== '' ? to($return) : url('/follow-ups');
$closed = $isEdit && in_array($followup['status'], FollowUp::CLOSED_STATUSES, true);
$overdue = $isEdit && FollowUp::isOverdue((string) $followup['follow_up_date'], (string) $followup['status'], $today);
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/follow-ups')) ?>">Follow Up</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e(excerpt($followup['purpose'], 50)) : 'Jadwalkan' ?></span></div>
<div class="page-header">
    <div>
        <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= $isEdit ? 'Follow Up' : 'Jadwalkan Follow Up' ?>
            <?php if ($isEdit): ?><?= $overdue ? status_badge('Overdue') : status_badge($followup['status']) ?><?php endif; ?></h1>
        <?php if ($isEdit): ?>
            <p class="page-subtitle"><span class="code-chip"><?= e($followup['code']) ?></span>
                <?php if ($followup['activity_subject']): ?> · dibuat dari aktivitas “<?= e($followup['activity_subject']) ?>”<?php endif; ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/follow-ups/' . $followup['id'] : '/follow-ups')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::select('customer_id', 'Customer', $customers, old('customer_id', $record), $errors, ['placeholder' => '— Pilih customer —', 'searchable' => 'Cari customer…', 'col' => 'col-md-6']) ?>
                    <?= Form::select('lead_id', 'Lead (opsional)', $leads, old('lead_id', $record), $errors, ['placeholder' => '— Tidak terkait lead —', 'searchable' => 'Cari lead…', 'col' => 'col-md-6']) ?>
                    <?= Form::input('purpose', 'Tujuan follow up', old('purpose', $record), $errors, ['required' => true, 'maxlength' => 255, 'placeholder' => 'mis. Tanyakan keputusan quotation']) ?>
                    <?= Form::input('follow_up_date', 'Tanggal', old('follow_up_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('follow_up_time', 'Jam (opsional)', old('follow_up_time', $record), $errors, ['type' => 'time', 'col' => 'col-md-4']) ?>
                    <?= Form::select('follow_up_type', 'Jenis', Form::list(FollowUp::TYPES), old('follow_up_type', $record, 'WhatsApp'), $errors, ['required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::select('pic_user_id', 'PIC', $pics, old('pic_user_id', $record), $errors, ['placeholder' => '— Pilih PIC —', 'col' => 'col-md-6']) ?>
                    <?= Form::select('status', 'Status', Form::list(FollowUp::EDITABLE_STATUSES), old('status', $record, 'Planned') === 'Overdue' ? 'Reschedule' : old('status', $record, 'Planned'), $errors, ['required' => true, 'col' => 'col-md-6', 'help' => 'Overdue diberikan otomatis oleh sistem.']) ?>
                    <?= Form::textarea('result', 'Hasil', old('result', $record), $errors, ['rows' => 2, 'maxlength' => 5000]) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 5000]) ?>
                    <?= Form::checkbox('reminder', 'Kirim notifikasi pengingat ke PIC', old('reminder', $record, '1') === '1', $errors) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('followups.delete')): ?>
                    <button type="submit" form="delete-fu" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light"><?= can($isEdit ? 'followups.edit' : 'followups.create') ? 'Batal' : 'Kembali' ?></a>
                <?php if (can($isEdit ? 'followups.edit' : 'followups.create')): ?>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                <?php else: ?>
                    <span class="small text-secondary align-self-center">Mode lihat saja</span>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($isEdit && can('followups.delete')): ?>
            <form id="delete-fu" method="post" action="<?= e(url('/follow-ups/' . $followup['id'] . '/delete')) ?>" data-confirm="Hapus follow up ini?">
                <?= csrf_field() ?><?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($isEdit && !$closed && can('followups.edit')): ?>
        <div class="col-xl-4">
            <form class="surface section-gap" method="post" action="<?= e(url('/follow-ups/' . $followup['id'] . '/done')) ?>">
                <?= csrf_field() ?><?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
                <div class="surface-header"><div><h2 class="surface-title">Tandai selesai</h2><p class="surface-subtitle">Catat hasil dan jadwalkan lanjutan bila perlu.</p></div></div>
                <div class="surface-body">
                    <label class="form-label" for="done_result">Hasil</label>
                    <textarea class="form-control mb-3" id="done_result" name="result" rows="3" maxlength="5000" placeholder="mis. Customer setuju, menunggu PO"></textarea>
                    <label class="form-label" for="done_next">Follow up lanjutan (opsional)</label>
                    <input type="date" class="form-control mb-2" id="done_next" name="next_follow_up" min="<?= e($today) ?>">
                    <input type="text" class="form-control" name="next_purpose" maxlength="255" placeholder="Tujuan follow up lanjutan" aria-label="Tujuan follow up lanjutan">
                </div>
                <div class="form-actions"><button class="btn btn-success-soft" type="submit"><i class="bi bi-check2-circle"></i> Selesai</button></div>
            </form>
            <form class="surface" method="post" action="<?= e(url('/follow-ups/' . $followup['id'] . '/reschedule')) ?>">
                <?= csrf_field() ?><?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
                <div class="surface-header"><h2 class="surface-title">Jadwalkan ulang</h2></div>
                <div class="surface-body">
                    <label class="form-label" for="rs_date">Tanggal baru</label>
                    <input type="date" class="form-control mb-2" id="rs_date" name="new_date" min="<?= e($today) ?>" required>
                    <input type="text" class="form-control" name="note" maxlength="500" placeholder="Alasan (opsional)" aria-label="Alasan jadwal ulang">
                </div>
                <div class="form-actions"><button class="btn btn-light" type="submit"><i class="bi bi-calendar-event"></i> Jadwalkan ulang</button></div>
            </form>
        </div>
    <?php endif; ?>
</div>
