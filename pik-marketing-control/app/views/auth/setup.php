<?php /** @var array<string,string> $errors */ ?>
<section class="auth-card">
    <span class="badge-soft badge-soft-accent no-dot mb-3">Langkah pertama</span>
    <h1 class="auth-title">Buat akun Admin</h1>
    <p class="auth-sub">Belum ada user di sistem. Buat akun Admin pertama untuk mulai memakai PIK Marketing Control. Halaman ini otomatis tertutup setelah Admin dibuat.</p>

    <form method="post" action="<?= e(url('/setup')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="name">Nama lengkap <span class="req">*</span></label>
            <input type="text" class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="120" autocomplete="name">
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="email">Email <span class="req">*</span></label>
            <input type="email" class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190" autocomplete="username">
            <?= field_error($errors, 'email') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password <span class="req">*</span></label>
            <input type="password" class="form-control<?= invalid($errors, 'password') ?>" id="password" name="password" required maxlength="200" autocomplete="new-password">
            <div class="form-text">Minimal 8 karakter, kombinasi huruf dan angka.</div>
            <?= field_error($errors, 'password') ?>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Ulangi password <span class="req">*</span></label>
            <input type="password" class="form-control<?= invalid($errors, 'password_confirmation') ?>" id="password_confirmation" name="password_confirmation" required maxlength="200" autocomplete="new-password">
            <?= field_error($errors, 'password_confirmation') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">Buat Admin & Masuk</button>
    </form>
</section>
