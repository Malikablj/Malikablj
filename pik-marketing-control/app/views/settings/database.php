<?php
/**
 * @var list<string> $all
 * @var list<string> $pending
 */
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Pembaruan database</h1>
        <p class="page-subtitle">Versi aplikasi baru kadang menambah tabel atau kolom. Pembaruan hanya menambah struktur dan tidak menghapus data.</p>
    </div>
</div>

<?php if ($pending !== []): ?>
    <div class="callout callout-warning section-gap">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i><?= count($pending) ?> pembaruan database belum dijalankan</div>
        <div class="small mb-2">Selama belum dijalankan, user lain melihat pesan "Aplikasi sedang diperbarui". Sebelum melanjutkan di server production,
            buat backup seluruh database (phpMyAdmin › Export, atau mysqldump). Aplikasi juga otomatis membackup tabel PO, baris PO, customer, dan migration issue ke <span class="code-chip">storage/backups</span>.</div>
        <form method="post" action="<?= e(url('/settings/database')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-primary btn-sm" type="submit" data-confirm="Backup sudah dibuat? Jalankan pembaruan database sekarang?"><i class="bi bi-database-up"></i> Jalankan pembaruan</button>
        </form>
    </div>
<?php else: ?>
    <div class="callout callout-success section-gap"><i class="bi bi-check2-circle me-1"></i>Struktur database sudah versi terbaru.</div>
<?php endif; ?>

<section class="surface">
    <div class="surface-header"><div><h2 class="surface-title">Daftar pembaruan</h2></div></div>
    <?php if ($all === []): ?>
        <div class="surface-pad small text-secondary">Belum ada pembaruan.</div>
    <?php else: ?>
        <ul class="list-lite">
            <?php foreach ($all as $name): ?>
                <li><div class="li-main"><span class="li-title text-break"><?= e($name) ?></span></div>
                    <div class="li-end"><?= in_array($name, $pending, true) ? '<span class="badge-soft badge-soft-warning">Belum dijalankan</span>' : '<span class="badge-soft badge-soft-success">Sudah</span>' ?></div></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="surface-footer small text-secondary">Lewat command line: <span class="code-chip">php database/migrate.php</span></div>
</section>
