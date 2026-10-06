<?php

use App\Models\EmailLog;
use App\Models\ProductReturn;
use App\Models\ReturnAttachment;

/**
 * Detail retur & komplain.
 * @var array<string,mixed> $ret
 * @var list<array<string,mixed>> $files
 * @var list<array<string,mixed>> $emails
 * @var array<string,mixed>|null $line
 * @var list<array<string,mixed>> $issues
 */
$r = $ret;
$id = (int) $r['id'];
$base = '/returns/' . $id;
$res = (string) $r['resolution_status'];
$canEdit = can('returns.edit');
$qty = $r['return_qty'] ?? $r['affected_qty'];
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/returns')) ?>">Retur &amp; Komplain</a><i class="bi bi-chevron-right"></i><span><?= e($r['code']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi <?= $r['case_type'] === 'Retur' ? 'bi-arrow-return-left' : 'bi-chat-left-dots' ?>"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($r['case_type']) ?> <?= e($r['code']) ?> <?= status_badge($res, ProductReturn::RESOLUTION_LABELS[$res] ?? $res) ?></h1>
            <div class="detail-meta">
                <span><i class="bi bi-calendar3"></i><?= e(fmt_date($r['return_date'], 'Tanpa tanggal')) ?></span>
                <?php if ($r['customer_id']): ?><span><i class="bi bi-buildings"></i><?= can('customers.view') ? '<a href="' . e(url('/customers/' . $r['customer_id'])) . '">' . e($r['customer_name']) . '</a>' : e($r['customer_name']) ?></span><?php endif; ?>
                <?php if ($r['po_id']): ?><span><i class="bi bi-receipt"></i><?= can('purchase_orders.view') ? '<a href="' . e(url('/purchase-orders/' . $r['po_id'])) . '">' . e($r['order_ref']) . '</a>' : e($r['order_ref']) ?></span><?php endif; ?>
                <span><i class="bi bi-box-seam"></i><?= e($r['product_name'] ?? ($r['product_legacy'] ?? '—')) ?></span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($canEdit): ?><a class="btn btn-light" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    </div>
</div>

