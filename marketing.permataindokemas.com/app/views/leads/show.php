<?php

use App\Models\Lead;

/** @var array<string,mixed> $lead @var list<array<string,mixed>> $activities @var list<array<string,mixed>> $followups @var list<array<string,mixed>> $history @var string $today */
$id = (int) $lead['id'];
$base = '/leads/' . $id;
$late = $lead['expected_close_date'] && $lead['expected_close_date'] < $today && in_array($lead['status'], Lead::OPEN_STATUSES, true);
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/leads')) ?>">Leads</a><i class="bi bi-chevron-right"></i><span><?= e($lead['lead_name']) ?></span></div>
<div class="detail-hero">
    <div class="detail-hero-main">
        <span class="avatar avatar-lg avatar-accent"><i class="bi bi-kanban"></i></span>
        <div class="min-w-0">
            <h1 class="page-title d-flex align-items-center gap-2 flex-wrap"><?= e($lead['lead_name']) ?> <?= status_badge($lead['status']) ?> <?= status_badge($lead['priority']) ?></h1>
            <div class="detail-meta">
                <span class="code-chip"><?= e($lead['code']) ?></span>
                <?php if ($lead['customer_id']): ?>
                    <span><i class="bi bi-buildings"></i><a href="<?= e(url('/customers/' . $lead['customer_id'])) ?>"><?= e($lead['customer_name']) ?></a></span>
                <?php else: ?>
                    <span><i class="bi bi-building-add"></i><?= e($lead['company_name'] ?: 'Prospek') ?> (belum customer)</span>
                <?php endif; ?>
                <span><i class="bi bi-person-badge"></i>PIC: <?= e($lead['pic_name'] ?? 'belum ditentukan') ?></span>
                <span><i class="bi bi-calendar3"></i>Dibuat <?= e(fmt_date($lead['created_at'])) ?></span>
            </div>
        </div>
    </div>
    <div class="page-actions">
        <?php if (can('activities.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/activities/create', ['lead_id' => $id, 'customer_id' => $lead['customer_id'], 'return' => $base])) ?>"><i class="bi bi-plus-lg"></i> Log aktivitas</a>
        <?php endif; ?>
        <?php if (can('followups.create')): ?>
            <a class="btn btn-light" href="<?= e(url('/follow-ups/create', ['lead_id' => $id, 'customer_id' => $lead['customer_id'], 'return' => $base])) ?>"><i class="bi bi-calendar-plus"></i> Follow up</a>
        <?php endif; ?>
        <?php if (can('leads.edit')): ?><a class="btn btn-light" href="<?= e(url($base . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
        <?php if (!$lead['customer_id'] && can('customers.create') && can('leads.edit')): ?>
            <form method="post" action="<?= e(url($base . '/convert')) ?>" data-confirm="Buat customer baru dari prospek ini?">
                <?= csrf_field() ?><button class="btn btn-success-soft" type="submit"><i class="bi bi-person-check"></i> Jadikan customer</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (can('leads.edit')): ?>
    <form class="surface surface-pad section-gap d-flex flex-wrap align-items-center gap-2" method="post" action="<?= e(url($base . '/status')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($base) ?>">
        <span class="small text-secondary me-1">Pindahkan status:</span>
        <?php foreach (Lead::STATUSES as $s): ?>
            <button type="submit" name="status" value="<?= e($s) ?>" class="btn btn-sm <?= $s === $lead['status'] ? 'btn-dark' : 'btn-light' ?>"<?= $s === $lead['status'] ? ' aria-pressed="true"' : '' ?>><?= e($s) ?></button>
        <?php endforeach; ?>
    </form>
<?php endif; ?>

