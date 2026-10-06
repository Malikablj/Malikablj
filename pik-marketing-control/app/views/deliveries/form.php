<?php

use App\Helpers\Form;
use App\Models\Delivery;

/**
 * @var array<string,mixed>|null $delivery
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<string,array<int,string>> $lineOptions
 * @var array<string,mixed>|null $line
 * @var string $return
 */
$isEdit = $delivery !== null;
$record = $delivery ?? $preset;
$statusOptions = [];
foreach (Delivery::STATUSES as $s) {
    $statusOptions[$s] = $s . ' — ' . Delivery::STATUS_HELP[$s];
}
$cancel = $return !== '' ? to($return) : ($isEdit ? url('/deliveries/' . $delivery['id']) : url('/deliveries'));
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/deliveries')) ?>">Deliveries</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($delivery['sj_number'] ?? $delivery['code']) : 'Catat' ?></span></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Delivery' : 'Catat Delivery' ?></h1>
    <p class="page-subtitle">Delivery selalu terhubung ke produk di OEF sehingga outstanding terhitung otomatis. Ubah tanggal bila jadwal kirim berubah.</p></div></div>

<div class="row g-4">
    <div class="col-xl-8">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/deliveries/' . $delivery['id'] : '/deliveries')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <div class="form-section">
                <div class="row g-3">
                    <?php if (!$lineOptions): ?>
                        <div class="col-12"><div class="callout callout-warning small">Belum ada OEF berjalan yang sudah disetujui PPIC.</div></div>
                    <?php endif; ?>
                    <?= Form::select('po_line_id', 'Produk OEF (OEF · produk)', $lineOptions, old('po_line_id', $record), $errors, [
                        'required' => !$isEdit || $delivery['po_line_id'] !== null, 'placeholder' => $isEdit && $delivery['po_line_id'] === null ? '— Belum terhubung (legacy) —' : '— Pilih OEF & produk —',
                        'searchable' => 'Cari no order, no PO, customer, atau produk…', 'help' => 'Hanya OEF yang sudah disetujui PPIC dan masih berjalan.',
                    ]) ?>
                    <?= Form::input('delivery_date', 'Tanggal delivery', old('delivery_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-4']) ?>
                    <?php if (can('deliveries.sj')): ?>
                        <?= Form::input('sj_number', 'Nomor surat jalan', old('sj_number', $record), $errors, ['maxlength' => 60, 'col' => 'col-md-4', 'placeholder' => 'mis. PIK-SJ-03698']) ?>
                    <?php else: ?>
                        <?= Form::input('sj_number_view', 'Nomor surat jalan', (string) ($record['sj_number'] ?? ''), [], ['col' => 'col-md-4', 'disabled' => true, 'placeholder' => 'Diisi PPIC', 'help' => 'Hanya PPIC yang dapat mengisi Surat Jalan.']) ?>
                    <?php endif; ?>
                    <?= Form::input('delivered_qty', 'Qty (pcs)', old('delivered_qty', $record), $errors, ['type' => 'number', 'min' => 1, 'step' => 1, 'required' => true, 'col' => 'col-md-4', 'inputmode' => 'numeric']) ?>
                    <?= Form::select('status', 'Status', $statusOptions, old('status', $record, 'Delivered'), $errors, ['required' => true, 'col' => 'col-md-6']) ?>
                    <?= Form::input('destination', 'Tujuan pengiriman', old('destination', $record), $errors, ['maxlength' => 255, 'col' => 'col-md-6']) ?>
                    <?= Form::input('attachment', 'Link BAST / QC (opsional)', old('attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://…']) ?>
                    <?= Form::textarea('note', 'Catatan', old('note', $record), $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
                    <?php if (isset($errors['delivered_qty']) && str_contains($errors['delivered_qty'], 'outstanding')): ?>
                        <?= Form::checkbox('confirm_over_delivery', 'Konfirmasi kelebihan kirim (over delivery)', false, $errors) ?>
                    <?php else: ?>
                        <input type="hidden" name="confirm_over_delivery" value="<?= e(old('confirm_over_delivery', null, '0')) ?>">
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($isEdit && can('deliveries.delete')): ?>
                    <button type="submit" form="delete-delivery" class="btn btn-danger-soft me-auto"><i class="bi bi-trash"></i> Hapus</button>
                <?php endif; ?>
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan delivery</button>
            </div>
        </form>
        <?php if ($isEdit && can('deliveries.delete')): ?>
            <form id="delete-delivery" method="post" action="<?= e(url('/deliveries/' . $delivery['id'] . '/delete')) ?>" data-confirm="Hapus delivery ini? Outstanding OEF akan dihitung ulang."><?= csrf_field() ?></form>
        <?php endif; ?>
    </div>
    <?php if ($line): ?>
        <div class="col-xl-4">
            <div class="surface surface-pad">
                <h2 class="surface-title mb-3">Produk OEF terpilih</h2>
                <dl class="dl-grid dl-single">
                    <div><dt>OEF</dt><dd><a href="<?= e(url('/purchase-orders/' . $line['po_id'])) ?>"><?= e($line['order_number'] ?: ($line['po_number'] ?? $line['po_code'])) ?></a> · <?= e($line['customer_name'] ?? '') ?></dd></div>
                    <div><dt>Produk</dt><dd><?= e($line['product_name']) ?></dd></div>
                    <div><dt>Order / Terkirim / Retur</dt><dd class="tabular"><?= e(fmt_qty($line['order_qty'])) ?> / <?= e(fmt_qty($line['delivered_qty'], '0')) ?> / <?= e(fmt_qty($line['return_qty'], '0')) ?></dd></div>
                    <div><dt>Outstanding</dt><dd class="fw-semibold fs-5"><?= e(fmt_qty($line['outstanding_qty'], '0')) ?> pcs</dd></div>
                </dl>
            </div>
        </div>
    <?php endif; ?>
</div>
