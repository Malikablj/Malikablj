<?php
$icons = [
    'approval_required' => 'inbox',
    'pr_submitted' => 'send',
    'pr_step_approved' => 'check-circle',
    'pr_approved' => 'check-circle',
    'pr_rejected' => 'x-circle',
    'pr_revision' => 'rotate',
    'pr_cancelled' => 'x-circle',
    'pr_completed' => 'archive',
];
?>
<header class="page-header">
    <div>
        <h1>Notifikasi</h1>
        <p class="subtitle">Pemberitahuan pengajuan, approval, penolakan, dan revisi PR.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary"><?= icon('check') ?> Tandai semua dibaca</button>
        </form>
    </div>
</header>

<nav class="tabs" aria-label="Filter notifikasi">
    <a class="tab<?= $unreadOnly ? '' : ' is-active' ?>" href="<?= e(url('/notifications')) ?>">Semua</a>
    <a class="tab<?= $unreadOnly ? ' is-active' : '' ?>" href="<?= e(url('/notifications', ['filter' => 'unread'])) ?>">Belum dibaca</a>
</nav>

<section class="card">
    <?php if ($rows === []): ?>
        <div class="empty"><?= icon('bell') ?><strong><?= $unreadOnly ? 'Semua notifikasi sudah dibaca' : 'Belum ada notifikasi' ?></strong></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($rows as $n): ?>
                <li class="list-item notification<?= $n['read_at'] === null ? ' is-unread' : '' ?>">
                    <form method="post" action="<?= e(url('/notifications/' . $n['id'] . '/read')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="notification-button">
                            <span class="avatar"><?= icon($icons[$n['type']] ?? 'bell') ?></span>
                            <span class="grow">
                                <span class="title"><?= e($n['title']) ?></span>
                                <span class="message"><?= e($n['message']) ?></span>
                                <span class="meta"><?= e(time_ago($n['created_at'])) ?><?= $n['pr_status'] ? ' · status kini: ' . e(status_label((string) $n['pr_status'])) : '' ?></span>
                            </span>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?= \App\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
</section>
