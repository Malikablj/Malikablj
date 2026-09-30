<?php
$user = auth_user();
$hour = (int) date('G');
$greeting = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam'));
?>
<header class="page-header">
    <div>
        <p class="eyebrow"><?= e(tanggal_panjang(date('Y-m-d'))) ?></p>
        <h1><?= e($greeting) ?>, <?= e(explode(' ', (string) $user['name'])[0]) ?></h1>
        <p class="subtitle">
            <?php if ($isAdmin): ?>Ringkasan seluruh Purchase Requisition.
            <?php elseif ($user['role'] === 'approver'): ?>Ringkasan PR yang terkait dengan Anda.
            <?php else: ?>Ringkasan PR yang Anda ajukan.<?php endif; ?>
        </p>
    </div>
    <?php if ($canCreate): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= e(url('/pr/create')) ?>"><?= icon('plus') ?> Buat PR</a>
        </div>
    <?php endif; ?>
</header>

<?php if ($myApprovalCount > 0): ?>
    <div class="banner">
        <?= icon('inbox') ?>
        <div>
            <strong><?= e((string) $myApprovalCount) ?> PR menunggu keputusan Anda</strong>
            <span>Tinjau lalu setujui, tolak, atau minta revisi.</span>
            <div class="actions-row"><a class="btn btn-primary btn-sm" href="<?= e(url('/approvals')) ?>">Buka antrian approval <?= icon('arrow-right') ?></a></div>
        </div>
    </div>
<?php endif; ?>

<section class="stats" aria-label="Ringkasan PR">
    <a class="stat" href="<?= e(url('/pr')) ?>">
        <span class="stat-label">Total PR</span>
        <span class="stat-value"><?= e((string) $stats['total']) ?></span>
        <span class="stat-caption">Semua status</span>
    </a>
    <a class="stat" href="<?= e(url('/pr', ['status' => 'draft'])) ?>">
        <span class="stat-label">Draft</span>
        <span class="stat-value"><?= e((string) $stats['draft']) ?></span>
        <span class="stat-caption">Belum diajukan</span>
    </a>
    <a class="stat" href="<?= e(url('/pr', ['status' => 'pending'])) ?>">
        <span class="stat-label">Menunggu Approval</span>
        <span class="stat-value"><?= e((string) $stats['pending']) ?></span>
        <span class="stat-caption">Submitted &amp; In Review · <?= e(money($stats['value_pending'])) ?></span>
    </a>
    <a class="stat" href="<?= e(url('/pr', ['status' => 'revision_required'])) ?>">
        <span class="stat-label">Revision Required</span>
        <span class="stat-value"><?= e((string) $stats['revision']) ?></span>
        <span class="stat-caption">Perlu diperbaiki pemohon</span>
    </a>
    <a class="stat" href="<?= e(url('/pr', ['status' => 'approved'])) ?>">
        <span class="stat-label">Approved</span>
        <span class="stat-value"><?= e((string) $stats['approved']) ?></span>
        <span class="stat-caption">Termasuk yang sudah selesai</span>
    </a>
    <a class="stat" href="<?= e(url('/pr', ['status' => 'rejected'])) ?>">
        <span class="stat-label">Rejected</span>
        <span class="stat-value"><?= e((string) $stats['rejected']) ?></span>
        <span class="stat-caption">Ditolak approver</span>
    </a>
    <div class="stat stat-accent stat-wide">
        <span class="stat-label"><?= icon('wallet') ?> Total Nilai PR</span>
        <span class="stat-value"><?= e(money($stats['value_approved'])) ?></span>
        <span class="stat-caption">Nilai PR berstatus Approved &amp; Completed</span>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-header">
            <div>
                <h2>PR Terbaru</h2>
                <p>Aktivitas terakhir</p>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/pr')) ?>">Lihat semua</a>
        </div>
        <div class="card-body flush">
            <?php if ($recent === []): ?>
                <div class="empty">
                    <?= icon('document') ?>
                    <strong>Belum ada PR</strong>
                    <?php if ($canCreate): ?>
                        <span>Mulai dengan membuat Purchase Requisition pertama Anda.</span><br>
                        <a class="btn btn-primary btn-sm" href="<?= e(url('/pr/create')) ?>"><?= icon('plus') ?> Buat PR</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($recent as $pr): ?>
                        <li>
                            <a class="list-item" href="<?= e(url('/pr/' . $pr['id'])) ?>">
                                <span class="grow">
                                    <span class="title"><?= e(pr_label($pr)) ?></span>
                                    <span class="meta"><?= e($pr['supplier_name'] ?? 'Supplier belum dipilih') ?> · <?= e($pr['requester_name']) ?> · <?= e(time_ago($pr['updated_at'])) ?></span>
                                </span>
                                <span class="end">
                                    <?= status_badge((string) $pr['status']) ?>
                                    <span class="meta num"><?= e(money($pr['grand_total'])) ?></span>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <div>
                <h2><?= e($side['title']) ?></h2>
                <p><?= e($side['subtitle']) ?></p>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url($side['link'])) ?>">Lihat semua</a>
        </div>
        <div class="card-body flush">
            <?php if ($side['rows'] === []): ?>
                <div class="empty">
                    <?= icon('check-circle') ?>
                    <strong>Tidak ada antrian</strong>
                    <span><?= e($side['empty']) ?></span>
                </div>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($side['rows'] as $pr): ?>
                        <li>
                            <a class="list-item" href="<?= e(url('/pr/' . $pr['id'])) ?>">
                                <span class="grow">
                                    <span class="title"><?= e(pr_label($pr)) ?></span>
                                    <span class="meta"><?= e($pr['requester_name']) ?> · <?= e($pr['department_name']) ?> · tahap <?= e($pr['current_step_label'] ?? '-') ?></span>
                                </span>
                                <span class="end">
                                    <?= status_badge((string) $pr['status']) ?>
                                    <span class="meta num"><?= e(money($pr['grand_total'])) ?></span>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>
