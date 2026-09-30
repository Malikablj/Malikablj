<?php
/**
 * @var array<string,int|float> $kpi
 * @var string $today
 * @var bool $hasBusinessData
 * @var list<array<string,mixed>>|null $followToday
 * @var list<array<string,mixed>>|null $followOverdue
 * @var list<array<string,mixed>>|null $activities
 * @var list<array<string,mixed>>|null $orders
 * @var list<array<string,mixed>>|null $deliveries
 * @var list<array<string,mixed>>|null $leadtimes
 * @var array<string,mixed>|null $finance
 */
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
$followRow = static function (array $f) use ($today): string {
    $who = $f['customer_name'] ?? $f['lead_name'] ?? '—';
    $when = $f['follow_up_date'] === $today ? ($f['follow_up_time'] ? substr((string) $f['follow_up_time'], 0, 5) : 'Hari ini') : fmt_date($f['follow_up_date']);
    $href = can('followups.view') ? url('/follow-ups/' . $f['id'] . '/edit', ['return' => '/']) : '#';
    return '<li><div class="li-main"><a class="li-title" href="' . e($href) . '">' . e($f['purpose']) . '</a>'
        . '<div class="li-sub">' . e($who) . ' · ' . e($f['follow_up_type']) . ($f['pic_name'] ? ' · ' . e($f['pic_name']) : '') . '</div></div>'
        . '<div class="li-end"><div class="' . ($f['follow_up_date'] < $today ? 'text-danger fw-semibold' : '') . '">' . e($when) . '</div>'
        . ($f['follow_up_date'] < $today ? '<div class="x-small text-secondary">' . e(relative_day($f['follow_up_date'])) . '</div>' : '') . '</div></li>';
};
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

