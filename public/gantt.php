<?php
declare(strict_types=1);

/**
 * Gantt lintas project (PRD §6.8): tingkat Project atau Part; filter customer, PIC, status, jenis part;
 * batang baseline opsional; paginasi (PRD §13.1: daftar besar dimuat bertahap).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/gantt.php';

use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Project\ProjectQuery;
use App\Timeline\PortfolioQuery;

$user = require_permission('project.view');
$level = Request::query('level') === 'part' ? 'part' : 'project';
$filters = [
    'q' => Request::query('q'),
    'customer_id' => Request::int('customer_id'),
    'pic_id' => Request::int('pic_id'),
    'status' => Request::query('status'),
    'part_type' => Request::query('part_type'),
];
$page = max(1, (int) Request::int('page', 1));
$data = (new PortfolioQuery())->gantt($user, $filters, $level, $page, 30);
$pages = max(1, (int) ceil($data['total'] / 30));
$customers = Db::fetchAll('SELECT id, name FROM customers ORDER BY name');
$users = Db::fetchAll("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code <> 'management' ORDER BY u.name");
$statuses = $level === 'part'
    ? ['not_started', 'on_progress', 'waiting_approval', 'waiting_external', 'hold', 'completed', 'cancelled']
    : ProjectQuery::PROJECT_STATUSES;
$qs = array_filter(['level' => $level] + $filters, static fn ($v) => $v !== null && $v !== '');

$pageTitle = I18n::t('gantt.portfolio_title');
$activeNav = 'gantt';
$pageScripts = ['js/gantt.js'];
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('gantt.portfolio_title') ?></h1>
    <p><?= t('gantt.portfolio_subtitle') ?></p>
  </div>
  <div class="page-actions">
    <div class="segmented" role="group" aria-label="<?= t('gantt.level') ?>">
      <a class="segmented-item<?= $level === 'project' ? ' is-active' : '' ?>" href="<?= e(url('gantt.php', ['level' => 'project'] + array_diff_key($qs, ['level' => 1, 'status' => 1, 'page' => 1]))) ?>"<?= $level === 'project' ? ' aria-current="page"' : '' ?>><?= t('gantt.level_project') ?></a>
      <a class="segmented-item<?= $level === 'part' ? ' is-active' : '' ?>" href="<?= e(url('gantt.php', ['level' => 'part'] + array_diff_key($qs, ['level' => 1, 'status' => 1, 'page' => 1]))) ?>"<?= $level === 'part' ? ' aria-current="page"' : '' ?>><?= t('gantt.level_part') ?></a>
    </div>
  </div>
</div>

<form method="get" class="filter-bar" role="search">
  <input type="hidden" name="level" value="<?= e($level) ?>">
  <div class="field"><label for="g-q"><?= t('common.search') ?></label><input class="input" id="g-q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= t('project.search_ph') ?>"></div>
  <div class="field"><label for="g-cust"><?= t('npr.customer') ?></label>
    <select class="input" id="g-cust" name="customer_id"><option value=""><?= t('common.all') ?></option>
      <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $filters['customer_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="g-pic">PIC</label>
    <select class="input" id="g-pic" name="pic_id"><option value=""><?= t('common.all') ?></option>
      <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $filters['pic_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="g-status"><?= t('common.status') ?></label>
    <select class="input" id="g-status" name="status"><option value=""><?= t('common.all') ?></option>
      <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= t('status.' . $s) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="g-type"><?= t('timeline.type') ?></label>
    <select class="input" id="g-type" name="part_type"><option value=""><?= t('common.all') ?></option>
      <?php foreach (['new_mold', 'subcont'] as $pt): ?><option value="<?= $pt ?>"<?= $filters['part_type'] === $pt ? ' selected' : '' ?>><?= t('part_type.' . $pt) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button></div>
</form>

<?php if (!$data['rows']): ?>
  <div class="card"><div class="card-body"><p class="muted"><?= t('gantt.empty') ?></p></div></div>
<?php else: ?>
  <?= render_gantt($data, [
      'id' => 'gantt-portfolio',
      'label' => I18n::t('gantt.portfolio_title'),
      'label_head' => $level === 'part' ? I18n::t('gantt.level_part') : I18n::t('gantt.level_project'),
      'link' => static fn (array $r): string => $level === 'part' ? url('timeline.php', ['part' => $r['id']]) : url('timeline.php', ['project' => $r['id']]),
      'sub' => static fn (array $r): string => $r['customer'] . ' · ' . PortfolioQuery::statusLabel((string) $r['status'])
          . ($level === 'part' ? ' · ' . I18n::t('part_type.' . $r['part_type']) : ($r['pic'] ? ' · ' . $r['pic'] : '')),
  ]) ?>
  <div class="pagination">
    <span><?= t('gantt.count', ['count' => $data['total']]) ?></span>
    <?php if ($pages > 1): ?>
      <span class="pagination-links">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('gantt.php', $qs + ['page' => $page - 1])) ?>"><?= icon('chevron-left', 'icon icon-sm') ?> <?= t('common.previous') ?></a><?php endif; ?>
        <span class="muted"><?= t('common.page_of', ['page' => $page, 'pages' => $pages]) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('gantt.php', $qs + ['page' => $page + 1])) ?>"><?= t('common.next') ?> <?= icon('chevron-right', 'icon icon-sm') ?></a><?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
