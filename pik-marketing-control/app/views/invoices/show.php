<?php

use App\Helpers\Form;
use App\Helpers\Number;

/**
 * @var array<string,mixed> $invoice
 * @var list<array<string,mixed>> $payments
 * @var list<array<string,mixed>> $issues
 * @var list<array<string,mixed>> $history
 * @var array<string,string> $errors
 */
$id = (int) $invoice['id'];
$base = '/invoices/' . $id;
$amountC = Number::toCents($invoice['invoice_amount']) ?? 0;
$paidC = Number::toCents($invoice['paid_amount']) ?? 0;
$remainingC = max(0, $amountC - $paidC);
$progress = $amountC > 0 ? min(100, (int) floor($paidC * 100 / $amountC)) : 0;
$overdue = $invoice['status'] === 'Overdue';
$recordedC = 0;
foreach ($payments as $p) {
    $recordedC += Number::toCents($p['amount']) ?? 0;
}
$beforeAppC = max(0, $paidC - $recordedC);
$errors = $errors ?? [];
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/invoices')) ?>">Invoice &amp; Payment</a><i class="bi bi-chevron-right"></i><span><?= e($invoice['invoice_number'] ?? $invoice['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-receipt-cutoff"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($invoice['invoice_number'] ?? '(tanpa nomor)') ?> <?= status_badge($invoice['status']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($invoice['code']) ?></span>
                <?php if ($invoice['customer_id']): ?><span><i class="bi bi-buildings"></i><a href="<?= e(url('/customers/' . $invoice['customer_id'])) ?>"><?= e($invoice['customer_name']) ?></a></span>
                <?php else: ?><span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span><?php endif; ?>
                <?php if ($invoice['po_id']): ?><span><i class="bi bi-receipt"></i><?php if (can('purchase_orders.view')): ?><a href="<?= e(url('/purchase-orders/' . $invoice['po_id'])) ?>"><?= e($invoice['po_number'] ?? $invoice['po_code']) ?></a><?php else: ?><?= e($invoice['po_number'] ?? $invoice['po_code']) ?><?php endif; ?></span>
                <?php elseif ($invoice['po_number_legacy']): ?><span title="Nomor PO di spreadsheet, belum terhubung"><i class="bi bi-receipt"></i><?= e($invoice['po_number_legacy']) ?></span><?php endif; ?>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($invoice['invoice_date'], 'Tanpa tanggal')) ?></span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('finance.edit')): ?><a class="btn btn-light" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
        <?php if (can('finance.delete')): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-icon" type="button" data-bs-toggle="dropdown" aria-label="Aksi lain"><i class="bi bi-three-dots"></i></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="Hapus invoice ini? Invoice yang sudah dibayar tidak dapat dihapus.">
                        <?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus invoice</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Nilai invoice</div><div class="stat-value"><?= e(fmt_money($invoice['invoice_amount'])) ?></div></div>
    <div><div class="stat-label">Dibayar</div><div class="stat-value"><?= e(fmt_money($invoice['paid_amount'])) ?></div>
        <div class="progress-thin mt-2<?= $progress >= 100 ? ' is-done' : '' ?>"><span style="width: <?= $progress ?>%"></span></div></div>
    <div><div class="stat-label">Sisa tagihan</div><div class="stat-value<?= $overdue ? ' text-danger' : '' ?>"><?= e(fmt_money(Number::fromCents($remainingC))) ?></div></div>
    <div><div class="stat-label">Jatuh tempo</div><div class="stat-value<?= $overdue ? ' text-danger' : '' ?>"><?= e(fmt_date($invoice['due_date'], 'Belum diisi')) ?></div>
        <?php if ($invoice['due_date'] && $remainingC > 0): ?><div class="x-small text-secondary"><?= e(relative_day($invoice['due_date'])) ?></div><?php endif; ?></div>
</div>

