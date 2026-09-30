<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed>|null $invoice
 * @var array<string,string> $errors
 * @var array<string,mixed> $preset
 * @var array<int,string> $customers
 * @var array<int,string> $pos
 * @var int $dueDays
 */
$isEdit = $invoice !== null;
$record = $invoice ?? $preset;
$cancel = $isEdit ? url('/invoices/' . $invoice['id']) : url('/invoices');
$legacyNoCustomer = $isEdit && $invoice['customer_id'] === null;
if ($isEdit) {
    // Tampilkan format Indonesia (15.000.000,5); server menerima format Indonesia maupun 15000000.50
    foreach (['invoice_amount', 'paid_amount'] as $k) {
        $record[$k] = $record[$k] !== null ? App\Helpers\Number::decimal($record[$k], 2) : null;
    }
}
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/invoices')) ?>">Invoice &amp; Payment</a><i class="bi bi-chevron-right"></i>
    <?php if ($isEdit): ?><a href="<?= e($cancel) ?>"><?= e($invoice['invoice_number'] ?? $invoice['code']) ?></a><i class="bi bi-chevron-right"></i><span>Edit</span><?php else: ?><span>Buat</span><?php endif; ?></div>
<div class="page-header"><div><h1 class="page-title"><?= $isEdit ? 'Edit Invoice' : 'Buat Invoice' ?></h1>
    <p class="page-subtitle"><?= $isEdit ? 'Pembayaran baru dicatat dari halaman detail invoice, bukan dari form ini.' : 'Setelah invoice dibuat, catat pembayaran dari halaman detail invoice.' ?></p></div></div>

<div class="row">
    <div class="col-xl-9">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/invoices/' . $invoice['id'] : '/invoices')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="form-section-title">Customer &amp; PO</div>
                <div class="row g-3">
                    <?= Form::select('customer_id', 'Customer', $customers, old('customer_id', $record), $errors, [
                        'required' => !$legacyNoCustomer, 'placeholder' => '— Pilih customer —', 'searchable' => 'Cari customer…', 'col' => 'col-md-6',
                    ]) ?>
                    <?= Form::select('po_id', 'PO (opsional)', $pos, old('po_id', $record), $errors, [
                        'placeholder' => '— Tanpa PO —', 'searchable' => 'Cari nomor PO…', 'col' => 'col-md-6',
                        'help' => 'PO harus milik customer yang sama. Termin NET n pada PO dipakai untuk jatuh tempo otomatis.',
                    ]) ?>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Invoice</div>
                <div class="row g-3">
                    <?= Form::input('invoice_number', 'Nomor invoice', old('invoice_number', $record), $errors, ['required' => true, 'maxlength' => 80, 'col' => 'col-md-6']) ?>
                    <?= Form::input('invoice_amount', 'Nilai invoice', old('invoice_amount', $record), $errors, ['required' => true, 'col' => 'col-md-6', 'prefix' => 'Rp', 'inputmode' => 'decimal',
                        'placeholder' => 'mis. 12.500.000', 'help' => 'Termasuk PPN bila ditagihkan.']) ?>
                    <?= Form::input('invoice_date', 'Tanggal invoice', old('invoice_date', $record), $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-6']) ?>
                    <?= Form::input('due_date', 'Jatuh tempo', old('due_date', $record), $errors, ['type' => 'date', 'col' => 'col-md-6',
                        'help' => 'Kosongkan untuk dihitung otomatis: termin PO NET n → n hari, CBD/COD → hari yang sama, selain itu ' . $dueDays . ' hari.']) ?>
                    <?php if ($isEdit): ?>
                        <?= Form::input('paid_amount', 'Total dibayar (koreksi)', old('paid_amount', $record), $errors, ['required' => true, 'col' => 'col-md-6', 'prefix' => 'Rp', 'inputmode' => 'decimal',
                            'help' => 'Ubah hanya untuk mengoreksi salah input. Perubahan tercatat di audit log.']) ?>
                    <?php endif; ?>
                    <?= Form::input('invoice_attachment', 'Link file invoice (opsional)', old('invoice_attachment', $record), $errors, ['type' => 'url', 'maxlength' => 500, 'col' => $isEdit ? 'col-md-6' : 'col-12']) ?>
                    <?= Form::textarea('notes', 'Catatan', old('notes', $record), $errors, ['rows' => 2, 'maxlength' => 5000]) ?>
                </div>
            </div>
            <div class="form-actions">
                <a href="<?= e($cancel) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Simpan invoice' ?></button>
            </div>
        </form>
    </div>
</div>