<div class="stat-strip section-gap">
    <div><div class="stat-label">Potential value</div><div class="stat-value"><?= e(fmt_money($lead['potential_value'])) ?></div></div>
    <div><div class="stat-label">Estimasi qty</div><div class="stat-value"><?= e(fmt_qty($lead['estimated_qty'])) ?></div></div>
    <div><div class="stat-label">Target closing</div><div class="stat-value fs-6 mt-1<?= $late ? ' is-negative' : '' ?>"><?= e(fmt_date($lead['expected_close_date'], 'Belum ada')) ?></div>
        <?php if ($late): ?><div class="x-small is-negative">Terlewat</div><?php endif; ?></div>
    <div><div class="stat-label">Kontak terakhir</div><div class="stat-value fs-6 mt-1"><?= e(fmt_date($lead['last_contact'], 'Belum ada')) ?></div></div>
    <div><div class="stat-label">Follow up berikutnya</div><div class="stat-value fs-6 mt-1"><?= e(fmt_date($lead['next_follow_up'], 'Belum dijadwalkan')) ?></div></div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Aktivitas</h2>
                <?php if (can('activities.create')): ?><a class="small" href="<?= e(url('/activities/create', ['lead_id' => $id, 'customer_id' => $lead['customer_id'], 'return' => $base])) ?>">+ Log aktivitas</a><?php endif; ?></div>
            <?php if (!$activities): ?>
                <div class="empty-inline">Belum ada aktivitas untuk lead ini.</div>
            <?php else: ?>
                <div class="surface-body"><ul class="timeline">
                    <?php foreach ($activities as $a): ?>
                        <li class="timeline-item">
                            <span class="timeline-icon"><i class="bi <?= e(activity_icon($a['activity_type'])) ?>"></i></span>
                            <div class="timeline-body">
                                <div class="d-flex justify-content-between gap-2"><div class="timeline-title"><?= e($a['subject']) ?></div>
                                    <?php if (can('activities.edit')): ?><a class="small" href="<?= e(url('/activities/' . $a['id'] . '/edit', ['return' => $base])) ?>">Edit</a><?php endif; ?></div>
                                <div class="timeline-meta"><?= e($a['activity_type']) ?> · <?= e(fmt_datetime($a['activity_date'])) ?><?= $a['pic_name'] ? ' · ' . e($a['pic_name']) : '' ?></div>
                                <?php if ($a['description']): ?><div class="small mt-1"><?= nl2br(e($a['description'])) ?></div><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul></div>
            <?php endif; ?>
        </section>

        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Follow up</h2>
                <?php if (can('followups.create')): ?><a class="small" href="<?= e(url('/follow-ups/create', ['lead_id' => $id, 'customer_id' => $lead['customer_id'], 'return' => $base])) ?>">+ Jadwalkan</a><?php endif; ?></div>
            <?php if (!$followups): ?>
                <div class="empty-inline">Belum ada follow up.</div>
            <?php else: ?>
                <ul class="list-lite">
                    <?php foreach ($followups as $f): $ov = (int) $f['is_overdue'] === 1; ?>
                        <li>
                            <span class="kpi-icon"><i class="bi <?= e(activity_icon($f['follow_up_type'])) ?>"></i></span>
                            <div class="li-main"><a class="li-title" href="<?= e(url('/follow-ups/' . $f['id'] . '/edit', ['return' => $base])) ?>"><?= e($f['purpose']) ?></a>
                                <div class="li-sub"><?= e($f['pic_name'] ?? 'Tanpa PIC') ?><?= $f['result'] ? ' · ' . e(excerpt($f['result'], 60)) : '' ?></div></div>
                            <div class="li-end"><div class="<?= $ov ? 'is-negative fw-semibold' : '' ?>"><?= e(fmt_date($f['follow_up_date'])) ?></div><?= $ov ? status_badge('Overdue') : status_badge($f['status']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="surface section-gap">
            <div class="surface-header"><h2 class="surface-title">Detail</h2></div>
            <div class="surface-body">
                <dl class="dl-grid dl-single">
                    <div><dt>Produk diminati</dt><dd><?= e($lead['product_interest'] ?: '—') ?></dd></div>
                    <div><dt>Sumber</dt><dd><?= e($lead['source'] ?: '—') ?></dd></div>
                    <div><dt>Contact person</dt><dd><?= e($lead['contact_name'] ?: '—') ?></dd></div>
                    <div><dt>Telepon</dt><dd><?= e($lead['phone'] ?: '—') ?></dd></div>
                    <div><dt>Email</dt><dd><?= $lead['email'] ? '<a href="mailto:' . e($lead['email']) . '">' . e($lead['email']) . '</a>' : '—' ?></dd></div>
                    <?php if ($lead['notes']): ?><div><dt>Catatan</dt><dd class="small"><?= nl2br(e($lead['notes'])) ?></dd></div><?php endif; ?>
                    <div><dt>Status terakhir diubah</dt><dd class="small text-secondary"><?= e(fmt_datetime($lead['status_changed_at'])) ?></dd></div>
                </dl>
            </div>
            <?php if (can('leads.delete')): ?>
                <div class="surface-footer">
                    <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="Hapus lead ini? Lead yang sudah memiliki aktivitas/follow up tidak dapat dihapus.">
                        <?= csrf_field() ?><button class="btn btn-danger-soft btn-sm" type="submit"><i class="bi bi-trash"></i> Hapus lead</button>
                    </form>
                </div>
            <?php endif; ?>
        </section>
        <?php if ($history): ?>
            <section class="surface section-gap">
                <div class="surface-header"><h2 class="surface-title">Riwayat</h2></div>
                <ul class="list-lite">
                    <?php foreach ($history as $h): $ch = json_decode((string) $h['changes'], true); ?>
                        <li><div class="li-main"><span class="li-title">
                            <?php if ($h['action'] === 'status_change' && is_array($ch) && isset($ch['status'])): ?>
                                <?= e($ch['status']['old'] ?? '') ?> → <?= e($ch['status']['new'] ?? '') ?>
                            <?php else: ?><?= e(ucfirst((string) $h['action'])) ?><?php endif; ?></span>
                            <div class="li-sub"><?= e($h['user_name'] ?? 'sistem') ?></div></div>
                            <div class="li-end x-small text-secondary"><?= e(fmt_datetime($h['created_at'])) ?></div></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
