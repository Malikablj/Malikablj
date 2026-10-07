<?php

use App\Helpers\Form;
use App\Models\ComplaintAttachment;
use App\Models\ProductReturn;

/**
 * Complaint & Return — catat / edit.
 *
 * @var array<string,mixed>|null $ret
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<int,string> $customers
 * @var array<string,array<int,string>> $lineOptions
 * @var array<int,string> $deliveries
 * @var array<string,string> $reasons
 * @var list<array<string,mixed>> $attachments
 * @var int $maxBytes
 * @var string $return
 */
$isEdit = $ret !== null;
$record = $ret ?? $preset;
$cancel = $return !== '' ? to($return) : url($isEdit ? '/returns/' . $ret['id'] : '/returns');
$legacyUnlinked = $isEdit && $ret['po_line_id'] === null && $ret['record_type'] === 'Return';
$slotsLeft = max(0, ComplaintAttachment::MAX_FILES - count($attachments));
$postMax = (string) ini_get('post_max_size');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/returns')) ?>">Complaint &amp; Return</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e(url('/returns/' . $ret['id'])) ?>"><?= e($ret['code']) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Catat</span><?php endif; ?></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Complaint ' . e($ret['code']) : 'Catat Complaint' ?></h1>
    <p class="page-subtitle">Pilih <strong>Complaint</strong> bila hanya keluhan, atau <strong>Complaint + retur barang</strong> bila barang dikembalikan (qty retur menambah outstanding order).</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/returns/' . $ret['id'] : '/returns')) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <?php if ($legacyUnlinked): ?>
                <div class="form-section"><div class="callout callout-warning small"><i class="bi bi-exclamation-triangle me-1"></i>
                    Retur legacy ini belum terhubung ke baris order<?= $ret['product_legacy'] ? ' (produk di spreadsheet: <strong>' . e($ret['product_legacy']) . '</strong>)' : '' ?>. Pilih order &amp; produk agar retur ikut dihitung.</div></div>
            <?php endif; ?>
            <div class="form-section">
                <div class="form-section-title">Complaint</div>
                <div class="row g-3">
                    <?= Form::select('record_type', 'Jenis', ProductReturn::TYPE_LABELS, old('record_type', $record, 'Complaint'), $errors, ['required' => true, 'col' => 'col-md-6', 'id' => 'f_record_type']) ?>
                    <?= Form::input('return_date', 'Tanggal complaint', old('return_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-3']) ?>
                    <?= Form::select('reason', 'Alasan', $reasons, old('reason', $record), $errors, ['required' => true, 'placeholder' => '— Pilih —', 'col' => 'col-md-3']) ?>
                    <?= Form::select('customer_id', 'Customer', $customers, old('customer_id', $record), $errors, ['placeholder' => '— Pilih customer —', 'searchable' => 'Cari customer…', 'col' => 'col-md-6',
                        'help' => 'Otomatis mengikuti order bila order dipilih.']) ?>
                    <?= Form::select('po_line_id', 'Order & produk', $lineOptions, old('po_line_id', $record), $errors, [
                        'placeholder' => '— Pilih order & produk —', 'searchable' => 'Cari no. order, customer, atau produk…', 'col' => 'col-md-6',
                        'help' => 'Wajib untuk retur barang; opsional untuk complaint.',
                    ]) ?>
                    <?= Form::textarea('complaint_detail', 'Detail complaint', old('complaint_detail', $record), $errors, ['rows' => 4, 'maxlength' => 5000, 'required' => !$isEdit,
                        'placeholder' => 'Apa yang dikeluhkan customer: kondisi barang, jumlah terdampak, kronologi, permintaan customer…']) ?>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Bukti complaint</div>
                <div class="form-section-desc">Foto (JPG, PNG, GIF, WEBP) atau PDF, maks. <?= e(ComplaintAttachment::human($maxBytes)) ?> per file, <?= ComplaintAttachment::MAX_FILES ?> file per complaint<?= $postMax !== '' ? ' (total sekali upload maks. ' . e($postMax) . 'B)' : '' ?>.</div>
                <?php if ($attachments): ?>
                    <div class="small text-secondary mb-2"><?= count($attachments) ?> file tersimpan — kelola dari halaman detail complaint.</div>
                <?php endif; ?>
                <?php if ($slotsLeft > 0): ?>
                    <input type="file" class="form-control<?= invalid($errors, 'evidence') ?>" id="f_evidence" name="evidence[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,image/jpeg,image/png,image/gif,image/webp,application/pdf"
                           data-max-bytes="<?= (int) $maxBytes ?>" data-file-list="#evidence-list" aria-describedby="evidence-help">
                    <?= field_error($errors, 'evidence') ?>
                    <ul class="file-list" id="evidence-list"></ul>
                    <div class="form-text" id="evidence-help">Bisa memilih beberapa file sekaligus.<?= isset($errors['evidence']) || ($errors !== [] && !$isEdit) ? ' File perlu dipilih ulang bila form dikirim ulang.' : '' ?></div>
                <?php else: ?>
                    <div class="small text-secondary">Batas <?= ComplaintAttachment::MAX_FILES ?> file sudah tercapai.</div>
                <?php endif; ?>
            </div>

            <div class="form-section" data-return-only>
                <div class="form-section-title">Retur barang</div>
                <div class="row g-3">
                    <?= Form::input('return_qty', 'Qty retur (pcs)', old('return_qty', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'col' => 'col-md-4', 'help' => 'Menambah outstanding order.']) ?>
                    <?php if ($deliveries): ?>
                        <?= Form::select('delivery_id', 'Surat jalan asal (opsional)', $deliveries, old('delivery_id', $record), $errors, ['placeholder' => '— Tidak dipilih —', 'col' => 'col-md-8']) ?>
                    <?php endif; ?>
                    <?= Form::input('sj_number', 'Nomor dokumen retur', old('sj_number', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-6']) ?>
                    <?= Form::input('destination', 'Asal / lokasi barang', old('destination', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-6']) ?>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Notifikasi QC</div>
                <div class="row g-3">
                    <?= Form::input('qc_email', 'Email QC', old('qc_email', $record), $errors, ['maxlength' => 500, 'placeholder' => 'qc@permataindokemas.com',
                        'help' => 'Complaint dan hasilnya (Selesai / Tidak selesai) dikirim ke email ini. Pisahkan dengan koma untuk lebih dari satu.']) ?>
                    <?= Form::input('attachment', 'Link dokumen lain (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500, 'col' => 'col-md-6', 'placeholder' => 'https://drive.google.com/…']) ?>
                    <?= Form::input('note', 'Catatan internal', old('note', $record), $errors, ['maxlength' => 2000, 'col' => 'col-md-6']) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('returns.delete')): ?>
                    <button type="submit" form="delete-return" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan complaint' ?></button>
            </div>
        </form>
        <?php if ($isEdit && can('returns.delete')): ?>
            <form id="delete-return" method="post" action="<?= e(url('/returns/' . $ret['id'] . '/delete')) ?>" data-confirm="Hapus complaint ini beserta file buktinya?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
</div>
