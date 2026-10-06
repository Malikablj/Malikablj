<?php

use App\Helpers\Form;
use App\Models\Delivery;

/** @var App\Helpers\Paginator $deliveries @var array<string,mixed> $summary @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['link'] !== '' || $filters['sj'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Deliveries</h1>
        <p class="page-subtitle">Jadwal kirim dibuat otomatis dari OEF yang disetujui PPIC dan bisa diubah. Surat Jalan diisi PPIC. Hanya status Delivered/Partial yang mengurangi outstanding.</p>
    </div>
    <?php if (can('deliveries.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/deliveries/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Delivery</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Delivery<?= $hasFilter ? ' (sesuai filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Qty diterima customer</div><div class="stat-value"><?= e(fmt_qty($summary['delivered'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Akan datang</div><div class="stat-value"><?= e(fmt_qty($summary['upcoming'] ?? 0, '0')) ?></div><div class="x-small text-secondary">Scheduled · On Delivery</div></div>
    <div><div class="stat-label">Belum ada Surat Jalan</div><div class="stat-value<?= ($summary['without_sj'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($summary['without_sj'] ?? 0, '0')) ?></div>
        <?php if (($summary['without_sj'] ?? 0) > 0 && $filters['sj'] === ''): ?><a class="x-small" href="<?= e(query_with(['sj' => 'missing', 'page' => null])) ?>">Lihat</a><?php endif; ?></div>
    <?php if (($summary['unlinked'] ?? 0) > 0): ?>
        <div><div class="stat-label">Belum terhubung ke OEF</div><div class="stat-value text-warning-ink"><?= e(fmt_qty($summary['unlinked'], '0')) ?></div>
            <a class="x-small" href="<?= e(url('/deliveries', ['link' => 'unlinked'])) ?>">Lihat</a></div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/deliveries')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari no. SJ, no order, PO, customer, produk…" aria-label="Cari delivery"></div>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="upcoming"<?= selected('upcoming', $filters['status']) ?>>Akan datang (Scheduled · On Delivery)</option>
            <?= Form::options(Form::list(Delivery::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <select class="form-select" name="sj" aria-label="Surat Jalan" data-autosubmit>
            <option value="">Semua SJ</option><option value="missing"<?= selected('missing', $filters['sj']) ?>>Belum ada Surat Jalan</option>
        </select>
        <select class="form-select" name="link" aria-label="Relasi" data-autosubmit>
            <option value="">Semua relasi</option><option value="unlinked"<?= selected('unlinked', $filters['link']) ?>>Belum terhubung ke OEF</option>
        </select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/deliveries')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($deliveries->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-truck"></i><div class="empty-title">Tidak ada delivery</div><p>Jadwal muncul otomatis setelah PPIC menyetujui OEF.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Surat Jalan</th><th class="d-none d-md-table-cell">OEF · Customer</th><th class="d-none d-xl-table-cell">Produk</th><th class="d-none d-sm-table-cell">Status</th><th class="num">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($deliveries->items as $d): ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($d['delivery_date'])) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= $d['sj_number'] ? e($d['sj_number']) : 'Belum ada SJ' ?></a>
                            <?php if (!$d['sj_number']): ?><div class="cell-sub"><span class="code-chip"><?= e($d['code']) ?></span><?= str_starts_with((string) $d['schedule_source'], 'OEF') ? ' · jadwal dari OEF' : '' ?></div><?php endif; ?>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($d['delivery_date'])) ?> · <?= status_badge($d['status']) ?></div>
                            <div class="cell-sub d-md-none"><?= e($d['order_ref'] ?? '') ?><?= ($d['customer_name'] ?? $d['destination'] ?? '') !== '' ? ' · ' . e($d['customer_name'] ?? $d['destination']) : '' ?></div>
                            <?php if ($d['product_name'] !== null): ?><div class="cell-sub d-xl-none"><?= e($d['product_name']) ?></div><?php endif; ?>
                            <?php if ($d['po_line_id'] === null): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot"><?= e($d['migration_flag'] ?? 'Belum terhubung') ?></span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell small">
                            <?php if ($d['po_id']): ?><?= can('purchase_orders.view') ? '<a href="' . e(url('/purchase-orders/' . $d['po_id'])) . '">' . e($d['order_ref']) . '</a>' : e($d['order_ref']) ?><?php else: ?><span class="text-subtle">OEF tidak diketahui</span><?php endif; ?>
                            <div class="text-secondary"><?= e($d['customer_name'] ?? $d['destination'] ?? '') ?></div></td>
                        <td class="d-none d-xl-table-cell small"><?= e($d['product_name'] ?? '—') ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($d['status']) ?></td>
                        <td class="num fw-semibold<?= (int) $d['delivered_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($d['delivered_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $deliveries->footer('delivery') ?>
    <?php endif; ?>
</div>
