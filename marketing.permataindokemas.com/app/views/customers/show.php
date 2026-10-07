<?php

use App\Helpers\View;

/**
 * @var array<string,mixed> $customer
 * @var array<string,int> $counts
 * @var array<string,mixed> $summary
 * @var array<string,array{label:string,count:int|null}> $tabs
 * @var string $tab
 * @var array<string,mixed> $tabData
 */
$id = (int) $customer['id'];
$base = '/customers/' . $id;
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/customers')) ?>">Customers</a><i class="bi bi-chevron-right"></i><span><?= e($customer['name']) ?></span></div>

<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><?= e(initials($customer['name'])) ?></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($customer['name']) ?> <?= status_badge($customer['status']) ?></h1>
            <div class="detail-meta">
                <span><i class="bi bi-hash"></i><span class="code-chip"><?= e($customer['code']) ?></span></span>
                <?php if ($customer['industry']): ?><span><i class="bi bi-tag"></i><?= e($customer['industry']) ?></span><?php endif; ?>
                <span><i class="bi bi-person-badge"></i>PIC: <?= e($customer['pic_name'] ?? 'belum ditentukan') ?></span>
                <?php if ($customer['phone']): ?><span><i class="bi bi-telephone"></i><?= e($customer['phone']) ?></span><?php endif; ?>
                <?php if ($customer['email']): ?><span><i class="bi bi-envelope"></i><a href="mailto:<?= e($customer['email']) ?>"><?= e($customer['email']) ?></a></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('activities.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/activities/create', ['customer_id' => $id, 'return' => $base])) ?>"><i class="bi bi-plus-lg"></i> Log aktivitas</a>
        <?php endif; ?>
        <?php if (can('followups.create')): ?>
            <a class="btn btn-light" href="<?= e(url('/follow-ups/create', ['customer_id' => $id, 'return' => $base])) ?>"><i class="bi bi-calendar-plus"></i> Follow up</a>
        <?php endif; ?>
        <?php if (can('purchase_orders.create')): ?>
            <a class="btn btn-light" href="<?= e(url('/purchase-orders/create', ['customer_id' => $id])) ?>"><i class="bi bi-receipt"></i> Buat OEF</a>
        <?php endif; ?>
        <?php if (can('customers.edit') || can('customers.delete') || can('leads.create')): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-icon" type="button" data-bs-toggle="dropdown" aria-label="Aksi lain"><i class="bi bi-three-dots"></i></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <?php if (can('leads.create')): ?><a class="dropdown-item" href="<?= e(url('/leads/create', ['customer_id' => $id])) ?>"><i class="bi bi-kanban me-2"></i>Buat lead</a><?php endif; ?>
                    <?php if (can('contacts.create')): ?><a class="dropdown-item" href="<?= e(url('/contacts/create', ['customer_id' => $id, 'return' => $base . '?tab=contacts'])) ?>"><i class="bi bi-person-plus me-2"></i>Tambah kontak</a><?php endif; ?>
                    <?php if (can('customers.edit')): ?><a class="dropdown-item" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil me-2"></i>Edit customer</a><?php endif; ?>
                    <?php if (can('customers.delete')): ?>
                        <div class="dropdown-divider"></div>
                        <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="Hapus customer ini? Customer yang sudah punya transaksi/riwayat CRM tidak dapat dihapus.">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus customer</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="stat-strip section-gap">
    <?php if (can('leads.view')): ?>
        <div><div class="stat-label">Leads aktif</div><div class="stat-value"><?= e(fmt_qty($summary['leads_open'], '0')) ?></div><div class="x-small text-secondary"><?= e(fmt_money($summary['leads_pipeline'], 'Rp 0')) ?></div></div>
    <?php endif; ?>
    <?php if (can('followups.view')): ?>
        <div><div class="stat-label">Follow up terbuka</div><div class="stat-value"><?= e(fmt_qty($summary['followups_open'], '0')) ?></div>
            <div class="x-small <?= $summary['followups_overdue'] > 0 ? 'is-negative fw-semibold' : 'text-secondary' ?>"><?= e($summary['followups_overdue']) ?> overdue</div></div>
    <?php endif; ?>
    <?php if (can('purchase_orders.view')): ?>
        <div><div class="stat-label">Order berjalan</div><div class="stat-value"><?= e(fmt_qty($summary['po_open'], '0')) ?></div><div class="x-small text-secondary">dari <?= e(fmt_qty($summary['po_count'], '0')) ?> order</div></div>
        <div><div class="stat-label">Outstanding</div><div class="stat-value"><?= e(fmt_qty($summary['outstanding_qty'], '0')) ?></div><div class="x-small text-secondary">pcs di order berjalan</div></div>
    <?php endif; ?>
    <div><div class="stat-label">Aktivitas terakhir</div><div class="stat-value fs-6 mt-1"><?= e(fmt_date($summary['last_activity'], 'Belum ada')) ?></div><div class="x-small text-secondary"><?= e(relative_day($summary['last_activity'])) ?></div></div>
</div>

<nav class="tabs-pik" aria-label="Bagian customer">
    <?php foreach ($tabs as $key => $t): ?>
        <a href="<?= e(url($base, $key === 'overview' ? [] : ['tab' => $key])) ?>" class="<?= $tab === $key ? 'active' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
            <?= e($t['label']) ?><?php if ($t['count'] !== null): ?><span class="tab-count"><?= (int) $t['count'] ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?= View::partial('customers/tabs/' . $tab, ['customer' => $customer, 'data' => $tabData, 'base' => $base, 'history' => $history ?? []]) ?>
