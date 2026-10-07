<?php
/**
 * @var array<string,mixed> $row
 * @var list<array<string,mixed>> $issues
 * @var list<array<string,mixed>> $history
 */
$id = (int) $row['id'];
$total = $row['total_in'] ?? ($row['quantity'] !== null ? (int) $row['quantity'] - (int) ($row['reject_qty'] ?? 0) : null);
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/inbound')) ?>">Inbound Maklon</a><i class="bi bi-chevron-right"></i><span><?= e($row['sj_number'] ?? $row['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-box-arrow-in-down"></i></span>
        <div class="min-w-0">
            <h1 class="page-title"><?= e($row['sj_number'] ?? 'Tanpa nomor SJ') ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($row['code']) ?></span>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($row['actual_inbound_date'], 'Tanpa tanggal')) ?></span>
                <span><i class="bi bi-truck"></i><?= e($row['vendor'] ?? '—') ?><?= $row['receiver'] ? ' → ' . e($row['receiver']) : '' ?></span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('inbound.edit')): ?><a class="btn btn-light" href="<?= e(url('/inbound/' . $id . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Qty diterima</div><div class="stat-value"><?= e(fmt_qty($row['quantity'], '0')) ?></div></div>
    <div><div class="stat-label">Reject</div><div class="stat-value<?= (int) ($row['reject_qty'] ?? 0) > 0 ? ' text-warning-ink' : '' ?>"><?= e(fmt_qty($row['reject_qty'], '0')) ?></div></div>
    <div><div class="stat-label">Total masuk</div><div class="stat-value"><?= e(fmt_qty($total, '0')) ?></div><div class="x-small text-secondary">Qty − Reject</div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7 min-w-0">
        <section class="surface surface-pad section-gap">
            <dl class="dl-single mb-0">
                <dt>Nama barang / komponen</dt><dd><?= e($row['component_name'] ?? '—') ?><?= $row['type'] ? ' <span class="chip">' . e($row['type']) . '</span>' : '' ?></dd>
                <dt>Produk</dt><dd><?php if ($row['product_id']): ?><?php if (can('products.view')): ?><a href="<?= e(url('/products/' . $row['product_id'])) ?>"><?= e($row['product_name']) ?></a><?php else: ?><?= e($row['product_name']) ?><?php endif; ?><?php else: ?><span class="text-subtle">Tidak terhubung</span><?php endif; ?></dd>
                <dt>Kode komponen internal</dt><dd><?= e($row['internal_component_code'] ?? '—') ?></dd>
                <dt>Kode komponen pabrik</dt><dd><?= e($row['factory_component_code'] ?? '—') ?></dd>
                <dt>Order / PO</dt><dd><?php if ($row['po_id']): ?><?php if (can('purchase_orders.view')): ?><a href="<?= e(url('/purchase-orders/' . $row['po_id'])) ?>"><?= e($row['po_number'] ?? $row['po_code']) ?></a><?php else: ?><?= e($row['po_number'] ?? $row['po_code']) ?><?php endif; ?><?= $row['customer_name'] ? ' · ' . e($row['customer_name']) : '' ?>
                    <?php elseif ($row['po_number_legacy']): ?><?= e($row['po_number_legacy']) ?> <span class="badge-soft badge-soft-warning no-dot">Belum terhubung</span><?php else: ?><span class="text-subtle">—</span><?php endif; ?></dd>
                <dt>Tanggal surat jalan</dt><dd><?= e(fmt_date($row['sj_date'])) ?></dd>
                <dt>Checklist Odoo</dt><dd><?= e($row['odoo_checklist'] ?? '—') ?></dd>
                <dt>Lampiran</dt><dd><?= $row['attachment'] ? external_link($row['attachment'], 'Buka lampiran') : '—' ?></dd>
                <dt>Catatan</dt><dd><?= $row['notes'] ? nl2br(e($row['notes'])) : '—' ?></dd>
                <?php if ($row['source_file']): ?><dt>Sumber data</dt><dd class="text-secondary"><?= e($row['source_file']) ?> › <?= e($row['source_sheet']) ?> baris <?= (int) $row['legacy_row'] ?></dd><?php endif; ?>
            </dl>
        </section>
    </div>
    <div class="col-lg-5 min-w-0">
        <?php if ($issues): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Migration issue</h2></div>
                <ul class="list-lite">
                    <?php foreach ($issues as $i): ?>
                        <li><div class="li-main"><span class="li-title text-wrap"><?= e($i['issue_type']) ?></span><div class="li-sub text-wrap"><?= e(excerpt($i['description'] ?? '', 160)) ?></div></div>
                            <div class="li-end"><?= status_badge($i['resolution_status']) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
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
