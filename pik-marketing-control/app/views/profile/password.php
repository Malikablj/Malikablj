<?php /** @var array<string,string> $errors */ ?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Akun</div>
        <h1 class="page-title">Ganti Password</h1>
        <p class="page-subtitle">Gunakan minimal 8 karakter dengan kombinasi huruf dan angka. Jangan memakai password yang sama dengan akun lain.</p>
    </div>
</div>
<div class="row">
    <div class="col-lg-6">
        <form class="surface" method="post" action="<?= e(url('/profile/password')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-section">
                <div class="mb-3">
                    <label class="form-label" for="current_password">Password saat ini <span class="req">*</span></label>
                    <input type="password" class="form-control<?= invalid($errors, 'current_password') ?>" id="current_password" name="current_password" required maxlength="200" autocomplete="current-password">
                    <?= field_error($errors, 'current_password') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password baru <span class="req">*</span></label>
                    <input type="password" class="form-control<?= invalid($errors, 'password') ?>" id="password" name="password" required maxlength="200" autocomplete="new-password">
                    <?= field_error($errors, 'password') ?>
                </div>
                <div>
                    <label class="form-label" for="password_confirmation">Ulangi password baru <span class="req">*</span></label>
                    <input type="password" class="form-control<?= invalid($errors, 'password_confirmation') ?>" id="password_confirmation" name="password_confirmation" required maxlength="200" autocomplete="new-password">
                    <?= field_error($errors, 'password_confirmation') ?>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Simpan password baru</button>
            </div>
        </form>
    </div>
</div>
