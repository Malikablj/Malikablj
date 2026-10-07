<?php

use App\Models\InboundSupplier;

/**
 * @var array<string,mixed> $row
 * @var list<array<string,mixed>> $history
 */
$id = (int) $row['id'];
$unit = (string) $row['unit'];
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/inbound-supplier')) ?>">Inbound Supplier</a><i class="bi bi-chevron-right"></i><span><?= e($row['item_name']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-truck-flatbed"></i></span>
        <div class="min-w-0">
            <h1 class="page-title"><?= e($row['item_name']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($row['code']) ?></span>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($row['inbound_date'])) ?></span>
                <span><i class="bi bi-building"></i><?= e($row['supplier']) ?></span>
                <?php if ($row['category']): ?><span><i class="bi bi-tag"></i><?= e($row['category']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('inbound_supplier.edit')): ?><a class="btn btn-light" href="<?= e(url('/inbound-supplier/' . $id . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Qty diterima</div><div class="stat-value"><?= e(InboundSupplier::formatQty($row['quantity'], '0')) ?></div><div class="x-small text-secondary"><?= e($unit) ?></div></div>
    <div><div class="stat-label">Reject</div><div class="stat-value<?= (float) ($row['reject_qty'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(InboundSupplier::formatQty($row['reject_qty'], '0')) ?></div><div class="x-small text-secondary"><?= e($unit) ?></div></div>
    <div><div class="stat-label">Total masuk</div><div class="stat-value"><?= e(InboundSupplier::formatQty($row['total_in'], '0')) ?></div><div class="x-small text-secondary">Qty − Reject (<?= e($unit) ?>)</div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7 min-w-0">
        <section class="surface surface-pad section-gap">
            <dl class="dl-single mb-0">
                <dt>Supplier</dt><dd><?= e($row['supplier']) ?></dd>
                <dt>No. surat jalan</dt><dd><?= e($row['sj_number'] ?? '—') ?><?= $row['sj_date'] ? ' · ' . e(fmt_date($row['sj_date'])) : '' ?></dd>
                <dt>No. PO pembelian</dt><dd><?= e($row['po_reference'] ?? '—') ?></dd>
                <dt>Kode barang</dt><dd><?= e($row['item_code'] ?? '—') ?></dd>
                <dt>Penerima</dt><dd><?= e($row['receiver'] ?? '—') ?></dd>
                <dt>Lokasi simpan</dt><dd><?= e($row['location'] ?? '—') ?></dd>
                <dt>Lampiran</dt><dd><?= $row['attachment'] ? external_link($row['attachment'], 'Buka lampiran') : '—' ?></dd>
                <dt>Catatan</dt><dd><?= $row['notes'] ? nl2br(e($row['notes'])) : '—' ?></dd>
            </dl>
        </section>
    </div>
    <div class="col-lg-5 min-w-0">
        <section class="surface surface-pad section-gap small">
            <dl class="dl-single mb-0">
                <dt>Dicatat</dt><dd><?= e(fmt_datetime($row['created_at'])) ?><?= $row['created_by_name'] ? ' oleh ' . e($row['created_by_name']) : '' ?></dd>
                <?php if ($row['updated_at']): ?><dt>Diperbarui</dt><dd><?= e(fmt_datetime($row['updated_at'])) ?><?= $row['updated_by_name'] ? ' oleh ' . e($row['updated_by_name']) : '' ?></dd><?php endif; ?>
            </dl>
        </section>
        <?php if ($history): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Riwayat perubahan</h2></div>
                <ul class="list-lite">
                    <?php foreach ($history as $h): $changes = json_decode((string) $h['changes'], true); ?>
                        <li><div class="li-main"><span class="li-title"><?= e(ucfirst((string) $h['action'])) ?> oleh <?= e($h['user_name'] ?? 'sistem') ?></span>
                            <div class="li-sub"><?= is_array($changes) ? e(implode(', ', array_slice(array_keys($changes), 0, 5))) : '' ?></div></div>
                            <div class="li-end text-secondary x-small"><?= e(fmt_datetime($h['created_at'])) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
