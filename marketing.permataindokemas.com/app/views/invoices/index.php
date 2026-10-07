<?php

use App\Helpers\Form;
use App\Models\Invoice;

/**
 * @var App\Helpers\Paginator $invoices
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $filters
 * @var array<int,string> $customers
 */
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['due'] !== '' || $filters['link'] !== '';
$today = today();
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Finance</div>
        <h1 class="page-title">Invoice &amp; Payment</h1>
        <p class="page-subtitle">Sisa tagihan = Nilai invoice − Total dibayar. Invoice belum lunas yang lewat jatuh tempo otomatis berstatus Overdue.</p>
    </div>
    <?php if (can('finance.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/invoices/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buat Invoice</a></div>
    <?php endif; ?>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Total tagihan<?= $hasFilter ? ' (filter)' : '' ?></div><div class="stat-value"><?= e(fmt_money($summary['invoiced'] ?? 0)) ?></div><div class="x-small text-secondary"><?= e(fmt_qty($summary['n'] ?? 0, '0')) ?> invoice</div></div>
    <div><div class="stat-label">Sudah dibayar</div><div class="stat-value"><?= e(fmt_money($summary['paid'] ?? 0)) ?></div></div>
    <div><div class="stat-label">Sisa tagihan</div><div class="stat-value"><?= e(fmt_money($summary['outstanding'] ?? 0)) ?></div>
        <?php if ((float) ($summary['outstanding'] ?? 0) > 0 && $filters['status'] !== 'open'): ?><a class="x-small" href="<?= e(url('/invoices', ['status' => 'open'])) ?>">Lihat yang belum lunas</a><?php endif; ?></div>
    <div><div class="stat-label">Overdue</div><div class="stat-value<?= (int) ($summary['overdue_count'] ?? 0) > 0 ? ' text-danger' : '' ?>"><?= e(fmt_money($summary['overdue_amount'] ?? 0)) ?></div>
        <div class="x-small text-secondary"><?= (int) ($summary['overdue_count'] ?? 0) ?> invoice<?php if ((int) ($summary['overdue_count'] ?? 0) > 0 && $filters['status'] !== 'Overdue'): ?> · <a href="<?= e(url('/invoices', ['status' => 'Overdue'])) ?>">Lihat</a><?php endif; ?></div></div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/invoices')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nomor invoice, PO, customer, bukti bayar…" aria-label="Cari invoice"></div>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="open"<?= selected('open', $filters['status']) ?>>Belum lunas (Unpaid · Partial · Overdue)</option>
            <?= Form::options(Form::list(Invoice::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <select class="form-select" name="due" aria-label="Jatuh tempo" data-autosubmit><option value="">Semua jatuh tempo</option><option value="week"<?= selected('week', $filters['due']) ?>>Jatuh tempo 7 hari ke depan</option></select>
        <select class="form-select" name="link" aria-label="Kelengkapan" data-autosubmit>
            <option value="">Semua data</option>
            <option value="no_po"<?= selected('no_po', $filters['link']) ?>>Belum terhubung ke PO</option>
            <option value="no_due"<?= selected('no_due', $filters['link']) ?>>Belum lunas tanpa jatuh tempo</option>
        </select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Tanggal invoice dari">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Tanggal invoice sampai">
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/invoices')) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($invoices->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-cash-coin"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada invoice yang cocok' : 'Belum ada invoice' ?></div>
            <p><?= $hasFilter ? 'Coba kata kunci atau filter lain.' : 'Buat invoice untuk customer, lalu catat pembayarannya.' ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr>
                    <th><?= sort_link('number', 'Invoice', $sort, $dir) ?></th>
                    <th class="d-none d-md-table-cell"><?= sort_link('customer', 'Customer', $sort, $dir) ?></th>
                    <th class="d-none d-sm-table-cell"><?= sort_link('due', 'Jatuh tempo', $sort, $dir) ?></th>
                    <th class="d-none d-sm-table-cell">Status</th>
                    <th class="num d-none d-lg-table-cell"><?= sort_link('amount', 'Tagihan', $sort, $dir) ?></th>
                    <th class="num d-none d-xl-table-cell">Dibayar</th>
                    <th class="num"><?= sort_link('outstanding', 'Sisa', $sort, $dir) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($invoices->items as $i): $overdue = $i['status'] === 'Overdue'; ?>
                    <tr>
                        <td class="min-w-0"><a class="cell-title" href="<?= e(url('/invoices/' . $i['id'])) ?>"><?= e($i['invoice_number'] ?? $i['code']) ?></a>
                            <div class="cell-sub"><?= e(fmt_date($i['invoice_date'], 'Tanpa tanggal')) ?><?= ($i['po_number'] ?? $i['po_number_legacy']) ? ' · PO ' . e($i['po_number'] ?? $i['po_number_legacy']) : '' ?></div>
                            <div class="cell-sub d-md-none"><?= e($i['customer_name'] ?? 'Customer belum terhubung') ?></div>
                            <div class="cell-sub d-sm-none"><?= status_badge($i['status']) ?> <span class="<?= $overdue ? 'text-danger' : '' ?>">jatuh tempo <?= e(fmt_date($i['due_date'], '—')) ?></span></div></td>
                        <td class="d-none d-md-table-cell small"><?= $i['customer_id'] ? '<a href="' . e(url('/customers/' . $i['customer_id'])) . '">' . e($i['customer_name']) . '</a>' : '<span class="badge-soft badge-soft-warning no-dot">Belum terhubung</span>' ?></td>
                        <td class="d-none d-sm-table-cell nowrap"><span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= e(fmt_date($i['due_date'], '—')) ?></span>
                            <?php if ($i['due_date'] && $i['status'] !== 'Paid'): ?><div class="cell-sub"><?= e(relative_day($i['due_date'])) ?></div><?php endif; ?></td>
                        <td class="d-none d-sm-table-cell"><?= status_badge($i['status']) ?></td>
                        <td class="num d-none d-lg-table-cell"><?= e(fmt_money($i['invoice_amount'])) ?></td>
                        <td class="num d-none d-xl-table-cell text-secondary"><?= e(fmt_money($i['paid_amount'])) ?></td>
                        <td class="num fw-semibold"><?= e(fmt_money(max(0, (float) $i['outstanding_amount']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $invoices->footer('invoice') ?>
    <?php endif; ?>
</div>
