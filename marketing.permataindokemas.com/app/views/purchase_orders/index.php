<?php

use App\Helpers\Form;
use App\Models\PurchaseOrder;

/** @var App\Helpers\Paginator $orders @var array<string,mixed> $summary @var array<string,mixed> $filters @var int $ppicPending @var int $perPage @var list<int> $pageSizes @var string $returnPath */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['ppic'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['issue'] !== '';
$ppicOptions = PurchaseOrder::PPIC_LABELS + ['legacy' => 'PO lama (tanpa PPIC)'];
$canCustomer = can('customers.view');
// Admin & PPIC: centang beberapa order (atau semua di halaman ini) lalu ubah konfirmasi PPIC sekaligus
$canPpic = can('ppic.approve');
$selectable = $canPpic ? count(array_filter($orders->items, static fn ($po) => $po['ppic_status'] !== null)) : 0;
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Order Entry Form</h1>
        <p class="page-subtitle">Order dari customer, dikonfirmasi PPIC. Outstanding = Order − Delivered + Return, dihitung otomatis.</p>
    </div>
    <?php if (can('purchase_orders.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/purchase-orders/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buat OEF</a></div>
    <?php endif; ?>
</div>

<?php if ($ppicPending > 0 && $filters['ppic'] !== 'Pending'): ?>
    <a class="callout callout-warning section-gap d-flex align-items-center gap-2 text-decoration-none" href="<?= e(url('/purchase-orders', ['ppic' => 'Pending'])) ?>">
        <i class="bi bi-hourglass-split"></i>
        <span><strong><?= e(fmt_qty($ppicPending, '0')) ?> order</strong> menunggu konfirmasi PPIC<?= can('ppic.approve') ? ' — klik untuk meninjau.' : '.' ?></span>
    </a>
<?php endif; ?>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Order<?= $hasFilter ? ' (sesuai filter)' : '' ?></div><div class="stat-value"><?= e(fmt_qty($summary['po_count'] ?? 0, '0')) ?></div></div>
    <div><div class="stat-label">Total order</div><div class="stat-value"><?= e(fmt_qty($summary['total_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs</div></div>
    <div><div class="stat-label">Terkirim</div><div class="stat-value"><?= e(fmt_qty($summary['delivered_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs</div></div>
    <div><div class="stat-label">Outstanding</div><div class="stat-value"><?= e(fmt_qty($summary['outstanding_qty'] ?? 0, '0')) ?></div><div class="x-small text-secondary">pcs belum terkirim</div></div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/purchase-orders')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari no. order, no. PO, customer, produk, sales…" aria-label="Cari order"></div>
        <select class="form-select" name="ppic" aria-label="Konfirmasi PPIC" data-autosubmit>
            <option value="">Semua konfirmasi PPIC</option>
            <?= Form::options($ppicOptions, $filters['ppic']) ?>
        </select>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="open"<?= selected('open', $filters['status']) ?>>Berjalan (Open · On Process · Partial)</option>
            <?= Form::options(Form::list(PurchaseOrder::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Tanggal order dari">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Tanggal order sampai">
        <select class="form-select filter-item" name="per_page" aria-label="Jumlah per halaman" data-autosubmit>
            <?php foreach ($pageSizes as $size): ?><option value="<?= $size ?>"<?= selected($size, $perPage) ?>><?= $size ?> per halaman</option><?php endforeach; ?>
        </select>
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/purchase-orders')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($orders->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-title"><?= $hasFilter ? 'Tidak ada order yang cocok' : 'Belum ada order' ?></div><p>Order Entry Form dari customer akan tampil di sini.</p></div>
    <?php else: ?>
        <?php if ($selectable > 0): ?>
        <form method="post" action="<?= e(url('/purchase-orders/ppic-bulk')) ?>" data-bulk="ids[]" id="ppic-bulk">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= e($returnPath) ?>">
            <div class="bulk-bar">
                <div class="bulk-bar-info" title="Centang kotak di judul kolom untuk memilih semua order di halaman ini"><i class="bi bi-ui-checks"></i>
                    <span><strong data-bulk-count>0</strong> order dipilih</span>
                    <span class="text-secondary d-none d-xxl-inline">· kotak di judul kolom = pilih semua di halaman ini</span></div>
                <input type="text" class="form-control form-control-sm bulk-bar-note" name="ppic_note" id="bulk-ppic-note" maxlength="2000" placeholder="Catatan / alasan (wajib bila tidak bisa diproses)" aria-label="Catatan atau alasan PPIC">
                <button class="btn btn-success btn-sm" type="submit" name="decision" value="approve" data-bulk-action data-confirm="Tandai semua order yang dicentang BISA diproses?"><i class="bi bi-check2-circle"></i> Bisa diproses</button>
                <button class="btn btn-danger btn-sm" type="submit" name="decision" value="reject" data-bulk-action data-bulk-require="#bulk-ppic-note" data-confirm="Tandai semua order yang dicentang TIDAK bisa diproses? Jadwal delivery-nya akan dibatalkan."><i class="bi bi-x-circle"></i> Tidak bisa diproses</button>
                <div class="invalid-feedback w-100" data-bulk-feedback>Isi alasan mengapa order yang dicentang tidak bisa diproses.</div>
            </div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr>
                    <?php if ($selectable > 0): ?><th class="col-check"><input class="form-check-input" type="checkbox" data-check-all="ids[]" aria-label="Pilih semua order di halaman ini" title="Pilih semua order di halaman ini"></th><?php endif; ?>
                    <th><?= sort_link('number', 'No. order', $sort, $dir) ?></th>
                    <th class="d-none d-md-table-cell"><?= sort_link('customer', 'Customer · Produk', $sort, $dir) ?></th>
                    <th class="d-none d-lg-table-cell"><?= sort_link('requested', 'Permintaan kirim', $sort, $dir) ?></th>
                    <th class="d-none d-sm-table-cell"><?= sort_link('ppic', 'PPIC', $sort, $dir) ?></th>
                    <th class="d-none d-xl-table-cell"><?= sort_link('status', 'Status', $sort, $dir) ?></th>
                    <th class="num d-none d-md-table-cell"><?= sort_link('qty', 'Qty', $sort, $dir) ?></th>
                    <th class="num"><?= sort_link('outstanding', 'Outstanding', $sort, $dir) ?></th>
                    <th class="d-none d-lg-table-cell">Progres</th>
                </tr></thead>
                <tbody>
                <?php foreach ($orders->items as $po): $progress = pct((int) $po['delivered_qty'], (int) $po['total_qty']); $out = (int) $po['outstanding_qty']; ?>
                    <tr>
                        <?php if ($selectable > 0): ?><td class="col-check"><?php if ($po['ppic_status'] !== null): ?><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $po['id'] ?>" aria-label="Pilih order <?= e(PurchaseOrder::displayNumber($po)) ?>"><?php endif; ?></td><?php endif; ?>
                        <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e(PurchaseOrder::displayNumber($po)) ?></a>
                            <div class="cell-sub">
                                <?php if ($po['order_number'] && $po['po_number']): ?>PO <?= e($po['po_number']) ?> · <?php endif; ?>
                                <?= e(fmt_date($po['po_date'], 'Tanpa tanggal')) ?><?= $po['sales_name'] ? ' · ' . e($po['sales_name']) : '' ?>
                                <span class="d-md-none"> · <?= e($po['customer_name'] ?? '') ?></span></div>
                            <div class="d-sm-none mt-1"><?= ppic_badge($po['ppic_status']) ?> <?= status_badge($po['status']) ?></div></td>
                        <td class="d-none d-md-table-cell"><?= $po['customer_id'] ? ($canCustomer ? '<a href="' . e(url('/customers/' . $po['customer_id'])) . '">' . e($po['customer_name']) . '</a>' : e($po['customer_name'])) : '<span class="badge-soft badge-soft-warning no-dot">Customer belum terhubung</span>' ?>
                            <div class="cell-sub"><?= e($po['first_product'] ?? '—') ?><?= (int) $po['line_count'] > 1 ? ' +' . ((int) $po['line_count'] - 1) . ' produk' : '' ?><?= (int) $po['is_subcont'] === 1 ? ' · <span class="badge-soft badge-soft-neutral no-dot">Subcont</span>' : '' ?></div></td>
                        <td class="d-none d-lg-table-cell nowrap"><?= e(fmt_date($po['requested_date'])) ?>
                            <?php if ($po['schedule_date'] && $po['schedule_date'] !== $po['requested_date'] && $po['schedule_status'] !== 'Cancelled'): ?><div class="cell-sub">Jadwal: <?= e(fmt_date($po['schedule_date'])) ?></div><?php endif; ?></td>
                        <td class="d-none d-sm-table-cell"><?= ppic_badge($po['ppic_status']) ?></td>
                        <td class="d-none d-xl-table-cell"><?= status_badge($po['status']) ?></td>
                        <td class="num d-none d-md-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                        <td class="num fw-semibold<?= $out < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($out)) ?><?= $out < 0 ? '<div class="x-small">over</div>' : '' ?></td>
                        <td class="d-none d-lg-table-cell"><div class="progress-thin<?= $progress >= 100 ? ($out < 0 ? ' is-over' : ' is-done') : '' ?>" title="<?= $progress ?>% terkirim"><span style="width: <?= $progress ?>%"></span></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($selectable > 0): ?></form><?php endif; ?>
        <?= $orders->footer('order') ?>
    <?php endif; ?>
</div>
