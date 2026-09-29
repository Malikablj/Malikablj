<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed>|null $ret
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<string,array<int,string>> $lineOptions
 * @var array<int,string> $deliveries
 * @var array<string,string> $reasons
 * @var string $return
 */
$isEdit = $ret !== null;
$record = $ret ?? $preset;
$cancel = $return !== '' ? to($return) : url('/returns');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/returns')) ?>">Returns</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($ret['code']) : 'Catat' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Retur' : 'Catat Retur' ?></h1>
    <p class="page-subtitle">Retur menambah outstanding baris PO (Outstanding = Order − Delivered + Return).</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/returns/' . $ret['id'] : '/returns')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <?php if ($isEdit && $ret['po_line_id'] === null): ?>
                <div class="form-section"><div class="callout callout-warning small"><i class="bi bi-exclamation-triangle me-1"></i>
                    Retur legacy ini belum terhubung ke baris PO<?= $ret['product_legacy'] ? ' (produk di spreadsheet: <strong>' . e($ret['product_legacy']) . '</strong>)' : '' ?>. Pilih baris PO agar retur ikut dihitung.</div></div>
            <?php endif; ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::select('po_line_id', 'Baris PO (PO · produk)', $lineOptions, old('po_line_id', $record), $errors, [
                        'required' => !$isEdit || $ret['po_line_id'] !== null, 'placeholder' => '— Pilih PO & produk —', 'searchable' => 'Cari nomor PO, customer, atau produk…',
                    ]) ?>
                    <?php if ($deliveries): ?>
                        <?= Form::select('delivery_id', 'Surat jalan asal (opsional)', $deliveries, old('delivery_id', $record), $errors, ['placeholder' => '— Tidak dipilih —']) ?>
                    <?php endif; ?>
                    <?= Form::input('return_date', 'Tanggal retur', old('return_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('return_qty', 'Qty retur (pcs)', old('return_qty', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::select('reason', 'Alasan', $reasons, old('reason', $record), $errors, ['required' => true, 'placeholder' => '— Pilih alasan —', 'col' => 'col-md-4']) ?>
                    <?= Form::input('sj_number', 'Nomor dokumen retur', old('sj_number', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-6']) ?>
                    <?= Form::input('destination', 'Asal / lokasi', old('destination', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-6']) ?>
                    <?= Form::input('attachment', 'Link lampiran (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500]) ?>
                    <?= Form::textarea('note', 'Catatan', old('note', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('returns.delete')): ?>
                    <button type="submit" form="delete-return" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan retur</button>
            </div>
        </form>
        <?php if ($isEdit && can('returns.delete')): ?>
            <form id="delete-return" method="post" action="<?= e(url('/returns/' . $ret['id'] . '/delete')) ?>" data-confirm="Hapus retur ini?"><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
</div>
