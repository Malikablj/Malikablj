<?php

use App\Helpers\Form;
use App\Models\ProductReturn;

/** @var App\Helpers\Paginator $returns @var array<string,mixed> $filters @var array<string,int> $counts */
$hasFilter = $filters['q'] !== '' || $filters['type'] !== '' || $filters['status'] !== '' || $filters['reason'] !== '' || $filters['customer_id'] > 0
    || $filters['from'] !== '' || $filters['to'] !== '' || $filters['link'] !== '';
$tabs = ['' => 'Semua', 'Open' => 'Dalam proses', 'Resolved' => 'Selesai', 'Unresolved' => 'Tidak selesai'];
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Complaint &amp; Return</h1>
        <p class="page-subtitle">Keluhan customer beserta bukti, retur barang, dan hasil penanganannya. Hasil complaint dikirim ke email QC dan tercatat di laporan.</p>
    </div>
    <div class="page-actions">
        <?php if (can('reports.complaint')): ?><a href="<?= e(url('/reports/complaint')) ?>" class="btn btn-light"><i class="bi bi-bar-chart-line"></i> Laporan</a><?php endif; ?>
        <?php if (can('returns.create')): ?><a href="<?= e(url('/returns/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Catat Complaint</a><?php endif; ?>
    </div>
</div>

<nav class="tabs-pik" aria-label="Status complaint">
    <?php foreach ($tabs as $key => $label): $n = $key === '' ? array_sum($counts) : ($counts[$key] ?? 0); ?>
        <a href="<?= e(query_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>" class="<?= $filters['status'] === $key ? 'active' : '' ?>"<?= $filters['status'] === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="tab-count<?= $key === 'Open' && $n > 0 ? ' text-warning-ink' : '' ?>"><?= (int) $n ?></span></a>
    <?php endforeach; ?>
</nav>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/returns')) ?>">
        <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari no. complaint, order, customer, produk, isi complaint…" aria-label="Cari complaint"></div>
        <select class="form-select" name="type" aria-label="Jenis" data-autosubmit><option value="">Semua jenis</option><?= Form::options(ProductReturn::TYPE_SHORT, $filters['type']) ?></select>
        <select class="form-select" name="reason" aria-label="Alasan" data-autosubmit><option value="">Semua alasan</option><?= Form::options(ProductReturn::REASON_LABELS, $filters['reason']) ?></select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <select class="form-select" name="link" aria-label="Relasi" data-autosubmit><option value="">Semua relasi</option><option value="unlinked"<?= selected('unlinked', $filters['link']) ?>>Retur belum terhubung ke order</option></select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/returns')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($returns->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-exclamation-octagon"></i><div class="empty-title">Tidak ada complaint</div><p>Complaint customer (dengan atau tanpa retur barang) akan tampil di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th class="d-none d-sm-table-cell">Tanggal</th><th>Complaint · Customer</th><th class="d-none d-lg-table-cell">Produk · Order</th><th class="d-none d-md-table-cell">Alasan</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-md-table-cell">Qty retur</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($returns->items as $r): ?>
                    <tr>
                        <td class="nowrap d-none d-sm-table-cell"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?></td>
                        <td><a class="cell-title" href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($r['customer_name'] ?? ($r['destination'] ?? 'Customer ?')) ?></a>
                            <div class="cell-sub"><span class="code-chip"><?= e($r['code']) ?></span> · <?= e(ProductReturn::TYPE_SHORT[$r['record_type']] ?? $r['record_type']) ?>
                                <?php if ((int) $r['evidence_count'] > 0): ?> · <i class="bi bi-paperclip" title="File bukti"></i><?= (int) $r['evidence_count'] ?><?php endif; ?>
                                <?php if ($r['email_status'] === 'sent'): ?> · <i class="bi bi-envelope-check" title="Email QC terkirim"></i><?php elseif ($r['email_status'] === 'failed'): ?> · <i class="bi bi-envelope-exclamation text-danger" title="Email QC gagal"></i><?php endif; ?></div>
                            <?php if ($r['complaint_detail']): ?><div class="cell-sub"><?= e(excerpt($r['complaint_detail'], 90)) ?></div><?php endif; ?>
                            <div class="cell-sub d-sm-none"><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?> · <?= complaint_badge($r['complaint_status']) ?></div></td>
                        <td class="d-none d-lg-table-cell small"><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?>
                            <div class="text-secondary"><?php if ($r['po_id']): ?><a href="<?= e(url('/purchase-orders/' . $r['po_id'])) ?>"><?= e($r['po_number'] ?? $r['po_code']) ?></a><?php else: ?><?= e($r['po_number_legacy'] ?? 'Tanpa order') ?><?php endif; ?></div>
                            <?php if ($r['po_line_id'] === null && $r['record_type'] === 'Return'): ?><div><span class="badge-soft badge-soft-warning no-dot">Belum terhubung ke order</span></div><?php endif; ?></td>
                        <td class="d-none d-md-table-cell"><?= e($r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—') ?></td>
                        <td class="d-none d-sm-table-cell"><?= complaint_badge($r['complaint_status']) ?>
                            <?php if ($r['complaint_status'] === 'Unresolved' && $r['resolution_note']): ?><div class="cell-sub text-danger"><?= e(excerpt($r['resolution_note'], 50)) ?></div><?php endif; ?></td>
                        <td class="num fw-semibold d-none d-md-table-cell"><?= e($r['record_type'] === 'Return' ? fmt_qty($r['return_qty']) : '—') ?></td>
                        <td class="col-actions"><a class="btn btn-light btn-sm" href="<?= e(url('/returns/' . $r['id'])) ?>">Detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $returns->footer('complaint') ?>
    <?php endif; ?>
</div>
