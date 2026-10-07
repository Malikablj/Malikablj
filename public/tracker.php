<?php
declare(strict_types=1);

/**
 * Process Tracker (PRD §6.8, §7.2): papan per proses, kartu per part-proses aktif.
 * Satu project dapat muncul di beberapa kolom karena part/proses paralel. Overdue merah dengan PIC & hari terlambat.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Project\ProjectQuery;
use App\Timeline\PortfolioQuery;

$user = require_permission('project.view');
$filters = [
    'q' => Request::query('q'),
    'customer_id' => Request::int('customer_id'),
    'pic_id' => Request::query('mine') === '1' ? $user->id : Request::int('pic_id'),
    'part_type' => Request::query('part_type'),
    'overdue' => Request::query('overdue') === '1',
];
$columns = (new PortfolioQuery())->tracker($user, $filters);
$customers = Db::fetchAll('SELECT id, name FROM customers ORDER BY name');
$total = array_sum(array_map(static fn ($c) => count($c['cards']), $columns));
$overdue = array_sum(array_column($columns, 'overdue'));

$pageTitle = I18n::t('tracker.title');
$activeNav = 'tracker';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('tracker.title') ?></h1>
    <p><?= t('tracker.subtitle') ?></p>
  </div>
</div>
<form method="get" class="filter-bar" role="search">
  <div class="field"><label for="t-q"><?= t('common.search') ?></label><input class="input" id="t-q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= t('project.search_ph') ?>"></div>
  <div class="field"><label for="t-cust"><?= t('npr.customer') ?></label>
    <select class="input" id="t-cust" name="customer_id"><option value=""><?= t('common.all') ?></option>
      <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $filters['customer_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="t-type"><?= t('timeline.type') ?></label>
    <select class="input" id="t-type" name="part_type"><option value=""><?= t('common.all') ?></option>
      <?php foreach (['new_mold', 'subcont'] as $pt): ?><option value="<?= $pt ?>"<?= $filters['part_type'] === $pt ? ' selected' : '' ?>><?= t('part_type.' . $pt) ?></option><?php endforeach; ?>
    </select></div>
  <label class="check"><input type="checkbox" name="mine" value="1"<?= Request::query('mine') === '1' ? ' checked' : '' ?>> <span><?= t('tracker.mine') ?></span></label>
  <label class="check"><input type="checkbox" name="overdue" value="1"<?= $filters['overdue'] ? ' checked' : '' ?>> <span><?= t('tracker.only_overdue') ?></span></label>
  <div class="field"><button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button></div>
</form>
<p class="muted small"><?= t('tracker.summary', ['count' => $total, 'overdue' => $overdue]) ?></p>

<?php if (!$columns): ?>
  <div class="card"><div class="card-body"><p class="muted"><?= t('tracker.empty') ?></p></div></div>
<?php else: ?>
  <div class="tracker" role="list">
    <?php foreach ($columns as $col): ?>
      <section class="tracker-col" role="listitem" aria-labelledby="tc-<?= e(md5($col['key'])) ?>">
        <div class="tracker-col-head">
          <span id="tc-<?= e(md5($col['key'])) ?>"><?= e($col['title']) ?></span>
          <span><?php if ($col['overdue'] > 0): ?><span class="badge badge-danger"><?= (int) $col['overdue'] ?></span> <?php endif; ?><span class="badge badge-neutral"><?= count($col['cards']) ?></span></span>
        </div>
        <div class="tracker-cards">
          <?php foreach ($col['cards'] as $c): ?>
            <a class="tracker-card<?= $c['overdue_days'] > 0 ? ' is-overdue' : '' ?>" href="<?= e(url('process.php', ['id' => $c['id']])) ?>">
              <div class="tc-title"><span class="mono"><?= e($c['project_code']) ?></span> · <?= e($c['part_name'] ?? I18n::t('project.project_level')) ?></div>
              <div class="small"><?= e($c['project_name']) ?> — <?= e($c['customer_name']) ?></div>
              <div class="tc-meta">
                <?= status_badge((string) $c['status']) ?>
                <?php if ($c['overdue_days'] > 0): ?><span class="badge badge-danger"><?= icon('alert', 'icon icon-sm') ?> <?= t('project.overdue_n', ['days' => $c['overdue_days']]) ?></span><?php endif; ?>
                <?php if ($c['held']): ?><span class="badge badge-neutral"><?= t('status.hold') ?></span><?php endif; ?>
                <?php if ((int) $c['is_customer_approval'] === 1): ?><span class="badge badge-warning"><?= t('status.waiting_approval') ?></span><?php elseif ((int) $c['is_external'] === 1): ?><span class="badge badge-warning"><?= t('status.waiting_external') ?></span><?php endif; ?>
              </div>
              <div class="tc-meta"><span><?= icon('user', 'icon icon-sm') ?> <?= e($c['pic_name'] ?? I18n::t('project.no_pic')) ?></span><span><?= icon('clock', 'icon icon-sm') ?> <?= fmt_date($c['planned_finish']) ?></span></div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