<div class="row g-4 section-gap">
    <?php if ($followToday !== null || $orders !== null): ?>
        <div class="col-xl-8 min-w-0">
            <?php if ($followToday !== null): ?>
                <div class="row g-4">
                    <div class="col-lg-6 min-w-0">
                        <section class="surface h-100">
                            <div class="surface-header"><h2 class="surface-title">Follow up hari ini <span class="tab-count"><?= (int) $kpi['followups_today'] ?></span></h2>
                                <a class="small" href="<?= e(url('/follow-ups', ['tab' => 'today'])) ?>">Lihat semua</a></div>
                            <?php if (!$followToday): ?>
                                <div class="empty-inline">Tidak ada follow up terjadwal hari ini.</div>
                            <?php else: ?>
                                <ul class="list-lite"><?php foreach ($followToday as $f) { echo $followRow($f); } ?></ul>
                            <?php endif; ?>
                        </section>
                    </div>
                    <div class="col-lg-6 min-w-0">
                        <section class="surface h-100">
                            <div class="surface-header"><h2 class="surface-title">Overdue <span class="tab-count"><?= (int) $kpi['followups_overdue'] ?></span></h2>
                                <a class="small" href="<?= e(url('/follow-ups', ['tab' => 'overdue'])) ?>">Lihat semua</a></div>
                            <?php if (!$followOverdue): ?>
                                <div class="empty-inline">Tidak ada follow up yang terlewat. <i class="bi bi-check2-circle text-success"></i></div>
                            <?php else: ?>
                                <ul class="list-lite"><?php foreach ($followOverdue as $f) { echo $followRow($f); } ?></ul>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($orders !== null): ?>
                <section class="surface<?= $followToday !== null ? ' mt-4' : '' ?>">
                    <div class="surface-header"><h2 class="surface-title">Order terbaru</h2><a class="small" href="<?= e(url('/purchase-orders')) ?>">Semua PO</a></div>
                    <?php if (!$orders): ?>
                        <div class="empty-inline">Belum ada purchase order.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table-pik table-compact">
                                <thead><tr><th>PO · Customer</th><th class="d-none d-sm-table-cell">Tanggal</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-md-table-cell">Qty</th><th class="num">Outstanding</th></tr></thead>
                                <tbody>
                                <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/purchase-orders/' . $o['id'])) ?>"><?= e($o['po_number'] ?? $o['code']) ?></a>
                                            <div class="cell-sub"><?= e($o['customer_name'] ?? 'Customer belum terhubung') ?></div>
                                            <div class="cell-sub d-sm-none"><?= e(fmt_date($o['po_date'], 'Tanpa tanggal')) ?> · <?= status_badge($o['status']) ?></div></td>
                                        <td class="d-none d-sm-table-cell nowrap text-secondary"><?= e(fmt_date($o['po_date'], 'Tanpa tanggal')) ?></td>
                                        <td class="d-none d-sm-table-cell"><?= status_badge($o['status']) ?></td>
                                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($o['total_qty'], '0')) ?></td>
                                        <td class="num fw-semibold<?= (int) $o['outstanding_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($o['outstanding_qty'], '0')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($deliveries !== null || $leadtimes !== null || $activities !== null || $finance !== null): ?>
        <div class="<?= $followToday !== null || $orders !== null ? 'col-xl-4' : 'col-12' ?> min-w-0">
            <?php if ($finance !== null): ?>
                <a class="surface surface-pad d-block mb-4 finance-card" href="<?= e(url('/invoices', ['status' => 'open'])) ?>">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div><div class="stat-label">Piutang belum dibayar</div><div class="stat-value"><?= e(fmt_money($finance['outstanding'] ?? 0, 'Rp 0')) ?></div></div>
                        <span class="avatar avatar-sm"><i class="bi bi-cash-coin"></i></span>
                    </div>
                    <div class="x-small text-secondary mt-1"><?php if ((int) ($finance['overdue_count'] ?? 0) > 0): ?><span class="text-danger fw-semibold"><?= (int) $finance['overdue_count'] ?> invoice overdue · <?= e(fmt_money($finance['overdue_amount'])) ?></span><?php else: ?>Tidak ada invoice overdue<?php endif; ?></div>
                </a>
            <?php endif; ?>
            <?php if ($deliveries !== null || $leadtimes !== null): ?>
                <section class="surface">
                    <div class="surface-header"><h2 class="surface-title">Pengiriman mendatang</h2>
                        <?php if ($deliveries !== null): ?><a class="small" href="<?= e(url('/deliveries', ['status' => 'upcoming'])) ?>">Semua</a><?php endif; ?></div>
                    <?php if (!$deliveries && !$leadtimes): ?>
                        <div class="empty-inline">Belum ada pengiriman terjadwal.</div>
                    <?php else: ?>
                        <ul class="list-lite">
                            <?php foreach ($deliveries ?? [] as $d): ?>
                                <li><div class="li-main"><a class="li-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?? $d['code']) ?></a>
                                    <div class="li-sub"><?= e($d['customer_name'] ?? $d['destination'] ?? '') ?><?= $d['product_name'] ? ' · ' . e($d['product_name']) : '' ?></div></div>
                                    <div class="li-end"><div><?= e(fmt_date($d['delivery_date'], 'Belum dijadwalkan')) ?></div><?= status_badge($d['status']) ?></div></li>
                            <?php endforeach; ?>
                            <?php foreach ($leadtimes ?? [] as $lt): ?>
                                <li><div class="li-main"><span class="li-title"><?= e($lt['po_number'] ?? $lt['po_number_legacy'] ?? 'Estimasi') ?></span>
                                    <div class="li-sub">Estimasi lead time · <?= e($lt['product_name'] ?? $lt['product_legacy'] ?? '') ?></div></div>
                                    <div class="li-end"><div><?= e(fmt_date($lt['delivery_date'])) ?></div><div class="x-small text-secondary"><?= e(relative_day($lt['delivery_date'])) ?></div></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activities !== null): ?>
                <section class="surface<?= $deliveries !== null || $leadtimes !== null ? ' mt-4' : '' ?>">
                    <div class="surface-header"><h2 class="surface-title">Aktivitas terbaru</h2><a class="small" href="<?= e(url('/activities')) ?>">Semua</a></div>
                    <?php if (!$activities): ?>
                        <div class="empty-inline">Belum ada aktivitas tercatat.</div>
                    <?php else: ?>
                        <ul class="list-lite">
                            <?php foreach ($activities as $a): ?>
                                <li><span class="avatar avatar-sm"><i class="bi <?= e(activity_icon($a['activity_type'])) ?>"></i></span>
                                    <div class="li-main"><span class="li-title"><?= e($a['subject']) ?></span>
                                        <div class="li-sub"><?= e($a['related'] ?? '—') ?><?= $a['pic_name'] ? ' · ' . e($a['pic_name']) : '' ?></div></div>
                                    <div class="li-end text-secondary x-small"><?= e(fmt_datetime($a['activity_date'])) ?></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
