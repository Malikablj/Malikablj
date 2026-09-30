<header class="page-header">
    <div>
        <p class="eyebrow">Sistem</p>
        <h1>Audit Log</h1>
        <p class="subtitle">Catatan aktivitas penting. Bersifat read-only — tidak ada user yang dapat mengubah atau menghapusnya dari aplikasi.</p>
    </div>
</header>

<section class="card">
    <form method="get" action="<?= e(url('/audit-logs')) ?>" class="toolbar">
        <div class="field">
            <label for="action" class="small">Aksi</label>
            <select id="action" name="action">
                <option value="">Semua aksi</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= e($action) ?>"<?= selected($filters['action'], $action) ?>><?= e($action) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="entity_type" class="small">Entitas</label>
            <select id="entity_type" name="entity_type">
                <option value="">Semua entitas</option>
                <?php foreach ($entityTypes as $type): ?>
                    <option value="<?= e($type) ?>"<?= selected($filters['entity_type'], $type) ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="user_id" class="small">User</label>
            <select id="user_id" name="user_id">
                <option value="">Semua user</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= e((string) $u['id']) ?>"<?= selected($filters['user_id'], $u['id']) ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="date_from" class="small">Dari</label>
            <input type="date" id="date_from" name="date_from" value="<?= e($filters['date_from']) ?>">
        </div>
        <div class="field">
            <label for="date_to" class="small">Sampai</label>
            <input type="date" id="date_to" name="date_to" value="<?= e($filters['date_to']) ?>">
        </div>
        <div class="toolbar-actions">
            <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filter</button>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <div class="empty"><?= icon('shield') ?><strong>Tidak ada aktivitas</strong></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <th scope="col">Waktu</th>
                    <th scope="col">User</th>
                    <th scope="col">Aksi</th>
                    <th scope="col">Entitas</th>
                    <th scope="col">IP</th>
                    <th scope="col"><span class="visually-hidden">Detail</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="cell-primary nowrap" data-label=""><?= e(tanggal($row['created_at'], true)) ?></td>
                        <td data-label="User"><?= e($row['user_name'] ?? 'Sistem / tamu') ?></td>
                        <td data-label="Aksi"><span class="mono"><?= e($row['action']) ?></span></td>
                        <td data-label="Entitas">
                            <?= e($row['entity_type']) ?><?= $row['entity_id'] !== null ? ' #' . e((string) $row['entity_id']) : '' ?>
                        </td>
                        <td data-label="IP" class="mono"><?= e($row['ip_address'] ?? '-') ?></td>
                        <td data-label=""><div class="table-actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('/audit-logs/' . $row['id'])) ?>">Detail</a></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= \App\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
</section>
