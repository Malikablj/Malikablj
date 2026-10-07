<?php

use App\Helpers\Form;
use App\Models\PoFinancial;

/**
 * @var App\Helpers\Paginator $rows
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $filters
 * @var array<int,string> $customers
 * @var list<string> $brands
 */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['brand'] !== '' || $filters['link'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Finance</div>
        <h1 class="page-title">PO Financials</h1>
        <p class="page-subtitle">Nilai PO per produk: Qty × Harga satuan, PPN, dan status pembayaran.</p>
    </div>
    <?php if (can('finance.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/po-financials/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Tambah</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Baris<?= $hasFilter ? ' (filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Nilai PO + PPN</div><div class="stat-value"><?= e(fmt_money($summary['total_incl'] ?? 0)) ?></div></div>
    <div><div class="stat-label">Belum lunas</div><div class="stat-value"><?= e(fmt_money($summary['unpaid_value'] ?? 0)) ?></div><div class="x-small text-secondary"><?= (int) ($summary['unpaid_count'] ?? 0) ?> baris</div></div>
    <?php if ((int) ($summary['no_po'] ?? 0) > 0): ?>
        <div><div class="stat-label">Belum terhubung ke PO</div><div class="stat-value text-warning-ink"><?= (int) $summary['no_po'] ?></div><a class="x-small" href="<?= e(url('/po-financials', ['link' => 'no_po'])) ?>">Lihat</a></div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/po-financials')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari PO, brand, produk, customer…" aria-label="Cari"></div>
        <select class="form-select" name="status" aria-label="Status pembayaran" data-autosubmit>
            <option value="">Semua status</option><?= Form::options(Form::list(PoFinancial::PAYMENT_STATUSES), $filters['status']) ?>
            <option value="none"<?= selected('none', $filters['status']) ?>>Tanpa status</option>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <?php if ($brands): ?><select class="form-select" name="brand" aria-label="Brand" data-autosubmit><option value="">Semua brand</option><?= Form::options(Form::list($brands), $filters['brand']) ?></select><?php endif; ?>
        <select class="form-select" name="link" aria-label="Relasi" data-autosubmit><option value="">Semua relasi</option><option value="no_po"<?= selected('no_po', $filters['link']) ?>>Belum terhubung ke PO</option></select>
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/po-financials')) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($rows->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-graph-up-arrow"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada data yang cocok' : 'Belum ada data finansial PO' ?></div>
            <p>Catat harga satuan per PO untuk menghitung nilai PO dan PPN.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>PO · Produk</th><th class="d-none d-lg-table-cell">Brand</th><th class="num d-none d-md-table-cell">Qty</th><th class="num d-none d-xl-table-cell">Harga satuan</th>
                    <th class="num">Total + PPN</th><th class="d-none d-sm-table-cell">Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows->items as $r): ?>
                    <tr>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/po-financials/' . $r['id'])) ?>"><?= e($r['po_number'] ?? $r['po_number_legacy'] ?? $r['code']) ?></a>
                            <?php if ($r['po_id'] === null): ?> <span class="badge-soft badge-soft-warning no-dot">Belum terhubung</span><?php endif; ?>
                            <div class="cell-sub"><?= e(excerpt($r['product_legacy'] ?? '—', 70)) ?></div>
                            <div class="cell-sub d-md-none">Qty <?= e(fmt_qty($r['order_qty'])) ?> · <?= e($r['customer_name'] ?? $r['brand'] ?? '') ?></div>
                            <div class="cell-sub d-sm-none"><?= status_badge($r['payment_status']) ?></div></td>
                        <td class="d-none d-lg-table-cell small"><?= e($r['brand'] ?? '—') ?><div class="text-secondary"><?= e($r['customer_name'] ?? '') ?></div></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($r['order_qty'])) ?></td>
                        <td class="num d-none d-xl-table-cell"><?= e(App\Helpers\Number::decimal($r['unit_price'])) ?></td>
                        <td class="num fw-semibold"><?= e(fmt_money($r['total_incl_ppn'])) ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($r['payment_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $rows->footer('baris') ?>
    <?php endif; ?>
</div>
