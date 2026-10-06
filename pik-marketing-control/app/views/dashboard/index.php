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
 * @var list<array<string,mixed>>|null $pendingOef
 * @var list<array<string,mixed>>|null $complaints
 * @var array<string,array<string,int>>|null $stockGroups
 * @var list<array<string,mixed>>|null $inbound
 * @var list<array<string,mixed>>|null $supplier
 */
$user = auth_user();
$hour = (int) date('G');
$greeting = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 19 ? 'Selamat sore' : 'Selamat malam'));

// KPI hanya tampil bila role user boleh melihat modul terkait (otorisasi juga dicek di backend).
$canReview = can('oef_review.approve');
$tiles = [
    ['perm' => 'customers.view', 'href' => url('/customers'), 'tone' => 'kpi-accent', 'icon' => 'bi-buildings', 'label' => 'Customer aktif',
     'value' => fmt_qty($kpi['customers_active'], '0'), 'meta' => 'dari ' . fmt_qty($kpi['customers_total'], '0') . ' customer', 'alert' => false],
    ['perm' => 'leads.view', 'href' => url('/leads'), 'tone' => 'kpi-info', 'icon' => 'bi-kanban', 'label' => 'Leads aktif',
     'value' => fmt_qty($kpi['leads_open'], '0'), 'meta' => 'Pipeline ' . fmt_money($kpi['leads_pipeline'], 'Rp 0'), 'alert' => false],
    ['perm' => 'followups.view', 'href' => url('/follow-ups', ['tab' => 'today']), 'tone' => 'kpi-success', 'icon' => 'bi-calendar2-check', 'label' => 'Follow up hari ini',
     'value' => fmt_qty($kpi['followups_today'], '0'), 'meta' => 'Jadwal yang belum selesai', 'alert' => false],
    ['perm' => 'followups.view', 'href' => url('/follow-ups', ['tab' => 'overdue']), 'tone' => 'kpi-danger', 'icon' => 'bi-alarm', 'label' => 'Overdue',
     'value' => fmt_qty($kpi['followups_overdue'], '0'), 'meta' => 'Follow up terlewat', 'alert' => $kpi['followups_overdue'] > 0],
    ['perm' => 'purchase_orders.view', 'href' => url('/purchase-orders', ['ppic' => 'Pending']), 'tone' => 'kpi-warning', 'icon' => 'bi-hourglass-split', 'label' => 'OEF menunggu review',
     'value' => fmt_qty($kpi['oef_pending'], '0'), 'meta' => $canReview ? 'Perlu dikonfirmasi PPIC' : 'Menunggu konfirmasi PPIC', 'alert' => $canReview && $kpi['oef_pending'] > 0],
    ['perm' => 'purchase_orders.view', 'href' => url('/purchase-orders', ['status' => 'open']), 'tone' => '', 'icon' => 'bi-receipt', 'label' => 'Outstanding OEF',
     'value' => fmt_qty($kpi['outstanding_qty'], '0'), 'meta' => 'pcs · ' . fmt_qty($kpi['po_open'], '0') . ' OEF berjalan', 'alert' => false],
    ['perm' => 'deliveries.view', 'href' => url('/deliveries', ['status' => 'upcoming']), 'tone' => 'kpi-info', 'icon' => 'bi-truck', 'label' => 'Kirim 7 hari ke depan',
     'value' => fmt_qty($kpi['deliveries_week'], '0'), 'meta' => fmt_qty($kpi['deliveries_no_sj'], '0') . ' jadwal belum ada Surat Jalan', 'alert' => false],
    ['perm' => 'returns.view', 'href' => url('/returns', ['resolution' => 'Open']), 'tone' => 'kpi-danger', 'icon' => 'bi-chat-left-dots', 'label' => 'Komplain belum selesai',
     'value' => fmt_qty($kpi['complaints_open'], '0'), 'meta' => 'Retur & komplain tanpa hasil', 'alert' => $kpi['complaints_open'] > 0],
    ['perm' => 'stock.view', 'href' => url('/stock'), 'tone' => 'kpi-success', 'icon' => 'bi-boxes', 'label' => 'Stok Ready',
     'value' => fmt_qty($kpi['stock_ready'], '0'), 'meta' => 'FG ' . fmt_qty($kpi['stock_fg'], '0') . ' · WIP ' . fmt_qty($kpi['stock_wip'], '0'), 'alert' => false],
    ['perm' => 'inbound.view', 'href' => url('/inbound'), 'tone' => 'kpi-accent', 'icon' => 'bi-box-arrow-in-down', 'label' => 'Inbound maklon bulan ini',
     'value' => fmt_qty($kpi['inbound_month_qty'], '0'), 'meta' => fmt_qty($kpi['inbound_month'], '0') . ' penerimaan', 'alert' => false],
    ['perm' => 'inbound_supplier.view', 'href' => url('/inbound-supplier'), 'tone' => 'kpi-accent', 'icon' => 'bi-truck-flatbed', 'label' => 'Inbound supplier bulan ini',
     'value' => fmt_qty($kpi['supplier_month_qty'], '0'), 'meta' => fmt_qty($kpi['supplier_month'], '0') . ' penerimaan', 'alert' => false],
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
        <p class="page-subtitle">Ringkasan pekerjaan Anda hari ini.</p>
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
        <?php if (!can($t['perm'])) { continue; } $canOpen = true; ?>
        <<?= $canOpen ? 'a href="' . e($t['href']) . '"' : 'div' ?> class="kpi <?= e($t['tone']) ?>">
            <div class="kpi-label"><span class="kpi-icon"><i class="bi <?= e($t['icon']) ?>"></i></span><?= e($t['label']) ?></div>
            <div class="kpi-value<?= $t['alert'] ? ' is-alert' : '' ?>"><?= e($t['value']) ?></div>
            <div class="kpi-meta"><?= e($t['meta']) ?></div>
        </<?= $canOpen ? 'a' : 'div' ?>>
    <?php endforeach; ?>
</div>

<?php $hasLeft = $followToday !== null || $orders !== null || $pendingOef !== null;
$hasRight = $deliveries !== null || $leadtimes !== null || $activities !== null || $complaints !== null || $stockGroups !== null || $inbound !== null || $supplier !== null; ?>
<div class="row g-4 section-gap">
    <?php if ($hasLeft): ?>
        <div class="<?= $hasRight ? 'col-xl-8' : 'col-12' ?> min-w-0">
            <?php if ($pendingOef !== null): ?>
                <section class="surface<?= $followToday !== null ? '' : '' ?> mb-4">
                    <div class="surface-header"><div><h2 class="surface-title">OEF menunggu review <span class="tab-count"><?= count($pendingOef) ?></span></h2>
                        <p class="surface-subtitle">Buka OEF lalu tekan <strong>Bisa diproses</strong> (hijau) atau <strong>Tidak bisa diproses</strong> (merah).</p></div>
                        <a class="small" href="<?= e(url('/purchase-orders', ['ppic' => 'Pending'])) ?>">Semua</a></div>
                    <?php if (!$pendingOef): ?>
                        <div class="empty-inline">Tidak ada OEF yang menunggu review. <i class="bi bi-check2-circle text-success"></i></div>
                    <?php else: ?>
                        <ul class="list-lite">
                            <?php foreach ($pendingOef as $o): ?>
                                <li><span class="avatar avatar-sm"><i class="bi bi-receipt"></i></span>
                                    <div class="li-main"><a class="li-title" href="<?= e(url('/purchase-orders/' . $o['id'])) ?>"><?= e(App\Models\PurchaseOrder::label($o)) ?></a>
                                        <div class="li-sub"><?= e($o['customer_name'] ?? '—') ?> · <?= (int) $o['line_count'] ?> produk · <?= e(fmt_qty($o['total_qty'], '0')) ?> pcs<?= $o['sales_name'] ? ' · ' . e($o['sales_name']) : '' ?></div></div>
                                    <div class="li-end"><div><?= e(fmt_date($o['requested_delivery_date'], '—')) ?></div><div class="x-small text-secondary">permintaan kirim</div></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
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
                    <div class="surface-header"><h2 class="surface-title">OEF terbaru</h2><a class="small" href="<?= e(url('/purchase-orders')) ?>">Semua OEF</a></div>
                    <?php if (!$orders): ?>
                        <div class="empty-inline">Belum ada Order Entry Form.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table-pik table-compact">
                                <thead><tr><th>OEF · Customer</th><th class="d-none d-sm-table-cell">Tanggal</th><th class="d-none d-sm-table-cell">Review PPIC</th><th class="num d-none d-md-table-cell">Qty</th><th class="num">Outstanding</th></tr></thead>
                                <tbody>
                                <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/purchase-orders/' . $o['id'])) ?>"><?= e(App\Models\PurchaseOrder::label($o)) ?></a>
                                            <div class="cell-sub"><?= e($o['customer_name'] ?? 'Customer belum terhubung') ?></div>
                                            <div class="cell-sub d-sm-none"><?= e(fmt_date($o['po_date'], 'Tanpa tanggal')) ?> · <?= App\Models\PurchaseOrder::reviewBadge($o['review_status']) ?></div></td>
                                        <td class="d-none d-sm-table-cell nowrap text-secondary"><?= e(fmt_date($o['po_date'], 'Tanpa tanggal')) ?></td>
                                        <td class="d-none d-sm-table-cell"><?= App\Models\PurchaseOrder::reviewBadge($o['review_status']) ?></td>
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

    <?php if ($hasRight): ?>
        <div class="<?= $hasLeft ? 'col-xl-4' : 'col-12' ?> min-w-0">
            <?php if ($complaints !== null): ?>
                <section class="surface mb-4">
                    <div class="surface-header"><h2 class="surface-title">Komplain belum selesai <span class="tab-count"><?= (int) $kpi['complaints_open'] ?></span></h2>
                        <a class="small" href="<?= e(url('/returns', ['resolution' => 'Open'])) ?>">Semua</a></div>
                    <?php if (!$complaints): ?>
                        <div class="empty-inline">Semua retur & komplain sudah ada hasilnya.</div>
                    <?php else: ?>
                        <ul class="list-lite">
                            <?php foreach ($complaints as $c): ?>
                                <li><div class="li-main"><a class="li-title" href="<?= e(url('/returns/' . $c['id'])) ?>"><?= e($c['case_type']) ?> · <?= e($c['customer_name'] ?? '—') ?></a>
                                    <div class="li-sub"><?= e($c['product_name'] ?? '') ?><?= $c['reason'] ? ' · ' . e(App\Models\ProductReturn::REASON_LABELS[$c['reason']] ?? $c['reason']) : '' ?></div></div>
                                    <div class="li-end x-small text-secondary"><?= e(fmt_date($c['return_date'])) ?></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <?php if ($stockGroups !== null): ?>
                <section class="surface mb-4">
                    <div class="surface-header"><h2 class="surface-title">Stok per kelompok</h2><a class="small" href="<?= e(url('/stock')) ?>">Semua</a></div>
                    <?php if (!$stockGroups): ?>
                        <div class="empty-inline">Belum ada data stok.<?= can('stock.create') ? ' <a href="' . e(url('/stock/create')) . '">Catat stok</a>' : '' ?></div>
                    <?php else: ?>
                        <ul class="list-lite">
                            <?php foreach (array_slice($stockGroups, 0, 8, true) as $group => $g): ?>
                                <li><div class="li-main"><a class="li-title" href="<?= e(url('/stock', ['group' => $group])) ?>"><?= e($group) ?></a>
                                    <div class="li-sub"><?= (int) $g['products'] ?> produk · WIP <?= e(fmt_qty($g['wip'], '0')) ?></div></div>
                                    <div class="li-end"><div>FG <?= e(fmt_qty($g['fg'], '0')) ?></div><div class="x-small text-secondary">Ready <?= e(fmt_qty($g['ready'], '0')) ?></div></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <?php if ($inbound !== null): ?>
                <section class="surface mb-4">
                    <div class="surface-header"><h2 class="surface-title">Inbound maklon terakhir</h2><a class="small" href="<?= e(url('/inbound')) ?>">Semua</a></div>
                    <?php if (!$inbound): ?>
                        <div class="empty-inline">Belum ada penerimaan maklon.</div>
                    <?php else: ?>
                        <ul class="list-lite">
                            <?php foreach ($inbound as $ib): ?>
                                <li><div class="li-main"><a class="li-title" href="<?= e(url('/inbound/' . $ib['id'])) ?>"><?= e(excerpt($ib['component_name'] ?? $ib['sj_number'] ?? $ib['code'], 50)) ?></a>
                                    <div class="li-sub"><?= e($ib['vendor'] ?? '') ?></div></div>
                                    <div class="li-end"><div><?= e(fmt_qty($ib['total_in'])) ?></div><div class="x-small text-secondary"><?= e(fmt_date($ib['actual_inbound_date'])) ?></div></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <?php if ($supplier !== null): ?>
                <section class="surface mb-4">
                    <div class="surface-header"><h2 class="surface-title">Inbound supplier terakhir</h2><a class="small" href="<?= e(url('/inbound-supplier')) ?>">Semua</a></div>
                    <?php if (!$supplier): ?>
                        <div class="empty-inline">Belum ada penerimaan dari supplier.<?= can('inbound_supplier.create') ? ' <a href="' . e(url('/inbound-supplier/create')) . '">Catat penerimaan</a>' : '' ?></div>
                    <?php else: ?>
                        <ul class="list-lite">
                            <?php foreach ($supplier as $sp): ?>
                                <li><div class="li-main"><a class="li-title" href="<?= e(url('/inbound-supplier/' . $sp['id'])) ?>"><?= e(excerpt($sp['item_name'], 50)) ?></a>
                                    <div class="li-sub"><?= e($sp['supplier']) ?></div></div>
                                    <div class="li-end"><div><?= e(fmt_qty($sp['accepted_qty'])) ?> <?= e($sp['unit']) ?></div><div class="x-small text-secondary"><?= e(fmt_date($sp['receive_date'])) ?></div></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
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
                                <li><div class="li-main"><a class="li-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?: ('Jadwal ' . ($d['order_ref'] ?? $d['code']))) ?></a>
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
