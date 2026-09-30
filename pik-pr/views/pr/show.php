<?php
/**
 * @var array $pr @var list<array> $items @var list<array> $attachments @var array $rounds
 * @var list<array> $progress @var list<array> $timeline @var array $can
 */
$actionLabels = ['approved' => 'Disetujui', 'rejected' => 'Ditolak', 'revision_required' => 'Minta revisi'];
$actionBadge = ['approved' => 'badge-approved', 'rejected' => 'badge-rejected', 'revision_required' => 'badge-revision-required'];
$latestRound = $rounds !== [] ? $rounds[max(array_keys($rounds))] : [];
$lastDecision = $latestRound !== [] ? $latestRound[count($latestRound) - 1] : null;
$id = (int) $pr['id'];

$checks = [
    ['Supplier dipilih', $pr['supplier_id'] !== null],
    ['Minimal satu item', $items !== []],
    ['Total lebih dari Rp0', \App\Support\Decimal::compare((string) $pr['grand_total'], '0') > 0],
    ['Workflow approval tersedia', $progress !== []],
];
$ready = !in_array(false, array_column($checks, 1), true);
?>
<a class="back-link" href="<?= e(url('/pr')) ?>"><?= icon('arrow-left') ?> Purchase Requisition</a>

<div class="pr-header">
    <div class="pr-title">
        <h1><?= e(pr_label($pr)) ?></h1>
        <div class="meta">
            <?= status_badge((string) $pr['status']) ?>
            <span><?= e($pr['department_name']) ?></span>
            <span aria-hidden="true">·</span>
            <span>Dibuat <?= e(tanggal($pr['created_at'], true)) ?></span>
            <?php if ((int) $pr['submission_round'] > 1): ?><span aria-hidden="true">·</span><span>Pengajuan ke-<?= e((string) $pr['submission_round']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($can['approve']): ?>
            <button type="button" class="btn btn-success" data-dialog-open="dlg-approve"><?= icon('check') ?> Setujui</button>
            <button type="button" class="btn btn-warning" data-dialog-open="dlg-revision"><?= icon('rotate') ?> Minta revisi</button>
            <button type="button" class="btn btn-danger" data-dialog-open="dlg-reject"><?= icon('x') ?> Tolak</button>
        <?php endif; ?>
        <?php if ($can['edit']): ?>
            <a class="btn btn-secondary" href="<?= e(url('/pr/' . $id . '/edit')) ?>"><?= icon('edit') ?> Ubah</a>
        <?php endif; ?>
        <?php if ($can['submit'] && !$reviewMode): ?>
            <form method="post" action="<?= e(url('/pr/' . $id . '/submit')) ?>" data-confirm="Ajukan PR ini ke approver? Setelah diajukan, PR tidak dapat diubah kecuali diminta revisi.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary"><?= icon('send') ?> <?= $pr['status'] === 'revision_required' ? 'Submit ulang' : 'Submit' ?></button>
            </form>
        <?php endif; ?>
        <?php if ($can['pdf']): ?>
            <a class="btn btn-primary" href="<?= e(url('/pr/' . $id . '/pdf')) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Lihat PDF</a>
            <a class="btn btn-secondary" href="<?= e(url('/pr/' . $id . '/pdf', ['download' => '1'])) ?>"><?= icon('download') ?> Unduh</a>
        <?php endif; ?>
        <?php if ($can['complete']): ?>
            <form method="post" action="<?= e(url('/pr/' . $id . '/complete')) ?>" data-confirm="Tandai PR ini selesai dan pindahkan ke arsip?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-secondary"><?= icon('archive') ?> Selesai &amp; arsipkan</button>
            </form>
        <?php endif; ?>
        <?php if ($can['cancel']): ?>
            <button type="button" class="btn btn-danger" data-dialog-open="dlg-cancel"><?= icon('x-circle') ?> Batalkan</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($reviewMode): ?>
    <div class="banner">
        <?= icon('eye') ?>
        <div>
            <strong>Review sebelum submit</strong>
            Periksa kembali data di bawah. Setelah disubmit, nomor PR diterbitkan dan PR dikirim ke approver tahap pertama.
            <ul class="checklist">
                <?php foreach ($checks as [$label, $ok]): ?>
                    <li><span class="<?= $ok ? 'ok' : 'no' ?>"><?= icon($ok ? 'check-circle' : 'x-circle') ?></span><?= e($label) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="actions-row">
                <a class="btn btn-secondary btn-sm" href="<?= e(url('/pr/' . $id . '/edit')) ?>"><?= icon('edit') ?> Ubah lagi</a>
                <form method="post" action="<?= e(url('/pr/' . $id . '/submit')) ?>" data-confirm="Ajukan PR ini ke approver?">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-sm"<?= $ready ? '' : ' disabled' ?>><?= icon('send') ?> Submit PR</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($noApprover): ?>
    <div class="banner banner-danger" role="alert">
        <?= icon('alert') ?>
        <div><strong>Tidak ada approver yang memenuhi syarat untuk tahap <?= e($pr['current_step_label']) ?>.</strong> Admin perlu memperbarui approval workflow.</div>
    </div>
<?php endif; ?>

<?php if ($pr['status'] === 'rejected' && $lastDecision !== null): ?>
    <div class="banner banner-danger">
        <?= icon('x-circle') ?>
        <div><strong>Ditolak oleh <?= e($lastDecision['approver_name']) ?> pada tahap <?= e($lastDecision['step_label']) ?></strong><?= e($lastDecision['comment']) ?></div>
    </div>
<?php elseif ($pr['status'] === 'revision_required' && $lastDecision !== null): ?>
    <div class="banner banner-warning">
        <?= icon('rotate') ?>
        <div>
            <strong>Revisi diminta oleh <?= e($lastDecision['approver_name']) ?> (tahap <?= e($lastDecision['step_label']) ?>)</strong>
            <?= e($lastDecision['comment']) ?>
            <?php if ($can['edit']): ?><div class="actions-row"><a class="btn btn-primary btn-sm" href="<?= e(url('/pr/' . $id . '/edit')) ?>"><?= icon('edit') ?> Perbaiki PR</a></div><?php endif; ?>
        </div>
    </div>
<?php elseif ($pr['status'] === 'cancelled'): ?>
    <div class="banner banner-danger">
        <?= icon('x-circle') ?>
        <div><strong>PR dibatalkan <?= e(tanggal($pr['cancelled_at'], true)) ?></strong><?= e($pr['cancel_reason'] ?? '') ?></div>
    </div>
<?php elseif ($pr['status'] === 'approved'): ?>
    <div class="banner banner-success">
        <?= icon('check-circle') ?>
        <div><strong>PR disetujui <?= e(tanggal($pr['approved_at'], true)) ?></strong>Dokumen PDF siap dicetak. Tandai selesai setelah PR diproses untuk mengarsipkannya.</div>
    </div>
<?php elseif ($pr['status'] === 'completed'): ?>
    <div class="banner">
        <?= icon('archive') ?>
        <div><strong>Selesai &amp; diarsipkan <?= e(tanggal($pr['completed_at'], true)) ?></strong>PR ini sudah diproses.</div>
    </div>
<?php endif; ?>

<div class="layout-main-aside">
    <div class="stack-lg">
        <section class="card">
            <div class="card-header"><h2>Informasi</h2></div>
            <div class="card-body">
                <dl class="info-grid">
                    <div><dt>Nomor PR</dt><dd><?= e($pr['pr_number'] ?? 'Terbit saat submit') ?></dd></div>
                    <div><dt>Tanggal</dt><dd><?= e(tanggal($pr['pr_date'])) ?></dd></div>
                    <div><dt>Department</dt><dd><?= e($pr['department_name']) ?></dd></div>
                    <div><dt>Nama pemohon</dt><dd><?= e($pr['requester_name']) ?></dd></div>
                    <div><dt>Supplier</dt><dd><?= e($pr['supplier_name'] ?? '—') ?><?= $pr['supplier_contact'] ? '<br><span class="muted small">' . e($pr['supplier_contact']) . '</span>' : '' ?></dd></div>
                    <div><dt>Workflow</dt><dd><?= e($pr['workflow_name'] ?? 'Ditentukan saat submit') ?></dd></div>
                    <div><dt>Diajukan</dt><dd><?= e(tanggal($pr['submitted_at'], true)) ?></dd></div>
                    <div><dt>Total</dt><dd class="num"><?= e(money($pr['grand_total'])) ?></dd></div>
                </dl>
                <?php if ($pr['notes']): ?><div class="notes-box"><?= e($pr['notes']) ?></div><?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2>Item</h2><span class="muted small"><?= count($items) ?> item</span></div>
            <div class="card-body flush">
                <?php if ($items === []): ?>
                    <div class="empty"><?= icon('box') ?><strong>Belum ada item</strong></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table-cards">
                            <thead>
                            <tr>
                                <th scope="col">No</th>
                                <th scope="col">Item</th>
                                <th scope="col" class="text-right">Qty</th>
                                <th scope="col" class="text-right">Harga</th>
                                <th scope="col" class="text-right">Jumlah</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td data-label="No" class="num"><?= e((string) $item['line_no']) ?></td>
                                    <td class="cell-primary" data-label="">
                                        <strong><?= e($item['item_name_snapshot']) ?></strong>
                                        <?php if ($item['description'] || $item['item_code']): ?>
                                            <span class="sub"><?= e(trim(($item['item_code'] ? $item['item_code'] . ' · ' : '') . ($item['description'] ?? ''), ' ·')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Qty" class="text-right num"><?= e(number_id($item['quantity'])) ?> <?= e($item['unit']) ?></td>
                                    <td data-label="Harga" class="text-right num"><?= e(money($item['unit_price'])) ?></td>
                                    <td data-label="Jumlah" class="text-right num"><?= e(money($item['line_total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body">
                        <dl class="totals">
                            <dt>Subtotal</dt><dd><?= e(money($pr['subtotal'])) ?></dd>
                            <dt>Pajak (<?= e(number_id($pr['tax_rate'])) ?>%)</dt><dd><?= e(money($pr['tax_amount'])) ?></dd>
                            <dt class="grand">Total</dt><dd class="grand"><?= e(money($pr['grand_total'])) ?></dd>
                        </dl>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <div>
                    <h2>Riwayat approval</h2>
                    <p>Seluruh keputusan tersimpan permanen, termasuk dari pengajuan sebelum revisi.</p>
                </div>
            </div>
            <div class="card-body flush">
                <?php if ($rounds === []): ?>
                    <div class="empty"><?= icon('clock') ?><strong>Belum ada keputusan approval</strong></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table-cards">
                            <thead>
                            <tr>
                                <th scope="col">Pengajuan</th>
                                <th scope="col">Tahap</th>
                                <th scope="col">Approver</th>
                                <th scope="col">Keputusan</th>
                                <th scope="col">Komentar</th>
                                <th scope="col">Waktu</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_reverse($rounds, true) as $round => $logs): ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td data-label="Pengajuan">Ke-<?= e((string) $round) ?></td>
                                        <td class="cell-primary" data-label=""><strong><?= e($log['step_label']) ?></strong></td>
                                        <td data-label="Approver"><?= e($log['approver_name']) ?></td>
                                        <td data-label="Keputusan"><span class="badge <?= e($actionBadge[$log['action']] ?? '') ?>"><span class="badge-dot"></span><?= e($actionLabels[$log['action']] ?? $log['action']) ?></span></td>
                                        <td data-label="Komentar"><?= e($log['comment'] ?? '—') ?></td>
                                        <td data-label="Waktu" class="nowrap"><?= e(tanggal($log['acted_at'], true)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <aside class="stack-lg">
        <section class="card">
            <div class="card-header">
                <div>
                    <h2>Progress approval</h2>
                    <p><?= $pr['workflow_id'] === null ? 'Pratinjau workflow yang akan dipakai' : e($pr['workflow_name'] ?? '') ?></p>
                </div>
            </div>
            <div class="card-body">
                <?php if ($progress === []): ?>
                    <p class="muted small">Belum ada approval workflow aktif untuk department ini. Hubungi admin.</p>
                <?php else: ?>
                    <ol class="progress-steps">
                        <li class="progress-step is-approved">
                            <span class="progress-marker"><?= icon('check') ?></span>
                            <div>
                                <p class="step-title">Dibuat oleh</p>
                                <p class="step-meta"><?= e($pr['requester_name']) ?><?= $pr['submitted_at'] ? ' · ' . e(tanggal($pr['submitted_at'], true)) : ' · belum disubmit' ?></p>
                            </div>
                        </li>
                        <?php foreach ($progress as $step): ?>
                            <li class="progress-step is-<?= e($step['state']) ?>">
                                <span class="progress-marker">
                                    <?php if ($step['state'] === 'approved'): ?><?= icon('check') ?>
                                    <?php elseif ($step['state'] === 'rejected'): ?><?= icon('x') ?>
                                    <?php elseif ($step['state'] === 'revision_required'): ?><?= icon('rotate') ?>
                                    <?php else: ?><?= e((string) $step['order']) ?><?php endif; ?>
                                </span>
                                <div>
                                    <p class="step-title"><?= e($step['label']) ?> oleh</p>
                                    <?php if (isset($step['approver'])): ?>
                                        <p class="step-meta"><?= e($step['approver']) ?> · <?= e($actionLabels[$step['state']] ?? '') ?> <?= e(tanggal($step['acted_at'], true)) ?></p>
                                        <?php if ($step['comment']): ?><p class="step-comment"><?= e($step['comment']) ?></p><?php endif; ?>
                                    <?php elseif ($step['state'] === 'current'): ?>
                                        <p class="step-meta">Menunggu: <?= e($step['candidates'] !== [] ? implode(', ', $step['candidates']) : 'tidak ada approver') ?></p>
                                    <?php else: ?>
                                        <p class="step-meta"><?= $step['candidates'] !== [] ? e(implode(', ', $step['candidates'])) : 'Belum berjalan' ?></p>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2>Lampiran</h2><span class="muted small"><?= count($attachments) ?> file</span></div>
            <div class="card-body">
                <?php if ($attachments === []): ?>
                    <p class="muted small">Tidak ada lampiran.</p>
                <?php else: ?>
                    <ul class="file-list">
                        <?php foreach ($attachments as $attachment): ?>
                            <li>
                                <?= icon('paperclip') ?>
                                <span class="file-info">
                                    <a class="file-name" href="<?= e(url('/attachments/' . $attachment['id'] . '/download')) ?>"><?= e($attachment['original_name']) ?></a>
                                    <span class="file-meta"><?= e(format_bytes((int) $attachment['size_bytes'])) ?> · <?= e($attachment['uploader_name']) ?></span>
                                </span>
                                <?php if ($can['attach']): ?>
                                    <form method="post" action="<?= e(url('/attachments/' . $attachment['id'] . '/delete')) ?>" data-confirm="Hapus lampiran <?= e($attachment['original_name']) ?>?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="icon-button" aria-label="Hapus lampiran"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($can['attach']): ?>
                    <form method="post" action="<?= e(url('/pr/' . $id . '/attachments')) ?>" enctype="multipart/form-data" class="stack">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="attachments" class="visually-hidden">Tambah lampiran</label>
                            <input type="file" id="attachments" name="attachments[]" multiple accept="<?= e(implode(',', array_map(static fn (string $ext): string => '.' . $ext, $upload['extensions']))) ?>">
                            <p class="hint"><?= e(implode(', ', $upload['extensions'])) ?> · maks <?= e((string) $upload['maxMb']) ?> MB</p>
                            <?= error_for('attachments') ?>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm"><?= icon('upload') ?> Unggah</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2>Timeline</h2></div>
            <div class="card-body">
                <?php if ($timeline === []): ?>
                    <p class="muted small">Belum ada aktivitas.</p>
                <?php else: ?>
                    <ol class="timeline">
                        <?php foreach ($timeline as $event): ?>
                            <li class="tone-<?= e($event['tone']) ?>">
                                <p class="t-title"><?= e($event['title']) ?></p>
                                <?php if ($event['detail'] !== ''): ?><p class="t-detail"><?= e($event['detail']) ?></p><?php endif; ?>
                                <p class="t-meta"><?= e($event['user']) ?> · <?= e(tanggal($event['at'], true)) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </section>
    </aside>
</div>

<?php if ($can['approve']): ?>
    <?php foreach ([
        'approve' => ['Setujui PR', 'Tahap ' . ($pr['current_step_label'] ?? '') . '. Komentar bersifat opsional.', 'btn-success', 'Setujui', false],
        'revision' => ['Minta revisi', 'PR dikembalikan ke pemohon untuk diperbaiki. Jelaskan apa yang perlu diubah.', 'btn-primary', 'Kirim permintaan revisi', true],
        'reject' => ['Tolak PR', 'PR akan ditolak dan tidak dapat diajukan ulang. Alasan wajib diisi.', 'btn-danger-solid', 'Tolak PR', true],
    ] as $action => [$heading, $help, $buttonClass, $buttonLabel, $required]): ?>
        <dialog class="modal" id="dlg-<?= e($action) ?>" aria-labelledby="dlg-<?= e($action) ?>-title">
            <form method="post" action="<?= e(url('/pr/' . $id . '/' . $action)) ?>">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <h2 id="dlg-<?= e($action) ?>-title"><?= e($heading) ?></h2>
                    <p><?= e($help) ?></p>
                    <div class="field">
                        <label for="comment-<?= e($action) ?>"><?= $required ? 'Alasan / komentar' : 'Komentar' ?><?= $required ? '' : ' <span class="label-optional">(opsional)</span>' ?></label>
                        <textarea id="comment-<?= e($action) ?>" name="comment" maxlength="2000"<?= $required ? ' required' : '' ?>></textarea>
                        <?= error_for('comment') ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Batal</button>
                    <button type="submit" class="btn <?= e($buttonClass) ?>"><?= e($buttonLabel) ?></button>
                </div>
            </form>
        </dialog>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($can['cancel']): ?>
    <dialog class="modal" id="dlg-cancel" aria-labelledby="dlg-cancel-title">
        <form method="post" action="<?= e(url('/pr/' . $id . '/cancel')) ?>">
            <?= csrf_field() ?>
            <div class="modal-body">
                <h2 id="dlg-cancel-title">Batalkan PR</h2>
                <p>PR yang dibatalkan tidak dapat diaktifkan kembali. Riwayat tetap tersimpan.</p>
                <div class="field">
                    <label for="reason">Alasan pembatalan<?= $pr['status'] === 'draft' ? ' <span class="label-optional">(opsional untuk draft)</span>' : '' ?></label>
                    <textarea id="reason" name="reason" maxlength="1000"<?= $pr['status'] === 'draft' ? '' : ' required' ?>></textarea>
                    <?= error_for('reason') ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dialog-close>Kembali</button>
                <button type="submit" class="btn btn-danger-solid">Batalkan PR</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
