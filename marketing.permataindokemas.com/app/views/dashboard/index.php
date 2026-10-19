<?php
/**
 * @var array<string,int|float> $kpi
 * @var array<string,mixed> $insights
 * @var string $today
 * @var bool $hasBusinessData
 * @var list<array<string,mixed>>|null $followToday
 * @var list<array<string,mixed>>|null $followOverdue
 * @var list<array<string,mixed>>|null $activities
 * @var list<array<string,mixed>>|null $orders
 * @var list<array<string,mixed>>|null $deliveries
 * @var list<array<string,mixed>>|null $leadtimes
 * @var list<array<string,mixed>>|null $inbound
 * @var list<array<string,mixed>>|null $stockTop
 */

use App\Helpers\Number;
use App\Models\Lead;

$user = auth_user();
$hour = (int) date('G');
$greeting = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 19 ? 'Selamat sore' : 'Selamat malam'));
$in = $insights;

// ------------------------------------------------------------------ visual mini (dekoratif, angka tetap tertulis di teks)
$ring = static function (int $part, int $whole, string $title): string {
    $pct = $whole > 0 ? max(0, min(100, $part / $whole * 100)) : 0;
    return '<div class="kpi-viz kpi-ring" title="' . e($title) . '" aria-hidden="true"><svg viewBox="0 0 40 40">'
        . '<circle class="ring-track" cx="20" cy="20" r="16" pathLength="100"/>'
        . ($pct > 0 ? '<circle class="ring-fill" cx="20" cy="20" r="16" pathLength="100" stroke-dasharray="' . round($pct, 1) . ' 100"/>' : '')
        . '</svg><span>' . e($whole > 0 ? (int) floor($pct) . '%' : '—') . '</span></div>';
};
/** @param array<string,int> $series label => nilai; $current = label yang disorot (hari ini / bulan ini) */
$cols = static function (array $series, string $current, callable $label): string {
    $max = max(1, ...array_values($series));
    $html = '<div class="kpi-viz kpi-cols" aria-hidden="true">';
    foreach ($series as $k => $v) {
        $h = $v > 0 ? max(12, round($v / $max * 100)) : 0;
        $html .= '<span class="' . ($k === $current ? 'is-current' : '') . ($v === 0 ? ' is-zero' : '') . '" style="height: ' . ($v > 0 ? $h : 6) . '%" title="' . e($label((string) $k) . ': ' . $v) . '"></span>';
    }
    return $html . '</div>';
};
/** @param list<array{0:string,1:int,2:string}> $parts [label, nilai, warna CSS] */
$stack = static function (array $parts): string {
    $parts = array_values(array_filter($parts, static fn ($p) => $p[1] > 0));
    if ($parts === []) {
        return '<div class="kpi-stack is-empty" aria-hidden="true"></div>';
    }
    $html = '<div class="kpi-stack" aria-hidden="true">';
    foreach ($parts as [$label, $value, $color]) {
        $html .= '<span style="flex-grow: ' . $value . '; background: ' . $color . '" title="' . e($label . ': ' . Number::qty($value)) . '"></span>';
    }
    return $html . '</div>';
};
$month = static fn (string $ym): string => ID_MONTHS[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
$day = static fn (string $d): string => fmt_day($d) . ' ' . fmt_date($d);
$tone = static fn (string $status): string => 'var(--pik-' . (status_tone($status) === 'neutral' ? 'subtle' : status_tone($status)) . ')';

// ------------------------------------------------------------------ kartu KPI
$byStatus = $in['orders_by_status'];
$stages = $in['leads_by_stage'];
$doneToday = (int) $in['followups_done_today'];
$weekKeys = array_keys($in['deliveries_week']);
$thisMonth = substr($today, 0, 7);
$st = $in['stock_types'];
$tiles = [
    ['perm' => 'customers.view', 'href' => url('/customers'), 'tone' => 'accent', 'icon' => 'bi-buildings', 'label' => 'Customer aktif',
     'value' => $kpi['customers_active'], 'meta' => 'dari ' . fmt_qty($kpi['customers_total'], '0') . ' customer',
     'side' => $ring((int) $kpi['customers_active'], (int) $kpi['customers_total'], 'Customer aktif dari seluruh customer')],
    ['perm' => 'leads.view', 'href' => url('/leads'), 'tone' => 'info', 'icon' => 'bi-kanban', 'label' => 'Leads aktif',
     'value' => $kpi['leads_open'], 'meta' => 'Pipeline Rp ' . Number::compact($kpi['leads_pipeline']),
     'bar' => $stack(array_map(static fn (string $s): array => [$s, (int) ($stages[$s] ?? 0), Lead::tone($s)], Lead::OPEN_STATUSES))],
    ['perm' => 'followups.view', 'href' => url('/follow-ups', ['tab' => 'today']), 'tone' => 'success', 'icon' => 'bi-calendar2-check', 'label' => 'Follow up hari ini',
     'value' => $kpi['followups_today'],
     'meta' => $kpi['followups_today'] + $doneToday === 0 ? 'Tidak ada jadwal hari ini' : $doneToday . ' dari ' . ($kpi['followups_today'] + $doneToday) . ' sudah selesai',
     'side' => $ring($doneToday, (int) $kpi['followups_today'] + $doneToday, 'Follow up hari ini yang sudah selesai')],
    ['perm' => 'followups.view', 'href' => url('/follow-ups', ['tab' => 'overdue']), 'tone' => 'danger', 'icon' => 'bi-alarm', 'label' => 'Overdue',
     'value' => $kpi['followups_overdue'], 'alert' => $kpi['followups_overdue'] > 0,
     'meta' => $kpi['followups_overdue'] > 0 ? 'dari ' . $in['followups_open'] . ' follow up terbuka · terlama ' . strtolower(relative_day((string) $in['overdue_oldest'])) : 'Semua follow up tepat waktu',
     'good' => (int) $kpi['followups_overdue'] === 0,
     'side' => $ring((int) $kpi['followups_overdue'], (int) $in['followups_open'], 'Porsi follow up terbuka yang sudah terlewat')],
    ['perm' => 'purchase_orders.view', 'href' => url('/purchase-orders', ['status' => 'open']), 'tone' => 'warning', 'icon' => 'bi-receipt', 'label' => 'Order berjalan',
     'value' => $kpi['po_open'],
     'meta' => implode(' · ', array_map(static fn (string $s): string => ($byStatus[$s] ?? 0) . ' ' . $s, ['Open', 'On Process', 'Partial'])),
     'bar' => $stack(array_map(static fn (string $s): array => [$s, (int) ($byStatus[$s] ?? 0), $tone($s)], ['Open', 'On Process', 'Partial']))],
    ['perm' => 'purchase_orders.view', 'href' => url('/purchase-orders', ['ppic' => 'Pending']), 'tone' => 'info', 'icon' => 'bi-clipboard-check', 'label' => 'Menunggu PPIC',
     'value' => $kpi['ppic_pending'], 'alert' => $kpi['ppic_pending'] > 0 && can('ppic.approve'),
     'meta' => $kpi['ppic_pending'] > 0 ? 'dari ' . fmt_qty($kpi['po_open'], '0') . ' order berjalan · sejak ' . fmt_date(substr((string) $in['ppic_oldest'], 0, 10)) : 'Semua OEF sudah dikonfirmasi',
     'good' => (int) $kpi['ppic_pending'] === 0,
     'side' => $ring((int) $kpi['ppic_pending'], (int) $kpi['po_open'], 'Porsi order berjalan yang menunggu konfirmasi PPIC')],
    ['perm' => 'purchase_orders.view', 'href' => url('/purchase-orders', ['status' => 'open']), 'tone' => 'accent', 'icon' => 'bi-box-seam', 'label' => 'Outstanding',
     'value' => $kpi['outstanding_qty'], 'meta' => 'pcs belum terkirim · ' . pct_text((int) $in['running_fulfilled'], (int) $in['running_total']) . ' terpenuhi',
     'bar' => progress_bar((int) $in['running_fulfilled'], (int) $in['running_total'])],
    ['perm' => 'returns.view', 'href' => url('/returns', ['status' => 'Open']), 'tone' => 'danger', 'icon' => 'bi-exclamation-octagon', 'label' => 'Complaint terbuka',
     'value' => $kpi['complaints_open'], 'alert' => $kpi['complaints_open'] > 0,
     'meta' => ($in['complaints_months'][$thisMonth] ?? 0) . ' masuk bulan ini',
     'side' => $cols($in['complaints_months'], $thisMonth, $month)],
    ['perm' => 'deliveries.edit', 'href' => url('/deliveries', ['status' => 'upcoming']), 'tone' => 'accent', 'icon' => 'bi-truck', 'label' => 'Surat jalan mendatang',
     'value' => $kpi['deliveries_upcoming'],
     'meta' => $in['deliveries_on_way'] . ' dalam perjalanan' . ($in['deliveries_late'] > 0 ? ' · ' . $in['deliveries_late'] . ' lewat jadwal' : ''),
     'side' => $cols($in['deliveries_week'], $today, $day)],
    ['perm' => 'stock.view', 'href' => url('/stock'), 'tone' => 'success', 'icon' => 'bi-boxes', 'label' => 'Stok FG',
     'value' => $kpi['stock_fg'], 'meta' => fmt_qty($kpi['stock_products'], '0') . ' produk · Ready ' . fmt_qty($st['Ready'] ?? 0, '0') . ' · WIP ' . fmt_qty($st['WIP'] ?? 0, '0'),
     'bar' => $stack([['FG', (int) ($st['FG'] ?? 0), 'var(--pik-success)'], ['WIP', (int) ($st['WIP'] ?? 0), 'var(--pik-warning)'],
                      ['Ready', (int) ($st['Ready'] ?? 0), 'var(--pik-accent)'], ['Reserved', (int) ($st['Reserved'] ?? 0), 'var(--pik-info)']])],
    ['perm' => 'inbound.view', 'href' => url('/inbound'), 'tone' => 'info', 'icon' => 'bi-box-arrow-in-down', 'label' => 'Inbound maklon',
     'value' => $kpi['inbound_maklon_month'], 'meta' => 'bulan ini · ' . array_sum($in['inbound_maklon_months']) . ' dalam 6 bulan',
     'side' => $cols($in['inbound_maklon_months'], $thisMonth, $month)],
    ['perm' => 'inbound_supplier.view', 'href' => url('/inbound-supplier'), 'tone' => 'warning', 'icon' => 'bi-truck-flatbed', 'label' => 'Inbound supplier',
     'value' => $kpi['inbound_supplier_month'], 'meta' => 'bulan ini · ' . array_sum($in['inbound_supplier_months']) . ' dalam 6 bulan',
     'side' => $cols($in['inbound_supplier_months'], $thisMonth, $month)],
];

// ------------------------------------------------------------------ tombol cepat di header (maks. 3, sesuai hak akses)
$actions = array_values(array_filter([
    can('ppic.approve') && $kpi['ppic_pending'] > 0 ? ['href' => url('/purchase-orders', ['ppic' => 'Pending']), 'icon' => 'bi-clipboard-check', 'label' => 'Tinjau ' . (int) $kpi['ppic_pending'] . ' OEF'] : null,
    can('purchase_orders.create') ? ['href' => url('/purchase-orders/create'), 'icon' => 'bi-plus-lg', 'label' => 'Buat OEF'] : null,
    can('activities.create') ? ['href' => url('/activities/create'), 'icon' => 'bi-journal-plus', 'label' => 'Log aktivitas'] : null,
    can('followups.create') ? ['href' => url('/follow-ups/create'), 'icon' => 'bi-calendar-plus', 'label' => 'Follow up'] : null,
    can('deliveries.create') ? ['href' => url('/deliveries/create'), 'icon' => 'bi-truck', 'label' => 'Catat surat jalan'] : null,
    can('stock.create') ? ['href' => url('/stock/create'), 'icon' => 'bi-boxes', 'label' => 'Catat stok'] : null,
    can('inbound.create') ? ['href' => url('/inbound/create'), 'icon' => 'bi-box-arrow-in-down', 'label' => 'Inbound maklon'] : null,
    can('inbound_supplier.create') ? ['href' => url('/inbound-supplier/create'), 'icon' => 'bi-truck-flatbed', 'label' => 'Inbound supplier'] : null,
    can('reports.view') ? ['href' => url('/reports'), 'icon' => 'bi-bar-chart-line', 'label' => 'Laporan'] : null,
]));
$actions = array_slice($actions, 0, 3);

// Subjudul mengikuti modul yang boleh dibuka role (mis. Produksi: stok & barang masuk)
$focus = array_keys(array_filter([
    'customer'                   => can('customers.view'),
    'pipeline'                   => can('leads.view'),
    'follow up'                  => can('followups.view'),
    'order'                      => can('purchase_orders.view'),
    'konfirmasi PPIC'            => can('ppic.approve') && !can('customers.view'),
    'surat jalan'                => can('deliveries.edit') && !can('customers.view'),
    'stok'                       => can('stock.view') && !can('customers.view'),
    'barang masuk'               => (can('inbound.view') || can('inbound_supplier.view')) && !can('customers.view'),
]));
$last = array_pop($focus);
$subtitle = $last === null ? 'Ringkasan hari ini.' : 'Ringkasan ' . ($focus === [] ? $last : implode(', ', $focus) . (count($focus) > 1 ? ', dan ' : ' dan ') . $last) . ' hari ini.';

$followRow = static function (array $f) use ($today): string {
    $who = $f['customer_name'] ?? $f['lead_name'] ?? '—';
    $when = $f['follow_up_date'] === $today ? ($f['follow_up_time'] ? substr((string) $f['follow_up_time'], 0, 5) : 'Hari ini') : fmt_date($f['follow_up_date']);
    $href = can('followups.view') ? url('/follow-ups/' . $f['id'] . '/edit', ['return' => '/']) : '#';
    return '<li><span class="avatar avatar-sm"><i class="bi ' . e(activity_icon($f['follow_up_type'])) . '"></i></span>'
        . '<div class="li-main"><a class="li-title" href="' . e($href) . '">' . e($f['purpose']) . '</a>'
        . '<div class="li-sub">' . e($who) . ($f['pic_name'] ? ' · ' . e($f['pic_name']) : '') . '</div></div>'
        . '<div class="li-end"><div class="' . ($f['follow_up_date'] < $today ? 'text-danger fw-semibold' : 'fw-semibold') . '">' . e($when) . '</div>'
        . '<div class="x-small text-secondary">' . e($f['follow_up_date'] < $today ? relative_day($f['follow_up_date']) : $f['follow_up_type']) . '</div></div></li>';
};

// ------------------------------------------------------------------ panel: dikelompokkan per baris agar tinggi seimbang
$hasFollow = $followToday !== null;
// Pengiriman mendatang: surat jalan dulu, estimasi lead time mengisi sisa (total 6 baris, sejajar tabel order)
$deliveryItems = array_slice($deliveries ?? [], 0, 6);
$leadItems = array_slice($leadtimes ?? [], 0, max(0, 6 - count($deliveryItems)));
// Follow up: hari ini + terlewat dalam satu panel (maks. 6 baris)
$todayShow = array_slice($followToday ?? [], 0, max(3, 6 - count($followOverdue ?? [])));
$overShow = array_slice($followOverdue ?? [], 0, 6 - count($todayShow));
$stockMax = $stockTop ? max(1, ...array_map(static fn ($r) => (int) $r['fg'], $stockTop)) : 1;

ob_start(); ?>
    <?php if ($hasFollow): ?>
        <section class="surface">
            <div class="surface-header"><h2 class="surface-title">Follow up</h2>
                <a class="small" href="<?= e(url('/follow-ups', ['tab' => 'today'])) ?>">Lihat semua</a></div>
            <div class="list-section">Hari ini <span class="tab-count"><?= (int) $kpi['followups_today'] ?></span></div>
            <?php if (!$todayShow): ?>
                <div class="empty-inline pt-1">Tidak ada follow up terjadwal hari ini.</div>
            <?php else: ?>
                <ul class="list-lite"><?php foreach ($todayShow as $f) { echo $followRow($f); } ?></ul>
            <?php endif; ?>
            <div class="list-section">Terlewat <span class="tab-count<?= $kpi['followups_overdue'] > 0 ? ' is-danger' : '' ?>"><?= (int) $kpi['followups_overdue'] ?></span>
                <?php if ($kpi['followups_overdue'] > count($overShow)): ?><a class="ms-auto" href="<?= e(url('/follow-ups', ['tab' => 'overdue'])) ?>">+<?= (int) $kpi['followups_overdue'] - count($overShow) ?> lainnya</a><?php endif; ?></div>
            <?php if (!$overShow): ?>
                <div class="empty-inline pt-1"><i class="bi bi-check2-circle text-success"></i> Tidak ada follow up yang terlewat.</div>
            <?php else: ?>
                <ul class="list-lite"><?php foreach ($overShow as $f) { echo $followRow($f); } ?></ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($activities !== null): ?>
        <section class="surface">
            <div class="surface-header"><h2 class="surface-title">Aktivitas terbaru</h2><a class="small" href="<?= e(url('/activities')) ?>">Semua</a></div>
            <?php if (!$activities): ?>
                <div class="empty-inline">Belum ada aktivitas tercatat.</div>
            <?php else: ?>
                <ul class="list-lite">
                    <?php foreach ($activities as $a): ?>
                        <li><span class="avatar avatar-sm"><i class="bi <?= e(activity_icon($a['activity_type'])) ?>"></i></span>
                            <div class="li-main"><span class="li-title"><?= e($a['subject']) ?></span>
                                <div class="li-sub"><?= e($a['related'] ?? '—') ?><?= $a['pic_name'] ? ' · ' . e($a['pic_name']) : '' ?></div></div>
                            <div class="li-end"><div class="fw-semibold"><?= e(fmt_date($a['activity_date'])) ?></div>
                                <div class="x-small text-secondary"><?= e(substr((string) $a['activity_date'], 11, 5)) ?></div></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php $crmRow = (string) ob_get_clean();

ob_start(); ?>
    <?php if ($orders !== null): ?>
        <section class="surface dash-wide">
            <div class="surface-header"><h2 class="surface-title">Order terbaru</h2><a class="small" href="<?= e(url('/purchase-orders')) ?>">Semua order</a></div>
            <?php if (!$orders): ?>
                <div class="empty-inline">Belum ada order.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-pik table-compact">
                        <thead><tr><th>Order · Customer</th><th class="d-none d-sm-table-cell">Tanggal</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-md-table-cell">Qty</th><th class="num">Outstanding</th></tr></thead>
                        <tbody>
                        <?php foreach ($orders as $o): $out = (int) $o['outstanding_qty']; ?>
                            <tr>
                                <td class="min-w-0"><a class="cell-title" href="<?= e(url('/purchase-orders/' . $o['id'])) ?>"><?= e_wrap($o['po_number'] ?? $o['code']) ?></a>
                                    <div class="cell-sub"><?= e($o['customer_name'] ?? 'Customer belum terhubung') ?></div>
                                    <div class="cell-sub d-sm-none"><?= e(fmt_date($o['po_date'], 'Tanpa tanggal')) ?> · <?= status_badge($o['status']) ?></div></td>
                                <td class="d-none d-sm-table-cell nowrap text-secondary"><?= e(fmt_date($o['po_date'], 'Tanpa tanggal')) ?></td>
                                <td class="d-none d-sm-table-cell"><?= status_badge($o['status']) ?><?php if ($o['ppic_status'] === 'Pending'): ?> <?= ppic_badge('Pending') ?><?php endif; ?></td>
                                <td class="num d-none d-md-table-cell"><?= e(fmt_qty($o['total_qty'], '0')) ?></td>
                                <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out, '0')) ?>
                                    <?= progress_bar(fulfilled_qty($o), (int) $o['total_qty'], $out < 0, 'd-none d-md-block mt-2 ms-auto') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($deliveries !== null || $leadtimes !== null): ?>
        <section class="surface">
            <div class="surface-header"><h2 class="surface-title">Pengiriman mendatang</h2>
                <?php if ($deliveries !== null): ?><a class="small" href="<?= e(url('/deliveries', ['status' => 'upcoming'])) ?>">Semua</a><?php endif; ?></div>
            <?php if (!$deliveryItems && !$leadItems): ?>
                <div class="empty-inline">Belum ada pengiriman terjadwal.</div>
            <?php else: ?>
                <ul class="list-lite">
                    <?php foreach ($deliveryItems as $d): $date = (string) ($d['delivery_date'] ?? ''); ?>
                        <li><span class="date-chip<?= $date !== '' && $date < $today ? ' is-late' : ($date === $today ? ' is-today' : '') ?>">
                                <?php if ($date !== ''): ?><b><?= e(date('j', strtotime($date))) ?></b><?= e(ID_MONTHS[(int) date('n', strtotime($date))]) ?><?php else: ?><b>—</b><?php endif; ?></span>
                            <div class="li-main"><a class="li-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?? $d['code']) ?></a>
                                <div class="li-sub"><?= e($d['customer_name'] ?? $d['destination'] ?? '') ?><?= $d['product_name'] ? ' · ' . e($d['product_name']) : '' ?></div></div>
                            <div class="li-end"><?= status_badge($d['status']) ?><div class="x-small text-secondary mt-1"><?= e($date !== '' ? relative_day($date) : 'Belum dijadwalkan') ?></div></div></li>
                    <?php endforeach; ?>
                    <?php foreach ($leadItems as $lt): $date = (string) ($lt['delivery_date'] ?? ''); ?>
                        <li><span class="date-chip is-estimate"><?php if ($date !== ''): ?><b><?= e(date('j', strtotime($date))) ?></b><?= e(ID_MONTHS[(int) date('n', strtotime($date))]) ?><?php else: ?><b>—</b><?php endif; ?></span>
                            <div class="li-main"><span class="li-title"><?= e($lt['po_number'] ?? $lt['po_number_legacy'] ?? 'Estimasi') ?></span>
                                <div class="li-sub">Estimasi lead time · <?= e($lt['product_name'] ?? $lt['product_legacy'] ?? '') ?></div></div>
                            <div class="li-end x-small text-secondary"><?= e(relative_day($date)) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php $opsRow = (string) ob_get_clean();

