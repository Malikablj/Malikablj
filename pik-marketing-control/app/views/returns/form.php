<?php

use App\Helpers\Form;
use App\Models\ProductReturn;
use App\Models\ReturnAttachment;

/**
 * @var array<string,mixed>|null $ret
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<string,array<int,string>> $lineOptions
 * @var array<int,string> $deliveries
 * @var array<string,string> $reasons
 * @var list<array<string,mixed>> $files
 * @var string $return
 */
$isEdit = $ret !== null;
$record = $ret ?? $preset;
$cancel = $return !== '' ? to($return) : ($isEdit ? url('/returns/' . $ret['id']) : url('/returns'));
$type = old('case_type', $record, 'Komplain');
$maxMb = (int) config('app.upload.max_evidence_mb', 5);
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/returns')) ?>">Retur &amp; Komplain</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e(url('/returns/' . $ret['id'])) ?>"><?= e($ret['code']) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Catat</span><?php endif; ?></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit ' . e($ret['case_type']) : 'Catat Retur / Komplain' ?></h1>
    <p class="page-subtitle">Retur menambah outstanding produk OEF (Outstanding = Order − Terkirim + Retur). Komplain tanpa barang kembali tidak mengubah outstanding.</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/returns/' . $ret['id'] : '/returns')) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <?php if ($isEdit && $ret['po_line_id'] === null): ?>
                <div class="form-section"><div class="callout callout-warning small"><i class="bi bi-exclamation-triangle me-1"></i>
                    Data legacy ini belum terhubung ke produk OEF<?= $ret['product_legacy'] ? ' (produk di spreadsheet: <strong>' . e($ret['product_legacy']) . '</strong>)' : '' ?>. Pilih produk OEF agar ikut dihitung.</div></div>
            <?php endif; ?>
            <div class="form-section">
                <div class="form-section-title">Jenis kasus</div>
                <div class="d-grid gap-2" role="radiogroup" aria-label="Jenis kasus">
                    <?php foreach (ProductReturn::CASE_TYPES as $t): ?>
                        <label class="form-check border rounded-3 px-3 py-2 ps-5 mb-0">
                            <input class="form-check-input" type="radio" name="case_type" value="<?= e($t) ?>"<?= $type === $t ? ' checked' : '' ?> data-case-type>
                            <span class="form-check-label"><?= e(ProductReturn::CASE_HELP[$t]) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?= field_error($errors, 'case_type') ?>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Detail</div>
                <div class="row g-3">
                    <?= Form::select('po_line_id', 'Produk OEF (OEF · produk)', $lineOptions, old('po_line_id', $record), $errors, [
                        'required' => !$isEdit || $ret['po_line_id'] !== null, 'placeholder' => '— Pilih OEF & produk —', 'searchable' => 'Cari no order, no PO, customer, atau produk…',
                    ]) ?>
                    <?php if ($deliveries): ?>
                        <?= Form::select('delivery_id', 'Surat jalan asal (opsional)', $deliveries, old('delivery_id', $record), $errors, ['placeholder' => '— Tidak dipilih —']) ?>
                    <?php endif; ?>
                    <?= Form::input('return_date', 'Tanggal', old('return_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::select('reason', 'Alasan', $reasons, old('reason', $record), $errors, ['required' => true, 'placeholder' => '— Pilih alasan —', 'col' => 'col-md-4']) ?>
                    <?= Form::input('qty', 'Qty (pcs)', old('qty', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'col' => 'col-md-4', 'inputmode' => 'numeric',
                        'help' => 'Retur: qty barang kembali (wajib). Komplain: qty bermasalah (opsional).']) ?>
                    <?= Form::textarea('note', 'Detail masalah / catatan', old('note', $record), $errors, ['rows' => 3, 'maxlength' => 5000,
                        'help' => 'Wajib untuk komplain: jelaskan masalah yang dilaporkan customer.']) ?>
                    <?= Form::input('sj_number', 'Nomor dokumen retur', old('sj_number', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-6']) ?>
                    <?= Form::input('destination', 'Asal / lokasi', old('destination', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-6']) ?>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Bukti komplain</div>
                <div class="form-section-desc">Foto (JPG, PNG, WEBP) atau PDF, maksimal <?= $maxMb ?> MB per file dan <?= ReturnAttachment::maxFiles() ?> file per kasus.</div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="f_evidence"><?= $isEdit ? 'Tambah file bukti' : 'File bukti' ?></label>
                        <input class="form-control<?= invalid($errors, 'evidence') ?>" type="file" id="f_evidence" name="evidence[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf">
                        <?= field_error($errors, 'evidence') ?>
                        <?php if ($isEdit && $files): ?><div class="form-text"><?= count($files) ?> file sudah tersimpan. Hapus file dari halaman detail.</div><?php endif; ?>
                    </div>
                    <?= Form::input('attachment', 'Link dokumen (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://…']) ?>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Email QC</div>
                <div class="row g-3">
                    <?= Form::input('qc_email', 'Email QC', old('qc_email', $record), $errors, ['maxlength' => 500, 'col' => 'col-md-8', 'placeholder' => 'qc@permataindokemas.com',
                        'help' => 'Hasil komplain dikirim ke email ini. Beberapa email pisahkan dengan koma.']) ?>
                    <div class="col-md-4 d-flex align-items-end">
                        <?= Form::checkbox('send_email', $isEdit ? 'Kirim ulang ke QC setelah disimpan' : 'Kirim ke QC setelah disimpan', old('send_email', $record, $isEdit ? '0' : '1') === '1', $errors, ['col' => 'w-100 pb-2']) ?>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('returns.delete')): ?>
                    <button type="submit" form="delete-return" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
        <?php if ($isEdit && can('returns.delete')): ?>
            <form id="delete-return" method="post" action="<?= e(url('/returns/' . $ret['id'] . '/delete')) ?>" data-confirm="Hapus data ini beserta file buktinya?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
</div>