<div class="row g-4">
    <div class="col-lg-7 min-w-0">
        <?php if (can('finance.edit') && $remainingC > 0): ?>
            <form class="surface section-gap" method="post" action="<?= e(url($base . '/payments')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="surface-header"><div><h2 class="surface-title">Catat pembayaran</h2><p class="surface-subtitle">Maksimal sebesar sisa tagihan <?= e(fmt_money(Number::fromCents($remainingC))) ?>.</p></div></div>
                <div class="form-section">
                    <div class="row g-3">
                        <?= Form::input('amount', 'Jumlah dibayar', old('amount', null, Number::decimal(Number::fromCents($remainingC), 2)), $errors, ['required' => true, 'prefix' => 'Rp', 'inputmode' => 'decimal', 'col' => 'col-md-6']) ?>
                        <?= Form::input('payment_date', 'Tanggal pembayaran', old('payment_date', null, today()), $errors, ['type' => 'date', 'required' => true, 'max' => today(), 'col' => 'col-md-6']) ?>
                        <?= Form::input('payment_receipt_number', 'Nomor bukti bayar', old('payment_receipt_number'), $errors, ['maxlength' => 80, 'col' => 'col-md-6']) ?>
                        <?= Form::input('payment_attachment', 'Link bukti bayar (opsional)', old('payment_attachment'), $errors, ['type' => 'url', 'maxlength' => 500, 'col' => 'col-md-6']) ?>
                        <?= Form::input('note', 'Catatan', old('note'), $errors, ['maxlength' => 500]) ?>
                    </div>
                </div>
                <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Simpan pembayaran</button></div>
            </form>
        <?php elseif ($remainingC <= 0 && $amountC > 0): ?>
            <div class="callout callout-success section-gap"><i class="bi bi-check2-circle me-1"></i> Invoice ini sudah lunas<?= $invoice['payment_date'] ? ' (pembayaran terakhir ' . e(fmt_date($invoice['payment_date'])) . ')' : '' ?>.</div>
        <?php endif; ?>

        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Riwayat pembayaran</h2></div>
            <?php if (!$payments && $beforeAppC === 0): ?>
                <div class="empty-inline">Belum ada pembayaran.</div>
            <?php else: ?>
                <ul class="list-lite">
                    <?php foreach ($payments as $p): ?>
                        <li><div class="li-main"><span class="li-title"><?= e(fmt_money($p['amount'])) ?></span>
                            <div class="li-sub"><?= e(fmt_date($p['payment_date'])) ?><?= $p['receipt'] ? ' · bukti ' . e($p['receipt']) : '' ?><?= $p['note'] ? ' · ' . e($p['note']) : '' ?></div></div>
                            <div class="li-end text-secondary x-small">dicatat <?= e(fmt_datetime($p['recorded_at'])) ?><div><?= e($p['user_name'] ?? '') ?></div></div></li>
                    <?php endforeach; ?>
                    <?php if ($beforeAppC > 0): ?>
                        <li><div class="li-main"><span class="li-title"><?= e(fmt_money(Number::fromCents($beforeAppC))) ?></span>
                            <div class="li-sub">Pembayaran dari data impor/koreksi<?= $invoice['payment_date'] ? ' · tanggal terakhir ' . e(fmt_date($invoice['payment_date'])) : '' ?><?= $invoice['payment_receipt_number'] ? ' · bukti ' . e($invoice['payment_receipt_number']) : '' ?></div></div>
                            <div class="li-end text-secondary x-small">sebelum dicatat di aplikasi</div></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-5 min-w-0">
        <section class="surface surface-pad section-gap small">
            <dl class="dl-single mb-0">
                <dt>Termin PO</dt><dd><?= e($invoice['payment_term'] ?? '—') ?></dd>
                <dt>Bukti bayar terakhir</dt><dd><?= e($invoice['payment_receipt_number'] ?? '—') ?></dd>
                <dt>File invoice</dt><dd><?= external_link($invoice['invoice_attachment'], 'Buka file invoice') ?></dd>
                <dt>Bukti transfer</dt><dd><?= external_link($invoice['payment_attachment'], 'Buka bukti bayar') ?></dd>
                <dt>Catatan</dt><dd><?= $invoice['notes'] ? nl2br(e($invoice['notes'])) : '—' ?></dd>
                <?php if ($invoice['legacy_invoice_outstanding'] !== null): ?>
                    <dt>Sisa di spreadsheet lama</dt><dd><?= e(fmt_money($invoice['legacy_invoice_outstanding'])) ?><?= Number::toCents($invoice['legacy_invoice_outstanding']) !== ($amountC - $paidC) ? ' <span class="badge-soft badge-soft-warning no-dot">berbeda</span>' : '' ?></dd>
                <?php endif; ?>
                <?php if ($invoice['source_file']): ?><dt>Sumber data</dt><dd class="text-secondary"><?= e($invoice['source_file']) ?> › <?= e($invoice['source_sheet']) ?> baris <?= (int) $invoice['legacy_row'] ?></dd><?php endif; ?>
            </dl>
        </section>
        <?php if ($issues): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Migration issue</h2></div>
                <ul class="list-lite">
                    <?php foreach ($issues as $i): ?>
                        <li><div class="li-main"><span class="li-title text-wrap"><?= e($i['issue_type']) ?></span><div class="li-sub text-wrap"><?= e(excerpt($i['description'] ?? '', 160)) ?></div></div>
                            <div class="li-end"><?= status_badge($i['resolution_status']) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php if ($history): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Riwayat perubahan</h2></div>
                <ul class="list-lite">
                    <?php foreach ($history as $h): $changes = json_decode((string) $h['changes'], true); ?>
                        <li><div class="li-main"><span class="li-title"><?= e(ucfirst(str_replace('_', ' ', (string) $h['action']))) ?> oleh <?= e($h['user_name'] ?? 'sistem') ?></span>
                            <div class="li-sub"><?= is_array($changes) ? e(implode(', ', array_slice(array_keys($changes), 0, 5))) : '' ?></div></div>
                            <div class="li-end text-secondary x-small"><?= e(fmt_datetime($h['created_at'])) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
