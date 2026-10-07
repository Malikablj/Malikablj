<?php
/** @var array<string,mixed> $log @var array<string,mixed>|null $changes */
$fmt = static function (mixed $v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    return (string) $v;
};
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/audit-log')) ?>">Audit Log</a><i class="bi bi-chevron-right"></i><span>#<?= (int) $log['id'] ?></span></div>
<div class="page-header">
    <div>
        <h1 class="page-title">Detail Audit Log</h1>
        <p class="page-subtitle"><?= e(fmt_datetime($log['created_at'])) ?> · <?= e($log['user_name'] ?? 'Tanpa user') ?></p>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="surface surface-pad">
            <dl class="dl-grid dl-single">
                <div><dt>Aksi</dt><dd><?= e($log['action']) ?></dd></div>
                <div><dt>Jenis data</dt><dd><?= e($log['entity_type'] ?? '—') ?><?= $log['entity_id'] ? ' #' . (int) $log['entity_id'] : '' ?></dd></div>
                <div><dt>Label</dt><dd><?= e($log['entity_label'] ?? '—') ?></dd></div>
                <div><dt>Alamat IP</dt><dd><?= e($log['ip_address'] ?? '—') ?></dd></div>
                <div><dt>Perangkat</dt><dd class="small text-secondary"><?= e($log['user_agent'] ?? '—') ?></dd></div>
            </dl>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="surface">
            <div class="surface-header"><h2 class="surface-title">Perubahan data</h2></div>
            <?php if (!$changes): ?>
                <div class="empty-inline">Tidak ada detail perubahan untuk aksi ini.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-pik table-compact">
                        <thead><tr><th>Field</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
                        <tbody>
                        <?php foreach ($changes as $field => $change): ?>
                            <tr>
                                <td class="fw-semibold nowrap"><?= e($field) ?></td>
                                <td class="text-secondary"><?= e($fmt(is_array($change) ? ($change['old'] ?? null) : null)) ?></td>
                                <td><?= e($fmt(is_array($change) ? ($change['new'] ?? null) : $change)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
