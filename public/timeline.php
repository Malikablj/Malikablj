<?php
declare(strict_types=1);

/**
 * Timeline dua level (PRD §6.6):
 *   timeline.php?project=<id>  Level 1 — proses level project + ringkasan per part
 *   timeline.php?part=<id>     Level 2 — seluruh proses part (tabel + Gantt, dependency, baseline, jalur kritis)
 * NPD/Admin dapat mengubah planning langsung (form → process.php, kembali ke halaman ini).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/gantt.php';

use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Project\ProjectQuery;
use App\Timeline\TimelineService;

$user = require_permission('project.view');
$svc = new TimelineService();
$partId = Request::int('part');
if ($partId !== null) {
    $data = $svc->part($partId);
    $id = (int) $data['project']['id'];
} else {
    $id = (int) Request::int('project', 0);
    $data = $svc->project($id);
}
$project = $data['project'];
$level = (int) $data['level'];
$filter = (string) Request::query('status', '');
$filters = ['active' => ProjectQuery::ACTIVE, 'not_started' => ['not_started'], 'completed' => ['completed'], 'skipped' => ['skipped']];
if ($level === 2 && $filter !== '') {
    $data['rows'] = array_values(array_filter($data['rows'], static fn ($r) => $filter === 'overdue' ? $r['overdue_days'] > 0 : in_array($r['status'], $filters[$filter] ?? [$r['status']], true)));
}
$canPlan = Gate::can($user, 'schedule.plan') && !in_array($project['status'], ['completed', 'cancelled'], true);
$picChoices = [];
if ($canPlan && $level === 2) {
    foreach (Db::fetchAll("SELECT u.id, u.name, u.role_id, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.name") as $u) {
        $picChoices[] = $u;
    }
}
$self = $level === 2 ? url('timeline.php', ['part' => $partId]) : url('timeline.php', ['project' => $id]);
$exportBase = ['project' => $id] + ($level === 2 ? ['part' => $partId] : []);

$tab = 'timeline';
$crumbPart = $level === 2 ? (string) $data['part']['name'] : null;
$pageTitle = $project['code'] . ' — ' . I18n::t('project.tab.timeline') . ($crumbPart ? ' · ' . $crumbPart : '');
$activeNav = 'projects';
$pageScripts = ['js/gantt.js'];
require APP_ROOT . '/includes/layout/header.php';
require APP_ROOT . '/includes/project_header.php';

$link = static function (array $r) use ($level): ?string {
    if ($r['kind'] === 'part') {
        return url('timeline.php', ['part' => $r['id']]);
    }
    return url('process.php', ['id' => $r['id']]);
};
$statusLabel = static fn (array $r): string => I18n::has('status.' . $r['status']) ? I18n::t('status.' . $r['status']) : (string) $r['status'];
?>
<div class="view-switch">
  <div>
    <h2><?= $level === 1 ? t('timeline.level1') : e($data['part']['name']) . ' · ' . t('part_type.' . $data['part']['part_type']) ?></h2>
    <p class="muted small"><?= $level === 1 ? t('timeline.level1_hint') : t('timeline.level2_hint') ?></p>
  </div>
  <div class="page-actions">
    <?php if ($level === 2): ?>
      <form method="get" class="inline-edit">
        <input type="hidden" name="part" value="<?= (int) $partId ?>">
        <label class="visually-hidden" for="tl-status"><?= t('common.status') ?></label>
        <select class="input input-sm" id="tl-status" name="status" data-autosubmit>
          <option value=""><?= t('timeline.all_status') ?></option>
          <?php foreach (['active' => 'timeline.f_active', 'not_started' => 'status.not_started', 'completed' => 'status.completed', 'overdue' => 'status.overdue', 'skipped' => 'status.skipped'] as $k => $lbl): ?>
            <option value="<?= $k ?>"<?= $filter === $k ? ' selected' : '' ?>><?= t($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-sm"><?= t('common.filter') ?></button></noscript>
      </form>
    <?php endif; ?>
    <?php if (can('export.timeline')): ?>
      <details class="menu">
        <summary class="btn"><?= icon('download') ?> <?= t('common.export') ?> <?= icon('chevron-down', 'icon icon-sm') ?></summary>
        <div class="menu-panel" role="menu">
          <?php
          $scopes = $level === 1 ? ['project' => 'timeline.scope_project', 'all' => 'timeline.scope_all'] : ['part' => 'timeline.scope_part', 'all' => 'timeline.scope_all'];
          foreach ($scopes as $scope => $lbl):
              foreach (['timeline_pdf' => 'PDF', 'timeline_xlsx' => 'Excel'] as $type => $fmt): ?>
                <a role="menuitem" href="<?= e(url('export.php', ['type' => $type, 'scope' => $scope] + $exportBase)) ?>"><?= icon($type === 'timeline_pdf' ? 'file-text' : 'file') ?> <?= t($lbl) ?> — <?= $fmt ?></a>
          <?php endforeach; endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>
</div>

<div data-view="gantt">
  <div class="segmented section" role="group" aria-label="<?= t('timeline.view') ?>">
    <button type="button" class="segmented-item is-active" data-view-set="gantt" aria-pressed="true"><?= icon('gantt', 'icon icon-sm') ?> Gantt</button>
    <button type="button" class="segmented-item" data-view-set="table" aria-pressed="false"><?= icon('layers', 'icon icon-sm') ?> <?= t('timeline.table') ?></button>
  </div>

  <div data-view-gantt>
    <?= render_gantt($data, [
        'id' => 'gantt-' . $level,
        'link' => $link,
        'deps' => $level === 2,
        'critical' => $level === 2,
        'label' => I18n::t('timeline.gantt_label', ['name' => $project['code'] . ($crumbPart ? ' › ' . $crumbPart : '')]),
        'label_head' => $level === 1 ? I18n::t('timeline.part_or_process') : I18n::t('process.process'),
        'sub' => static fn (array $r): string => $r['kind'] === 'part'
            ? I18n::t('part_type.' . $r['part_type']) . ' · ' . $r['progress'] . '% · ' . ($r['pic'] ?? '–')
            : ($r['pic'] ?? I18n::t('project.no_pic')),
    ]) ?>
  </div>

  <div data-view-table class="card">
    <div class="table-wrap">
      <?php if ($level === 1): ?>
        <table class="table">
          <thead><tr>
            <th scope="col">No</th><th scope="col"><?= t('timeline.part_or_process') ?></th><th scope="col"><?= t('timeline.type') ?></th>
            <th scope="col"><?= t('common.status') ?></th><th scope="col" class="right"><?= t('project.progress') ?></th><th scope="col">PIC</th>
            <th scope="col" class="nowrap"><?= t('timeline.start') ?></th><th scope="col" class="nowrap"><?= t('timeline.planned_finish') ?></th>
            <th scope="col" class="nowrap"><?= t('project.forecast_finish') ?></th><th scope="col"><?= t('status.overdue') ?></th><th scope="col"><?= t('timeline.remark') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($data['rows'] as $r): ?>
              <tr class="<?= $r['overdue_days'] > 0 ? 'row-overdue' : '' ?><?= !empty($r['cancelled']) ? ' row-skipped' : '' ?>">
                <td><?= (int) $r['no'] ?></td>
                <td><a href="<?= e((string) $link($r)) ?>"><?= e(trim($r['code'] . ' ' . $r['name'])) ?></a></td>
                <td class="small"><?= $r['kind'] === 'part' ? t('part_type.' . $r['part_type']) : t('timeline.project_level') ?></td>
                <td><?= status_badge((string) $r['status']) ?></td>
                <td class="right small"><?= $r['kind'] === 'part' ? (int) $r['progress'] . '%' : '–' ?></td>
                <td class="small"><?= e($r['pic'] ?? '–') ?></td>
                <td class="small nowrap"><?= fmt_date($r['kind'] === 'part' ? $r['actual_start'] : ($r['actual_start'] ?? $r['planned_start'])) ?></td>
                <td class="small nowrap"><?= fmt_date($r['planned_finish']) ?></td>
                <td class="small nowrap"><?= fmt_date($r['kind'] === 'part' && $r['actual_finish'] ? $r['actual_finish'] : $r['forecast_finish']) ?></td>
                <td class="small nowrap"><?= $r['overdue_days'] > 0 ? '<span class="badge badge-danger">' . e(I18n::t('project.overdue_n', ['days' => $r['overdue_days']])) . '</span>' . (($r['overdue_count'] ?? 0) > 1 ? ' <span class="muted">(' . (int) $r['overdue_count'] . ' ' . t('timeline.processes') . ')</span>' : '') : '–' ?></td>
                <td class="small"><?= e($r['remark']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <table class="table">
          <thead><tr>
            <th scope="col">No</th><th scope="col"><?= t('process.process') ?></th><th scope="col">PIC</th><th scope="col"><?= t('process.dependencies') ?></th>
            <th scope="col" class="right"><?= t('process.duration_short') ?></th>
            <th scope="col" class="nowrap"><?= t('timeline.planned_start') ?></th><th scope="col" class="nowrap"><?= t('timeline.planned_finish') ?></th>
            <th scope="col" class="nowrap"><?= t('timeline.actual_start') ?></th><th scope="col" class="nowrap"><?= t('timeline.actual_finish') ?></th>
            <th scope="col" class="right"><?= t('process.deviation') ?></th><th scope="col"><?= t('common.status') ?></th><th scope="col"><?= t('timeline.remark') ?></th>
            <?php if ($canPlan): ?><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th><?php endif; ?>
          </tr></thead>
          <tbody>
            <?php if (!$data['rows']): ?><tr><td colspan="13" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
            <?php foreach ($data['rows'] as $r): ?>
              <tr class="<?= $r['overdue_days'] > 0 ? 'row-overdue' : '' ?><?= $r['skipped'] ? ' row-skipped' : '' ?>">
                <td><?= (int) $r['no'] ?></td>
                <td><a href="<?= e(url('process.php', ['id' => $r['id']])) ?>"><span class="mono small"><?= e($r['code']) ?></span> <?= e($r['name']) ?></a>
                  <?php if ($r['manual']): ?><span title="<?= t('gantt.manual_date') ?>"><?= icon('lock', 'icon icon-sm') ?></span><?php endif; ?>
                  <?php if ($r['critical']): ?><span class="badge badge-warning" title="<?= t('gantt.critical') ?>">CP</span><?php endif; ?></td>
                <td class="small"><?= e($r['pic'] ?? I18n::t('project.no_pic')) ?></td>
                <td><span class="dep-list"><?php foreach ($r['deps'] as $d): ?><span class="dep-chip"><?= e($d['code'] . ' ' . $d['type'] . ($d['type'] !== 'PARALLEL' && $d['lag'] !== 0 ? sprintf('%+d', $d['lag']) : '')) ?></span><?php endforeach; ?><?= $r['deps'] ? '' : '–' ?></span></td>
                <td class="right"><?= (int) $r['duration'] ?></td>
                <td class="small nowrap"><?= fmt_date($r['planned_start']) ?></td>
                <td class="small nowrap"><?= fmt_date($r['planned_finish']) ?></td>
                <td class="small nowrap"><?= fmt_date($r['actual_start']) ?></td>
                <td class="small nowrap"><?= fmt_date($r['actual_finish']) ?></td>
                <td class="right small"><?= $r['deviation'] === null ? ($r['overdue_days'] > 0 ? '<span class="badge badge-danger">+' . (int) $r['overdue_days'] . '</span>' : '–') : e(sprintf('%+d', $r['deviation'])) ?></td>
                <td><?= status_badge((string) $r['status']) ?></td>
                <td class="small"><?= e($r['remark']) ?></td>
                <?php if ($canPlan): ?>
                  <td><?php if ($r['editable']): ?><button type="button" class="icon-btn icon-btn-sm" data-open-dialog="dlg-plan-<?= (int) $r['id'] ?>" aria-label="<?= t('timeline.edit_plan') ?> <?= e($r['code']) ?>"><?= icon('edit', 'icon icon-sm') ?></button><?php endif; ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<p class="muted small"><?= t('process.dates_note') ?></p>

<?php if ($canPlan && $level === 2): ?>
  <?php foreach ($data['rows'] as $r): ?>
    <?php if (!$r['editable']) { continue; } ?>
    <dialog class="modal" id="dlg-plan-<?= (int) $r['id'] ?>" aria-labelledby="dlg-plan-title-<?= (int) $r['id'] ?>">
      <form method="post" action="<?= e(url('process.php', ['id' => $r['id']])) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="lock_version" value="<?= (int) $r['lock_version'] ?>">
        <input type="hidden" name="return" value="<?= e($self) ?>">
        <div class="modal-header"><h2 id="dlg-plan-title-<?= (int) $r['id'] ?>"><?= e($r['code'] . ' ' . $r['name']) ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
        <div class="modal-body form-grid">
          <div class="field"><label for="tp-d-<?= (int) $r['id'] ?>"><?= t('process.duration') ?> (<?= t('process.working_days') ?>)</label>
            <input class="input" type="number" min="1" max="365" id="tp-d-<?= (int) $r['id'] ?>" name="plan[duration]" value="<?= (int) $r['duration'] ?>"></div>
          <div class="field"><label for="tp-pic-<?= (int) $r['id'] ?>">PIC</label>
            <select class="input" id="tp-pic-<?= (int) $r['id'] ?>" name="plan[pic_user_id]">
              <option value="">–</option>
              <?php foreach ($picChoices as $u): ?><?php if ((int) $u['role_id'] === $r['pic_role_id'] || $u['code'] === 'admin'): ?><option value="<?= (int) $u['id'] ?>"<?= $r['pic_user_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endif; ?><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="tp-ms-<?= (int) $r['id'] ?>"><?= t('process.manual_start') ?></label>
            <input class="input" type="date" id="tp-ms-<?= (int) $r['id'] ?>" name="plan[manual_start]" value="<?= e((string) $r['manual_start']) ?>"></div>
          <div class="field"><label for="tp-mf-<?= (int) $r['id'] ?>"><?= t('process.manual_finish') ?></label>
            <input class="input" type="date" id="tp-mf-<?= (int) $r['id'] ?>" name="plan[manual_finish]" value="<?= e((string) $r['manual_finish']) ?>"></div>
          <p class="field-hint span-2"><?= t('process.manual_start_hint') ?></p>
        </div>
        <div class="modal-footer"><a class="btn" href="<?= e(url('process.php', ['id' => $r['id']])) ?>"><?= t('timeline.open_process') ?></a><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
      </form>
    </dialog>
  <?php endforeach; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
