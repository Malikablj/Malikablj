<?php
declare(strict_types=1);

/** Pusat notifikasi (PRD §7.3): daftar, filter, tandai dibaca, buka tautan ke proses/project. */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Notification\NotificationCenter;

$user = require_login();
$center = new NotificationCenter();

// buka notifikasi: tandai dibaca lalu menuju tautannya (hanya milik sendiri, tautan internal)
$open = Request::int('open');
if ($open !== null) {
    $n = $center->markRead($user->id, $open);
    if (!$n) {
        Response::error(404, I18n::t('error.not_found'));
    }
    $link = NotificationCenter::safeLink($n['link']);
    Response::redirect($link !== null ? url($link) : url('notifications.php'));
}

if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    if ($action === 'read_all') {
        $count = $center->markAllRead($user->id);
        Session::flash('success', I18n::t('notifc.read_all_msg', ['count' => $count]));
    } elseif ($action === 'read') {
        $center->markRead($user->id, (int) Request::int('id'));
    } else {
        Response::error(400, I18n::t('validation.invalid'));
    }
    if (Request::wantsJson()) {
        Response::json(['ok' => true, 'unread' => \App\Notification\Notifier::unreadCount($user->id)]);
    }
    Response::redirect(url('notifications.php', array_filter(['filter' => Request::query('filter'), 'type' => Request::query('type')])));
}

$unreadOnly = Request::query('filter') !== 'all';
$type = Request::query('type');
$page = max(1, (int) Request::int('page', 1));
$result = $center->list($user->id, $unreadOnly, $type, $page, 30);
$pages = max(1, (int) ceil($result['total'] / 30));
$types = $center->types($user->id);
$qs = array_filter(['filter' => $unreadOnly ? null : 'all', 'type' => $type]);

$pageTitle = I18n::t('notifc.title');
$activeNav = 'notifications';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('notifc.title') ?></h1>
    <p><?= t('notifc.subtitle') ?></p>
  </div>
  <div class="page-actions">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read_all">
      <button type="submit" class="btn"><?= icon('check') ?> <?= t('notifc.read_all') ?></button></form>
  </div>
</div>
<nav class="tabs" aria-label="<?= t('notifc.title') ?>">
  <a href="<?= e(url('notifications.php', array_filter(['type' => $type]))) ?>"<?= $unreadOnly ? ' class="active" aria-current="page"' : '' ?>><?= t('notifc.unread') ?></a>
  <a href="<?= e(url('notifications.php', array_filter(['filter' => 'all', 'type' => $type]))) ?>"<?= !$unreadOnly ? ' class="active" aria-current="page"' : '' ?>><?= t('notifc.all') ?></a>
</nav>
<?php if ($types): ?>
  <form method="get" class="filter-bar">
    <?php if (!$unreadOnly): ?><input type="hidden" name="filter" value="all"><?php endif; ?>
    <div class="field"><label for="n-type"><?= t('notifc.type') ?></label>
      <select class="input" id="n-type" name="type" data-autosubmit><option value=""><?= t('common.all') ?></option>
        <?php foreach ($types as $tp): ?><option value="<?= e($tp) ?>"<?= $type === $tp ? ' selected' : '' ?>><?= I18n::has('notifc.t.' . $tp) ? t('notifc.t.' . $tp) : e($tp) ?></option><?php endforeach; ?>
      </select></div>
    <noscript><button type="submit" class="btn"><?= t('common.filter') ?></button></noscript>
  </form>
<?php endif; ?>

<div class="card">
  <?php if (!$result['rows']): ?>
    <div class="card-body"><p class="muted"><?= $unreadOnly ? t('notifc.empty_unread') : t('notifc.empty') ?></p></div>
  <?php endif; ?>
  <ul class="notif-list">
    <?php foreach ($result['rows'] as $n): ?>
      <li class="notif-item<?= (int) $n['is_read'] === 0 ? ' is-unread' : '' ?>">
        <span class="notif-dot" aria-hidden="true"></span>
        <div class="notif-main">
          <a href="<?= e(url('notifications.php', ['open' => $n['id']])) ?>" class="notif-title"><?= e($n['title']) ?></a>
          <?php if ($n['body']): ?><div class="small pre muted"><?= e($n['body']) ?></div><?php endif; ?>
          <div class="timeline-meta"><?= fmt_datetime($n['created_at']) ?> · <?= I18n::has('notifc.t.' . $n['type']) ? t('notifc.t.' . $n['type']) : e($n['type']) ?><?= (int) $n['is_read'] === 0 ? ' · <strong>' . t('notifc.new') . '</strong>' : '' ?></div>
        </div>
        <?php if ((int) $n['is_read'] === 0): ?>
          <form method="post" action="<?= e(url('notifications.php', $qs)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button type="submit" class="icon-btn icon-btn-sm" aria-label="<?= t('notifc.mark_read') ?>" title="<?= t('notifc.mark_read') ?>"><?= icon('check', 'icon icon-sm') ?></button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="pagination">
    <span><?= t('notifc.count', ['count' => $result['total']]) ?></span>
    <?php if ($pages > 1): ?>
      <span class="pagination-links">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('notifications.php', $qs + ['page' => $page - 1])) ?>"><?= t('common.previous') ?></a><?php endif; ?>
        <span class="muted"><?= t('common.page_of', ['page' => $page, 'pages' => $pages]) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('notifications.php', $qs + ['page' => $page + 1])) ?>"><?= t('common.next') ?></a><?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
