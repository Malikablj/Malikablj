<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['activities'];
$ret = $base . '?tab=activities';
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Riwayat aktivitas</h2><p class="surface-subtitle">WhatsApp, telepon, meeting, visit, quotation, sample, dan lainnya</p></div>
        <?php if (can('activities.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/activities/create', ['customer_id' => $customer['id'], 'return' => $ret])) ?>"><i class="bi bi-plus-lg"></i> Log aktivitas</a>
        <?php endif; ?>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-chat-square-text"></i><div class="empty-title">Belum ada aktivitas</div><p>Setiap interaksi dengan customer sebaiknya dicatat di sini.</p></div>
    <?php else: ?>
        <div class="surface-body">
            <ul class="timeline">
                <?php foreach ($list->items as $a): ?>
                    <li class="timeline-item">
                        <span class="timeline-icon"><i class="bi <?= e(activity_icon($a['activity_type'])) ?>"></i></span>
                        <div class="timeline-body">
                            <div class="d-flex justify-content-between gap-2 flex-wrap">
                                <div class="timeline-title"><?= e($a['subject']) ?></div>
                                <?php if (can('activities.edit')): ?><a class="small" href="<?= e(url('/activities/' . $a['id'] . '/edit', ['return' => $ret])) ?>">Edit</a><?php endif; ?>
                            </div>
                            <div class="timeline-meta"><?= e($a['activity_type']) ?> · <?= e(fmt_datetime($a['activity_date'])) ?><?= $a['pic_name'] ? ' · ' . e($a['pic_name']) : '' ?><?= $a['lead_name'] ? ' · Lead: ' . e($a['lead_name']) : '' ?></div>
                            <?php if ($a['description']): ?><div class="small mt-1"><?= nl2br(e($a['description'])) ?></div><?php endif; ?>
                            <?php if ($a['next_action'] || $a['next_follow_up']): ?>
                                <div class="small mt-1 text-secondary"><i class="bi bi-arrow-return-right text-primary"></i> <?= e($a['next_action'] ?? 'Follow up') ?><?= $a['next_follow_up'] ? ' · ' . e(fmt_date($a['next_follow_up'])) : '' ?></div>
                            <?php endif; ?>
                            <?php if ($a['attachment']): ?><div class="small mt-1"><?= external_link($a['attachment'], 'Lampiran') ?></div><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?= $list->footer('aktivitas') ?>
    <?php endif; ?>
</section>
