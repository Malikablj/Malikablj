<?php

use App\Helpers\Form;

/**
 * @var array<string,mixed> $delivery
 * @var array<string,mixed>|null $line
 * @var list<array<string,mixed>> $returns
 * @var list<array<string,mixed>> $issues
 * @var array<string,array<int,string>> $lineOptions
 */
$d = $delivery;
$id = (int) $d['id'];
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/deliveries')) ?>">Deliveries</a><i class="bi bi-chevron-right"></i><span><?= e($d['sj_number'] ?? $d['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-truck"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($d['sj_number'] ?? 'Tanpa nomor SJ') ?> <?= status_badge($d['status']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($d['code']) ?></span>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($d['delivery_date'], 'Tanpa tanggal')) ?></span>
                <?php if ($d['destination']): ?><span><i class="bi bi-geo-alt"></i><?= e($d['destination']) ?></span><?php endif; ?>
                <?php if ((int) $d['is_oef_schedule'] === 1): ?><span><i class="bi bi-calendar-event"></i>Jadwal otomatis dari OEF<?= $d['ppic_status'] === 'Pending' ? ' · menunggu konfirmasi PPIC' : '' ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('returns.create') && $d['po_line_id']): ?><a class="btn btn-light" href="<?= e(url('/returns/create', ['po_line_id' => $d['po_line_id'], 'delivery_id' => $id])) ?>"><i class="bi bi-exclamation-octagon"></i> Complaint / retur</a><?php endif; ?>
        <?php if (can('deliveries.edit')): ?><a class="btn btn-primary" href="<?= e(url('/deliveries/' . $id . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Detail pengiriman</h2></div>
            <div class="surface-body">
                <dl class="dl-grid">
                    <div><dt>Qty</dt><dd class="fs-5 fw-semibold<?= (int) $d['delivered_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($d['delivered_qty'])) ?> pcs</dd></div>
                    <div><dt>Order</dt><dd><?= $d['po_id'] ? '<a href="' . e(url('/purchase-orders/' . $d['po_id'])) . '">' . e($d['po_number'] ?? $d['po_code']) . '</a>' : '<span class="text-subtle">Tidak diketahui</span>' ?></dd></div>
                    <div><dt>Customer</dt><dd><?= $d['customer_id'] ? (can('customers.view') ? '<a href="' . e(url('/customers/' . $d['customer_id'])) . '">' . e($d['customer_name']) . '</a>' : e($d['customer_name'])) : '—' ?></dd></div>
                    <div><dt>Produk</dt><dd><?= e($d['product_name'] ?? 'Belum terpetakan') ?></dd></div>
                    <div><dt>Lampiran</dt><dd><?= external_link($d['attachment'], 'Buka dokumen') ?></dd></div>
                    <div><dt>Catatan</dt><dd><?= $d['note'] ? nl2br(e($d['note'])) : '—' ?></dd></div>
                    <?php if ($d['source_file']): ?><div><dt>Sumber data</dt><dd class="small text-secondary"><?= e($d['source_file']) ?> › <?= e($d['source_sheet']) ?> baris <?= (int) $d['legacy_row'] ?></dd></div><?php endif; ?>
                </dl>
            </div>
        </section>
        <?php if ($returns): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Retur dari surat jalan ini</h2></div>
                <ul class="list-lite">
                    <?php foreach ($returns as $r): ?>
                        <li><div class="li-main"><span class="li-title"><?= e(fmt_qty($r['return_qty'])) ?> pcs · <?= e($r['reason'] ?? '—') ?></span><div class="li-sub"><?= e(fmt_date($r['return_date'])) ?></div></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <?php if ($line): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Baris PO</h2></div>
                <div class="surface-body">
                    <dl class="dl-grid dl-single">
                        <div><dt>Produk</dt><dd><?= e($line['product_name']) ?></dd></div>
                        <div><dt>Order / Terkirim / Retur</dt><dd class="tabular"><?= e(fmt_qty($line['order_qty'])) ?> / <?= e(fmt_qty($line['delivered_qty'], '0')) ?> / <?= e(fmt_qty($line['return_qty'], '0')) ?></dd></div>
                        <div><dt>Outstanding baris</dt><dd class="fw-semibold"><?= e(fmt_qty($line['outstanding_qty'], '0')) ?> pcs</dd></div>
                    </dl>
                </div>
            </section>
        <?php else: ?>
            <section class="surface section-gap">
                <div class="surface-header"><div><h2 class="surface-title">Belum terhubung ke baris PO</h2>
                    <p class="surface-subtitle">Data legacy: produk/baris PO tidak dapat dipetakan otomatis. Delivery ini belum mengurangi outstanding.</p></div></div>
                <?php if ($lineOptions && can('deliveries.edit')): ?>
                    <form class="surface-body" method="post" action="<?= e(url('/deliveries/' . $id . '/link')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label" for="link_line">Pilih baris PO yang benar</label>
                        <select class="form-select mb-2" id="link_line" name="po_line_id" required data-searchable="Cari produk…">
                            <option value="">— Pilih —</option><?= Form::options($lineOptions, null) ?>
                        </select>
                        <?php foreach ($issues as $i): if ($i['legacy_product'] || $i['legacy_po']): ?>
                            <div class="small text-secondary mb-2">Di spreadsheet: <strong><?= e($i['legacy_product'] ?? '—') ?></strong><?= $i['legacy_po'] ? ' · PO ' . e($i['legacy_po']) : '' ?></div>
                        <?php break; endif; endforeach; ?>
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-link-45deg"></i> Hubungkan</button>
                    </form>
                <?php elseif (!$d['po_id']): ?>
                    <div class="empty-inline">PO untuk delivery ini tidak ditemukan. Perbaiki lewat Edit dengan memilih baris PO.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
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
    </div>
</div>
