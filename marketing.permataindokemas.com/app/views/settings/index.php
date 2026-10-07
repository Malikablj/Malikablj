<?php

use App\Helpers\Form;
use App\Helpers\Mailer;

/**
 * @var array<string,string|null> $values
 * @var array<string,string> $errors
 * @var string|null $lastRun
 * @var array<string,mixed>|null $lastResult
 * @var array<string,mixed>|null $lastImport
 * @var bool $hasSmtpPassword
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
            <div class="form-section" id="email">
                <div class="form-section-title">Email notifikasi complaint (QC)</div>
                <div class="form-section-desc">Hasil complaint dikirim ke email QC yang ditulis di setiap complaint. Email QC default otomatis terisi saat membuat complaint baru.</div>
                <div class="row g-3">
                    <?= Form::input('qc_default_email', 'Email QC default', old('qc_default_email', $values), $errors, ['maxlength' => 500, 'placeholder' => 'qc@permataindokemas.com, qc2@…',
                        'help' => 'Boleh lebih dari satu, pisahkan dengan koma.']) ?>
                    <?= Form::select('mail_transport', 'Metode kirim', Mailer::TRANSPORTS, old('mail_transport', $values, 'mail'), $errors, ['required' => true, 'col' => 'col-md-6',
                        'help' => 'mail() cukup untuk hosting cPanel. Pakai SMTP bila email sering masuk spam / tidak terkirim.']) ?>
                    <?= Form::input('mail_from_address', 'Email pengirim', old('mail_from_address', $values), $errors, ['type' => 'email', 'maxlength' => 190, 'col' => 'col-md-6',
                        'placeholder' => 'no-reply@permataindokemas.com', 'help' => 'Gunakan email di domain hosting Anda.']) ?>
                    <?= Form::input('mail_from_name', 'Nama pengirim', old('mail_from_name', $values), $errors, ['maxlength' => 120, 'col' => 'col-md-6', 'placeholder' => 'PIK Marketing Control']) ?>
                    <div class="col-12"><div class="small fw-semibold text-secondary mt-2">SMTP (hanya bila metode kirim = SMTP)</div></div>
                    <?= Form::input('smtp_host', 'SMTP host', old('smtp_host', $values), $errors, ['maxlength' => 190, 'col' => 'col-md-6', 'placeholder' => 'mail.permataindokemas.com']) ?>
                    <?= Form::input('smtp_port', 'Port', old('smtp_port', $values), $errors, ['type' => 'number', 'min' => 1, 'max' => 65535, 'col' => 'col-md-3']) ?>
                    <?= Form::select('smtp_encryption', 'Enkripsi', Mailer::ENCRYPTIONS, old('smtp_encryption', $values, 'ssl'), $errors, ['col' => 'col-md-3']) ?>
                    <?= Form::input('smtp_username', 'Username', old('smtp_username', $values), $errors, ['maxlength' => 190, 'col' => 'col-md-6', 'autocomplete' => 'off']) ?>
                    <div class="col-md-6">
                        <label class="form-label" for="f_smtp_password">Password</label>
                        <input type="password" class="form-control" id="f_smtp_password" name="smtp_password" maxlength="255" autocomplete="new-password" placeholder="<?= $hasSmtpPassword ? '•••••••• (tersimpan — kosongkan bila tidak diganti)' : '' ?>">
                        <?php if ($hasSmtpPassword): ?>
                            <div class="form-check mt-1 small"><input class="form-check-input" type="checkbox" id="f_smtp_password_clear" name="smtp_password_clear" value="1"><label class="form-check-label" for="f_smtp_password_clear">Hapus password tersimpan</label></div>
                        <?php endif; ?>
                    </div>
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
        <section class="surface section-gap">
            <div class="surface-header"><div><h2 class="surface-title">Tes email</h2>
                <p class="surface-subtitle">Simpan pengaturan email terlebih dahulu, lalu kirim email percobaan.</p></div></div>
            <form class="surface-body" method="post" action="<?= e(url('/settings/mail/test')) ?>">
                <?= csrf_field() ?>
                <label class="form-label small" for="test_email">Kirim ke</label>
                <div class="input-group">
                    <input type="email" class="form-control" id="test_email" name="test_email" placeholder="<?= e(auth_user()['email'] ?? '') ?>" maxlength="190">
                    <button class="btn btn-light" type="submit"><i class="bi bi-send"></i> Kirim tes</button>
                </div>
                <div class="form-text">Kosongkan untuk mengirim ke email Anda sendiri.</div>
            </form>
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
