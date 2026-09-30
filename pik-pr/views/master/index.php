<?php
/** @var array $config @var list<array> $rows @var array $filters */
$base = $config['baseUrl'];
?>
<header class="page-header">
    <div>
        <p class="eyebrow">Master Data</p>
        <h1><?= e($config['title']) ?></h1>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url($base . '/create')) ?>"><?= icon('plus') ?> Tambah <?= e($config['title']) ?></a>
    </div>
</header>

<section class="card">
    <form method="get" action="<?= e(url($base)) ?>" class="toolbar" role="search">
        <div class="field field-search">
            <label for="q" class="visually-hidden">Cari</label>
            <div class="search-input">
                <?= icon('search') ?>
                <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama atau kode…">
            </div>
        </div>
        <div class="field">
            <label for="status" class="visually-hidden">Status</label>
            <select id="status" name="status" data-autosubmit>
                <option value="">Semua status</option>
                <option value="active"<?= selected($filters['status'], 'active') ?>>Aktif</option>
                <option value="inactive"<?= selected($filters['status'], 'inactive') ?>>Nonaktif</option>
            </select>
        </div>
        <div class="toolbar-actions">
            <button type="submit" class="btn btn-secondary">Cari</button>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <div class="empty">
            <?= icon('box') ?>
            <strong>Belum ada data</strong>
            <span>Tambahkan <?= e(strtolower($config['title'])) ?> pertama.</span>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <?php foreach ($config['columns'] as $key => $label): ?>
                        <th scope="col"<?= ($config['fields'][$key]['type'] ?? '') === 'money' ? ' class="text-right"' : '' ?>><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Aksi</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr class="<?= $row['is_active'] ? '' : 'row-inactive' ?>">
                        <?php $first = true; foreach ($config['columns'] as $key => $label): ?>
                            <?php $type = $config['fields'][$key]['type'] ?? 'text'; ?>
                            <td data-label="<?= e($first ? '' : $label) ?>" class="<?= $first ? 'cell-primary' : '' ?><?= $type === 'money' ? ' text-right num' : '' ?>">
                                <?php if ($first): ?>
                                    <a class="row-link mono" href="<?= e(url($base . '/' . $row['id'] . '/edit')) ?>"><?= e($row[$key]) ?></a>
                                <?php elseif ($type === 'money'): ?>
                                    <?= e(money($row[$key])) ?>
                                <?php else: ?>
                                    <?= e($row[$key] ?? '-') ?>
                                <?php endif; ?>
                            </td>
                        <?php $first = false; endforeach; ?>
                        <td data-label="Status">
                            <span class="badge <?= $row['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><span class="badge-dot"></span><?= $row['is_active'] ? 'Aktif' : 'Nonaktif' ?></span>
                        </td>
                        <td data-label="">
                            <div class="table-actions">
                                <a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/' . $row['id'] . '/edit')) ?>"><?= icon('edit') ?> Ubah</a>
                                <form method="post" action="<?= e(url($base . '/' . $row['id'] . '/toggle')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-secondary btn-sm"><?= $row['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                </form>
                                <form method="post" action="<?= e(url($base . '/' . $row['id'] . '/delete')) ?>" data-confirm="Hapus <?= e($row['name']) ?>? Data yang sudah dipakai tidak dapat dihapus.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="icon-button" title="Hapus" aria-label="Hapus <?= e($row['name']) ?>"><?= icon('trash') ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= \App\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
</section>
