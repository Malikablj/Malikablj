<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/** @var App\Helpers\Paginator $orders @var array<string,mixed> $summary @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['issue'] !== ''
    || $filters['month'] !== '' || $filters['review'] !== '';
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
    <div><div class="stat-label">Nilai PO</div><div class="stat-value" title="<?= e(fmt_money($summary['total_value'] ?? 0)) ?>"><?= e(App\Helpers\Number::compact($summary['total_value'] ?? 0)) ?></div>
        <div class="x-small text-secondary">grand total, tanpa Cancelled<?= (int) ($summary['without_value'] ?? 0) > 0 ? ' · ' . (int) $summary['without_value'] . ' PO belum bernilai' : '' ?></div></div>
</div>
<?php if ((int) ($summary['needs_review'] ?? 0) > 0 && $filters['review'] === ''): ?>
    <div class="callout callout-info section-gap small"><i class="bi bi-info-circle me-1"></i><?= (int) $summary['needs_review'] ?> PO dari import database PO masih perlu ditinjau.
        <a href="<?= e(query_with(['review' => 'needs_review', 'page' => null])) ?>">Tampilkan</a></div>
<?php endif; ?>

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
        <input type="month" class="form-control filter-date" name="month" value="<?= e($filters['month']) ?>" aria-label="Bulan PO" title="Bulan PO">
        <select class="form-select" name="review" aria-label="Status review" data-autosubmit><option value="">Semua data</option><option value="needs_review"<?= selected('needs_review', $filters['review']) ?>>Perlu review</option></select>
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
                    <th class="d-none d-xl-table-cell"><?= sort_link('date', 'Tanggal', $sort, $dir) ?></th>
                    <th class="d-none d-sm-table-cell d-lg-none d-xl-table-cell"><?= sort_link('status', 'Status', $sort, $dir) ?></th>
                    <th class="num d-none d-xl-table-cell"><?= sort_link('qty', 'Total qty', $sort, $dir) ?></th>
                    <th class="num d-none d-xxl-table-cell">Terkirim</th>
                    <th class="num d-none d-md-table-cell"><?= sort_link('value', 'Nilai', $sort, $dir) ?></th>
                    <th class="num"><?= sort_link('outstanding', 'Outstanding', $sort, $dir) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($orders->items as $po): $progress = pct((int) $po['delivered_qty'], (int) $po['total_qty']); $out = (int) $po['outstanding_qty']; ?>
                    <tr>
                        <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e($po['po_number'] ?? '(tanpa nomor)') ?></a>
                            <div class="cell-sub"><span class="code-chip"><?= e($po['code']) ?></span> · <?= (int) $po['line_count'] ?> item
                                <span class="d-xl-none"> · <?= e(fmt_date($po['po_date'], 'tanpa tanggal')) ?></span>
                                <span class="d-md-none"> · <?= e($po['customer_name'] ?? '') ?></span></div>
                            <?php if ($po['grand_total'] !== null): ?><div class="cell-sub d-md-none"><?= e(fmt_money($po['grand_total'])) ?></div><?php endif; ?>
                            <div class="d-sm-none d-lg-block d-xl-none mt-1"><?= status_badge($po['status']) ?></div>
                            <?php if ($po['import_status'] === 'NEEDS_REVIEW'): ?><div class="mt-1"><span class="badge-soft badge-soft-warning no-dot">Perlu review</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell"><?= $po['customer_id'] ? '<a href="' . e(url('/customers/' . $po['customer_id'])) . '">' . e($po['customer_name']) . '</a>' : '<span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span>' ?></td>
                        <td class="d-none d-xl-table-cell nowrap text-secondary"><?= e(fmt_date($po['po_date'], 'Tanpa tanggal')) ?></td>
                        <td class="d-none d-sm-table-cell d-lg-none d-xl-table-cell"><?= status_badge($po['status']) ?></td>
                        <td class="num d-none d-xl-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                        <td class="num d-none d-xxl-table-cell"><?= e(fmt_qty($po['delivered_qty'])) ?></td>
                        <td class="num d-none d-md-table-cell nowrap"><?= e(fmt_money($po['grand_total'])) ?></td>
                        <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out)) ?><?= $out < 0 ? '<div class="x-small">over</div>' : '' ?>
                            <div class="progress-thin mt-1 d-none d-lg-block<?= $progress >= 100 ? ($out < 0 ? ' is-over' : ' is-done') : '' ?>" title="<?= $progress ?>% terkirim"><span style="width: <?= $progress ?>%"></span></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $orders->footer('PO') ?>
    <?php endif; ?>
</div>
