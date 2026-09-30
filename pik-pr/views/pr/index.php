<?php
$statusTabs = ['' => 'Semua', 'draft' => 'Draft', 'pending' => 'Menunggu', 'revision_required' => 'Revisi', 'approved' => 'Approved', 'completed' => 'Arsip'];
$hasFilter = $filters['q'] !== '' || $filters['department_id'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== '';
?>
<header class="page-header">
    <div>
        <h1>Purchase Requisition</h1>
        <p class="subtitle"><?= is_admin() ? 'Seluruh PR di semua department.' : 'PR yang dapat Anda akses.' ?></p>
    </div>
    <?php if ($canCreate): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= e(url('/pr/create')) ?>"><?= icon('plus') ?> Buat PR</a>
        </div>
    <?php endif; ?>
</header>

<nav class="tabs" aria-label="Filter status">
    <?php foreach ($statusTabs as $value => $label): ?>
        <a class="tab<?= $filters['status'] === $value ? ' is-active' : '' ?>" href="<?= e(query_url(['status' => $value, 'page' => null])) ?>"<?= $filters['status'] === $value ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <form method="get" action="<?= e(url('/pr')) ?>" class="toolbar" role="search">
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <div class="field field-search">
            <label for="q" class="small">Cari</label>
            <div class="search-input">
                <?= icon('search') ?>
                <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="No. PR, supplier, pemohon, catatan…">
            </div>
        </div>
        <div class="field">
            <label for="department_id" class="small">Department</label>
            <select id="department_id" name="department_id">
                <option value="">Semua</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= e((string) $department['id']) ?>"<?= selected($filters['department_id'], $department['id']) ?>><?= e($department['name']) ?></option>
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
            <?php if ($hasFilter): ?><a class="btn btn-ghost" href="<?= e(url('/pr', ['status' => $filters['status']])) ?>">Reset</a><?php endif; ?>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <div class="empty">
            <?= icon('document') ?>
            <strong><?= $hasFilter || $filters['status'] !== '' ? 'Tidak ada PR yang cocok' : 'Belum ada PR' ?></strong>
            <?php if ($canCreate && !$hasFilter): ?>
                <span>Buat Purchase Requisition pertama Anda.</span><br>
                <a class="btn btn-primary btn-sm" href="<?= e(url('/pr/create')) ?>"><?= icon('plus') ?> Buat PR</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <th scope="col">No. PR</th>
                    <th scope="col">Tanggal</th>
                    <th scope="col">Department</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Pemohon</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-right">Total</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $pr): ?>
                    <tr>
                        <td class="cell-primary" data-label="">
                            <a class="row-link" href="<?= e(url('/pr/' . $pr['id'])) ?>"><?= e(pr_label($pr)) ?></a>
                            <?php if (in_array($pr['status'], ['submitted', 'in_review'], true) && $pr['current_step_label']): ?>
                                <span class="sub">Menunggu: <?= e($pr['current_step_label']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Tanggal" class="nowrap"><?= e(tanggal($pr['pr_date'])) ?></td>
                        <td data-label="Department"><?= e($pr['department_name']) ?></td>
                        <td data-label="Supplier"><?= e($pr['supplier_name'] ?? '-') ?></td>
                        <td data-label="Pemohon"><?= e($pr['requester_name']) ?></td>
                        <td data-label="Status"><?= status_badge((string) $pr['status']) ?></td>
                        <td data-label="Total" class="text-right num"><?= e(money($pr['grand_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= \App\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
</section>
