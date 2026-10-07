<?php

use App\Helpers\Form;
use App\Helpers\Permission;

/** @var array<string,mixed>|null $user @var array<string,string> $errors @var string|null $blockedUntil @var array<string,int> $assignments @var bool $isSelf */
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
                    <?= Form::select('role', 'Role', Form::list(Permission::ROLES), old('role', $user, 'Viewer'), $errors, ['required' => true, 'col' => 'col-md-6']) ?>
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
            <?php if ($blockedUntil !== null): ?>
                <form class="callout callout-warning mb-3" method="post" action="<?= e(url('/users/' . $user['id'] . '/unblock')) ?>" data-confirm="Buka blokir login <?= e($user['name']) ?> sekarang?">
                    <?= csrf_field() ?><input type="hidden" name="return" value="/users/<?= (int) $user['id'] ?>/edit">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-shield-lock"></i>
                        <div class="flex-grow-1"><strong>Login user ini sedang terblokir</strong> karena salah password berulang kali.
                            Blokir terbuka otomatis pukul <?= e(date('H:i', (int) strtotime($blockedUntil))) ?>.</div>
                    </div>
                    <button class="btn btn-warning btn-sm mt-2" type="submit"><i class="bi bi-unlock"></i> Buka blokir sekarang</button>
                </form>
            <?php endif; ?>
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
            </div>
            <?php if (!$isSelf): ?>
                <form class="surface mt-3" method="post" action="<?= e(url('/users/' . $user['id'] . '/delete')) ?>" data-confirm="Hapus user <?= e($user['name']) ?> (<?= e($user['email']) ?>) secara permanen? Tindakan ini tidak bisa dibatalkan.">
                    <?= csrf_field() ?>
                    <div class="surface-header">
                        <div>
                            <h2 class="surface-title text-danger">Hapus user</h2>
                            <p class="surface-subtitle">User tidak bisa login lagi. Data yang pernah dibuatnya tetap ada dan nama user tetap tercatat di Audit Log.</p>
                        </div>
                    </div>
                    <div class="surface-body small">
                        <?php if ($assignments !== []): ?>
                            <p class="mb-1">User ini masih tercatat sebagai PIC / sales pada:</p>
                            <ul class="mb-2"><?php foreach ($assignments as $label => $n): ?><li><?= e(fmt_qty($n, '0')) ?> <?= e($label) ?></li><?php endforeach; ?></ul>
                            <p class="text-secondary mb-0">Setelah dihapus, kolom PIC / sales pada data tersebut menjadi kosong. Bila hanya sementara tidak dipakai, cukup hilangkan centang <em>User aktif</em>.</p>
                        <?php else: ?>
                            <p class="text-secondary mb-0">User ini tidak sedang menjadi PIC customer, lead, follow up, maupun sales order.</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Hapus user</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
