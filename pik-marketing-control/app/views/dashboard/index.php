<?php
/** @var array<string,int|float> $kpi @var string $today @var bool $hasBusinessData */
$user = auth_user();
$hour = (int) date('G');
$greeting = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 19 ? 'Selamat sore' : 'Selamat malam'));

// KPI tampil bila user punya akses modul terkait, atau akses laporan (Management/Viewer).
$tiles = [
    ['perm' => 'customers.view', 'href' => url('/customers'), 'tone' => 'kpi-accent', 'icon' => 'bi-buildings', 'label' => 'Customer aktif',
     'value' => fmt_qty($kpi['customers_active'], '0'), 'meta' => 'dari ' . fmt_qty($kpi['customers_total'], '0') . ' customer', 'alert' => false],
    ['perm' => 'leads.view', 'href' => url('/leads'), 'tone' => 'kpi-info', 'icon' => 'bi-kanban', 'label' => 'Leads aktif',
     'value' => fmt_qty($kpi['leads_open'], '0'), 'meta' => 'Pipeline ' . fmt_money($kpi['leads_pipeline'], 'Rp 0'), 'alert' => false],
    ['perm' => 'followups.view', 'href' => url('/follow-ups', ['tab' => 'today']), 'tone' => 'kpi-success', 'icon' => 'bi-calendar2-check', 'label' => 'Follow up hari ini',
     'value' => fmt_qty($kpi['followups_today'], '0'), 'meta' => 'Jadwal yang belum selesai', 'alert' => false],
    ['perm' => 'followups.view', 'href' => url('/follow-ups', ['tab' => 'overdue']), 'tone' => 'kpi-danger', 'icon' => 'bi-alarm', 'label' => 'Overdue',
     'value' => fmt_qty($kpi['followups_overdue'], '0'), 'meta' => 'Follow up terlewat', 'alert' => $kpi['followups_overdue'] > 0],
    ['perm' => 'purchase_orders.view', 'href' => url('/purchase-orders', ['status' => 'open']), 'tone' => 'kpi-warning', 'icon' => 'bi-receipt', 'label' => 'Open PO',
     'value' => fmt_qty($kpi['po_open'], '0'), 'meta' => 'Open · On Process · Partial', 'alert' => false],
    ['perm' => 'purchase_orders.view', 'href' => url('/purchase-orders', ['status' => 'open']), 'tone' => '', 'icon' => 'bi-box-seam', 'label' => 'Outstanding',
     'value' => fmt_qty($kpi['outstanding_qty'], '0'), 'meta' => 'pcs belum terkirim (PO open)', 'alert' => false],
];
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow"><?= e(fmt_day($today) . ', ' . fmt_date($today, '—', true)) ?></div>
        <h1 class="page-title"><?= e($greeting) ?>, <?= e(explode(' ', (string) ($user['name'] ?? ''))[0]) ?></h1>
        <p class="page-subtitle">Ringkasan customer, pipeline, follow up, dan order hari ini.</p>
    </div>
</div>

<?php if (!$hasBusinessData && can('import.view')): ?>
    <div class="callout callout-info section-gap d-flex gap-3 align-items-start">
        <i class="bi bi-cloud-arrow-up fs-4 text-primary"></i>
        <div>
            <div class="fw-semibold">Database masih kosong</div>
            <div class="text-secondary small mb-2">Impor data awal dari <span class="code-chip">PIK_Master_Database_AppSheet.xlsx</span>, lalu buat akun untuk tim Marketing, Sales, dan Management.</div>
            <a href="<?= e(url('/import')) ?>" class="btn btn-primary btn-sm">Import data</a>
            <a href="<?= e(url('/users/create')) ?>" class="btn btn-light btn-sm">Tambah user</a>
        </div>
    </div>
<?php endif; ?>

<div class="kpi-grid">
    <?php foreach ($tiles as $t): ?>
        <?php $canOpen = can($t['perm']); if (!$canOpen && !can('reports.view')) { continue; } ?>
        <<?= $canOpen ? 'a href="' . e($t['href']) . '"' : 'div' ?> class="kpi <?= e($t['tone']) ?>">
            <div class="kpi-label"><span class="kpi-icon"><i class="bi <?= e($t['icon']) ?>"></i></span><?= e($t['label']) ?></div>
            <div class="kpi-value<?= $t['alert'] ? ' is-alert' : '' ?>"><?= e($t['value']) ?></div>
            <div class="kpi-meta"><?= e($t['meta']) ?></div>
        </<?= $canOpen ? 'a' : 'div' ?>>
    <?php endforeach; ?>
</div>
