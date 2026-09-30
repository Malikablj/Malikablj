<?php
/** @var string $content */
$user = auth_user();
$counts = nav_counts();
$isAdmin = is_admin($user);
$role = (string) ($user['role'] ?? '');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'PR PIK') ?> · <?= e(config('app.name', 'PR PIK')) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Lewati ke konten utama</a>
<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">P</span>
            <span class="brand-text">
                <strong>PR PIK</strong>
                <small>Permata Indo Kemas</small>
            </span>
            <button type="button" class="icon-button sidebar-close" data-nav-close aria-label="Tutup menu"><?= icon('x') ?></button>
        </div>

        <nav class="nav">
            <a class="nav-link<?= nav_active('/dashboard') ?>" href="<?= e(url('/dashboard')) ?>"><?= icon('home') ?><span>Dashboard</span></a>
            <a class="nav-link<?= nav_active('/pr') ?>" href="<?= e(url('/pr')) ?>"><?= icon('document') ?><span>Purchase Requisition</span></a>
            <?php if ($role === 'requester'): ?>
                <a class="nav-link" href="<?= e(url('/pr/create')) ?>"><?= icon('plus') ?><span>Buat PR</span></a>
            <?php endif; ?>
            <?php if ($role !== 'requester'): ?>
                <a class="nav-link<?= nav_active('/approvals') ?>" href="<?= e(url('/approvals')) ?>">
                    <?= icon('check-circle') ?><span>Approval</span>
                    <?php if ($counts['approvals'] > 0): ?><span class="nav-badge" aria-label="<?= $counts['approvals'] ?> menunggu"><?= $counts['approvals'] ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <a class="nav-link<?= nav_active('/notifications') ?>" href="<?= e(url('/notifications')) ?>">
                <?= icon('bell') ?><span>Notifikasi</span>
                <?php if ($counts['notifications'] > 0): ?><span class="nav-badge" aria-label="<?= $counts['notifications'] ?> belum dibaca"><?= $counts['notifications'] ?></span><?php endif; ?>
            </a>

            <?php if ($isAdmin): ?>
                <p class="nav-heading">Analitik</p>
                <a class="nav-link<?= nav_active('/reports') ?>" href="<?= e(url('/reports')) ?>"><?= icon('chart') ?><span>Laporan</span></a>

                <p class="nav-heading">Master Data</p>
                <a class="nav-link<?= nav_active('/users') ?>" href="<?= e(url('/users')) ?>"><?= icon('users') ?><span>User</span></a>
                <a class="nav-link<?= nav_active('/departments') ?>" href="<?= e(url('/departments')) ?>"><?= icon('building') ?><span>Department</span></a>
                <a class="nav-link<?= nav_active('/suppliers') ?>" href="<?= e(url('/suppliers')) ?>"><?= icon('truck') ?><span>Supplier</span></a>
                <a class="nav-link<?= nav_active('/items') ?>" href="<?= e(url('/items')) ?>"><?= icon('box') ?><span>Item</span></a>
                <a class="nav-link<?= nav_active('/approval-workflows') ?>" href="<?= e(url('/approval-workflows')) ?>"><?= icon('flow') ?><span>Approval Workflow</span></a>
            <?php endif; ?>

            <p class="nav-heading">Sistem</p>
            <?php if ($isAdmin): ?>
                <a class="nav-link<?= nav_active('/audit-logs') ?>" href="<?= e(url('/audit-logs')) ?>"><?= icon('shield') ?><span>Audit Log</span></a>
            <?php endif; ?>
            <a class="nav-link<?= nav_active('/settings') ?>" href="<?= e(url('/settings')) ?>"><?= icon('gear') ?><span>Pengaturan</span></a>
        </nav>

        <div class="sidebar-user">
            <span class="avatar" aria-hidden="true"><?= e(initials((string) $user['name'])) ?></span>
            <span class="sidebar-user-text">
                <strong><?= e($user['name']) ?></strong>
                <small><?= e(role_label($role)) ?><?= $user['department_name'] ? ' · ' . e($user['department_name']) : '' ?></small>
            </span>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="icon-button" title="Keluar" aria-label="Keluar"><?= icon('logout') ?></button>
            </form>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-nav-close></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-button" data-nav-open aria-controls="sidebar" aria-expanded="false" aria-label="Buka menu"><?= icon('menu') ?></button>
            <a class="topbar-brand" href="<?= e(url('/dashboard')) ?>"><span class="brand-mark" aria-hidden="true">P</span> PR PIK</a>
            <a class="icon-button topbar-bell" href="<?= e(url('/notifications')) ?>" aria-label="Notifikasi">
                <?= icon('bell') ?>
                <?php if ($counts['notifications'] > 0): ?><span class="dot-badge"><?= $counts['notifications'] ?></span><?php endif; ?>
            </a>
        </header>

        <main id="main" class="content" tabindex="-1">
            <?= \App\Core\View::partial('flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>
</body>
</html>
