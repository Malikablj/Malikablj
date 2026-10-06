<?php

use App\Helpers\Form;

/**
 * @var App\Helpers\Paginator $rows
 * @var array<string,int> $summary
 * @var array<string,mixed> $filters
 * @var list<string> $vendors
 * @var list<string> $receivers
 */
$hasFilter = $filters['q'] !== '' || $filters['vendor'] !== '' || $filters['receiver'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['link'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Inventory</div>
        <h1 class="page-title">Inbound Maklon</h1>
        <p class="page-subtitle">Penerimaan barang/komponen dari vendor maklon, diinput manual oleh Gudang. Total masuk = Qty diterima − Qty reject.</p>
    </div>
    <?php if (can('inbound.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/inbound/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Inbound</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Penerimaan<?= $hasFilter ? ' (filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Qty diterima</div><div class="stat-value"><?= e(fmt_qty($summary['qty'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Reject</div><div class="stat-value<?= ($summary['reject'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($summary['reject'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Total masuk</div><div class="stat-value"><?= e(fmt_qty($summary['total_in'] ?? 0, '0')) ?></div></div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/inbound')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari SJ, vendor, komponen, PO, produk…" aria-label="Cari inbound"></div>
        <?php if ($vendors): ?><select class="form-select" name="vendor" aria-label="Vendor" data-autosubmit><option value="">Semua vendor</option><?= Form::options(Form::list($vendors), $filters['vendor']) ?></select><?php endif; ?>
        <?php if ($receivers): ?><select class="form-select" name="receiver" aria-label="Penerima" data-autosubmit><option value="">Semua penerima</option><?= Form::options(Form::list($receivers), $filters['receiver']) ?></select><?php endif; ?>
        <select class="form-select" name="link" aria-label="Relasi" data-autosubmit>
            <option value="">Semua relasi</option>
            <option value="no_po"<?= selected('no_po', $filters['link']) ?>>Belum terhubung ke PO</option>
            <option value="no_product"<?= selected('no_product', $filters['link']) ?>>Belum terhubung ke produk</option>
        </select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/inbound')) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($rows->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-box-arrow-in-down"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada data yang cocok' : 'Belum ada inbound maklon' ?></div>
            <p>Catat setiap penerimaan komponen dari vendor beserta qty reject.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Surat Jalan · Komponen</th><th class="d-none d-md-table-cell">Vendor → Penerima</th><th class="d-none d-lg-table-cell">PO</th>
                    <th class="num d-none d-md-table-cell">Qty</th><th class="num d-none d-md-table-cell">Reject</th><th class="num">Total masuk</th></tr></thead>
                <tbody>
                <?php foreach ($rows->items as $r): $total = $r['total_in'] ?? ($r['quantity'] !== null ? (int) $r['quantity'] - (int) ($r['reject_qty'] ?? 0) : null); ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['actual_inbound_date'], 'Tanpa tanggal')) ?></td>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/inbound/' . $r['id'])) ?>"><?= e($r['sj_number'] ?? $r['code']) ?></a>
                            <div class="cell-sub"><?= e(excerpt($r['component_name'] ?? $r['product_name'] ?? '—', 70)) ?></div>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['actual_inbound_date'], 'Tanpa tanggal')) ?> · <?= e($r['vendor'] ?? '') ?></div>
                            <div class="cell-sub d-md-none">Qty <?= e(fmt_qty($r['quantity'])) ?><?= (int) ($r['reject_qty'] ?? 0) > 0 ? ' · reject ' . e(fmt_qty($r['reject_qty'])) : '' ?></div></td>
                        <td class="d-none d-md-table-cell small"><?= e($r['vendor'] ?? '—') ?><?= $r['receiver'] ? ' → ' . e($r['receiver']) : '' ?></td>
                        <td class="d-none d-lg-table-cell small"><?php if ($r['po_id']): ?><?= can('purchase_orders.view') ? '<a href="' . e(url('/purchase-orders/' . $r['po_id'])) . '">' . e($r['po_number'] ?? $r['po_code']) . '</a>' : e($r['po_number'] ?? $r['po_code']) ?><?php else: ?><span class="text-secondary"><?= e($r['po_number_legacy'] ?? '—') ?></span><?php endif; ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($r['quantity'])) ?></td>
                        <td class="num d-none d-md-table-cell<?= (int) ($r['reject_qty'] ?? 0) > 0 ? ' text-warning-ink fw-semibold' : '' ?>"><?= e(fmt_qty($r['reject_qty'], '0')) ?></td>
                        <td class="num fw-semibold"><?= e(fmt_qty($total)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('penerimaan') ?>
    <?php endif; ?>
</div>
