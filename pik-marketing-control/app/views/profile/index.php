<?php

use App\Helpers\Form;

/** @var array<string,mixed> $user @var array<string,string> $errors */
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Akun</div>
        <h1 class="page-title">Profil Saya</h1>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-7">
        <form class="surface" method="post" action="<?= e(url('/profile')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <span class="avatar avatar-lg avatar-accent"><?= e(initials($user['name'])) ?></span>
                    <div>
                        <div class="fw-semibold"><?= e($user['name']) ?></div>
                        <div class="text-secondary small"><?= e($user['email']) ?> · <?= e($user['role']) ?></div>
                    </div>
                </div>
                <div class="row g-3">
                    <?= Form::input('name', 'Nama lengkap', old('name', $user), $errors, ['required' => true, 'maxlength' => 120]) ?>
                    <?= Form::input('email_display', 'Email', (string) $user['email'], [], ['readonly' => true, 'help' => 'Perubahan email dilakukan oleh Admin.']) ?>
                </div>
            </div>
            <div class="form-actions">
                <a class="btn btn-light" href="<?= e(url('/profile/password')) ?>"><i class="bi bi-key"></i> Ganti password</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="surface surface-pad">
            <h2 class="surface-title mb-3">Informasi akun</h2>
            <dl class="dl-grid">
                <div><dt>ID User</dt><dd class="code-chip"><?= e($user['code']) ?></dd></div>
                <div><dt>Role</dt><dd><?= e($user['role']) ?></dd></div>
                <div><dt>Login terakhir</dt><dd><?= e(fmt_datetime($user['last_login_at'])) ?></dd></div>
                <div><dt>Password diganti</dt><dd><?= e(fmt_datetime($user['password_changed_at'])) ?></dd></div>
            </dl>
        </div>
    </div>
</div>
