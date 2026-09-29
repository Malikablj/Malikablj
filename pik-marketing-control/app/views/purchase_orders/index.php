<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/** @var App\Helpers\Paginator $orders @var array<string,mixed> $summary @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['issue'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Purchase Orders</h1>
        <p class="page-subtitle">Outstanding = Order − Delivered + Return, dihitung otomatis dari delivery &amp; retur.</p>
    </div>
    <?php if (can('purchase_orders.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/purchase-orders/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buat PO</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">PO<?= $hasFilter ? ' (sesuai filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['po_count'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Total order</div><div class="stat-value"><?= e(fmt_qty($summary['total_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs</div></div>
    <div><div class="stat-label">Terkirim</div><div class="stat-value"><?= e(fmt_qty($summary['delivered_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs</div></div>
    <div><div class="stat-label">Outstanding</div><div class="stat-value"><?= e(fmt_qty($summary['outstanding_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs belum terkirim</div></div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/purchase-orders')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nomor PO, customer, produk…" aria-label="Cari PO"></div>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="open"<?= selected('open', $filters['status']) ?>>Berjalan (Open · On Process · Partial)</option>
            <?= Form::options(Form::list(PurchaseOrder::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Tanggal PO dari">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Tanggal PO sampai">
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/purchase-orders')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($orders->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-title"><?= $hasFilter ? 'Tidak ada PO yang cocok' : 'Belum ada PO' ?></div><p>PO dari customer beserta baris produknya akan tampil di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr>
                    <th><?= sort_link('number', 'No. PO', $sort, $dir) ?></th>
                    <th class="d-none d-md-table-cell"><?= sort_link('customer', 'Customer', $sort, $dir) ?></th>
                    <th class="d-none d-lg-table-cell"><?= sort_link('date', 'Tanggal', $sort, $dir) ?></th>
                    <th class="d-none d-sm-table-cell"><?= sort_link('status', 'Status', $sort, $dir) ?></th>
                    <th class="num d-none d-md-table-cell"><?= sort_link('qty', 'Total qty', $sort, $dir) ?></th>
                    <th class="num d-none d-xl-table-cell">Terkirim</th>
                    <th class="num"><?= sort_link('outstanding', 'Outstanding', $sort, $dir) ?></th>
                    <th class="d-none d-lg-table-cell">Progres</th>
                </tr></thead>
                <tbody>
                <?php foreach ($orders->items as $po): $progress = pct((int) $po['delivered_qty'], (int) $po['total_qty']); $out = (int) $po['outstanding_qty']; ?>
                    <tr>
                        <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e($po['po_number'] ?? '(tanpa nomor)') ?></a>
                            <div class="cell-sub"><span class="code-chip"><?= e($po['code']) ?></span> · <?= (int) $po['line_count'] ?> item
                                <span class="d-md-none"> · <?= e($po['customer_name'] ?? '') ?></span></div>
                            <div class="d-sm-none mt-1"><?= status_badge($po['status']) ?></div></td>
                        <td class="d-none d-md-table-cell"><?= $po['customer_id'] ? '<a href="' . e(url('/customers/' . $po['customer_id'])) . '">' . e($po['customer_name']) . '</a>' : '<span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span>' ?></td>
                        <td class="d-none d-lg-table-cell nowrap text-secondary"><?= e(fmt_date($po['po_date'], 'Tanpa tanggal')) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($po['status']) ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                        <td class="num d-none d-xl-table-cell"><?= e(fmt_qty($po['delivered_qty'])) ?></td>
                        <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out)) ?><?= $out < 0 ? '<div class="x-small">over</div>' : '' ?></td>
                        <td class="d-none d-lg-table-cell"><div class="progress-thin<?= $progress >= 100 ? ($out < 0 ? ' is-over' : ' is-done') : '' ?>" title="<?= $progress ?>% terkirim"><span style="width: <?= $progress ?>%"></span></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $orders->footer('PO') ?>
    <?php endif; ?>
</div>
