<?php
declare(strict_types=1);

/**
 * Kerangka halaman aplikasi: <head>, sidebar, topbar, flash.
 *
 * Variabel yang dipakai:
 * @var string $pageTitle   judul halaman (teks biasa, di-escape di sini)
 * @var string $activeNav   kunci navigasi aktif
 * @var array  $breadcrumbs [[label, url|null], ...] (opsional)
 * @var array  $pageScripts js tambahan (opsional)
 */

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Session;

$pageTitle = $pageTitle ?? '';
$activeNav = $activeNav ?? '';
$breadcrumbs = $breadcrumbs ?? [];
$__user = Auth::user();
require __DIR__ . '/head.php';
?>
<body class="app <?= e($bodyClass ?? '') ?>">
<a class="skip-link" href="#main"><?= t('common.skip_to_content') ?></a>
<div class="shell" data-shell>
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="shell-backdrop" data-drawer-close hidden></div>
  <div class="shell-main">
    <header class="topbar">
      <button type="button" class="icon-btn topbar-menu" data-drawer-open aria-label="<?= t('common.open_menu') ?>" aria-controls="sidebar" aria-expanded="false">
        <?= icon('menu') ?>
      </button>
      <div class="topbar-title">
        <?php if ($breadcrumbs): ?>
          <nav class="breadcrumbs" aria-label="Breadcrumb">
            <?php foreach ($breadcrumbs as $i => [$label, $href]): ?>
              <?php if ($i > 0): ?><span class="breadcrumbs-sep" aria-hidden="true">›</span><?php endif; ?>
              <?php if ($href): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php else: ?><span aria-current="page"><?= e($label) ?></span><?php endif; ?>
            <?php endforeach; ?>
          </nav>
        <?php else: ?>
          <span class="topbar-heading"><?= e($pageTitle) ?></span>
        <?php endif; ?>
      </div>
      <div class="topbar-actions">
        <form method="post" action="<?= e(url('api/preferences.php')) ?>" class="lang-switch" data-pref-form>
          <?= csrf_field() ?>
          <input type="hidden" name="return" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '')) ?>">
          <span class="visually-hidden"><?= t('lang.switch') ?></span>
          <div class="segmented" role="group" aria-label="<?= t('lang.switch') ?>">
            <?php foreach (I18n::SUPPORTED as $__lang): ?>
              <button type="submit" name="language" value="<?= e($__lang) ?>" class="segmented-item<?= I18n::locale() === $__lang ? ' is-active' : '' ?>" aria-pressed="<?= I18n::locale() === $__lang ? 'true' : 'false' ?>" title="<?= t('lang.' . $__lang) ?>"><?= e(strtoupper($__lang)) ?></button>
            <?php endforeach; ?>
          </div>
        </form>
        <button type="button" class="icon-btn" data-theme-toggle aria-label="<?= t('theme.toggle') ?>"
                data-label-system="<?= t('theme.system') ?>" data-label-light="<?= t('theme.light') ?>" data-label-dark="<?= t('theme.dark') ?>"
                title="<?= t('theme.' . ($__user?->theme ?? 'system')) ?>">
          <span class="theme-icon theme-icon-system"><?= icon('monitor') ?></span>
          <span class="theme-icon theme-icon-light"><?= icon('sun') ?></span>
          <span class="theme-icon theme-icon-dark"><?= icon('moon') ?></span>
        </button>
        <?php if (is_file(APP_ROOT . '/public/notifications.php')): ?>
          <a class="icon-btn notif-bell" href="<?= e(url('notifications.php')) ?>" aria-label="<?= t('nav.notifications') ?>" data-notif-bell>
            <?= icon('bell') ?><span class="notif-count" data-notif-count hidden></span>
          </a>
        <?php endif; ?>
        <details class="menu user-menu">
          <summary class="user-chip" aria-label="<?= t('common.profile') ?>">
            <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($__user?->name ?? '?', 0, 1))) ?></span>
            <span class="user-chip-text">
              <span class="user-chip-name"><?= e($__user?->name) ?></span>
              <span class="user-chip-role"><?= role_label($__user?->roleCode ?? '') ?></span>
            </span>
            <?= icon('chevron-down', 'icon icon-sm') ?>
          </summary>
          <div class="menu-panel" role="menu">
            <a role="menuitem" href="<?= e(url('profile.php')) ?>"><?= icon('user') ?> <?= t('common.profile') ?></a>
            <form method="post" action="<?= e(url('logout.php')) ?>">
              <?= csrf_field() ?>
              <button type="submit" role="menuitem"><?= icon('logout') ?> <?= t('common.logout') ?></button>
            </form>
          </div>
        </details>
      </div>
    </header>
    <main id="main" class="content" tabindex="-1">
      <?php foreach (Session::takeFlashes() as $__flash): ?>
        <div class="flash flash-<?= e($__flash['type']) ?>" role="<?= $__flash['type'] === 'error' ? 'alert' : 'status' ?>" data-flash>
          <?= icon(match ($__flash['type']) { 'success' => 'check', 'error' => 'alert', 'warning' => 'alert', default => 'info' }) ?>
          <span><?= e($__flash['message']) ?></span>
          <button type="button" class="icon-btn icon-btn-sm" data-flash-close aria-label="<?= t('common.close') ?>"><?= icon('x', 'icon icon-sm') ?></button>
        </div>
      <?php endforeach; ?>
