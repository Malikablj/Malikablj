<?php

use App\Helpers\Form;

/**
 * @var array<string,string|null> $values
 * @var array<string,string> $errors
 * @var string|null $lastRun
 * @var array<string,mixed>|null $lastResult
 * @var array<string,mixed>|null $lastImport
 */
$labels = [
    'followup_due'      => 'Follow up hari ini',
    'followup_overdue'  => 'Follow up terlewat',
    'delivery_upcoming' => 'Delivery mendatang',
    'invoice_overdue'   => 'Invoice overdue',
    'lead_closing'      => 'Target closing lead',
];
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Pengaturan</h1>
        <p class="page-subtitle">Pengaturan umum aplikasi, keuangan, dan otomasi notifikasi.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7 min-w-0">
        <form class="surface" method="post" action="<?= e(url('/settings')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="form-section-title">Umum</div>
                <div class="row g-3">
                    <?= Form::input('company_name', 'Nama perusahaan', old('company_name', $values), $errors, ['required' => true, 'maxlength' => 120, 'help' => 'Tampil di kop laporan yang dicetak.']) ?>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Keuangan</div>
                <div class="row g-3">
                    <?= Form::input('invoice_default_due_days', 'Jatuh tempo default invoice', old('invoice_default_due_days', $values), $errors, ['type' => 'number', 'min' => 0, 'max' => 365, 'required' => true, 'col' => 'col-md-6', 'suffix' => 'hari',
                        'help' => 'Dipakai bila jatuh tempo dikosongkan dan termin PO bukan NET n / CBD / COD.']) ?>
                    <?= Form::input('ppn_rate', 'Tarif PPN', old('ppn_rate', $values), $errors, ['required' => true, 'col' => 'col-md-6', 'suffix' => '%', 'inputmode' => 'decimal',
                        'help' => 'Untuk menghitung nilai PO (PO Financials).']) ?>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Otomasi &amp; notifikasi</div>
                <div class="row g-3">
                    <?= Form::input('delivery_reminder_days', 'Pengingat delivery', old('delivery_reminder_days', $values), $errors, ['type' => 'number', 'min' => 0, 'max' => 30, 'required' => true, 'col' => 'col-md-6', 'suffix' => 'hari sebelum',
                        'help' => 'Delivery terjadwal dalam rentang ini dikirim sebagai notifikasi.']) ?>
                    <?= Form::input('automation_interval_minutes', 'Interval otomasi', old('automation_interval_minutes', $values), $errors, ['type' => 'number', 'min' => 5, 'max' => 1440, 'required' => true, 'col' => 'col-md-6', 'suffix' => 'menit',
                        'help' => 'Otomasi berjalan saat aplikasi dipakai, maksimal sekali per interval ini.']) ?>
                </div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Simpan pengaturan</button></div>
        </form>
    </div>

    <div class="col-xl-5 min-w-0">
        <section class="surface section-gap">
            <div class="surface-header"><div><h2 class="surface-title">Status otomasi</h2>
                <p class="surface-subtitle">Terakhir berjalan: <?= e($lastRun ? fmt_datetime($lastRun) : 'belum pernah') ?></p></div>
                <form method="post" action="<?= e(url('/settings/automation/run')) ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit"><i class="bi bi-play-fill"></i> Jalankan sekarang</button></form></div>
            <?php if ($lastResult): ?>
                <ul class="list-lite">
                    <?php foreach ($labels as $key => $label): ?>
                        <li><div class="li-main"><span class="li-title"><?= e($label) ?></span></div><div class="li-end"><?= (int) ($lastResult['notifications'][$key] ?? 0) ?> notifikasi</div></li>
                    <?php endforeach; ?>
                    <li><div class="li-main"><span class="li-title">Follow up ditandai Overdue</span></div><div class="li-end"><?= (int) ($lastResult['followups_overdue'] ?? 0) ?></div></li>
                    <li><div class="li-main"><span class="li-title">Status invoice diperbarui</span></div><div class="li-end"><?= (int) ($lastResult['invoices_updated'] ?? 0) ?></div></li>
                </ul>
            <?php endif; ?>
            <div class="surface-footer small text-secondary">Untuk server tanpa pengunjung rutin, jadwalkan cron:
                <div class="code-chip mt-1 d-inline-block text-wrap">*/15 * * * * php cron/automation.php</div></div>
        </section>
        <?php if ($lastImport): ?>
            <section class="surface surface-pad small text-break">
                <div class="fw-semibold mb-1">Import data terakhir</div>
                <div class="text-secondary"><?= e(fmt_datetime($lastImport['at'] ?? null)) ?> · <?= e((string) ($lastImport['file'] ?? '')) ?></div>
                <?php if (!empty($lastImport['counts']) && is_array($lastImport['counts'])): ?>
                    <div class="text-secondary mt-1"><?= e(implode(' · ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($lastImport['counts']), $lastImport['counts']))) ?></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