ob_start(); ?>
    <?php if ($inbound !== null): ?>
        <section class="surface">
            <div class="surface-header"><h2 class="surface-title">Barang masuk terbaru</h2>
                <a class="small" href="<?= e(url(can('inbound.view') ? '/inbound' : '/inbound-supplier')) ?>">Semua</a></div>
            <?php if (!$inbound): ?>
                <div class="empty-inline">Belum ada penerimaan barang.</div>
            <?php else: ?>
                <ul class="list-lite">
                    <?php foreach ($inbound as $ib): $isSupplier = $ib['kind'] === 'supplier'; ?>
                        <li><span class="avatar avatar-sm"><i class="bi <?= $isSupplier ? 'bi-truck-flatbed' : 'bi-box-arrow-in-down' ?>"></i></span>
                            <div class="li-main"><a class="li-title" href="<?= e(url(($isSupplier ? '/inbound-supplier/' : '/inbound/') . $ib['id'])) ?>"><?= e($ib['item_name'] !== '' ? $ib['item_name'] : ($ib['sj_number'] ?? $ib['code'])) ?></a>
                                <div class="li-sub"><?= $isSupplier ? 'Supplier' : 'Maklon' ?> · <?= e($ib['partner'] ?? '—') ?></div></div>
                            <div class="li-end"><div class="fw-semibold"><?= e(Number::decimal($ib['qty'], 2)) ?> <span class="x-small text-secondary"><?= e($ib['unit']) ?></span></div>
                                <div class="x-small text-secondary"><?= e(fmt_date($ib['inbound_date'], 'Tanpa tanggal')) ?></div></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($stockTop !== null): ?>
        <section class="surface">
            <div class="surface-header"><h2 class="surface-title">Posisi stok</h2><a class="small" href="<?= e(url('/stock')) ?>">Semua</a></div>
            <?php if (!$stockTop): ?>
                <div class="empty-inline">Belum ada stok tercatat.</div>
            <?php else: ?>
                <ul class="list-lite">
                    <?php foreach ($stockTop as $s): ?>
                        <li><div class="li-main"><a class="li-title" href="<?= e(url('/stock', ['product_id' => $s['product_id']])) ?>"><?= e($s['product_name'] . ($s['variant'] ? ' · ' . $s['variant'] : '')) ?></a>
                                <div class="li-sub">Ready <?= e(fmt_qty($s['ready'], '0')) ?> · WIP <?= e(fmt_qty($s['wip'], '0')) ?></div></div>
                            <div class="li-end li-meter"><div class="fw-semibold"><?= e(fmt_qty($s['fg'], '0')) ?> <span class="x-small text-secondary">FG</span></div>
                                <div class="mini-meter" aria-hidden="true"><span style="width: <?= (int) $s['fg'] > 0 ? max(4, round((int) $s['fg'] / $stockMax * 100)) : 0 ?>%"></span></div></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php $stockRow = (string) ob_get_clean();
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow"><?= e(fmt_day($today) . ', ' . fmt_date($today, '—', true)) ?></div>
        <h1 class="page-title"><?= e($greeting) ?>, <?= e(explode(' ', (string) ($user['name'] ?? ''))[0]) ?></h1>
        <p class="page-subtitle"><?= e($subtitle) ?></p>
    </div>
    <?php if ($actions): ?>
        <div class="page-actions">
            <?php foreach ($actions as $i => $a): ?>
                <a href="<?= e($a['href']) ?>" class="btn <?= $i === 0 ? 'btn-primary' : 'btn-light' ?><?= $i === 2 ? ' optional-xs' : '' ?>"><i class="bi <?= e($a['icon']) ?>"></i> <?= e($a['label']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
        <<?= $canOpen ? 'a href="' . e($t['href']) . '"' : 'div' ?> class="kpi tone-<?= e($t['tone']) ?>">
            <div class="kpi-label"><span class="kpi-icon"><i class="bi <?= e($t['icon']) ?>"></i></span><span class="kpi-label-text" title="<?= e($t['label']) ?>"><?= e($t['label']) ?></span>
                <?php if ($canOpen): ?><i class="bi bi-chevron-right kpi-go" aria-hidden="true"></i><?php endif; ?></div>
            <div class="kpi-main">
                <div class="kpi-figure"><div class="kpi-value<?= !empty($t['alert']) ? ' is-alert' : '' ?>"><?= e(fmt_qty($t['value'], '0')) ?></div></div>
                <?= $t['side'] ?? '' ?>
            </div>
            <?= $t['bar'] ?? '' ?>
            <div class="kpi-meta<?= !empty($t['good']) ? ' is-good' : '' ?>"><?php if (!empty($t['good'])): ?><i class="bi bi-check2-circle"></i> <?php endif; ?><?= e($t['meta']) ?></div>
        </<?= $canOpen ? 'a' : 'div' ?>>
    <?php endforeach; ?>
</div>

<?php
// Baris panel: CRM dulu bagi role yang mengelola follow up, lalu order & pengiriman, lalu gudang.
$rows = $hasFollow ? [$crmRow, $opsRow, $stockRow] : [$opsRow, $crmRow, $stockRow];
foreach ($rows as $row):
    if (trim($row) === '') { continue; } ?>
    <div class="dash-row"><?= $row ?></div>
<?php endforeach; ?>
