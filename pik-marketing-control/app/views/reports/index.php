<?php
/** @var array<string,array<string,string>> $reports */
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Insight</div>
        <h1 class="page-title">Reports</h1>
        <p class="page-subtitle">Laporan dari data aktual. Filter periode, customer, dan PIC; export ke Excel/CSV atau cetak sebagai PDF.</p>
    </div>
</div>

<?php if (!$reports): ?>
    <div class="surface"><div class="empty-state"><i class="bi bi-bar-chart-line"></i><div class="empty-title">Tidak ada laporan untuk role Anda</div></div></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($reports as $key => $r): ?>
            <div class="col-md-6 col-xl-4">
                <a class="surface report-card" href="<?= e(url('/reports/' . $key)) ?>">
                    <span class="avatar avatar-accent"><i class="bi <?= e($r['icon']) ?>"></i></span>
                    <span class="min-w-0">
                        <span class="d-block fw-semibold">Laporan <?= e($r['title']) ?></span>
                        <span class="d-block small text-secondary mt-1"><?= e($r['desc']) ?></span>
                        <span class="d-block x-small text-subtle mt-2">Periode: <?= e(mb_strtolower($r['date'])) ?></span>
                    </span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
