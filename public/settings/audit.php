<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;

/*
 * Audit log sistem penuh — hanya Admin (PRD §9.4). Halaman ini HANYA MEMBACA:
 * tidak ada aksi ubah/hapus untuk audit log di seluruh aplikasi.
 */
require_permission('audit.view_full');

$perPage = 50;
$page = max(1, (int) Request::int('page', 1));
$action = Request::query('action');
$userId = Request::int('user_id');
$entity = Request::query('entity');
$from = Request::query('from');
$to = Request::query('to');

$where = ['1 = 1'];
$params = [];
if ($action !== null && $action !== '') {
    $where[] = 'a.action LIKE ?';
    $params[] = str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%';
}
if ($userId) {
    $where[] = 'a.user_id = ?';
    $params[] = $userId;
}
if ($entity !== null && $entity !== '') {
    $where[] = 'a.entity_type = ?';
    $params[] = $entity;
}
if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'a.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);
$total = (int) Db::value("SELECT COUNT(*) FROM audit_logs a WHERE {$whereSql}", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$rows = Db::fetchAll(
    "SELECT a.* FROM audit_logs a WHERE {$whereSql} ORDER BY a.id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);
$users = Db::fetchAll('SELECT id, name FROM users ORDER BY name');
$entities = Db::column('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type');

$pageTitle = I18n::t('audit.title');
$activeNav = 'audit';
require APP_ROOT . '/includes/layout/header.php';

$renderJson = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '<span class="muted">–</span>';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return e($json);
    }
    $parts = [];
    foreach ($data as $k => $v) {
        $parts[] = '<span class="mono small">' . e((string) $k) . '</span>: ' . e(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE));
    }
    return implode('<br>', $parts);
};
$query = array_filter(['action' => $action, 'user_id' => $userId, 'entity' => $entity, 'from' => $from, 'to' => $to], static fn ($v) => $v !== null && $v !== '');
?>
<div class="page-header">
  <div>
    <h1><?= t('audit.title') ?></h1>
    <p><?= t('audit.subtitle') ?></p>
  </div>
</div>

<form method="get" class="filter-bar" role="search">
  <div class="field">
    <label for="f-action"><?= t('audit.filter_action') ?></label>
    <input class="input" id="f-action" name="action" value="<?= e($action) ?>" placeholder="auth., user., npr.">
  </div>
  <div class="field">
    <label for="f-user"><?= t('audit.filter_user') ?></label>
    <select class="input" id="f-user" name="user_id">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($users as $u): ?>
        <option value="<?= e((string) $u['id']) ?>"<?= $userId === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-entity"><?= t('audit.entity') ?></label>
    <select class="input" id="f-entity" name="entity">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($entities as $en): ?>
        <option value="<?= e($en) ?>"<?= $entity === $en ? ' selected' : '' ?>><?= e($en) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-from"><?= t('common.from') ?></label>
    <input class="input" type="date" id="f-from" name="from" value="<?= e($from) ?>">
  </div>
  <div class="field">
    <label for="f-to"><?= t('common.to') ?></label>
    <input class="input" type="date" id="f-to" name="to" value="<?= e($to) ?>">
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button>
    <a class="btn btn-ghost" href="<?= e(url('settings/audit.php')) ?>"><?= t('common.reset') ?></a>
  </div>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table table-cards">
      <thead>
        <tr>
          <th><?= t('common.time') ?></th>
          <th><?= t('common.user') ?></th>
          <th><?= t('audit.ip') ?></th>
          <th><?= t('audit.action') ?></th>
          <th><?= t('audit.entity') ?></th>
          <th><?= t('audit.old') ?></th>
          <th><?= t('audit.new') ?></th>
          <th><?= t('common.reason') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="table-empty"><?= t('common.empty') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td data-label="<?= t('common.time') ?>" class="nowrap"><?= fmt_datetime($r['created_at']) ?></td>
            <td data-label="<?= t('common.user') ?>"><?= e($r['user_name'] ?? tr('common.system')) ?></td>
            <td data-label="<?= t('audit.ip') ?>" class="mono small"><?= e($r['ip_address']) ?></td>
            <td data-label="<?= t('audit.action') ?>"><span class="mono small"><?= e($r['action']) ?></span></td>
            <td data-label="<?= t('audit.entity') ?>"><?= e($r['entity_type']) ?><?= $r['entity_id'] !== null ? ' #' . e($r['entity_id']) : '' ?></td>
            <td data-label="<?= t('audit.old') ?>" class="small"><?= $renderJson($r['old_value']) ?></td>
            <td data-label="<?= t('audit.new') ?>" class="small"><?= $renderJson($r['new_value']) ?></td>
            <td data-label="<?= t('common.reason') ?>" class="small"><?= e($r['reason'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination">
    <span><?= t('common.page_of', ['page' => $page, 'pages' => $pages]) ?> · <?= fmt_number($total) ?></span>
    <span class="pagination-links">
      <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('settings/audit.php', $query + ['page' => $page - 1])) ?>"><?= icon('chevron-left', 'icon icon-sm') ?> <?= t('common.previous') ?></a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('settings/audit.php', $query + ['page' => $page + 1])) ?>"><?= t('common.next') ?> <?= icon('chevron-right', 'icon icon-sm') ?></a><?php endif; ?>
    </span>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
