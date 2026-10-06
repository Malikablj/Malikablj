<?php

use App\Helpers\Navigation;
use App\Helpers\Session;
use App\Models\Notification;

$user = auth_user();
$flashes = Session::pullFlash();
$unread = $user ? Notification::unreadCount((int) $user['id']) : 0;
$collapsed = ($_COOKIE['pik_sidebar'] ?? '') === 'collapsed';
$pageTitle = isset($title) && $title !== '' ? $title . ' · PIK Marketing Control' : 'PIK Marketing Control';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-path" content="<?= e(url('/')) ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#F5F5F7">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app-body<?= $collapsed ? ' sidebar-collapsed' : '' ?>">
<a class="skip-link" href="#main-content">Lewati ke konten</a>

<aside class="sidebar d-none d-lg-flex" aria-label="Navigasi utama">
    <div class="sidebar-brand">
        <a href="<?= e(url('/')) ?>" class="brand-link">
            <img src="<?= e(asset('assets/img/logo.svg')) ?>" alt="" width="34" height="34" class="brand-mark">
            <span class="brand-text">
                <span class="brand-name">PIK Marketing</span>
                <span class="brand-sub">Control</span>
            </span>
        </a>
    </div>
    <nav class="sidebar-scroll">
        <?= App\Helpers\View::partial('partials/nav', ['groups' => Navigation::visibleGroups()]) ?>
    </nav>
    <div class="sidebar-footer">
        <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-label="Ciutkan / lebarkan sidebar">
            <i class="bi bi-layout-sidebar-inset"></i><span class="nav-text">Ciutkan menu</span>
        </button>
    </div>
</aside>

<div class="offcanvas offcanvas-start mobile-nav" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
    <div class="offcanvas-header">
        <div class="brand-link" id="mobileNavLabel">
            <img src="<?= e(asset('assets/img/logo.svg')) ?>" alt="" width="32" height="32" class="brand-mark">
            <span class="brand-text"><span class="brand-name">PIK Marketing</span><span class="brand-sub">Control</span></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
    </div>
    <div class="offcanvas-body">
        <?= App\Helpers\View::partial('partials/nav', ['groups' => Navigation::visibleGroups()]) ?>
    </div>
</div>

<div class="app-main">
    <header class="topbar">
        <button class="icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Buka menu">
            <i class="bi bi-list"></i>
        </button>
        <?php if (can('customers.view') || can('purchase_orders.view') || can('products.view') || can('leads.view')): ?>
            <form class="topbar-search" action="<?= e(url('/search')) ?>" method="get" role="search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" placeholder="Cari customer, OEF, lead, produk…" aria-label="Pencarian global" value="<?= e($_GET['q'] ?? '') ?>" maxlength="100">
            </form>
        <?php else: ?>
            <div class="flex-grow-1"></div>
        <?php endif; ?>
        <div class="topbar-actions">
            <a class="icon-btn position-relative" href="<?= e(url('/notifications')) ?>" aria-label="Notifikasi<?= $unread ? " ({$unread} belum dibaca)" : '' ?>">
                <i class="bi bi-bell"></i>
                <?php if ($unread > 0): ?><span class="notif-dot"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?>
            </a>
            <div class="dropdown">
                <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar avatar-sm"><?= e(initials($user['name'] ?? '')) ?></span>
                    <span class="user-chip-text d-none d-md-flex">
                        <span class="user-chip-name"><?= e($user['name'] ?? '') ?></span>
                        <span class="user-chip-role"><?= e($user['role'] ?? '') ?></span>
                    </span>
                    <i class="bi bi-chevron-down small text-secondary d-none d-md-inline"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-sm">
                    <div class="px-3 py-2 small">
                        <div class="fw-semibold"><?= e($user['name'] ?? '') ?></div>
                        <div class="text-secondary"><?= e($user['email'] ?? '') ?></div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person me-2"></i>Profil saya</a>
                    <a class="dropdown-item" href="<?= e(url('/profile/password')) ?>"><i class="bi bi-key me-2"></i>Ganti password</a>
                    <div class="dropdown-divider"></div>
                    <form action="<?= e(url('/logout')) ?>" method="post" class="px-2">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item rounded"><i class="bi bi-box-arrow-right me-2"></i>Keluar</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="content" id="main-content" tabindex="-1">
        <?php if ($flashes): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                        <?= e($flash['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?= $content ?>
    </main>
</div>

<?php $bottom = Navigation::bottomItems(); ?>
<?php if ($bottom): ?>
<nav class="bottom-nav d-lg-none" aria-label="Navigasi cepat">
    <?php foreach ($bottom as $item): ?>
        <a href="<?= e(url($item['path'])) ?>" class="<?= Navigation::isActive($item['path']) ? 'active' : '' ?>">
            <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
    <button type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav">
        <i class="bi bi-grid-3x3-gap"></i><span>Menu</span>
    </button>
</nav>
<?php endif; ?>

<script src="<?= e(asset('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
