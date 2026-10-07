<?php
declare(strict_types=1);

/** Daftar project (PRD §3, §11.4): filter, status turunan + tanda Overdue/Berisiko, proses aktif. */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Project\ProjectQuery;
use App\Project\ProjectService;

$user = require_permission('project.view');
$query = new ProjectQuery();

$filters = [
    'q' => Request::query('q'),
    'status' => Request::query('status'),
    'customer_id' => Request::int('customer_id'),
    'npd_pic_id' => Request::int('npd_pic_id'),
    'mine' => Request::query('mine') === '1',
    'archived' => Request::query('archived') === '1',
    'overdue' => Request::query('overdue') === '1',
    'due_soon' => Request::query('due_soon') === '1',
    'part_type' => in_array(Request::query('part_type'), ['new_mold', 'subcont'], true) ? Request::query('part_type') : null,
    'priority' => in_array(Request::query('priority'), ProjectService::PRIORITIES, true) ? Request::query('priority') : null,
];
$page = max(1, (int) Request::int('page', 1));
$perPage = 25;
$result = $query->list($user, $filters, $page, $perPage);
$rows = $result['rows'];
$pages = max(1, (int) ceil($result['total'] / $perPage));
$customers = Db::fetchAll('SELECT id, name FROM customers ORDER BY name');
$npdUsers = Db::fetchAll("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code IN ('npd_staff', 'admin') ORDER BY u.name");
$qs = array_filter(['q' => $filters['q'], 'status' => $filters['status'], 'customer_id' => $filters['customer_id'], 'npd_pic_id' => $filters['npd_pic_id'],
    'mine' => $filters['mine'] ? '1' : null, 'archived' => $filters['archived'] ? '1' : null, 'overdue' => $filters['overdue'] ? '1' : null,
    'due_soon' => $filters['due_soon'] ? '1' : null, 'part_type' => $filters['part_type'], 'priority' => $filters['priority']], static fn ($v) => $v !== null && $v !== '');

$pageTitle = I18n::t('project.list_title');
$activeNav = 'projects';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('project.list_title') ?></h1>
    <p><?= t('project.list_subtitle') ?></p>
  </div>
  <?php if (can('export.report')): ?>
    <div class="page-actions"><a class="btn" href="<?= e(url('export.php', ['type' => 'projects_xlsx'] + $qs)) ?>"><?= icon('download') ?> <?= t('report.export_excel') ?></a></div>
  <?php endif; ?>
</div>

