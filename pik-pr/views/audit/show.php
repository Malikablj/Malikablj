<a class="back-link" href="<?= e(url('/audit-logs')) ?>"><?= icon('arrow-left') ?> Audit Log</a>
<header class="page-header">
    <div>
        <h1><span class="mono"><?= e($log['action']) ?></span></h1>
        <p class="subtitle"><?= e(tanggal($log['created_at'], true)) ?> · <?= e($log['user_name'] ?? 'Sistem / tamu') ?></p>
    </div>
    <?php if ($log['entity_type'] === 'purchase_requisition' && $log['entity_id'] !== null): ?>
        <div class="page-actions"><a class="btn btn-secondary" href="<?= e(url('/pr/' . $log['entity_id'])) ?>"><?= icon('document') ?> Buka PR</a></div>
    <?php endif; ?>
</header>

<section class="card">
    <div class="card-body">
        <dl class="info-grid">
            <div><dt>Entitas</dt><dd><?= e($log['entity_type']) ?><?= $log['entity_id'] !== null ? ' #' . e((string) $log['entity_id']) : '' ?></dd></div>
            <div><dt>User</dt><dd><?= e($log['user_name'] ?? '-') ?><?= $log['user_email'] ? '<br><span class="muted small">' . e($log['user_email']) . '</span>' : '' ?></dd></div>
            <div><dt>Alamat IP</dt><dd class="mono"><?= e($log['ip_address'] ?? '-') ?></dd></div>
            <div><dt>User agent</dt><dd class="small"><?= e($log['user_agent'] ?? '-') ?></dd></div>
        </dl>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-header"><h2>Nilai lama</h2></div>
        <div class="card-body">
            <?php if ($old === null): ?><p class="muted">—</p><?php else: ?>
                <pre class="json-block"><?= e(json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php endif; ?>
        </div>
    </section>
    <section class="card">
        <div class="card-header"><h2>Nilai baru</h2></div>
        <div class="card-body">
            <?php if ($new === null): ?><p class="muted">—</p><?php else: ?>
                <pre class="json-block"><?= e(json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php endif; ?>
        </div>
    </section>
</div>
