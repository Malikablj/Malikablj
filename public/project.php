<?php
declare(strict_types=1);

/**
 * Detail project (PRD §3, §6): ringkasan, part & PIC, proses per part, riwayat (revisi, penggeseran
 * jadwal, baseline, gate). Aksi (target, baseline, PIC, prioritas) diotorisasi di service.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AppException;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Project\ProjectQuery;
use App\Project\ProjectService;
use App\Project\RevisionHistory;
use App\Scheduling\ScheduleService;

$user = require_permission('project.view');
$query = new ProjectQuery();
$id = (int) Request::int('id', 0);
$project = $query->find($id);
$tab = in_array(Request::query('tab'), ['overview', 'processes', 'history'], true) ? (string) Request::query('tab') : 'overview';

$errors = [];
$failed = null;
if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $back = url('project.php', ['id' => $id, 'tab' => Request::post('tab') ?: $tab]);
    try {
        switch ($action) {
            case 'update_project':
                $input = [];
                if (Request::post('priority') !== null) {
                    $input['priority'] = (string) Request::post('priority');
                }
                if (Request::post('npd_pic_id') !== null && Request::post('npd_pic_id') !== '') {
                    $input['npd_pic_id'] = (int) Request::post('npd_pic_id');
                }
                (new ProjectService())->updateProject($user, $id, $input);
                Session::flash('success', I18n::t('common.saved'));
                break;
            case 'part_pics':
                $partId = (int) Request::int('part_id');
                if (!\App\Core\Db::value('SELECT id FROM project_parts WHERE id = ? AND project_id = ?', [$partId, $id])) {
                    Response::error(404, I18n::t('error.not_found'));
                }
                (new ProjectService())->assignPartPics($user, $partId, is_array($_POST['pic'] ?? null) ? $_POST['pic'] : []);
                Session::flash('success', I18n::t('project.pics_saved'));
                break;
            case 'change_target':
                (new ScheduleService())->changeTarget($user, $id, (string) Request::post('target_finish'), (string) Request::post('reason'));
                Session::flash('success', I18n::t('project.target_changed'));
                break;
            case 'baseline':
                $partId = Request::int('part_id');
                if ($partId !== null && !\App\Core\Db::value('SELECT id FROM project_parts WHERE id = ? AND project_id = ?', [$partId, $id])) {
                    Response::error(404, I18n::t('error.not_found'));
                }
                (new ScheduleService())->setBaseline($user, $id, $partId, (string) Request::post('reason'));
                Session::flash('success', I18n::t('project.baseline_saved'));
                break;
            default:
                Response::error(400, I18n::t('validation.invalid'));
        }
        Response::redirect($back);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $failed = $action;
        http_response_code(422);
        Session::flash('error', implode(' ', $errors));
    } catch (AppException $e) {
        if ($e->httpStatus() === 403) {
            throw $e;
        }
        Session::flash('error', $e->getMessage());
        Response::redirect($back);
    }
}

$parts = $query->parts($id);
$processes = $query->processes($id);
$projectLevel = array_values(array_filter($processes, static fn ($p) => $p['part_id'] === null));
$byPart = [];
foreach ($processes as $p) {
    if ($p['part_id'] !== null) {
        $byPart[(int) $p['part_id']][] = $p;
    }
}
$canPlan = Gate::can($user, 'schedule.plan');
$canTarget = Gate::can($user, 'target.change');
$canBaseline = Gate::can($user, 'baseline.create');
$canEdit = Gate::can($user, 'project.edit', ['owner_ids' => [$project['sales_pic_id'], $project['npd_pic_id']]]);
$users = $canPlan ? $query->usersByRole() : [];
$closed = in_array($project['status'], ['completed', 'cancelled'], true) || (int) $project['is_archived'] === 1;

$procName = static fn (array $p): string => ProjectQuery::processName($p);
$pName = static fn (array $p): string => e($p['code']) . ' · ' . e(ProjectQuery::processName($p));

$pageTitle = $project['code'] . ' — ' . $project['name'];
$activeNav = 'projects';
require APP_ROOT . '/includes/layout/header.php';
?>
<?php require APP_ROOT . '/includes/project_header.php'; ?>

<?php if ($tab === 'overview'): ?>
<div class="grid grid-2">
  <section class="card" aria-labelledby="sec-info">
    <div class="card-header"><h2 id="sec-info"><?= t('project.info') ?></h2></div>
    <div class="card-body">
      <dl class="kv">
        <dt><?= t('npr.customer') ?></dt><dd><?= e($project['customer_name']) ?></dd>
        <dt><?= t('project.sales_pic') ?></dt><dd><?= e($project['sales_pic_name'] ?? '–') ?></dd>
        <dt><?= t('project.npd_pic') ?></dt><dd><?= e($project['npd_pic_name'] ?? '–') ?></dd>
        <dt><?= t('project.start_date') ?></dt><dd><?= fmt_date($project['start_date']) ?></dd>
        <dt><?= t('project.target_finish') ?></dt><dd><?= fmt_date($project['target_finish']) ?></dd>
        <dt><?= t('project.forecast_finish') ?></dt>
        <dd><?= fmt_date($project['forecast_finish']) ?>
          <?php if ($project['at_risk']): ?><span class="badge badge-warning"><?= t('project.past_target') ?></span><?php endif; ?></dd>
        <dt><?= t('project.gate') ?></dt><dd><?= (int) $project['gate_enabled'] === 1 ? t('project.gate_on') : t('project.gate_off') ?></dd>
        <?php if ($project['finished_at']): ?><dt><?= t('project.finished_at') ?></dt><dd><?= fmt_datetime($project['finished_at']) ?></dd><?php endif; ?>
        <?php if ($project['cancelled_at']): ?><dt><?= t('status.cancelled') ?></dt><dd><?= fmt_datetime($project['cancelled_at']) ?> — <?= e($project['cancel_reason']) ?></dd><?php endif; ?>
      </dl>
    </div>
    <?php if (!$closed && ($canEdit || $canPlan || $canTarget || $canBaseline)): ?>
      <div class="card-footer">
        <?php if ($canEdit || $canPlan): ?><button type="button" class="btn btn-sm" data-open-dialog="dlg-project"><?= icon('edit') ?> <?= t('project.edit') ?></button><?php endif; ?>
        <?php if ($canTarget): ?><button type="button" class="btn btn-sm" data-open-dialog="dlg-target"><?= icon('flag') ?> <?= t('project.change_target') ?></button><?php endif; ?>
        <?php if ($canBaseline): ?><button type="button" class="btn btn-sm" data-open-dialog="dlg-baseline"><?= icon('layers') ?> <?= t('project.set_baseline') ?></button><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="sec-project-proc">
    <div class="card-header"><h2 id="sec-project-proc"><?= t('project.project_level') ?></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col"><?= t('process.process') ?></th><th scope="col"><?= t('common.status') ?></th><th scope="col"><?= t('process.planned') ?></th><th scope="col">PIC</th></tr></thead>
        <tbody>
          <?php foreach ($projectLevel as $p): ?>
            <tr>
              <td><a href="<?= e(url('process.php', ['id' => $p['id']])) ?>"><?= $pName($p) ?></a></td>
              <td><?= status_badge((string) $p['status']) ?><?php if ($p['overdue_days'] > 0): ?> <span class="badge badge-danger"><?= t('project.overdue_n', ['days' => $p['overdue_days']]) ?></span><?php endif; ?></td>
              <td class="nowrap small"><?= fmt_date($p['planned_start']) ?> – <?= fmt_date($p['planned_finish']) ?></td>
              <td class="small"><?= e($p['pic_name'] ?? I18n::t('project.no_pic')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<h2 class="section-title"><?= t('project.parts') ?></h2>
<?php if (!$parts): ?><p class="muted"><?= t('project.no_parts') ?></p><?php endif; ?>
<div class="grid grid-2">
  <?php foreach ($parts as $pt): ?>
    <?php $pct = (int) $pt['proc_total'] > 0 ? (int) round(100 * (int) $pt['proc_done'] / (int) $pt['proc_total']) : 0; ?>
    <section class="card" aria-labelledby="part-<?= (int) $pt['id'] ?>">
      <div class="card-header">
        <div>
          <h3 id="part-<?= (int) $pt['id'] ?>"><?= e($pt['name']) ?></h3>
          <p class="muted small"><?= t('part_type.' . $pt['part_type']) ?><?= $pt['start_date'] ? ' · ' . t('project.started_on', ['date' => I18n::date($pt['start_date'])]) : '' ?></p>
        </div>
        <?= status_badge((string) $pt['status']) ?>
      </div>
      <div class="card-body stack">
        <?php if ($pt['start_date']): ?>
          <div>
            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="<?= t('project.progress') ?>"><span style="width: <?= $pct ?>%"></span></div>
            <p class="small muted"><?= t('project.progress_n', ['done' => (int) $pt['proc_done'], 'total' => (int) $pt['proc_total']]) ?> · <?= t('project.forecast_finish') ?>: <?= fmt_date($pt['forecast_finish']) ?></p>
          </div>
          <div>
            <p class="small"><strong><?= t('project.active_processes') ?></strong></p>
            <?php if (!$pt['active_processes']): ?><p class="muted small">–</p><?php endif; ?>
            <ul class="plain-list small">
              <?php foreach ($pt['active_processes'] as $a): ?>
                <li><a href="<?= e(url('process.php', ['id' => $a['id']])) ?>"><?= $pName($a) ?></a> · <?= status_badge((string) $a['status']) ?>
                  <?php if ($a['overdue_days'] > 0): ?><span class="badge badge-danger"><?= t('project.overdue_n', ['days' => $a['overdue_days']]) ?></span><?php elseif ($a['due_soon']): ?><span class="badge badge-warning"><?= t('status.due_soon') ?></span><?php endif; ?>
                  <span class="muted">· <?= e($a['pic_name'] ?? I18n::t('project.no_pic')) ?> · <?= fmt_date($a['planned_finish']) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php elseif ($pt['cancelled_at']): ?>
          <p class="small muted"><?= t('project.part_cancelled', ['reason' => (string) $pt['cancel_reason']]) ?></p>
        <?php else: ?>
          <p class="small muted"><?= t('project.part_waiting_feedback') ?></p>
        <?php endif; ?>
        <dl class="kv small">
          <?php foreach (ProjectService::PART_ROLES as $role): ?>
            <dt><?= role_label($role) ?></dt><dd><?= e($pt[$role . '_name'] ?? '–') ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>
      <?php if ($canPlan && !$closed && $pt['cancelled_at'] === null && $pt['completed_at'] === null): ?>
        <div class="card-footer"><button type="button" class="btn btn-sm" data-open-dialog="dlg-pics-<?= (int) $pt['id'] ?>"><?= icon('users') ?> <?= t('project.assign_pics') ?></button></div>
        <dialog class="modal" id="dlg-pics-<?= (int) $pt['id'] ?>" aria-labelledby="dlg-pics-title-<?= (int) $pt['id'] ?>"<?= $failed === 'part_pics' && (int) Request::post('part_id') === (int) $pt['id'] ? ' data-autoopen' : '' ?>>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="part_pics"><input type="hidden" name="part_id" value="<?= (int) $pt['id'] ?>">
            <div class="modal-header"><h2 id="dlg-pics-title-<?= (int) $pt['id'] ?>"><?= t('project.assign_pics') ?> — <?= e($pt['name']) ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
            <div class="modal-body">
              <p class="muted small"><?= t('project.assign_pics_hint') ?></p>
              <div class="form-grid">
                <?php foreach (ProjectService::PART_ROLES as $role): ?>
                  <div class="field<?= isset($errors['pic.' . $role]) ? ' has-error' : '' ?>">
                    <label for="pic-<?= (int) $pt['id'] ?>-<?= e($role) ?>"><?= role_label($role) ?></label>
                    <select class="input" id="pic-<?= (int) $pt['id'] ?>-<?= e($role) ?>" name="pic[<?= e($role) ?>]">
                      <option value="">–</option>
                      <?php foreach ($users[$role] ?? [] as $u): ?>
                        <option value="<?= $u['id'] ?>"<?= (int) $pt[$role . '_pic_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['pic.' . $role])): ?><p class="field-error"><?= e($errors['pic.' . $role]) ?></p><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
          </form>
        </dialog>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<?php elseif ($tab === 'processes'): ?>
<?php foreach (array_merge([['id' => null, 'name' => I18n::t('project.project_level'), 'part_type' => null]], $parts) as $pt): ?>
  <?php $list = $pt['id'] === null ? $projectLevel : ($byPart[(int) $pt['id']] ?? []); ?>
  <?php if (!$list) { continue; } ?>
  <section class="card section" aria-labelledby="proc-part-<?= (int) $pt['id'] ?>">
    <div class="card-header">
      <h2 id="proc-part-<?= (int) $pt['id'] ?>"><?= e($pt['name']) ?></h2>
      <?php if ($pt['id'] !== null): ?><?= status_badge((string) $pt['status']) ?><?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col"><?= t('process.process') ?></th>
            <th scope="col">PIC</th>
            <th scope="col"><?= t('common.status') ?></th>
            <th scope="col" class="right"><?= t('process.duration_short') ?></th>
            <th scope="col" class="nowrap"><?= t('process.planned') ?></th>
            <th scope="col" class="nowrap"><?= t('process.forecast') ?></th>
            <th scope="col" class="nowrap"><?= t('process.actual') ?></th>
            <th scope="col" class="right"><?= t('process.deviation') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $p): ?>
            <?php $dormant = $p['activation'] === 'loop_only' && $p['status'] === 'not_started'; ?>
            <tr<?= $p['status'] === 'skipped' ? ' class="row-skipped"' : '' ?>>
              <td><a href="<?= e(url('process.php', ['id' => $p['id']])) ?>"><?= $pName($p) ?></a>
                <?php if ((int) $p['iteration'] > 1): ?><span class="badge badge-neutral"><?= t('process.iteration_n', ['n' => (int) $p['iteration']]) ?></span><?php endif; ?>
                <?php if ($dormant): ?><div class="muted small"><?= t('process.loop_only_hint') ?></div><?php endif; ?></td>
              <td class="small"><?= e($p['pic_name'] ?? I18n::t('project.no_pic')) ?><div class="muted"><?= role_label((string) $p['pic_role_code']) ?></div></td>
              <td><?= status_badge((string) $p['status']) ?>
                <?php if ($p['overdue_days'] > 0): ?><span class="badge badge-danger"><?= t('project.overdue_n', ['days' => $p['overdue_days']]) ?></span><?php elseif ($p['due_soon']): ?><span class="badge badge-warning"><?= t('status.due_soon') ?></span><?php endif; ?>
                <?php if ($p['held']): ?><span class="badge badge-neutral"><?= t('status.hold') ?></span><?php endif; ?></td>
              <td class="right"><?= $p['status'] === 'skipped' ? '0' : (int) $p['duration'] ?></td>
              <td class="nowrap small"><?= $p['planned_start'] ? fmt_date($p['planned_start']) . ' – ' . fmt_date($p['planned_finish']) : '–' ?></td>
              <td class="nowrap small"><?= $p['forecast_finish'] ? fmt_date($p['forecast_finish']) : '–' ?></td>
              <td class="nowrap small"><?= $p['actual_start'] ? fmt_date($p['actual_start']) . ' – ' . ($p['actual_finish'] ? fmt_date($p['actual_finish']) : '…') : '–' ?></td>
              <td class="right small"><?= $p['deviation'] === null ? '–' : e(sprintf('%+d', $p['deviation'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>
<p class="muted small"><?= t('process.dates_note') ?></p>

<?php else: ?>
<?php $history = RevisionHistory::forProject($id); $changes = $query->scheduleChanges($id); $baselines = $query->baselines($id); $gates = $query->gates($id); ?>
<div class="grid grid-2">
  <section class="card" aria-labelledby="sec-rev">
    <div class="card-header"><h2 id="sec-rev"><?= t('project.revision_history') ?></h2></div>
    <div class="card-body">
      <?php if (!$history): ?><p class="muted"><?= t('common.empty') ?></p><?php endif; ?>
      <ol class="timeline-list">
        <?php foreach ($history as $h): ?>
          <li>
            <div><?= e($h['summary']) ?></div>
            <?php $d = $h['details_json'] ? json_decode((string) $h['details_json'], true) : []; ?>
            <?php if (!empty($d['reason']) && is_string($d['reason'])): ?><div class="small"><?= t('common.reason') ?>: <?= e($d['reason']) ?></div><?php endif; ?>
            <?php if (!empty($d['comment']) && is_string($d['comment'])): ?><div class="small"><?= e($d['comment']) ?></div><?php endif; ?>
            <div class="timeline-meta"><?= fmt_datetime($h['created_at']) ?> · <?= e($h['user_name'] ?? 'Sistem') ?></div>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>
  <div class="stack">
    <section class="card" aria-labelledby="sec-baseline">
      <div class="card-header"><h2 id="sec-baseline"><?= t('project.baselines') ?></h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col"><?= t('project.version') ?></th><th scope="col"><?= t('project.scope') ?></th><th scope="col"><?= t('common.reason') ?></th><th scope="col"><?= t('common.date') ?></th></tr></thead>
          <tbody>
            <?php if (!$baselines): ?><tr><td colspan="4" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
            <?php foreach ($baselines as $b): ?>
              <tr><td>v<?= (int) $b['version_no'] ?><?= (int) $b['is_active'] === 1 ? ' <span class="badge badge-success">' . t('common.active') . '</span>' : '' ?></td>
                <td><?= e($b['part_name'] ?? I18n::t('project.whole_project')) ?></td><td class="small"><?= e($b['reason']) ?></td>
                <td class="small nowrap"><?= fmt_datetime($b['created_at']) ?><div class="muted"><?= e($b['user_name'] ?? 'Sistem') ?></div></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php if ($gates): ?>
      <section class="card" aria-labelledby="sec-gates">
        <div class="card-header"><h2 id="sec-gates"><?= t('project.gate_results') ?></h2></div>
        <div class="card-body">
          <ul class="plain-list">
            <?php foreach ($gates as $g): ?>
              <li><?= status_badge((string) $g['result'], 'gate') ?> <?= e($g['name']) ?> · <?= t('process.iteration_n', ['n' => (int) $g['iteration']]) ?>
                <div class="small"><?= e($g['result_note']) ?></div><div class="timeline-meta"><?= fmt_datetime($g['decided_at']) ?> · <?= e($g['user_name']) ?></div></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>
<section class="card section" aria-labelledby="sec-shift">
  <div class="card-header"><h2 id="sec-shift"><?= t('project.schedule_changes') ?></h2></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col"><?= t('common.date') ?></th><th scope="col"><?= t('process.process') ?></th><th scope="col"><?= t('project.change_type') ?></th><th scope="col"><?= t('project.old') ?></th><th scope="col"><?= t('project.new') ?></th><th scope="col" class="right"><?= t('project.shift') ?></th><th scope="col"><?= t('project.cause') ?></th></tr></thead>
      <tbody>
        <?php if (!$changes): ?><tr><td colspan="7" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
        <?php foreach ($changes as $c): ?>
          <tr>
            <td class="small nowrap"><?= fmt_datetime($c['created_at']) ?><div class="muted"><?= e($c['user_name'] ?? 'Sistem') ?></div></td>
            <td class="small"><?= e(trim(($c['part_name'] ? $c['part_name'] . ' › ' : '') . ($c['code'] ? $c['code'] . ' ' . $c['process_name'] : I18n::t('project.target_finish')))) ?></td>
            <td class="small"><?= t('change_type.' . $c['change_type']) ?></td>
            <td class="small nowrap"><?= $c['old_start'] ? fmt_date($c['old_start']) . ' – ' : '' ?><?= fmt_date($c['old_finish']) ?></td>
            <td class="small nowrap"><?= $c['new_start'] ? fmt_date($c['new_start']) . ' – ' : '' ?><?= fmt_date($c['new_finish']) ?></td>
            <td class="right small"><?= $c['shift_working_days'] === null ? '–' : e(sprintf('%+d', (int) $c['shift_working_days'])) ?></td>
            <td class="small"><?= e($c['cause_name'] ?? '') ?><?= $c['reason'] ? '<div class="muted">' . e($c['reason']) . '</div>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if (!$closed && ($canEdit || $canPlan)): ?>
  <dialog class="modal" id="dlg-project" aria-labelledby="dlg-project-title"<?= $failed === 'update_project' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_project"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="modal-header"><h2 id="dlg-project-title"><?= t('project.edit') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body form-grid">
        <?php if ($canEdit): ?>
          <div class="field"><label for="pj-priority"><?= t('project.priority') ?></label>
            <select class="input" id="pj-priority" name="priority">
              <?php foreach (ProjectService::PRIORITIES as $pr): ?><option value="<?= e($pr) ?>"<?= $project['priority'] === $pr ? ' selected' : '' ?>><?= t('project.priority.' . $pr) ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <?php if ($canPlan): ?>
          <div class="field"><label for="pj-npd"><?= t('project.npd_pic') ?></label>
            <select class="input" id="pj-npd" name="npd_pic_id">
              <?php foreach (array_merge($users['npd_staff'] ?? [], $users['admin'] ?? []) as $u): ?><option value="<?= $u['id'] ?>"<?= (int) $project['npd_pic_id'] === $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
            <p class="field-hint"><?= t('project.npd_pic_hint') ?></p></div>
        <?php endif; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php if (!$closed && $canTarget): ?>
  <dialog class="modal" id="dlg-target" aria-labelledby="dlg-target-title"<?= $failed === 'change_target' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="change_target"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="modal-header"><h2 id="dlg-target-title"><?= t('project.change_target') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body stack">
        <p class="muted small"><?= t('project.change_target_hint', ['forecast' => I18n::date($project['forecast_finish'])]) ?></p>
        <div class="field<?= isset($errors['target_finish']) ? ' has-error' : '' ?>"><label for="tg-date"><?= t('project.new_target') ?></label>
          <input class="input" type="date" id="tg-date" name="target_finish" required value="<?= e(Request::post('target_finish') ?? $project['target_finish']) ?>"></div>
        <div class="field<?= isset($errors['reason']) ? ' has-error' : '' ?>"><label for="tg-reason"><?= t('common.reason_required') ?></label>
          <textarea class="input" id="tg-reason" name="reason" rows="3" required maxlength="500"><?= e(Request::post('reason')) ?></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php if (!$closed && $canBaseline): ?>
  <dialog class="modal" id="dlg-baseline" aria-labelledby="dlg-baseline-title"<?= $failed === 'baseline' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="baseline"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="modal-header"><h2 id="dlg-baseline-title"><?= t('project.set_baseline') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body stack">
        <p class="muted small"><?= t('project.baseline_hint') ?></p>
        <div class="field"><label for="bl-part"><?= t('project.scope') ?></label>
          <select class="input" id="bl-part" name="part_id">
            <option value=""><?= t('project.whole_project') ?></option>
            <?php foreach ($parts as $pt): ?><?php if ($pt['start_date'] && !$pt['cancelled_at']): ?><option value="<?= (int) $pt['id'] ?>"><?= e($pt['name']) ?></option><?php endif; ?><?php endforeach; ?>
          </select></div>
        <div class="field<?= isset($errors['reason']) ? ' has-error' : '' ?>"><label for="bl-reason"><?= t('common.reason_required') ?></label>
          <textarea class="input" id="bl-reason" name="reason" rows="3" required maxlength="500"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
