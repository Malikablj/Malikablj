<?php

use App\Helpers\Form;
use App\Models\Customer;

/** @var App\Helpers\Paginator $customers @var array<string,mixed> $filters */
$showPo = can('purchase_orders.view');
$showAct = can('activities.view');
$hasFilter = $filters['q'] !== '' || $filters['status'] !== '' || $filters['pic'] > 0 || $filters['industry'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Customer &amp; CRM</div>
        <h1 class="page-title">Customers</h1>
        <p class="page-subtitle"><?= e(fmt_qty($customers->total, '0')) ?> customer<?= $hasFilter ? ' sesuai filter' : ' terdaftar' ?></p>
    </div>
    <?php if (can('customers.create')): ?>
        <div class="page-actions">
            <a href="<?= e(url('/customers/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Tambah Customer</a>
        </div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/customers')) ?>">
        <div class="filter-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama, kode, contact person, telepon…" aria-label="Cari customer">
        </div>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <?= Form::options(Form::list(Customer::STATUSES), $filters['status']) ?>
        </select>
        <select class="form-select" name="pic" aria-label="PIC Marketing" data-autosubmit>
            <option value="">Semua PIC</option>
            <?= Form::options($pics, (string) $filters['pic']) ?>
        </select>
        <?php if ($industries): ?>
            <select class="form-select" name="industry" aria-label="Industri" data-autosubmit>
                <option value="">Semua industri</option>
                <?= Form::options(Form::list($industries), $filters['industry']) ?>
            </select>
        <?php endif; ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/customers')) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($customers->isEmpty()): ?>
        <div class="empty-state">
            <i class="bi bi-buildings"></i>
            <div class="empty-title"><?= $hasFilter ? 'Tidak ada customer yang cocok' : 'Belum ada customer' ?></div>
            <p><?= $hasFilter ? 'Coba kata kunci atau filter lain.' : 'Tambahkan customer pertama atau impor data dari workbook.' ?></p>
            <?php if (!$hasFilter && can('customers.create')): ?><a href="<?= e(url('/customers/create')) ?>" class="btn btn-primary btn-sm">Tambah Customer</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead>
                <tr>
                    <th><?= sort_link('name', 'Customer', $sort, $dir) ?></th>
                    <th><?= sort_link('status', 'Status', $sort, $dir) ?></th>
                    <th class="d-none d-md-table-cell">PIC Marketing</th>
                    <th class="d-none d-xl-table-cell">Kontak</th>
                    <?php if ($showPo): ?>
                        <th class="num d-none d-md-table-cell"><?= sort_link('open_po', 'Open PO', $sort, $dir) ?></th>
                        <th class="num"><?= sort_link('outstanding', 'Outstanding', $sort, $dir) ?></th>
                    <?php endif; ?>
                    <?php if ($showAct): ?><th class="d-none d-lg-table-cell"><?= sort_link('last_activity', 'Aktivitas terakhir', $sort, $dir) ?></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($customers->items as $c): ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('/customers/' . $c['id'])) ?>"><?= e($c['name']) ?></a>
                            <div class="cell-sub"><span class="code-chip"><?= e($c['code']) ?></span><?php if ($c['industry']): ?><span class="dot-sep"></span><?= e($c['industry']) ?><?php endif; ?></div>
                        </td>
                        <td><?= status_badge($c['status']) ?></td>
                        <td class="d-none d-md-table-cell"><?= $c['pic_name'] ? e($c['pic_name']) : '<span class="text-subtle">—</span>' ?></td>
                        <td class="d-none d-xl-table-cell small">
                            <?php if ($c['pic'] || $c['phone'] || $c['email']): ?>
                                <div><?= e($c['pic'] ?? '') ?></div>
                                <div class="text-secondary"><?= e(trim(($c['phone'] ?? '') . ' ' . ($c['email'] ?? ''))) ?></div>
                            <?php else: ?><span class="text-subtle"><?= (int) $c['contact_count'] > 0 ? e($c['contact_count'] . ' kontak') : '—' ?></span><?php endif; ?>
                        </td>
                        <?php if ($showPo): ?>
                            <td class="num d-none d-md-table-cell"><?= (int) $c['open_po'] > 0 ? e(fmt_qty($c['open_po'])) : '<span class="text-subtle">0</span>' ?></td>
                            <td class="num"><?= (int) $c['outstanding'] > 0 ? e(fmt_qty($c['outstanding'])) : '<span class="text-subtle">0</span>' ?></td>
                        <?php endif; ?>
                        <?php if ($showAct): ?>
                            <td class="d-none d-lg-table-cell text-secondary small"><?= $c['last_activity'] ? e(fmt_date($c['last_activity'])) . '<div class="text-subtle">' . e(relative_day($c['last_activity'])) . '</div>' : '—' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $customers->footer('customer') ?>
    <?php endif; ?>
</div>
