<?php

use App\Models\Contact;

/** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base @var list<array<string,mixed>> $history */
$id = (int) $customer['id'];
?>
<div class="row g-4">
    <div class="col-xl-8">
        <?php if (can('activities.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header">
                    <div><h2 class="surface-title">Aktivitas terbaru</h2><p class="surface-subtitle">Interaksi terakhir dengan customer</p></div>
                    <a class="small" href="<?= e(url($base, ['tab' => 'activities'])) ?>">Lihat semua</a>
                </div>
                <?php if (!$data['activities']): ?>
                    <div class="empty-inline">Belum ada aktivitas tercatat.
                        <?php if (can('activities.create')): ?><a href="<?= e(url('/activities/create', ['customer_id' => $id, 'return' => $base])) ?>">Log aktivitas pertama</a><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="surface-body">
                        <ul class="timeline">
                            <?php foreach ($data['activities'] as $a): ?>
                                <li class="timeline-item">
                                    <span class="timeline-icon"><i class="bi <?= e(activity_icon($a['activity_type'])) ?>"></i></span>
                                    <div class="timeline-body">
                                        <div class="timeline-title"><?= e($a['subject']) ?></div>
                                        <div class="timeline-meta"><?= e($a['activity_type']) ?> · <?= e(fmt_datetime($a['activity_date'])) ?><?= $a['pic_name'] ? ' · ' . e($a['pic_name']) : '' ?></div>
                                        <?php if ($a['description']): ?><div class="small text-secondary mt-1"><?= e(excerpt($a['description'], 180)) ?></div><?php endif; ?>
                                        <?php if ($a['next_action']): ?><div class="small mt-1"><i class="bi bi-arrow-return-right text-primary"></i> <?= e($a['next_action']) ?></div><?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can('purchase_orders.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header">
                    <div><h2 class="surface-title">PO berjalan</h2><p class="surface-subtitle">Open · On Process · Partial</p></div>
                    <a class="small" href="<?= e(url($base, ['tab' => 'pos'])) ?>">Semua PO</a>
                </div>
                <?php if (!$data['openPos']): ?>
                    <div class="empty-inline">Tidak ada PO yang sedang berjalan.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table-pik table-compact">
                            <thead><tr><th>No. PO</th><th class="d-none d-md-table-cell">Tanggal</th><th class="d-none d-sm-table-cell">Status</th><th class="num d-none d-sm-table-cell">Order</th><th class="num">Outstanding</th><th class="d-none d-md-table-cell">Progres</th></tr></thead>
                            <tbody>
                            <?php foreach ($data['openPos'] as $po): $progress = pct((int) $po['delivered_qty'], (int) $po['total_qty']); ?>
                                <tr>
                                    <td><a class="cell-title" href="<?= e(url('/purchase-orders/' . $po['id'])) ?>"><?= e($po['po_number'] ?? '(tanpa nomor)') ?></a>
                                        <div class="cell-sub d-sm-none">Order <?= e(fmt_qty($po['total_qty'])) ?> · <?= status_badge($po['status']) ?></div></td>
                                    <td class="d-none d-md-table-cell text-secondary nowrap"><?= e(fmt_date($po['po_date'])) ?></td>
                                    <td class="d-none d-sm-table-cell"><?= status_badge($po['status']) ?></td>
                                    <td class="num d-none d-sm-table-cell"><?= e(fmt_qty($po['total_qty'])) ?></td>
                                    <td class="num fw-semibold<?= (int) $po['outstanding_qty'] < 0 ? ' is-negative' : '' ?>"><?= e(fmt_qty($po['outstanding_qty'])) ?></td>
                                    <td class="d-none d-md-table-cell"><div class="progress-thin<?= $progress >= 100 ? ' is-done' : '' ?>"><span style="width: <?= $progress ?>%"></span></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can('deliveries.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header">
                    <div><h2 class="surface-title">Delivery terakhir</h2></div>
                    <a class="small" href="<?= e(url($base, ['tab' => 'deliveries'])) ?>">Semua delivery</a>
                </div>
                <?php if (!$data['deliveries']): ?>
                    <div class="empty-inline">Belum ada pengiriman.</div>
                <?php else: ?>
                    <ul class="list-lite">
                        <?php foreach ($data['deliveries'] as $d): ?>
                            <li>
                                <span class="kpi-icon"><i class="bi bi-truck"></i></span>
                                <div class="li-main">
                                    <a class="li-title" href="<?= e(url('/deliveries/' . $d['id'])) ?>"><?= e($d['sj_number'] ?? $d['code']) ?> · <?= e($d['product_name'] ?? 'Produk belum terhubung') ?></a>
                                    <div class="li-sub">PO <?= e($d['po_number'] ?? '—') ?> · <?= e(fmt_date($d['delivery_date'])) ?></div>
                                </div>
                                <div class="li-end"><div class="fw-semibold tabular"><?= e(fmt_qty($d['delivered_qty'])) ?></div><?= status_badge($d['status']) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Informasi customer</h2>
                <?php if (can('customers.edit')): ?><a class="small" href="<?= e(url($base . '/edit')) ?>">Edit</a><?php endif; ?>
            </div>
            <div class="surface-body">
                <dl class="dl-grid dl-single">
                    <div><dt>Perusahaan</dt><dd><?= e($customer['company'] ?: $customer['name']) ?></dd></div>
                    <div><dt>Contact person</dt><dd><?= e($customer['pic'] ?: '—') ?></dd></div>
                    <div><dt>Telepon</dt><dd><?= e($customer['phone'] ?: '—') ?>
                        <?php if ($wa = Contact::waLink($customer['phone'])): ?> <a class="small ms-1" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp"></i> Chat</a><?php endif; ?></dd></div>
                    <div><dt>Email</dt><dd><?= $customer['email'] ? '<a href="mailto:' . e($customer['email']) . '">' . e($customer['email']) . '</a>' : '—' ?></dd></div>
                    <div><dt>Alamat</dt><dd><?= $customer['address'] ? nl2br(e($customer['address'])) : '—' ?></dd></div>
                    <div><dt>Sumber</dt><dd><?= e($customer['source'] ?: '—') ?></dd></div>
                    <?php if ($customer['notes']): ?><div><dt>Catatan</dt><dd class="small"><?= nl2br(e($customer['notes'])) ?></dd></div><?php endif; ?>
                    <div><dt>Dibuat</dt><dd class="small text-secondary"><?= e(fmt_datetime($customer['created_at'])) ?><?= $customer['created_by_name'] ? ' oleh ' . e($customer['created_by_name']) : '' ?></dd></div>
                </dl>
            </div>
        </section>

        <?php if (can('contacts.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Kontak</h2><a class="small" href="<?= e(url($base, ['tab' => 'contacts'])) ?>">Semua</a></div>
                <?php if (!$data['contacts']): ?>
                    <div class="empty-inline">Belum ada kontak.
                        <?php if (can('contacts.create')): ?><a href="<?= e(url('/contacts/create', ['customer_id' => $id, 'return' => $base . '?tab=contacts'])) ?>">Tambah kontak</a><?php endif; ?></div>
                <?php else: ?>
                    <ul class="list-lite">
                        <?php foreach ($data['contacts'] as $ct): ?>
                            <li>
                                <span class="avatar avatar-sm"><?= e(initials($ct['name'])) ?></span>
                                <div class="li-main">
                                    <span class="li-title"><?= e($ct['name']) ?><?= (int) $ct['is_primary'] === 1 ? ' <span class="badge-soft badge-soft-accent no-dot">Utama</span>' : '' ?></span>
                                    <div class="li-sub"><?= e(trim(($ct['position'] ?? '') . ' ' . ($ct['phone'] ?? ''))) ?: '—' ?></div>
                                </div>
                                <?php if ($wa = Contact::waLink($ct['whatsapp'] ?: $ct['phone'])): ?>
                                    <a class="btn btn-light btn-sm btn-icon" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp <?= e($ct['name']) ?>"><i class="bi bi-whatsapp"></i></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can('followups.view')): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Follow up berikutnya</h2><a class="small" href="<?= e(url($base, ['tab' => 'followups'])) ?>">Semua</a></div>
                <?php if (!$data['followups']): ?>
                    <div class="empty-inline">Tidak ada follow up terbuka.
                        <?php if (can('followups.create')): ?><a href="<?= e(url('/follow-ups/create', ['customer_id' => $id, 'return' => $base])) ?>">Jadwalkan</a><?php endif; ?></div>
                <?php else: ?>
                    <ul class="list-lite">
                        <?php foreach ($data['followups'] as $f): ?>
                            <li>
                                <span class="kpi-icon"><i class="bi <?= e(activity_icon($f['follow_up_type'])) ?>"></i></span>
                                <div class="li-main">
                                    <a class="li-title" href="<?= e(url('/follow-ups/' . $f['id'] . '/edit', ['return' => $base])) ?>"><?= e($f['purpose']) ?></a>
                                    <div class="li-sub"><?= e($f['pic_name'] ?? 'Tanpa PIC') ?></div>
                                </div>
                                <div class="li-end">
                                    <div class="<?= (int) $f['is_overdue'] === 1 ? 'is-negative fw-semibold' : '' ?>"><?= e(fmt_date($f['follow_up_date'])) ?></div>
                                    <div class="x-small <?= (int) $f['is_overdue'] === 1 ? 'is-negative' : 'text-secondary' ?>"><?= (int) $f['is_overdue'] === 1 ? 'Overdue' : e(relative_day($f['follow_up_date'])) ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (can('leads.view') && $data['leads']): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Leads aktif</h2><a class="small" href="<?= e(url($base, ['tab' => 'leads'])) ?>">Semua</a></div>
                <ul class="list-lite">
                    <?php foreach ($data['leads'] as $l): ?>
                        <li>
                            <div class="li-main">
                                <a class="li-title" href="<?= e(url('/leads/' . $l['id'])) ?>"><?= e($l['lead_name']) ?></a>
                                <div class="li-sub"><?= e(fmt_money($l['potential_value'], '—')) ?><?= $l['expected_close_date'] ? ' · target ' . e(fmt_date($l['expected_close_date'])) : '' ?></div>
                            </div>
                            <div class="li-end"><?= status_badge($l['status']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($history): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Riwayat perubahan data</h2></div>
                <ul class="list-lite">
                    <?php foreach ($history as $h): $changes = json_decode((string) $h['changes'], true); ?>
                        <li>
                            <div class="li-main">
                                <span class="li-title"><?= e(ucfirst((string) $h['action'])) ?> oleh <?= e($h['user_name'] ?? 'sistem') ?></span>
                                <div class="li-sub"><?= is_array($changes) ? e(implode(', ', array_slice(array_keys($changes), 0, 5))) : '' ?></div>
                            </div>
                            <div class="li-end text-secondary x-small"><?= e(fmt_datetime($h['created_at'])) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
