<?php

use App\Helpers\Session;

$flashes = Session::pullFlash();
$pageTitle = isset($title) && $title !== '' ? $title . ' · PIK Marketing Control' : 'PIK Marketing Control';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#F5F5F7">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">
<main class="auth-wrap">
    <div class="auth-brand">
        <img src="<?= e(asset('assets/img/logo.svg')) ?>" alt="" width="44" height="44">
        <div>
            <div class="auth-brand-name">PIK Marketing Control</div>
            <div class="auth-brand-sub">PT Permata Indo Kemas</div>
        </div>
    </div>
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> auth-alert" role="alert"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
    <p class="auth-footer">&copy; <?= date('Y') ?> PT Permata Indo Kemas</p>
</main>
<script src="<?= e(asset('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
