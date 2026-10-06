<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/** @var App\Helpers\Paginator $orders @var array<string,mixed> $summary @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['issue'] !== ''
    || $filters['month'] !== '' || $filters['review'] !== '' || $filters['ppic'] !== '';
$pending = (int) ($summary['pending_review'] ?? 0);
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Order Entry Form</h1>
        <p class="page-subtitle">OEF diinput Marketing, direview PPIC. Outstanding = Order − Terkirim + Retur, dihitung otomatis.</p>
    </div>
    <?php if (can('purchase_orders.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/purchase-orders/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buat OEF</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">OEF<?= $hasFilter ? ' (sesuai filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['po_count'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Menunggu review PPIC</div><div class="stat-value<?= $pending > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($pending, '0')) ?></div>
        <?php if ($pending > 0 && $filters['ppic'] !== 'Pending'): ?><a class="x-small" href="<?= e(query_with(['ppic' => 'Pending', 'page' => null])) ?>">Tampilkan</a><?php endif; ?></div>
    <div><div class="stat-label">Total order</div><div class="stat-value"><?= e(fmt_qty($summary['total_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs</div></div>
    <div><div class="stat-label">Terkirim</div><div class="stat-value"><?= e(fmt_qty($summary['delivered_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs</div></div>
    <div><div class="stat-label">Outstanding</div><div class="stat-value"><?= e(fmt_qty($summary['outstanding_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs belum terkirim</div></div>
</div>
<?php if ((int) ($summary['needs_review'] ?? 0) > 0 && $filters['review'] === '' && can('import.view')): ?>
    <div class="callout callout-info section-gap small"><i class="bi bi-info-circle me-1"></i><?= (int) $summary['needs_review'] ?> data dari import database PO masih perlu dicek.
        <a href="<?= e(query_with(['review' => 'needs_review', 'page' => null])) ?>">Tampilkan</a></div>
<?php endif; ?>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/purchase-orders')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari no order, no PO, customer, sales, produk…" aria-label="Cari OEF"></div>
        <select class="form-select" name="ppic" aria-label="Review PPIC" data-autosubmit>
            <option value="">Semua review</option>
            <?= Form::options(PurchaseOrder::REVIEW_LABELS, $filters['ppic']) ?>
        </select>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="open"<?= selected('open', $filters['status']) ?>>Berjalan (Open · On Process · Partial)</option>
            <?= Form::options(Form::list(PurchaseOrder::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <input type="month" class="form-control filter-date" name="month" value="<?= e($filters['month']) ?>" aria-label="Bulan order" title="Bulan order">
        <?php if ($filters['review'] !== ''): ?><input type="hidden" name="review" value="needs_review"><?php endif; ?>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Tanggal order dari">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Tanggal order sampai">
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/purchase-orders')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($orders->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-title"><?= $hasFilter ? 'Tidak ada OEF yang cocok' : 'Belum ada OEF' ?></div>
            <p>Order Entry Form beserta produknya akan tampil di sini.</p>
            <?php if (!$hasFilter && can('purchase_orders.create')): ?><a href="<?= e(url('/purchase-orders/create')) ?>" class="btn btn-primary btn-sm">Buat OEF</a><?php endif; ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr>
                    <th><?= sort_link('order', 'No. order', $sort, $dir) ?></th>
                    <th class="d-none d-md-table-cell"><?= sort_link('customer', 'Customer', $sort, $dir) ?></th>
                    <th class="d-none d-xl-table-cell"><?= sort_link('request', 'Permintaan kirim', $sort, $dir) ?></th>
                    <th class="d-none d-sm-table-cell">Review PPIC</th>
                    <th class="d-none d-xl-table-cell"><?= sort_link('status', 'Status', $sort, $dir) ?></th>
                    <th class="num d-none d-xxl-table-cell"><?= sort_link('qty', 'Total qty', $sort, $dir) ?></th>
                    <th class="num"><?= sort_link('outstanding', 'Outstanding', $sort, $dir) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($orders->items as $po): $progress = pct((int) $po['delivered_qty'], (int) $po['total_qty']); $out = (int) $po['outstanding_qty']; ?>
                    <tr>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e(PurchaseOrder::label($po)) ?></a>
                            <div class="cell-sub"><?= $po['order_number'] && $po['po_number'] ? 'PO ' . e($po['po_number']) . ' · ' : '' ?><?= e(fmt_date($po['po_date'], 'tanpa tanggal')) ?> · <?= (int) $po['line_count'] ?> produk<?= $po['sales_name'] ? ' · ' . e($po['sales_name']) : '' ?></div>
                            <div class="cell-sub d-md-none"><?= e($po['customer_name'] ?? '') ?></div>
                            <div class="cell-sub d-xl-none"><?= $po['requested_delivery_date'] ? 'Kirim ' . e(fmt_date($po['requested_delivery_date'])) : '' ?></div>
                            <div class="d-sm-none mt-1"><?= PurchaseOrder::reviewBadge($po['review_status']) ?></div>
                            <div class="d-xl-none mt-1"><?= status_badge($po['status']) ?></div>
                            <?php if ($po['import_status'] === 'NEEDS_REVIEW'): ?><div class="mt-1"><span class="badge-soft badge-soft-warning no-dot">Data import perlu dicek</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell"><?= $po['customer_id'] ? (can('customers.view') ? '<a href="' . e(url('/customers/' . $po['customer_id'])) . '">' . e($po['customer_name']) . '</a>' : e($po['customer_name'])) : '<span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span>' ?></td>
                        <td class="d-none d-xl-table-cell nowrap text-secondary"><?= e(fmt_date($po['requested_delivery_date'], '—')) ?></td>
                        <td class="d-none d-sm-table-cell"><?= PurchaseOrder::reviewBadge($po['review_status']) ?></td>
                        <td class="d-none d-xl-table-cell"><?= status_badge($po['status']) ?></td>
                        <td class="num d-none d-xxl-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                        <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out)) ?><?= $out < 0 ? '<div class="x-small">over</div>' : '' ?>
                            <div class="progress-thin mt-1 d-none d-lg-block<?= $progress >= 100 ? ($out < 0 ? ' is-over' : ' is-done') : '' ?>" title="<?= $progress ?>% terkirim"><span style="width: <?= $progress ?>%"></span></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $orders->footer('OEF') ?>
    <?php endif; ?>
</div>
