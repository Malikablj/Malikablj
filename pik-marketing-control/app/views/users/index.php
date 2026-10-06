<?php

use App\Helpers\Form;
use App\Helpers\Permission;

/** @var App\Helpers\Paginator $users */
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle">Kelola akun dan role akses. User yang tidak dipakai cukup dinonaktifkan agar riwayat audit tetap utuh.</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(url('/users/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Tambah User</a>
    </div>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/users')) ?>">
        <div class="filter-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Cari nama atau email…" aria-label="Cari user">
        </div>
        <select class="form-select" name="role" aria-label="Filter role" data-autosubmit>
            <option value="">Semua role</option>
            <?= Form::options(Form::list(Permission::ROLES), $role) ?>
        </select>
        <select class="form-select" name="status" aria-label="Filter status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="active"<?= selected('active', $status) ?>>Aktif</option>
            <option value="inactive"<?= selected('inactive', $status) ?>>Nonaktif</option>
        </select>
        <button class="btn btn-light" type="submit">Terapkan</button>
    </form>

    <?php if ($users->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-people"></i><div class="empty-title">Tidak ada user</div><p>Coba ubah filter pencarian.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead>
                <tr>
                    <th>Nama</th>
                    <th class="d-none d-sm-table-cell">Role</th>
                    <th>Status</th>
                    <th class="d-none d-md-table-cell">Login terakhir</th>
                    <th class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users->items as $u): ?>
                    <tr class="<?= (int) $u['is_active'] === 1 ? '' : 'row-muted' ?>">
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <span class="avatar avatar-sm"><?= e(initials($u['name'])) ?></span>
                                <div>
                                    <div class="cell-title"><?= e($u['name']) ?></div>
                                    <div class="cell-sub"><?= e($u['email']) ?></div>
                                    <div class="cell-sub d-sm-none"><?= e($u['role']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-sm-table-cell"><span class="chip"><?= e($u['role']) ?></span></td>
                        <td>
                            <?= (int) $u['is_active'] === 1 ? status_badge('Active', 'Aktif') : status_badge('Inactive', 'Nonaktif') ?>
                            <?php if ((int) $u['must_change_password'] === 1): ?><span class="badge-soft badge-soft-warning no-dot ms-1">Wajib ganti password</span><?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell text-secondary"><?= e(fmt_datetime($u['last_login_at'], 'Belum pernah')) ?></td>
                        <td class="col-actions">
                            <a class="btn btn-light btn-sm" href="<?= e(url('/users/' . $u['id'] . '/edit')) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $users->footer('user') ?>
    <?php endif; ?>
</div>

<div class="surface surface-pad mt-4">
    <h2 class="surface-title mb-2">Hak akses per role</h2>
    <p class="text-secondary small mb-3">Diatur di <span class="code-chip">config/permissions.php</span> dan diverifikasi di backend untuk setiap halaman &amp; aksi.</p>
    <div class="row g-3 small">
        <?php foreach (Permission::ROLE_HELP as $roleName => $help): ?>
            <div class="col-md-6 col-xl-4"><strong><?= e($roleName) ?></strong><div class="text-secondary"><?= e($help) ?>.</div></div>
        <?php endforeach; ?>
    </div>
</div>
