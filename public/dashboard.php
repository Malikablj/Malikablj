<?php
declare(strict_types=1);

/**
 * Dashboard (PRD §10.1): 8 kartu KPI (klik → daftar terfilter), Panel Overdue (§7.2), Attention Required,
 * Menunggu tindakan Anda, dan 6 grafik. Semua angka dihitung server dari MySQL (DashboardService).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Project\ProjectService;
use App\Report\DashboardService;

$user = require_permission('report.view');
$filters = DashboardService::cleanFilters($_GET);
$data = (new DashboardService())->build($user, $filters);
$customers = Db::fetchAll('SELECT id, name FROM customers ORDER BY name');
$npdUsers = Db::fetchAll("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code IN ('npd_staff', 'admin') AND u.is_active = 1 ORDER BY u.name");
$base = array_filter($filters, static fn ($v) => $v !== null);
$cardLinks = [
    'total' => [], 'new_mold' => ['part_type' => 'new_mold'], 'subcont' => ['part_type' => 'subcont'], 'on_progress' => ['status' => 'on_progress'],
    'waiting' => ['status' => 'waiting'], 'overdue' => ['overdue' => '1'], 'due_soon' => ['due_soon' => '1'], 'completed' => ['status' => 'completed'],
];
$cardIcons = ['total' => 'folder', 'new_mold' => 'layers', 'subcont' => 'link', 'on_progress' => 'play', 'waiting' => 'clock', 'overdue' => 'alert', 'due_soon' => 'calendar', 'completed' => 'check-circle'];
$tone = ['overdue' => 'danger', 'due_soon' => 'warning', 'waiting' => 'warning', 'completed' => 'success'];
$procUrl = static fn (array $r): string => url('process.php', ['id' => $r['id'] ?? $r['process_id']]);

/** Grafik batang horizontal (HTML/CSS, dapat dibaca pembaca layar). @param list<array<string,mixed>> $rows */
$chart = static function (string $id, string $title, array $rows) use ($base): string {
    $max = max(1, ...array_map(static fn ($r) => (int) $r['value'], $rows ?: [['value' => 1]]));
    ob_start(); ?>
  <section class="card chart-card" aria-labelledby="<?= e($id) ?>">
    <div class="card-header"><h3 id="<?= e($id) ?>"><?= e($title) ?></h3></div>
    <div class="card-body">
      <?php if (!$rows): ?><p class="muted small"><?= t('common.empty') ?></p><?php endif; ?>
      <ul class="bars">
        <?php foreach ($rows as $r): ?>
          <?php $pct = round(100 * (int) $r['value'] / $max, 1); ?>
          <li>
            <span class="bar-label"><?php if (isset($r['query'])): ?><a href="<?= e(url('projects.php', $r['query'] + $base)) ?>"><?= e($r['label']) ?></a><?php else: ?><?= e($r['label']) ?><?php endif; ?></span>
            <span class="bar-track" aria-hidden="true"><span class="bar-fill" style="--v: <?= $pct ?>%"></span></span>
            <span class="bar-value"><?= (int) $r['value'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
    <?php return (string) ob_get_clean();
};

$pageTitle = I18n::t('dashboard.title');
$activeNav = 'dashboard';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('dashboard.greeting', ['name' => $user->name]) ?></h1>
    <p><?= t('dashboard.subtitle', ['date' => I18n::date($data['today'])]) ?></p>
  </div>
</div>

<form method="get" class="filter-bar" role="search" aria-label="<?= t('common.filter') ?>">
  <div class="field">
    <label for="d-type"><?= t('report.part_type') ?></label>
    <select class="input" id="d-type" name="part_type" data-autosubmit>
      <option value=""><?= t('common.all') ?></option>
      <?php foreach (['new_mold', 'subcont'] as $pt): ?><option value="<?= $pt ?>"<?= $filters['part_type'] === $pt ? ' selected' : '' ?>><?= t('part_type.' . $pt) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="d-cust"><?= t('npr.customer') ?></label>
    <select class="input" id="d-cust" name="customer_id" data-autosubmit>
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filters['customer_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="d-npd"><?= t('project.npd_pic') ?></label>
    <select class="input" id="d-npd" name="npd_pic_id" data-autosubmit>
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($npdUsers as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $filters['npd_pic_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="d-prio"><?= t('project.priority') ?></label>
    <select class="input" id="d-prio" name="priority" data-autosubmit>
      <option value=""><?= t('common.all') ?></option>
      <?php foreach (ProjectService::PRIORITIES as $pr): ?><option value="<?= $pr ?>"<?= $filters['priority'] === $pr ? ' selected' : '' ?>><?= t('project.priority.' . $pr) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field"><button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button></div>
  <?php if ($base): ?><div class="field"><a class="btn btn-ghost" href="<?= e(url('dashboard.php')) ?>"><?= t('common.reset') ?></a></div><?php endif; ?>
</form>

<section aria-label="<?= t('dash.kpi') ?>" class="kpi-grid">
  <?php foreach (DashboardService::CARDS as $k): ?>
    <?php $c = $data['cards'][$k]; ?>
    <a class="card kpi-card<?= isset($tone[$k]) && $c['projects'] > 0 ? ' kpi-' . $tone[$k] : '' ?>" href="<?= e(url('projects.php', $cardLinks[$k] + $base)) ?>" data-kpi="<?= e($k) ?>">
      <span class="kpi-head"><span class="kpi-icon"><?= icon($cardIcons[$k]) ?></span><span class="kpi-label"><?= t('dash.card.' . $k) ?></span></span>
      <span class="kpi-value"><?= (int) $c['projects'] ?></span>
      <span class="kpi-sub"><?= t('dash.projects_parts', ['parts' => (int) $c['parts']]) ?></span>
    </a>
  <?php endforeach; ?>
</section>

<div class="dash-main">
  <section class="card" id="overdue" aria-labelledby="sec-overdue">
    <div class="card-header">
      <h2 id="sec-overdue"><?= icon('alert') ?> <?= t('dash.overdue_panel') ?> <span class="badge <?= $data['overdue'] ? 'badge-danger' : 'badge-neutral' ?>"><?= count($data['overdue']) ?></span></h2>
      <?php if ($data['overdue']): ?><a class="btn btn-sm" href="<?= e(url('projects.php', ['overdue' => '1'] + $base)) ?>"><?= t('dash.see_all') ?></a><?php endif; ?>
    </div>
    <?php if (!$data['overdue']): ?>
      <div class="card-body"><p class="muted"><?= icon('check-circle') ?> <?= t('dash.no_overdue') ?></p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col"><?= t('project.project') ?></th><th scope="col"><?= t('project.part') ?></th><th scope="col"><?= t('process.process') ?></th><th scope="col">PIC</th>
            <th scope="col" class="nowrap"><?= t('timeline.planned_finish') ?></th><th scope="col" class="right nowrap"><?= t('report.days_late') ?></th><th scope="col"><?= t('report.waiting_for') ?></th></tr></thead>
          <tbody>
            <?php foreach (array_slice($data['overdue'], 0, 15) as $o): ?>
              <tr class="row-danger">
                <td class="mono small nowrap"><a href="<?= e(url('project.php', ['id' => $o['project_id']])) ?>"><?= e($o['project_code']) ?></a></td>
                <td class="small"><?= e($o['part_name'] ?? '–') ?></td>
                <td class="small"><a href="<?= e($procUrl($o)) ?>"><?= e($o['process_label']) ?></a></td>
                <td class="small"><?= e($o['pic_name'] ?? I18n::t('project.no_pic')) ?></td>
                <td class="small nowrap"><?= fmt_date($o['planned_finish']) ?></td>
                <td class="right"><span class="badge badge-danger"><?= t('project.overdue_n', ['days' => $o['overdue_days']]) ?></span></td>
                <td class="small"><?= t('report.waiting.' . $o['waiting']) ?><?= $o['waiting_note'] ? '<div class="muted">' . e($o['waiting_note']) . '</div>' : '' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($data['overdue']) > 15): ?><div class="card-footer small muted"><?= t('project.more_n', ['count' => count($data['overdue']) - 15]) ?></div><?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="sec-mine">
    <div class="card-header"><h2 id="sec-mine"><?= icon('user') ?> <?= t('dash.my_tasks') ?> <span class="badge badge-neutral"><?= count($data['mine']) ?></span></h2></div>
    <div class="card-body">
      <?php if (!$data['mine']): ?><p class="muted"><?= t('dash.no_tasks') ?></p><?php endif; ?>
      <ul class="task-list">
        <?php foreach (array_slice($data['mine'], 0, 12) as $m): ?>
          <li>
            <a href="<?= e($procUrl($m)) ?>"><strong class="mono"><?= e($m['project_code']) ?></strong> · <?= e($m['label']) ?></a>
            <div class="small muted">
              <?= status_badge((string) $m['status']) ?>
              <?php if ($m['held']): ?><span class="badge badge-neutral"><?= t('status.hold') ?></span><?php endif; ?>
              <?php if ($m['overdue_days'] > 0): ?><span class="badge badge-danger"><?= t('project.overdue_n', ['days' => $m['overdue_days']]) ?></span><?php elseif ($m['due_soon']): ?><span class="badge badge-warning"><?= t('status.due_soon') ?></span><?php endif; ?>
              · <?= t('timeline.planned_finish') ?> <?= fmt_date($m['planned_finish']) ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($data['mine']) > 12): ?><p class="small muted"><?= t('project.more_n', ['count' => count($data['mine']) - 12]) ?></p><?php endif; ?>
    </div>
  </section>
</div>

<h2 class="section-title"><?= t('dash.attention') ?></h2>
<div class="grid grid-3 attention-grid">
  <?php foreach (DashboardService::ATTENTION as $k): ?>
    <?php $items = $data['attention'][$k]; ?>
    <section class="card attention-card" aria-labelledby="att-<?= $k ?>" data-attention="<?= e($k) ?>">
      <div class="card-header"><h3 id="att-<?= $k ?>"><?= t('dash.att.' . $k) ?></h3><span class="badge <?= $items ? ($k === 'overdue' ? 'badge-danger' : 'badge-warning') : 'badge-neutral' ?>"><?= count($items) ?></span></div>
      <div class="card-body">
        <p class="small muted"><?= t('dash.att_hint.' . $k, ['days' => $k === 'no_update' ? $data['no_update_days'] : $data['due_soon_days']]) ?></p>
        <?php if (!$items): ?><p class="small muted"><?= t('dash.none') ?></p><?php endif; ?>
        <ul class="plain-list small">
          <?php foreach (array_slice($items, 0, 5) as $it): ?>
            <li>
              <?php if ($k === 'no_update'): ?>
                <a href="<?= e(url('project.php', ['id' => $it['id']])) ?>" class="mono"><?= e($it['code']) ?></a> <?= e($it['name']) ?>
                <span class="muted">· <?= t('dash.last_activity', ['date' => I18n::date($it['last_activity'])]) ?> · <?= e($it['npd_pic_name'] ?? '–') ?></span>
              <?php elseif ($k === 'overdue'): ?>
                <a href="<?= e($procUrl($it)) ?>"><span class="mono"><?= e($it['project_code']) ?></span> · <?= e(($it['part_name'] ? $it['part_name'] . ' › ' : '') . $it['process_label']) ?></a>
                <span class="muted">· <?= e($it['pic_name'] ?? I18n::t('project.no_pic')) ?> · <?= t('project.overdue_n', ['days' => $it['overdue_days']]) ?></span>
              <?php else: ?>
                <a href="<?= e($procUrl($it)) ?>"><span class="mono"><?= e($it['project_code']) ?></span> · <?= e($it['label']) ?></a>
                <span class="muted">· <?= e($it['pic_name'] ?? I18n::t('project.no_pic')) ?>
                  <?php if ($k === 'missing_document'): ?>· <?= e(implode(', ', $it['missing'])) ?><?php else: ?>· <?= fmt_date($it['planned_finish']) ?><?php endif; ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($items) > 5): ?><p class="small muted"><?= t('project.more_n', ['count' => count($items) - 5]) ?></p><?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<h2 class="section-title"><?= t('dash.charts') ?></h2>
<div class="grid grid-3 chart-grid">
  <?php foreach (['by_process', 'by_status', 'by_customer', 'by_pic', 'by_type', 'by_priority'] as $ck): ?>
    <?= $chart('chart-' . $ck, I18n::t('dash.chart.' . $ck), $data['charts'][$ck]) ?>
  <?php endforeach; ?>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
