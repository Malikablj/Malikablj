<?php

use App\Helpers\Form;
use App\Models\LeadTime;

/**
 * @var App\Helpers\Paginator $rows
 * @var array<string,int> $summary
 * @var array<string,mixed> $filters
 * @var array<int,string> $customers
 */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['link'] !== '';
$today = today();
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Inventory</div>
        <h1 class="page-title">Lead Time</h1>
        <p class="page-subtitle">Estimasi tanggal delivery per PO &amp; produk. Estimasi terbuka yang tanggalnya sudah lewat ditandai terlambat.</p>
    </div>
    <?php if (can('leadtime.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/lead-times/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Tambah Estimasi</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Estimasi terbuka</div><div class="stat-value"><?= e(fmt_qty($summary['open_count'] ?? 0, '0')) ?></div>
        <?php if ($filters['status'] !== 'open'): ?><a class="x-small" href="<?= e(url('/lead-times', ['status' => 'open'])) ?>">Lihat</a><?php endif; ?></div>
    <div><div class="stat-label">Jatuh tempo 7 hari</div><div class="stat-value"><?= e(fmt_qty($summary['due_week'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Terlambat</div><div class="stat-value<?= ($summary['late'] ?? 0) > 0 ? ' text-danger' : '' ?>"><?= e(fmt_qty($summary['late'] ?? 0, '0')) ?></div>
        <?php if (($summary['late'] ?? 0) > 0 && $filters['status'] !== 'late'): ?><a class="x-small" href="<?= e(url('/lead-times', ['status' => 'late'])) ?>">Lihat</a><?php endif; ?></div>
    <?php if (($summary['unlinked'] ?? 0) > 0): ?>
        <div><div class="stat-label">Belum terhubung ke PO</div><div class="stat-value text-warning-ink"><?= e(fmt_qty($summary['unlinked'], '0')) ?></div>
            <a class="x-small" href="<?= e(url('/lead-times', ['link' => 'unlinked'])) ?>">Lihat</a></div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/lead-times')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari PO, produk, customer…" aria-label="Cari lead time"></div>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="open"<?= selected('open', $filters['status']) ?>>Terbuka (Planned · On Process · Delayed)</option>
            <option value="late"<?= selected('late', $filters['status']) ?>>Terlambat</option>
            <?= Form::options(Form::list(LeadTime::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <select class="form-select" name="link" aria-label="Relasi" data-autosubmit><option value="">Semua relasi</option><option value="unlinked"<?= selected('unlinked', $filters['link']) ?>>Belum terhubung ke PO</option></select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/lead-times')) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($rows->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-hourglass-split"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada lead time yang cocok' : 'Belum ada estimasi lead time' ?></div>
            <p>Estimasi dibuat per baris PO (PO + produk) dari menu ini atau halaman PO.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Estimasi</th><th>PO · Produk</th><th class="d-none d-md-table-cell">Customer</th><th class="num d-none d-sm-table-cell">Qty</th><th class="d-none d-sm-table-cell">Status</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($rows->items as $r): $late = LeadTime::isLate($r, $today); ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><span class="<?= $late ? 'text-danger fw-semibold' : '' ?>"><?= e(fmt_date($r['delivery_date'], 'Belum ada')) ?></span>
                            <?php if ($r['delivery_date'] && in_array($r['status'], LeadTime::OPEN_STATUSES, true)): ?><div class="cell-sub"><?= e(relative_day($r['delivery_date'])) ?></div><?php endif; ?></td>
                        <td class="min-w-0">
                            <?php if ($r['po_id']): ?><a class="cell-title" href="<?= e(url('/purchase-orders/' . $r['po_id'])) ?>"><?= e($r['po_number'] ?? $r['po_code']) ?></a>
                            <?php else: ?><span class="cell-title"><?= e($r['po_number_legacy'] ?? 'Tanpa PO') ?></span> <span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke PO</span><?php endif; ?>
                            <div class="cell-sub"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></div>
                            <div class="cell-sub d-md-none"><?= e($r['customer_name'] ?? '') ?></div>
                            <div class="cell-sub d-sm-none"><span class="<?= $late ? 'text-danger fw-semibold' : '' ?>">Estimasi <?= e(fmt_date($r['delivery_date'], 'belum ada')) ?></span> · <?= e(fmt_qty($r['quantity'])) ?> pcs</div>
                            <div class="cell-sub d-sm-none"><?= $late ? status_badge('Overdue', 'Terlambat') : status_badge($r['status']) ?></div>
                        </td>
                        <td class="d-none d-md-table-cell small"><?= e($r['customer_name'] ?? '—') ?></td>
                        <td class="num d-none d-sm-table-cell fw-semibold"><?= e(fmt_qty($r['quantity'])) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($r['status']) ?><?php if ($late): ?><div class="mt-1"><?= status_badge('Overdue', 'Terlambat') ?></div><?php endif; ?></td>
                        <td class="col-actions"><?php if (can('leadtime.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/lead-times/' . $r['id'] . '/edit', ['return' => '/lead-times'])) ?>" title="<?= $r['po_id'] ? 'Edit' : 'Hubungkan ke PO' ?>"><i class="bi <?= $r['po_id'] ? 'bi-pencil' : 'bi-link-45deg' ?>"></i><span class="d-none d-md-inline"> <?= $r['po_id'] ? 'Edit' : 'Hubungkan' ?></span></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('lead time') ?>
    <?php endif; ?>
</div>
