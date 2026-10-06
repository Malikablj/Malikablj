<?php

use App\Helpers\Form;
use App\Helpers\Permission;

/** @var array<string,mixed>|null $user @var array<string,string> $errors */
$isEdit = $user !== null;
?>
<div class="breadcrumb-lite"><a href="<?= e(url('/users')) ?>">Users</a><i class="bi bi-chevron-right"></i><span><?= $isEdit ? e($user['name']) : 'Tambah' ?></span></div>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit User' : 'Tambah User' ?></h1>
        <?php if ($isEdit): ?><p class="page-subtitle"><span class="code-chip"><?= e($user['code']) ?></span></p><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <form class="surface" method="post" action="<?= e(url($isEdit ? '/users/' . $user['id'] : '/users')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="row g-3">
                    <?= Form::input('name', 'Nama lengkap', old('name', $user), $errors, ['required' => true, 'maxlength' => 120, 'col' => 'col-md-6']) ?>
                    <?= Form::input('email', 'Email (untuk login)', old('email', $user), $errors, ['type' => 'email', 'required' => true, 'maxlength' => 190, 'col' => 'col-md-6']) ?>
                    <?php $roleOptions = []; foreach (Permission::ROLES as $r) { $roleOptions[$r] = $r . ' — ' . Permission::ROLE_HELP[$r]; } ?>
                    <?= Form::select('role', 'Role', $roleOptions, old('role', $user, 'Viewer'), $errors, ['required' => true, 'col' => 'col-md-6']) ?>
                    <div class="col-md-6 d-flex align-items-end">
                        <?= Form::checkbox('is_active', 'User aktif (boleh login)', old('is_active', $user, '1') === '1', $errors, ['col' => 'w-100 pb-2']) ?>
                    </div>
                    <?php if (!$isEdit): ?>
                        <?= Form::input('password', 'Password sementara', '', $errors, ['type' => 'password', 'required' => true, 'maxlength' => 200, 'autocomplete' => 'new-password', 'col' => 'col-md-6', 'help' => 'Minimal 8 karakter, huruf dan angka.']) ?>
                        <div class="col-md-6 d-flex align-items-end">
                            <?= Form::checkbox('must_change_password', 'Wajib ganti password saat login pertama', true, $errors, ['col' => 'w-100 pb-2']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-actions">
                <a href="<?= e(url('/users')) ?>" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>

    <?php if ($isEdit): ?>
        <div class="col-lg-5">
            <form class="surface" method="post" action="<?= e(url('/users/' . $user['id'] . '/password')) ?>" data-confirm="Set password sementara baru untuk user ini?">
                <?= csrf_field() ?>
                <div class="surface-header">
                    <div>
                        <h2 class="surface-title">Reset password</h2>
                        <p class="surface-subtitle">User wajib mengganti password ini saat login berikutnya.</p>
                    </div>
                </div>
                <div class="surface-body">
                    <label class="form-label" for="new_password">Password sementara baru</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required maxlength="200" autocomplete="new-password">
                    <div class="form-text">Minimal 8 karakter, huruf dan angka.</div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-dark">Set password sementara</button>
                </div>
            </form>
            <div class="callout mt-3 small text-secondary">
                <i class="bi bi-info-circle me-1"></i> Login terakhir: <strong><?= e(fmt_datetime($user['last_login_at'], 'belum pernah')) ?></strong>.
                User tidak dapat dihapus agar jejak audit tetap lengkap — gunakan status nonaktif.
            </div>
        </div>
    <?php endif; ?>
</div>
