<?php

use App\Helpers\Form;
use App\Models\Delivery;

/** @var App\Helpers\Paginator $deliveries @var array<string,mixed> $summary @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['link'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Deliveries</h1>
        <p class="page-subtitle">Pengiriman (Surat Jalan) per baris order — Surat Jalan diisi oleh PPIC. Jadwal dari Order Entry Form masuk otomatis sebagai <em>Scheduled</em>. Hanya status Delivered/Partial yang mengurangi outstanding.</p>
    </div>
    <?php if (can('deliveries.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/deliveries/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Delivery</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Delivery<?= $hasFilter ? ' (sesuai filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Qty diterima customer</div><div class="stat-value"><?= e(fmt_qty($summary['delivered'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Belum terhubung ke PO line</div><div class="stat-value<?= ($summary['unlinked'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($summary['unlinked'] ?? 0, '0')) ?></div>
        <?php if (($summary['unlinked'] ?? 0) > 0): ?><a class="x-small" href="<?= e(url('/deliveries', ['link' => 'unlinked'])) ?>">Lihat</a><?php endif; ?></div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/deliveries')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari no. SJ, PO, customer, produk, tujuan…" aria-label="Cari delivery"></div>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="upcoming"<?= selected('upcoming', $filters['status']) ?>>Akan datang (Scheduled · On Delivery)</option>
            <?= Form::options(Form::list(Delivery::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <select class="form-select" name="link" aria-label="Relasi" data-autosubmit>
            <option value="">Semua relasi</option><option value="unlinked"<?= selected('unlinked', $filters['link']) ?>>Belum terhubung ke PO line</option>
        </select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/deliveries')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($deliveries->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-truck"></i><div class="empty-title">Tidak ada delivery</div><p><?= can('deliveries.create') ? 'Catat pengiriman dari halaman order atau tombol Catat Delivery.' : 'Surat jalan dicatat oleh PPIC.' ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Surat Jalan</th><th class="d-none d-md-table-cell">PO · Customer</th><th class="d-none d-lg-table-cell">Produk</th><th class="d-none d-sm-table-cell">Status</th><th class="num">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($deliveries->items as $d): ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($d['delivery_date'])) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?? $d['code']) ?></a>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($d['delivery_date'])) ?> · <?= status_badge($d['status']) ?></div>
                            <div class="cell-sub d-md-none"><?= e($d['po_number'] ?? $d['po_code'] ?? '') ?><?= ($d['customer_name'] ?? $d['destination'] ?? '') !== '' ? ' · ' . e($d['customer_name'] ?? $d['destination']) : '' ?></div>
                            <?php if ($d['product_name'] !== null): ?><div class="cell-sub d-lg-none"><?= e($d['product_name']) ?></div><?php endif; ?>
                            <?php if ($d['po_line_id'] === null): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot"><?= e($d['migration_flag'] ?? 'Belum terhubung') ?></span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell small">
                            <?php if ($d['po_id']): ?><a href="<?= e(url('/purchase-orders/' . $d['po_id'])) ?>"><?= e($d['po_number'] ?? $d['po_code']) ?></a><?php else: ?><span class="text-subtle">Order tidak diketahui</span><?php endif; ?>
                            <div class="text-secondary"><?= e($d['customer_name'] ?? $d['destination'] ?? '') ?></div></td>
                        <td class="d-none d-lg-table-cell small"><?= e($d['product_name'] ?? '—') ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($d['status']) ?>
                            <?php if ((int) $d['is_oef_schedule'] === 1): ?><div class="x-small text-secondary mt-1" title="Dijadwalkan otomatis dari Order Entry Form"><i class="bi bi-calendar-event"></i> Jadwal OEF<?= $d['ppic_status'] === 'Pending' ? ' · menunggu PPIC' : '' ?></div><?php endif; ?></td>
                        <td class="num fw-semibold<?= (int) $d['delivered_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($d['delivered_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $deliveries->footer('delivery') ?>
    <?php endif; ?>
</div>