<section class="decision-panel section-gap <?= $res === 'Selesai' ? 'is-approved' : ($res === 'Tidak selesai' ? 'is-rejected' : 'is-pending') ?>" aria-label="Hasil penyelesaian">
    <?php if ($res === 'Open'): ?>
        <div class="decision-title"><i class="bi bi-hourglass-split me-1"></i>Belum ada hasil</div>
        <div class="small text-secondary">Tandai hasilnya setelah komplain ditangani. Hasil otomatis dikirim ke email QC<?= $r['qc_email'] ? ' (' . e($r['qc_email']) . ')' : ' — isi Email QC lewat Edit' ?>.</div>
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url($base . '/resolve')) ?>" class="mt-3">
                <?= csrf_field() ?>
                <label class="form-label small" for="resolution_note">Catatan penyelesaian <span class="text-secondary fw-normal">(wajib berupa alasan bila tidak selesai)</span></label>
                <textarea class="form-control" id="resolution_note" name="resolution_note" rows="2" maxlength="2000" placeholder="mis. barang diganti baru tanggal …, atau alasan tidak dapat diselesaikan"></textarea>
                <div class="decision-actions">
                    <button type="submit" name="resolution_status" value="Selesai" class="btn btn-success" data-confirm="Tandai kasus <?= e($r['code']) ?> SELESAI dan kirim hasilnya ke QC?"><i class="bi bi-check2-circle me-1"></i>Selesai</button>
                    <button type="submit" name="resolution_status" value="Tidak selesai" class="btn btn-danger" data-confirm="Tandai kasus <?= e($r['code']) ?> TIDAK SELESAI? Pastikan alasannya sudah ditulis."><i class="bi bi-x-circle me-1"></i>Tidak selesai</button>
                </div>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <div class="decision-title <?= $res === 'Selesai' ? 'text-success-ink' : 'text-danger-ink' ?>"><i class="bi <?= $res === 'Selesai' ? 'bi-check2-circle' : 'bi-x-circle' ?> me-1"></i><?= e($res) ?></div>
        <div class="small text-secondary"><?= e($r['resolved_by_name'] ?? '—') ?> · <?= e(fmt_datetime($r['resolved_at'])) ?></div>
        <div class="small mt-1"><strong><?= $res === 'Selesai' ? 'Catatan:' : 'Alasan:' ?></strong> <?= $r['resolution_note'] ? nl2br(e($r['resolution_note'])) : '—' ?></div>
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url($base . '/reopen')) ?>" class="mt-2" data-confirm="Buka kembali kasus ini? Hasil sebelumnya tetap tercatat di audit log.">
                <?= csrf_field() ?><button type="submit" class="btn btn-link-plain btn-sm px-0"><i class="bi bi-arrow-counterclockwise me-1"></i>Buka kembali</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($issues): ?>
    <div class="callout callout-warning section-gap small"><i class="bi bi-exclamation-triangle me-1"></i><strong><?= count($issues) ?> migration issue</strong> terbuka untuk data ini.
        <?php if (can('migration.view')): ?><a href="<?= e(url('/migration-issues', ['q' => $r['code']])) ?>">Tinjau</a><?php endif; ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8 min-w-0">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Detail</h2></div>
            <div class="surface-body">
                <dl class="dl-grid">
                    <div><dt><?= $r['case_type'] === 'Retur' ? 'Qty retur' : 'Qty bermasalah' ?></dt><dd class="fs-5 fw-semibold"><?= e(fmt_qty($qty)) ?><?= $qty !== null ? ' pcs' : '' ?></dd></div>
                    <div><dt>Alasan</dt><dd><?= e($r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—') ?></dd></div>
                    <div><dt>Surat jalan asal</dt><dd><?= $r['delivery_id'] && can('deliveries.view') ? '<a href="' . e(url('/deliveries/' . $r['delivery_id'])) . '">' . e($r['delivery_sj'] ?? 'Lihat delivery') . '</a>' : e($r['delivery_sj'] ?? '—') ?></dd></div>
                    <div><dt>Dokumen retur</dt><dd><?= e($r['sj_number'] ?? '—') ?></dd></div>
                    <div><dt>Asal / lokasi</dt><dd><?= e($r['destination'] ?? '—') ?></dd></div>
                    <div><dt>Link dokumen</dt><dd><?= external_link($r['attachment'], 'Buka dokumen') ?></dd></div>
                    <div class="dl-span"><dt>Detail masalah / catatan</dt><dd><?= $r['note'] ? nl2br(e($r['note'])) : '—' ?></dd></div>
                    <div><dt>Dicatat</dt><dd class="small"><?= e($r['created_by_name'] ?? '—') ?> · <?= e(fmt_datetime($r['created_at'])) ?></dd></div>
                    <?php if ($line): ?><div><dt>Outstanding produk OEF</dt><dd><?= e(fmt_qty($line['outstanding_qty'], '0')) ?> pcs</dd></div><?php endif; ?>
                    <?php if ($r['source_file']): ?><div><dt>Sumber data</dt><dd class="small text-secondary"><?= e($r['source_file']) ?> › <?= e($r['source_sheet']) ?> baris <?= (int) $r['legacy_row'] ?></dd></div><?php endif; ?>
                </dl>
            </div>
        </section>

        <section class="surface section-gap">
            <div class="surface-header"><div><h2 class="surface-title">Bukti komplain <span class="tab-count"><?= count($files) ?></span></h2>
                <p class="surface-subtitle">Gambar atau PDF. Hanya bisa dibuka oleh user yang berhak.</p></div></div>
            <?php if (!$files): ?>
                <div class="empty-inline">Belum ada file bukti.</div>
            <?php else: ?>
                <div class="surface-pad pt-0"><div class="evidence-grid">
                    <?php foreach ($files as $f): $fileUrl = url($base . '/files/' . $f['id']); $isPdf = $f['mime_type'] === 'application/pdf'; ?>
                        <div class="evidence-item">
                            <a class="evidence-thumb<?= $isPdf ? ' is-pdf' : '' ?>" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener" title="<?= e($f['original_name']) ?>">
                                <?php if ($isPdf): ?><i class="bi bi-file-earmark-pdf"></i><?php else: ?><img src="<?= e($fileUrl) ?>" alt="<?= e($f['original_name']) ?>" loading="lazy"><?php endif; ?>
                            </a>
                            <div class="evidence-meta">
                                <span class="name" title="<?= e($f['original_name']) ?>"><?= e($f['original_name']) ?></span>
                                <span class="d-flex gap-1">
                                    <a href="<?= e(url($base . '/files/' . $f['id'], ['download' => 1])) ?>" title="Unduh" aria-label="Unduh <?= e($f['original_name']) ?>"><i class="bi bi-download"></i></a>
                                    <?php if ($canEdit): ?>
                                        <form method="post" action="<?= e(url($base . '/files/' . $f['id'] . '/delete')) ?>" data-confirm="Hapus file <?= e($f['original_name']) ?>?" class="d-inline">
                                            <?= csrf_field() ?><button type="submit" class="btn btn-link p-0 text-danger" title="Hapus" aria-label="Hapus <?= e($f['original_name']) ?>"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div></div>
            <?php endif; ?>
            <?php if ($canEdit && count($files) < ReturnAttachment::maxFiles()): ?>
                <form class="surface-footer" method="post" action="<?= e(url($base . '/files')) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="row g-2 align-items-center">
                        <div class="col-md-9"><input class="form-control" type="file" name="evidence[]" multiple required accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf" aria-label="File bukti"></div>
                        <div class="col-md-3 d-grid"><button class="btn btn-light" type="submit"><i class="bi bi-upload"></i> Unggah</button></div>
                    </div>
                    <div class="form-text">JPG, PNG, WEBP, atau PDF · maks <?= (int) config('app.upload.max_evidence_mb', 5) ?> MB per file.</div>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4 min-w-0">
        <section class="surface section-gap">
            <div class="surface-header"><div><h2 class="surface-title">Email QC</h2>
                <p class="surface-subtitle text-break"><?= $r['qc_email'] ? e($r['qc_email']) : 'Belum diisi' ?></p></div>
                <?php if ($canEdit && $r['qc_email']): ?>
                    <form method="post" action="<?= e(url($base . '/email')) ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit"><i class="bi bi-send"></i> Kirim ulang</button></form>
                <?php endif; ?></div>
            <?php if (!$emails): ?>
                <div class="empty-inline">Belum pernah dikirim.</div>
            <?php else: ?>
                <ul class="list-lite">
                    <?php foreach ($emails as $m): ?>
                        <li><div class="li-main"><span class="li-title"><span class="badge-soft badge-soft-<?= EmailLog::tone((string) $m['status']) ?> no-dot"><?= e(EmailLog::LABELS[$m['status']] ?? $m['status']) ?></span></span>
                            <div class="li-sub text-break"><?= e($m['recipients']) ?><?= $m['error_message'] ? ' · ' . e($m['error_message']) : '' ?></div></div>
                            <div class="li-end x-small text-secondary"><?= e(fmt_datetime($m['created_at'])) ?><div><?= e($m['sent_by_name'] ?? '') ?></div></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="surface-footer x-small text-secondary">Pengiriman: <?= e(App\Helpers\Mailer::describe()) ?>.</div>
        </section>
    </div>
</div>
