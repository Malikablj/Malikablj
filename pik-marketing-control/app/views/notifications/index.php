<?php
/** @var App\Helpers\Paginator $notifications @var string $filter @var int $unread */
$icon = static fn (string $type): string => match (true) {
    str_starts_with($type, 'followup') => 'bi-calendar2-check',
    str_starts_with($type, 'lead') => 'bi-kanban',
    str_starts_with($type, 'delivery') => 'bi-truck',
    str_starts_with($type, 'po'), str_starts_with($type, 'oef') => 'bi-receipt',
    str_starts_with($type, 'complaint') => 'bi-chat-left-dots',
    default => 'bi-bell',
};
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Insight</div>
        <h1 class="page-title">Notifikasi</h1>
        <p class="page-subtitle"><?= $unread > 0 ? e($unread . ' notifikasi belum dibaca') : 'Semua notifikasi sudah dibaca' ?></p>
    </div>
    <?php if ($unread > 0): ?>
        <div class="page-actions">
            <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-light"><i class="bi bi-check2-all"></i> Tandai semua dibaca</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="tabs-pik">
    <a href="<?= e(url('/notifications')) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">Semua</a>
    <a href="<?= e(url('/notifications', ['filter' => 'unread'])) ?>" class="<?= $filter === 'unread' ? 'active' : '' ?>">Belum dibaca <span class="tab-count"><?= (int) $unread ?></span></a>
</div>

<div class="surface">
    <?php if ($notifications->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-bell-slash"></i><div class="empty-title">Tidak ada notifikasi</div><p>Pengingat follow up, OEF, delivery, dan komplain akan muncul di sini.</p></div>
    <?php else: ?>
        <ul class="list-lite">
            <?php foreach ($notifications->items as $n): $isUnread = (int) $n['is_read'] === 0; ?>
                <li>
                    <span class="kpi-icon<?= $isUnread ? ' bg-primary-subtle text-primary' : '' ?>"><i class="bi <?= e($icon((string) $n['type'])) ?>"></i></span>
                    <div class="li-main">
                        <a class="li-title" href="<?= e(url('/notifications/' . $n['id'] . '/open')) ?>"><?= $isUnread ? '<span class="badge-soft badge-soft-accent no-dot me-1">Baru</span>' : '' ?><?= e($n['title']) ?></a>
                        <?php if (!empty($n['message'])): ?><div class="li-sub"><?= e($n['message']) ?></div><?php endif; ?>
                    </div>
                    <div class="li-end text-secondary"><?= e(fmt_datetime($n['created_at'])) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?= $notifications->footer('notifikasi') ?>
    <?php endif; ?>
</div>
