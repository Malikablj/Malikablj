<header class="page-header">
    <div>
        <p class="eyebrow">Master Data</p>
        <h1>User</h1>
        <p class="subtitle">Akun tidak dihapus permanen agar riwayat PR tetap utuh — nonaktifkan untuk mencabut akses.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/users/create')) ?>"><?= icon('plus') ?> Tambah User</a>
    </div>
</header>

<section class="card">
    <form method="get" action="<?= e(url('/users')) ?>" class="toolbar" role="search">
        <div class="field field-search">
            <label for="q" class="visually-hidden">Cari</label>
            <div class="search-input">
                <?= icon('search') ?>
                <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama atau email…">
            </div>
        </div>
        <div class="field">
            <label for="role" class="visually-hidden">Role</label>
            <select id="role" name="role" data-autosubmit>
                <option value="">Semua role</option>
                <?php foreach (\App\Models\Role::labels() as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($filters['role'], $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status" class="visually-hidden">Status</label>
            <select id="status" name="status" data-autosubmit>
                <option value="">Semua status</option>
                <option value="active"<?= selected($filters['status'], 'active') ?>>Aktif</option>
                <option value="inactive"<?= selected($filters['status'], 'inactive') ?>>Nonaktif</option>
            </select>
        </div>
        <div class="toolbar-actions"><button type="submit" class="btn btn-secondary">Cari</button></div>
    </form>

    <?php if ($rows === []): ?>
        <div class="empty"><?= icon('users') ?><strong>Tidak ada user</strong></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead>
                <tr>
                    <th scope="col">Nama</th>
                    <th scope="col">Role</th>
                    <th scope="col">Department</th>
                    <th scope="col">Login terakhir</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Aksi</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): $isSelf = (int) $row['id'] === (int) auth_user()['id']; ?>
                    <tr class="<?= $row['is_active'] ? '' : 'row-inactive' ?>">
                        <td class="cell-primary" data-label="">
                            <strong><?= e($row['name']) ?></strong><?= $isSelf ? ' <span class="badge badge-neutral">Anda</span>' : '' ?>
                            <span class="sub"><?= e($row['email']) ?><?= $row['job_title'] ? ' · ' . e($row['job_title']) : '' ?></span>
                        </td>
                        <td data-label="Role"><?= e(role_label((string) $row['role'])) ?></td>
                        <td data-label="Department"><?= e($row['department_name'] ?? '-') ?></td>
                        <td data-label="Login terakhir" class="nowrap"><?= e($row['last_login_at'] ? time_ago($row['last_login_at']) : 'Belum pernah') ?></td>
                        <td data-label="Status"><span class="badge <?= $row['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><span class="badge-dot"></span><?= $row['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td data-label="">
                            <?php $manageable = $row['role'] !== 'super_admin' || auth_user()['role'] === 'super_admin'; ?>
                            <?php if ($manageable): ?>
                                <div class="table-actions">
                                    <a class="btn btn-secondary btn-sm" href="<?= e(url('/users/' . $row['id'] . '/edit')) ?>"><?= icon('edit') ?> Ubah</a>
                                    <?php if (!$isSelf): ?>
                                        <form method="post" action="<?= e(url('/users/' . $row['id'] . '/toggle')) ?>"
                                              <?= $row['is_active'] ? 'data-confirm="Nonaktifkan ' . e($row['name']) . '? User tidak akan bisa login."' : '' ?>>
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-secondary btn-sm"><?= $row['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= \App\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
</section>