<form method="get" class="filter-bar" role="search">
  <div class="field">
    <label for="f-q"><?= t('common.search') ?></label>
    <input class="input" id="f-q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= t('project.search_ph') ?>">
  </div>
  <div class="field">
    <label for="f-status"><?= t('common.status') ?></label>
    <select class="input" id="f-status" name="status">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach (ProjectQuery::PROJECT_STATUSES as $s): ?>
        <option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= t('status.' . $s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-customer"><?= t('npr.customer') ?></label>
    <select class="input" id="f-customer" name="customer_id">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= (int) $c['id'] ?>"<?= (int) $filters['customer_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-npd"><?= t('project.npd_pic') ?></label>
    <select class="input" id="f-npd" name="npd_pic_id">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($npdUsers as $u): ?>
        <option value="<?= (int) $u['id'] ?>"<?= (int) $filters['npd_pic_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-type"><?= t('report.part_type') ?></label>
    <select class="input" id="f-type" name="part_type">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach (['new_mold', 'subcont'] as $pt): ?><option value="<?= $pt ?>"<?= $filters['part_type'] === $pt ? ' selected' : '' ?>><?= t('part_type.' . $pt) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-prio"><?= t('project.priority') ?></label>
    <select class="input" id="f-prio" name="priority">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach (ProjectService::PRIORITIES as $pr): ?><option value="<?= $pr ?>"<?= $filters['priority'] === $pr ? ' selected' : '' ?>><?= t('project.priority.' . $pr) ?></option><?php endforeach; ?>
    </select>
  </div>
  <label class="check"><input type="checkbox" name="mine" value="1"<?= $filters['mine'] ? ' checked' : '' ?>> <span><?= t('project.mine') ?></span></label>
  <label class="check"><input type="checkbox" name="overdue" value="1"<?= $filters['overdue'] ? ' checked' : '' ?>> <span><?= t('status.overdue') ?></span></label>
  <label class="check"><input type="checkbox" name="due_soon" value="1"<?= $filters['due_soon'] ? ' checked' : '' ?>> <span><?= t('status.due_soon') ?></span></label>
  <label class="check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> <span><?= t('project.archived') ?></span></label>
  <div class="field"><button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button></div>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table table-cards">
      <thead>
        <tr>
          <th scope="col"><?= t('project.project') ?></th>
          <th scope="col"><?= t('npr.customer') ?></th>
          <th scope="col"><?= t('common.status') ?></th>
          <th scope="col"><?= t('project.active_processes') ?></th>
          <th scope="col" class="nowrap"><?= t('project.parts') ?></th>
          <th scope="col" class="nowrap"><?= t('project.target_finish') ?></th>
          <th scope="col" class="nowrap"><?= t('project.forecast_finish') ?></th>
          <th scope="col"><?= t('project.npd_pic') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="table-empty"><?= t('project.empty') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a href="<?= e(url('project.php', ['id' => $r['id']])) ?>" class="mono"><?= e($r['code']) ?></a>
              <div class="small"><?= e($r['name']) ?></div>
              <div class="muted small mono"><?= e($r['npr_number']) ?></div>
            </td>
            <td><?= e($r['customer_name']) ?></td>
            <td>
              <?= status_badge((string) $r['status']) ?>
              <?php if ($r['overdue_count'] > 0): ?>
                <?php $worst = array_reduce($r['active_processes'], static fn ($c, $a) => $c === null || $a['overdue_days'] > $c['overdue_days'] ? $a : $c); ?>
                <span class="badge badge-danger"><?= icon('alert', 'icon icon-sm') ?> <?= t('project.overdue_n', ['days' => $r['max_overdue']]) ?></span>
                <div class="small muted"><?= e(($worst['part_name'] ? $worst['part_name'] . ' › ' : '') . ProjectQuery::processName($worst)) ?> · <?= e($worst['pic_name'] ?? I18n::t('project.no_pic')) ?><?= $r['overdue_count'] > 1 ? ' · +' . ($r['overdue_count'] - 1) : '' ?></div>
              <?php endif; ?>
              <?php if ($r['at_risk']): ?>
                <span class="badge badge-warning"><?= icon('flag', 'icon icon-sm') ?> <?= t('project.at_risk') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php $act = $r['active_processes']; ?>
              <?php if (!$act): ?><span class="muted">–</span><?php endif; ?>
              <ul class="plain-list small">
                <?php foreach (array_slice($act, 0, 2) as $a): ?>
                  <li>
                    <a href="<?= e(url('process.php', ['id' => $a['id']])) ?>"><?= e(($a['part_name'] ? $a['part_name'] . ' › ' : '') . ProjectQuery::processName($a)) ?></a>
                    <?php if ($a['overdue_days'] > 0): ?><span class="badge badge-danger"><?= t('project.overdue_n', ['days' => $a['overdue_days']]) ?></span><?php endif; ?>
                    <span class="muted"> · <?= e($a['pic_name'] ?? I18n::t('project.no_pic')) ?></span>
                  </li>
                <?php endforeach; ?>
                <?php if (count($act) > 2): ?><li class="muted"><?= t('project.more_n', ['count' => count($act) - 2]) ?></li><?php endif; ?>
              </ul>
            </td>
            <td class="nowrap"><?= (int) $r['parts_completed'] ?>/<?= (int) $r['parts_active'] ?></td>
            <td class="nowrap"><?= fmt_date($r['target_finish']) ?></td>
            <td class="nowrap"><?= fmt_date($r['forecast_finish']) ?></td>
            <td><?= e($r['npd_pic_name'] ?? '–') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination">
    <span><?= t('project.count', ['count' => $result['total']]) ?></span>
    <?php if ($pages > 1): ?>
      <span class="pagination-links">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('projects.php', $qs + ['page' => $page - 1])) ?>"><?= icon('chevron-left') ?> <?= t('common.previous') ?></a><?php endif; ?>
        <span class="muted"><?= t('common.page_of', ['page' => $page, 'pages' => $pages]) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('projects.php', $qs + ['page' => $page + 1])) ?>"><?= t('common.next') ?> <?= icon('chevron-right') ?></a><?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
