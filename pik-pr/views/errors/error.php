<section class="error-page">
    <p class="error-code"><?= e((string) $status) ?></p>
    <h1><?= e($title) ?></h1>
    <p class="muted"><?= e($message) ?></p>
    <div class="actions-row">
        <a class="btn btn-primary" href="<?= e(url(auth_user() ? '/dashboard' : '/login')) ?>"><?= icon('home') ?> Kembali ke <?= auth_user() ? 'Dashboard' : 'Login' ?></a>
    </div>
</section>
