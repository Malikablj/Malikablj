<?php

use App\Helpers\Form;
use App\Models\ProductReturn;

/** @var App\Helpers\Paginator $returns @var array<string,mixed> $filters @var array<string,int> $summary */
$hasFilter = $filters['q'] !== '' || $filters['reason'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['link'] !== ''
    || $filters['type'] !== '' || $filters['resolution'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Retur &amp; Komplain</h1>
        <p class="page-subtitle">Retur barang dan komplain customer beserta bukti, hasil penyelesaian, dan email ke QC.</p>
    </div>
    <div class="page-actions">
        <?php if (can('reports.complaint')): ?><a href="<?= e(url('/reports/complaint')) ?>" class="btn btn-light"><i class="bi bi-bar-chart-line"></i> Laporan</a><?php endif; ?>
        <?php if (can('returns.create')): ?><a href="<?= e(url('/returns/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Retur / Komplain</a><?php endif; ?>
    </div>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Kasus<?= $hasFilter ? ' (sesuai filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div>
        <div class="x-small text-secondary"><?= (int) ($summary['retur'] ?? 0) ?> retur · <?= (int) ($summary['komplain'] ?? 0) ?> komplain</div></div>
    <div><div class="stat-label">Belum ada hasil</div><div class="stat-value<?= ($summary['open_count'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($summary['open_count'] ?? 0, '0')) ?></div>
        <?php if (($summary['open_count'] ?? 0) > 0 && $filters['resolution'] === ''): ?><a class="x-small" href="<?= e(query_with(['resolution' => 'Open', 'page' => null])) ?>">Tampilkan</a><?php endif; ?></div>
    <div><div class="stat-label">Selesai</div><div class="stat-value text-success-ink"><?= e(fmt_qty($summary['done'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Tidak selesai</div><div class="stat-value<?= ($summary['failed'] ?? 0) > 0 ? ' text-danger-ink' : '' ?>"><?= e(fmt_qty($summary['failed'] ?? 0, '0')) ?></div></div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/returns')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari kode, OEF, PO, customer, produk, detail…" aria-label="Cari retur & komplain"></div>
        <select class="form-select" name="type" aria-label="Jenis" data-autosubmit><option value="">Retur &amp; komplain</option><?= Form::options(Form::list(ProductReturn::CASE_TYPES), $filters['type']) ?></select>
        <select class="form-select" name="resolution" aria-label="Hasil" data-autosubmit><option value="">Semua hasil</option><?= Form::options(ProductReturn::RESOLUTION_LABELS, $filters['resolution']) ?></select>
        <select class="form-select" name="reason" aria-label="Alasan" data-autosubmit><option value="">Semua alasan</option><?= Form::options(ProductReturn::REASON_LABELS, $filters['reason']) ?></select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <?php if ($filters['link'] !== ''): ?><input type="hidden" name="link" value="<?= e($filters['link']) ?>"><?php endif; ?>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/returns')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($returns->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-chat-left-dots"></i><div class="empty-title"><?= $hasFilter ? 'Tidak ada data yang cocok' : 'Belum ada retur atau komplain' ?></div><p>Retur & komplain dicatat per produk di OEF.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Kasus</th><th class="d-none d-md-table-cell">OEF · Customer</th><th class="d-none d-xl-table-cell">Produk</th><th class="d-none d-xl-table-cell">Alasan</th><th class="num d-none d-sm-table-cell">Qty</th><th class="d-none d-sm-table-cell">Hasil</th></tr></thead>
                <tbody>
                <?php foreach ($returns->items as $r): ?>
                    <tr>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($r['case_type']) ?> · <?= e(fmt_date($r['return_date'], 'tanpa tanggal')) ?></a>
                            <div class="cell-sub"><span class="code-chip"><?= e($r['code']) ?></span><?= (int) $r['evidence_count'] > 0 ? ' · <i class="bi bi-paperclip"></i>' . (int) $r['evidence_count'] . ' bukti' : '' ?></div>
                            <div class="cell-sub d-md-none"><?= e($r['order_ref'] ?? '') ?><?= $r['customer_name'] ? ' · ' . e($r['customer_name']) : '' ?></div>
                            <div class="cell-sub d-xl-none"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '')) ?></div>
                            <?php if ($r['reason']): ?><div class="cell-sub d-xl-none"><?= e(ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) ?></div><?php endif; ?>
                            <div class="cell-sub d-sm-none"><?= e(fmt_qty($r['return_qty'] ?? $r['affected_qty'])) ?> pcs</div>
                            <div class="d-sm-none mt-1"><?= status_badge($r['resolution_status'], ProductReturn::RESOLUTION_LABELS[$r['resolution_status']] ?? $r['resolution_status']) ?></div>
                            <?php if ($r['po_line_id'] === null): ?><div class="cell-sub"><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke produk OEF</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell small"><?php if ($r['po_id']): ?><?= can('purchase_orders.view') ? '<a href="' . e(url('/purchase-orders/' . $r['po_id'])) . '">' . e($r['order_ref']) . '</a>' : e($r['order_ref']) ?><?php else: ?><?= e($r['po_number_legacy'] ?? '—') ?><?php endif; ?>
                            <div class="text-secondary"><?= e($r['customer_name'] ?? $r['destination'] ?? '') ?></div></td>
                        <td class="d-none d-xl-table-cell small"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></td>
                        <td class="d-none d-xl-table-cell"><?= e($r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—') ?></td>
                        <td class="num d-none d-sm-table-cell fw-semibold"><?= e(fmt_qty($r['return_qty'] ?? $r['affected_qty'])) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($r['resolution_status'], ProductReturn::RESOLUTION_LABELS[$r['resolution_status']] ?? $r['resolution_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $returns->footer('kasus') ?>
    <?php endif; ?>
</div>
