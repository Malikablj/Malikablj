<?php
/**
 * @var array<string,mixed> $row
 * @var list<array<string,mixed>> $history
 */
$id = (int) $row['id'];
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/inbound-supplier')) ?>">Inbound Supplier</a><i class="bi bi-chevron-right"></i><span><?= e($row['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-truck-flatbed"></i></span>
        <div class="min-w-0">
            <h1 class="page-title"><?= e($row['item_name']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($row['code']) ?></span>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($row['receive_date'])) ?></span>
                <span><i class="bi bi-shop"></i><?= e($row['supplier']) ?><?= $row['receiver'] ? ' → ' . e($row['receiver']) : '' ?></span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('inbound_supplier.edit')): ?><a class="btn btn-light" href="<?= e(url('/inbound-supplier/' . $id . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Qty datang</div><div class="stat-value"><?= e(fmt_qty($row['quantity'], '0')) ?></div><div class="x-small text-secondary"><?= e($row['unit']) ?></div></div>
    <div><div class="stat-label">Reject</div><div class="stat-value<?= (int) $row['reject_qty'] > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($row['reject_qty'], '0')) ?></div></div>
    <div><div class="stat-label">Diterima bersih</div><div class="stat-value"><?= e(fmt_qty($row['accepted_qty'], '0')) ?></div><div class="x-small text-secondary">Qty datang − Reject</div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7 min-w-0">
        <section class="surface surface-pad section-gap">
            <dl class="dl-single mb-0">
                <dt>Spesifikasi</dt><dd><?= $row['specification'] ? nl2br(e($row['specification'])) : '—' ?></dd>
                <dt>No PO pembelian</dt><dd><?= e($row['purchase_number'] ?? '—') ?></dd>
                <dt>No surat jalan supplier</dt><dd><?= e($row['sj_number'] ?? '—') ?></dd>
                <dt>Catatan</dt><dd><?= $row['notes'] ? nl2br(e($row['notes'])) : '—' ?></dd>
                <dt>Dicatat</dt><dd><?= e($row['created_by_name'] ?? '—') ?> · <?= e(fmt_datetime($row['created_at'])) ?></dd>
            </dl>
        </section>
    </div>
    <?php if ($history): ?>
        <div class="col-lg-5 min-w-0">
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Riwayat perubahan</h2></div>
                <ul class="list-lite">
                    <?php foreach ($history as $h): ?>
                        <li><div class="li-main"><span class="li-title"><?= e($h['action']) ?></span><div class="li-sub"><?= e($h['user_name'] ?? 'Sistem') ?></div></div>
                            <div class="li-end x-small text-secondary"><?= e(fmt_datetime($h['created_at'])) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    <?php endif; ?>
</div>
