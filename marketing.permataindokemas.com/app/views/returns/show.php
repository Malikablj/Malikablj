<?php

use App\Models\ComplaintAttachment;
use App\Models\ProductReturn;

/**
 * @var array<string,mixed> $ret
 * @var list<array<string,mixed>> $attachments
 * @var bool $mailReady
 */
$r = $ret;
$id = (int) $r['id'];
$base = '/returns/' . $id;
$status = (string) $r['complaint_status'];
$canResolve = can('returns.resolve');
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/returns')) ?>">Complaint &amp; Return</a><i class="bi bi-chevron-right"></i><span><?= e($r['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-exclamation-octagon"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($r['customer_name'] ?? 'Complaint') ?> <?= complaint_badge($status) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($r['code']) ?></span>
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?></span>
                <span><i class="bi bi-tag"></i><?= e(ProductReturn::TYPE_LABELS[$r['record_type']] ?? $r['record_type']) ?></span>
                <?php if ($r['created_by_name']): ?><span><i class="bi bi-person"></i><?= e($r['created_by_name']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('returns.edit')): ?><a class="btn btn-light" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8 min-w-0">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Detail complaint</h2></div>
            <div class="surface-body">
                <div class="spec-box mb-3"><?= $r['complaint_detail'] ? nl2br(e($r['complaint_detail'])) : '<span class="text-subtle">' . e($r['note'] ?? 'Tidak ada detail (data retur lama).') . '</span>' ?></div>
                <dl class="dl-grid">
                    <div><dt>Customer</dt><dd><?= $r['customer_id'] ? (can('customers.view') ? '<a href="' . e(url('/customers/' . $r['customer_id'])) . '">' . e($r['customer_name']) . '</a>' : e($r['customer_name'])) : '—' ?></dd></div>
                    <div><dt>Order</dt><dd><?= $r['po_id'] ? (can('purchase_orders.view') ? '<a href="' . e(url('/purchase-orders/' . $r['po_id'])) . '">' . e($r['po_number'] ?? $r['po_code']) . '</a>' : e($r['po_number'] ?? $r['po_code'])) : e($r['po_number_legacy'] ?? '—') ?></dd></div>
                    <div><dt>Produk</dt><dd><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></dd></div>
                    <div><dt>Alasan</dt><dd><?= e($r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—') ?></dd></div>
                    <?php if ($r['record_type'] === 'Return'): ?>
                        <div><dt>Qty retur</dt><dd class="fw-semibold"><?= e(fmt_qty($r['return_qty'])) ?> pcs</dd></div>
                        <div><dt>Surat jalan asal</dt><dd><?= $r['delivery_id'] ? '<a href="' . e(url('/deliveries/' . $r['delivery_id'])) . '">' . e($r['delivery_sj'] ?? 'Delivery') . '</a>' : '—' ?></dd></div>
                        <div><dt>Dokumen retur</dt><dd><?= e($r['sj_number'] ?? '—') ?></dd></div>
                        <div><dt>Asal / lokasi</dt><dd><?= e($r['destination'] ?? '—') ?></dd></div>
                    <?php endif; ?>
                    <div><dt>Link dokumen</dt><dd><?= external_link($r['attachment'], 'Buka dokumen') ?></dd></div>
                    <?php if ($r['note']): ?><div><dt>Catatan internal</dt><dd><?= nl2br(e($r['note'])) ?></dd></div><?php endif; ?>
                </dl>
                <?php if ($r['record_type'] === 'Return' && $r['po_line_id'] === null): ?>
                    <div class="callout callout-warning small mt-3"><i class="bi bi-exclamation-triangle me-1"></i>Retur ini belum terhubung ke baris order sehingga belum menambah outstanding.
                        <?php if (can('returns.edit')): ?><a href="<?= e(url($base . '/edit')) ?>">Hubungkan</a><?php endif; ?></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="surface section-gap">
            <div class="surface-header"><div><h2 class="surface-title">Bukti complaint <span class="tab-count"><?= count($attachments) ?></span></h2>
                <p class="surface-subtitle">Gambar atau PDF. Klik untuk membuka ukuran penuh.</p></div>
                <?php if (can('returns.edit') && count($attachments) < ComplaintAttachment::MAX_FILES): ?><a class="btn btn-light btn-sm" href="<?= e(url($base . '/edit')) ?>#f_evidence"><i class="bi bi-plus-lg"></i> Tambah bukti</a><?php endif; ?></div>
            <?php if (!$attachments): ?>
                <div class="empty-inline">Belum ada bukti yang diunggah.</div>
            <?php else: ?>
                <div class="surface-body">
                    <div class="evidence-grid">
                        <?php foreach ($attachments as $a): $src = url('/returns/attachments/' . $a['id']); ?>
                            <div class="evidence-item">
                                <a class="evidence-link" href="<?= e($src) ?>" target="_blank" rel="noopener">
                                    <div class="evidence-thumb"><?php if (ComplaintAttachment::isImage($a)): ?><img src="<?= e($src) ?>" alt="<?= e($a['original_name']) ?>" loading="lazy"><?php else: ?><i class="bi bi-file-earmark-pdf"></i><?php endif; ?></div>
                                    <div class="evidence-meta"><span class="name" title="<?= e($a['original_name']) ?>"><?= e($a['original_name']) ?></span>
                                        <span class="text-secondary"><?= e(ComplaintAttachment::human((int) $a['file_size'])) ?> · <?= e(fmt_date($a['created_at'])) ?></span></div>
                                </a>
                                <?php if (can('returns.edit')): ?>
                                    <form method="post" action="<?= e(url('/returns/attachments/' . $a['id'] . '/delete', ['return' => $base])) ?>" data-confirm="Hapus file bukti ini?">
                                        <?= csrf_field() ?><button type="submit" class="btn btn-light btn-sm" title="Hapus file" aria-label="Hapus file"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4 min-w-0">
        <section class="surface section-gap resolve-panel">
            <div class="surface-header"><div><h2 class="surface-title">Hasil penanganan</h2><p class="surface-subtitle">Tercatat di laporan Complaint.</p></div><?= complaint_badge($status) ?></div>
            <div class="surface-body">
                <?php if ($status !== 'Open'): ?>
                    <div class="callout <?= $status === 'Resolved' ? 'callout-success' : 'callout-danger' ?> mb-3">
                        <div class="fw-semibold"><i class="bi <?= $status === 'Resolved' ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?> me-1"></i><?= e(ProductReturn::STATUS_LABELS[$status]) ?></div>
                        <div class="small text-secondary"><?= e($r['resolved_by_name'] ?? '—') ?> · <?= e(fmt_datetime($r['resolved_at'])) ?></div>
                        <?php if ($r['resolution_note']): ?><div class="mt-2"><strong><?= $status === 'Unresolved' ? 'Alasan' : 'Hasil' ?>:</strong> <?= nl2br(e($r['resolution_note'])) ?></div><?php endif; ?>
                    </div>
                    <?php if ($canResolve): ?>
                        <form method="post" action="<?= e(url($base . '/resolve')) ?>" data-confirm="Buka kembali complaint ini?">
                            <?= csrf_field() ?><input type="hidden" name="outcome" value="reopen">
                            <button type="submit" class="btn btn-light btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Buka kembali</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($canResolve): ?>
                    <form method="post" action="<?= e(url($base . '/resolve')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="resolution_note">Hasil penanganan / alasan <span class="text-secondary fw-normal">(alasan wajib bila tidak selesai)</span></label>
                        <textarea class="form-control mb-3" id="resolution_note" name="resolution_note" rows="4" maxlength="5000" placeholder="mis. barang diganti baru tgl …, customer menerima / customer menolak karena …"></textarea>
                        <div class="d-grid gap-2">
                            <button type="submit" name="outcome" value="resolved" class="btn btn-success"><i class="bi bi-check-lg"></i> Selesai</button>
                            <button type="submit" name="outcome" value="unresolved" class="btn btn-danger" data-confirm="Tandai complaint ini TIDAK selesai?"><i class="bi bi-x-lg"></i> Tidak selesai</button>
                        </div>
                        <?php if ($r['qc_email']): ?><div class="form-text">Hasil otomatis dikirim ke email QC.</div><?php endif; ?>
                    </form>
                <?php else: ?>
                    <p class="small text-secondary mb-0">Complaint sedang ditangani.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Email QC</h2>
                <?php if ($r['email_status'] === 'sent'): ?><span class="badge-soft badge-soft-success">Terkirim</span><?php elseif ($r['email_status'] === 'failed'): ?><span class="badge-soft badge-soft-danger">Gagal</span><?php endif; ?></div>
            <div class="surface-body">
                <?php if (!$r['qc_email']): ?>
                    <p class="small text-secondary mb-2">Email QC belum diisi, sehingga complaint tidak dikirim ke QC.</p>
                    <?php if (can('returns.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url($base . '/edit')) ?>#f_qc_email">Isi email QC</a><?php endif; ?>
                <?php else: ?>
                    <div class="small mb-1"><i class="bi bi-envelope me-1"></i><?= e($r['qc_email']) ?></div>
                    <?php if ($r['email_sent_at']): ?><div class="small text-secondary">Terakhir terkirim <?= e(fmt_datetime($r['email_sent_at'])) ?></div><?php endif; ?>
                    <?php if ($r['email_status'] === 'failed' && $r['email_error']): ?><div class="small text-danger mt-1"><?= e($r['email_error']) ?></div><?php endif; ?>
                    <?php if (!$mailReady): ?><div class="small text-warning-ink mt-2">Pengaturan email belum lengkap<?= can('settings.view') ? ' — <a href="' . e(url('/settings')) . '#email">buka Settings</a>' : '' ?>.</div><?php endif; ?>
                    <?php if (can('returns.edit')): ?>
                        <form class="mt-3" method="post" action="<?= e(url($base . '/email')) ?>">
                            <?= csrf_field() ?><button type="submit" class="btn btn-light btn-sm"><i class="bi bi-send"></i> <?= $r['email_status'] === 'sent' ? 'Kirim ulang email' : 'Kirim email ke QC' ?></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
