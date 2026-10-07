<?php /** @var array<string,string> $errors @var string $email */ ?>
<section class="auth-card">
    <h1 class="auth-title">Masuk</h1>
    <p class="auth-sub">Gunakan akun yang diberikan Admin untuk mengakses sistem.</p>

    <?php if (!empty($errors['login'])): ?>
        <div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-circle me-1"></i><?= e($errors['login']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input type="email" class="form-control form-control-lg<?= invalid($errors, 'email') ?>" id="email" name="email"
                   value="<?= e($email) ?>" autocomplete="username" required autofocus maxlength="190">
            <?= field_error($errors, 'email') ?>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password">Password</label>
            <div class="position-relative">
                <input type="password" class="form-control form-control-lg<?= invalid($errors, 'password') ?>" id="password" name="password"
                       autocomplete="current-password" required maxlength="200">
                <button type="button" class="btn btn-link btn-sm position-absolute top-50 end-0 translate-middle-y text-secondary" data-toggle-password="#password" aria-label="Tampilkan password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <?= field_error($errors, 'password') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">Masuk</button>
    </form>
    <p class="text-secondary small mt-4 mb-0 text-center">Lupa password? Hubungi Admin untuk reset password.</p>
</section>
