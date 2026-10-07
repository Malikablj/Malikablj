<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed>|null $row
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<string,array<int,string>> $lineOptions
 * @var array<string,string> $statuses
 * @var list<array<string,mixed>> $issues
 * @var string $return
 */
$isEdit = $row !== null;
$record = array_merge($row ?? [], $preset);
$lineOptional = $isEdit && ($preset['po_line_id'] ?? null) === null;
$cancel = $return !== '' ? to($return) : url('/lead-times');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/lead-times')) ?>">Lead Time</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($row['code']) : 'Tambah' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Lead Time' : 'Tambah Estimasi Lead Time' ?></h1>
    <p class="page-subtitle">Estimasi tanggal barang siap dikirim ke customer untuk satu baris PO.</p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/lead-times/' . $row['id'] : '/lead-times')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <?php if ($lineOptional || $issues): ?>
                <div class="form-section">
                    <?php if ($lineOptional): ?>
                        <div class="callout callout-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>
                            Lead time legacy ini belum terhubung ke baris PO
                            <?php if ($row['po_number_legacy'] || $row['product_legacy']): ?>(di spreadsheet: <strong><?= e(trim(($row['po_number_legacy'] ?? '') . ' · ' . ($row['product_legacy'] ?? ''), ' ·')) ?></strong>)<?php endif; ?>.
                            Pilih baris PO bila diketahui.</div>
                    <?php endif; ?>
                    <?php foreach ($issues as $i): ?>
                        <div class="small text-secondary"><?= status_badge($i['resolution_status']) ?> <strong><?= e($i['issue_type']) ?></strong> — <?= e(excerpt($i['description'] ?? '', 160)) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::select('po_line_id', 'Baris PO (PO · produk)', $lineOptions, old('po_line_id', $record), $errors, [
                        'required' => !$lineOptional, 'placeholder' => '— Pilih PO & produk —', 'searchable' => 'Cari nomor PO, customer, atau produk…',
                        'help' => 'Hanya PO terbuka (Open, On Process, Partial) yang ditampilkan.',
                    ]) ?>
                    <?= Form::input('delivery_date', 'Estimasi tanggal delivery', old('delivery_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::input('quantity', 'Qty (pcs)', old('quantity', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'col' => 'col-md-4']) ?>
                    <?= Form::select('status', 'Status', $statuses, old('status', $record, 'Planned'), $errors, ['required' => true, 'col' => 'col-md-4']) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('leadtime.delete')): ?>
                    <button type="submit" form="delete-leadtime" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
        <?php if ($isEdit && can('leadtime.delete')): ?>
            <form id="delete-leadtime" method="post" action="<?= e(url('/lead-times/' . $row['id'] . '/delete')) ?>" data-confirm="Hapus estimasi lead time ini?">
                <?= csrf_field() ?><?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
