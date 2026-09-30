<header class="page-header">
    <div>
        <p class="eyebrow">Master Data</p>
        <h1>Approval Workflow</h1>
        <p class="subtitle">Tahap approval per department. Department tanpa workflow khusus memakai workflow default.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/approval-workflows/create')) ?>"><?= icon('plus') ?> Tambah Workflow</a>
    </div>
</header>

<section class="card">
    <?php if ($workflows === []): ?>
        <div class="empty">
            <?= icon('flow') ?>
            <strong>Belum ada workflow</strong>
            <span>PR tidak dapat disubmit sebelum ada workflow aktif.</span>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <th scope="col">Workflow</th>
                    <th scope="col">Berlaku untuk</th>
                    <th scope="col">Tahap</th>
                    <th scope="col">Dipakai</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Aksi</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($workflows as $workflow): ?>
                    <tr class="<?= $workflow['is_active'] ? '' : 'row-inactive' ?>">
                        <td class="cell-primary" data-label="">
                            <a class="row-link" href="<?= e(url('/approval-workflows/' . $workflow['id'] . '/edit')) ?>"><?= e($workflow['name']) ?></a>
                            <?php if ($workflow['description']): ?><span class="sub"><?= e($workflow['description']) ?></span><?php endif; ?>
                        </td>
                        <td data-label="Berlaku untuk"><?= $workflow['department_name'] ? e($workflow['department_name']) : '<span class="badge badge-neutral">Default</span>' ?></td>
                        <td data-label="Tahap">
                            <div class="workflow-steps-inline">
                                <?php foreach ($workflow['steps'] as $i => $step): ?>
                                    <?php if ($i > 0): ?><?= icon('arrow-right') ?><?php endif; ?>
                                    <span class="step-pill" title="<?= e($step['approver_type'] === 'user' ? ($step['approver_user_name'] ?? '') : role_label((string) $step['approver_role']) . ($step['same_department'] ? ' (department PR)' : '')) ?>">
                                        <?= e($step['label']) ?><?= $step['is_required'] ? '' : ' ≥ ' . e(money($step['min_amount'])) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td data-label="Dipakai" class="num"><?= e((string) $workflow['usage_count']) ?> PR</td>
                        <td data-label="Status"><span class="badge <?= $workflow['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><span class="badge-dot"></span><?= $workflow['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td data-label="">
                            <div class="table-actions">
                                <a class="btn btn-secondary btn-sm" href="<?= e(url('/approval-workflows/' . $workflow['id'] . '/edit')) ?>"><?= icon('edit') ?> Ubah</a>
                                <?php if ((int) $workflow['usage_count'] === 0): ?>
                                    <form method="post" action="<?= e(url('/approval-workflows/' . $workflow['id'] . '/delete')) ?>" data-confirm="Hapus workflow <?= e($workflow['name']) ?>?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="icon-button" aria-label="Hapus workflow"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
