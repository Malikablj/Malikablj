<?php
declare(strict_types=1);

/**
 * Sidebar (PRD §11.7). Menu hanya ditampilkan bila halamannya tersedia dan pengguna berhak —
 * tidak ada tautan ke fitur yang belum berfungsi. Pengaturan hanya untuk Admin.
 *
 * @var string $activeNav
 */

use App\Core\Auth;

$__u = Auth::user();
$__items = [
    ['dashboard', 'dashboard.php', 'nav.dashboard', 'dashboard', 'report.view'],
    ['projects', 'projects.php', 'nav.projects', 'folder', 'project.view'],
    ['npr', 'npr.php', 'nav.npr', 'file-text', 'project.view'],
    ['tracker', 'tracker.php', 'nav.tracker', 'kanban', 'project.view'],
    ['gantt', 'gantt.php', 'nav.gantt', 'gantt', 'project.view'],
    ['calendar', 'calendar.php', 'nav.calendar', 'calendar', 'project.view'],
    ['documents', 'documents.php', 'nav.documents', 'file', 'document.view'],
    ['approvals', 'approvals.php', 'nav.approvals', 'check-circle', 'project.view'],
    ['reports', 'reports.php', 'nav.reports', 'chart', 'report.view'],
    ['notifications', 'notifications.php', 'nav.notifications', 'bell', 'project.view'],
];
$__settings = [
    ['users', 'settings/users.php', 'nav.users', 'users', 'user.manage'],
    ['customers', 'settings/customers.php', 'nav.customers', 'folder', 'customer.manage'],
    ['import', 'settings/import.php', 'nav.import', 'upload', 'project.import'],
    ['masters', 'settings/masters.php', 'nav.masters', 'layers', 'settings.manage'],
    ['workflow', 'settings/workflow.php', 'nav.workflow', 'gantt', 'settings.manage'],
    ['holidays', 'settings/holidays.php', 'nav.holidays', 'calendar', 'settings.manage'],
    ['notif_settings', 'settings/notifications.php', 'nav.notif_settings', 'bell', 'settings.manage'],
    ['email_queue', 'settings/email-queue.php', 'nav.email_queue', 'refresh', 'settings.manage'],
    ['audit', 'settings/audit.php', 'nav.audit', 'shield', 'audit.view_full'],
];
?>
<aside class="sidebar" id="sidebar" data-drawer aria-label="<?= t('nav.main') ?>">
  <div class="sidebar-brand">
    <a href="<?= e(url('dashboard.php')) ?>" class="brand-link">
      <img class="brand-logo brand-logo-light" src="<?= e(asset('images/logo-light.png')) ?>" alt="PT. Permata Indo Kemas" width="112" height="60">
      <img class="brand-logo brand-logo-dark" src="<?= e(asset('images/logo-dark.png')) ?>" alt="" aria-hidden="true" width="112" height="60">
    </a>
    <button type="button" class="icon-btn sidebar-close" data-drawer-close aria-label="<?= t('common.close_menu') ?>"><?= icon('x') ?></button>
  </div>
  <div class="sidebar-app"><?= t('app.name') ?></div>
  <nav class="sidebar-nav">
    <ul>
      <?php foreach ($__items as [$key, $file, $label, $ico, $perm]): ?>
        <?php if (is_file(APP_ROOT . '/public/' . $file) && can($perm, ['any' => true])): ?>
          <li><a href="<?= e(url($file)) ?>"<?= nav_active($activeNav, $key) ?>><?= icon($ico) ?><span><?= t($label) ?></span></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <?php
    $__visibleSettings = array_filter($__settings, static fn ($s) => is_file(APP_ROOT . '/public/' . $s[1]) && can($s[4]));
    ?>
    <?php if ($__visibleSettings): ?>
      <div class="sidebar-section"><?= icon('settings', 'icon icon-sm') ?><span><?= t('nav.settings') ?></span></div>
      <ul>
        <?php foreach ($__visibleSettings as [$key, $file, $label, $ico, $perm]): ?>
          <li><a href="<?= e(url($file)) ?>"<?= nav_active($activeNav, $key) ?>><?= icon($ico) ?><span><?= t($label) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <span><?= e($__u?->name) ?></span>
    <span class="muted"><?= role_label($__u?->roleCode ?? '') ?><?= $__u?->isReadOnly() ? ' · ' . t('role.read_only_note') : '' ?></span>
  </div>
</aside>
