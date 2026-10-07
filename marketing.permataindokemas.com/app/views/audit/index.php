<?php

use App\Helpers\Form;

/** @var App\Helpers\Paginator $logs @var array<string,mixed> $filters */
$actionTone = static fn (string $a): string => match (true) {
    in_array($a, ['delete', 'login_failed', 'login_blocked', 'login_locked'], true) => 'danger',
    in_array($a, ['create', 'login', 'user_unblock', 'ppic_approve'], true) => 'success',
    in_array($a, ['update', 'status_change', 'auto_status', 'payment'], true) => 'info',
    default => 'neutral',
};
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Audit Log</h1>
        <p class="page-subtitle">Jejak semua perubahan data, login, dan aksi penting — siapa, kapan, dari mana.</p>
    </div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/audit-log')) ?>">
        <div class="filter-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari label data, nama user, IP…" aria-label="Cari">
        </div>
        <select class="form-select" name="user_id" aria-label="User">
            <option value="">Semua user</option>
            <?= Form::options($users, (string) $filters['user_id']) ?>
        </select>
        <select class="form-select" name="action" aria-label="Aksi">
            <option value="">Semua aksi</option>
            <?= Form::options(Form::list($actions), $filters['action']) ?>
        </select>
        <select class="form-select" name="entity" aria-label="Jenis data">
            <option value="">Semua data</option>
            <?= Form::options(Form::list($entities), $filters['entity']) ?>
        </select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
    </form>

    <?php if ($logs->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-shield-check"></i><div class="empty-title">Belum ada catatan</div><p>Aktivitas user akan tercatat di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik table-compact">
                <thead>
                <tr><th class="d-none d-sm-table-cell">Waktu</th><th class="d-none d-sm-table-cell">User</th><th class="d-none d-sm-table-cell">Aksi</th><th>Data</th><th class="d-none d-lg-table-cell">IP</th><th class="col-actions"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs->items as $log): ?>
                    <tr>
                        <td class="nowrap tabular text-secondary d-none d-sm-table-cell"><?= e(fmt_datetime($log['created_at'])) ?></td>
                        <td class="d-none d-sm-table-cell"><?= e($log['user_name'] ?? '—') ?></td>
                        <td class="d-none d-sm-table-cell"><span class="badge-soft badge-soft-<?= $actionTone((string) $log['action']) ?> no-dot"><?= e($log['action']) ?></span></td>
                        <td>
                            <div class="cell-title"><?= e(excerpt($log['entity_label'] ?? '', 60)) ?></div>
                            <div class="cell-sub"><?= e($log['entity_type'] ?? '') ?><?= $log['entity_id'] ? ' #' . (int) $log['entity_id'] : '' ?></div>
                            <div class="cell-sub d-sm-none"><span class="badge-soft badge-soft-<?= $actionTone((string) $log['action']) ?> no-dot"><?= e($log['action']) ?></span> <?= e(fmt_datetime($log['created_at'])) ?> · <?= e($log['user_name'] ?? '—') ?></div>
                        </td>
                        <td class="d-none d-lg-table-cell text-secondary small"><?= e($log['ip_address'] ?? '') ?></td>
                        <td class="col-actions"><a class="btn btn-light btn-sm" href="<?= e(url('/audit-log/' . $log['id'])) ?>">Detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $logs->footer('catatan') ?>
    <?php endif; ?>
</div>
