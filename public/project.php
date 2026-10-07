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
use App\Approval\ApprovalService;
use App\Document\DocumentService;
use App\Master\MasterService;
use App\Project\NextActionService;
use App\Project\ProjectQuery;
use App\Record\RecordService;
use App\Project\ProjectService;
use App\Project\RevisionHistory;
use App\Scheduling\ScheduleService;

$user = require_permission('project.view');
$query = new ProjectQuery();
$id = (int) Request::int('id', 0);
$project = $query->find($id);
$tab = in_array(Request::query('tab'), ['overview', 'processes', 'approvals', 'documents', 'records', 'history', 'activity'], true) ? (string) Request::query('tab') : 'overview';

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
            case 'next_action':
                $partParam = Request::int('part_id');
                (new NextActionService())->set($user, $id, $partParam, $_POST);
                Session::flash('success', I18n::t('next.saved'));
                break;
            case 'next_action_done':
                (new NextActionService())->complete($user, (int) Request::int('next_action_id'));
                Session::flash('success', I18n::t('next.done_msg'));
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
$nextSvc = new NextActionService();
$nextActions = [];
foreach ($nextSvc->open($id) as $na) {
    $nextActions[$na['part_id'] === null ? 0 : (int) $na['part_id']] = $na;
}
$canNext = $nextSvc->canEdit($user, $project) && !in_array($project['status'], ['completed', 'cancelled'], true);
$activeUsers = $canNext ? \App\Core\Db::fetchAll("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code <> 'management' ORDER BY u.name") : [];
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
<?php
/** Blok Next Action & Waiting For (PRD §9.5) untuk part (atau level project bila $partKey = 0). */
$renderNext = static function (int $partKey, string $scopeName) use ($nextActions, $canNext, $activeUsers, $failed, $errors): string {
    $na = $nextActions[$partKey] ?? null;
    $today = \App\Core\Clock::todayString();
    ob_start(); ?>
    <div class="next-action">
      <p class="small"><strong><?= t('next.title') ?></strong></p>
      <?php if ($na): ?>
        <p class="small"><?= e($na['description']) ?></p>
        <p class="small muted"><?= t('next.waiting_for') ?>: <?= t('next.waiting.' . $na['waiting_for']) ?><?= $na['waiting_for_note'] ? ' (' . e($na['waiting_for_note']) . ')' : '' ?>
          · <?= e($na['owner_name'] ?? '–') ?> · <?= $na['due_date'] ? fmt_date($na['due_date']) : '–' ?>
          <?php if ($na['due_date'] && $na['due_date'] < $today): ?><span class="badge badge-danger"><?= t('next.late') ?></span><?php elseif ($na['due_date'] === $today): ?><span class="badge badge-warning"><?= t('next.due_today') ?></span><?php endif; ?></p>
      <?php else: ?>
        <p class="small muted"><?= t('next.none') ?></p>
      <?php endif; ?>
      <?php if ($canNext): ?>
        <div class="inline-edit">
          <button type="button" class="btn btn-sm" data-open-dialog="dlg-next-<?= $partKey ?>"><?= icon('edit', 'icon icon-sm') ?> <?= $na ? t('next.update') : t('next.set') ?></button>
          <?php if ($na): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="next_action_done"><input type="hidden" name="next_action_id" value="<?= (int) $na['id'] ?>">
              <button type="submit" class="btn btn-sm btn-ghost"><?= icon('check', 'icon icon-sm') ?> <?= t('next.mark_done') ?></button></form>
          <?php endif; ?>
        </div>
        <dialog class="modal" id="dlg-next-<?= $partKey ?>" aria-labelledby="dlg-next-title-<?= $partKey ?>"<?= $failed === 'next_action' && (int) Request::post('part_id') === $partKey ? ' data-autoopen' : '' ?>>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="next_action"><?php if ($partKey > 0): ?><input type="hidden" name="part_id" value="<?= $partKey ?>"><?php endif; ?>
            <div class="modal-header"><h2 id="dlg-next-title-<?= $partKey ?>"><?= t('next.title') ?> — <?= e($scopeName) ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
            <div class="modal-body form-grid">
              <div class="field span-2<?= isset($errors['description']) ? ' has-error' : '' ?>"><label for="na-desc-<?= $partKey ?>"><?= t('next.description') ?></label>
                <textarea class="input" id="na-desc-<?= $partKey ?>" name="description" rows="2" maxlength="500" required><?= e($na['description'] ?? '') ?></textarea></div>
              <div class="field"><label for="na-due-<?= $partKey ?>"><?= t('next.due') ?></label><input class="input" type="date" id="na-due-<?= $partKey ?>" name="due_date" value="<?= e((string) ($na['due_date'] ?? '')) ?>"></div>
              <div class="field"><label for="na-owner-<?= $partKey ?>"><?= t('next.owner') ?></label>
                <select class="input" id="na-owner-<?= $partKey ?>" name="owner_user_id"><option value="">–</option>
                  <?php foreach ($activeUsers as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) ($na['owner_user_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="na-wait-<?= $partKey ?>"><?= t('next.waiting_for') ?></label>
                <select class="input" id="na-wait-<?= $partKey ?>" name="waiting_for"><?php foreach (NextActionService::WAITING as $w): ?><option value="<?= $w ?>"<?= ($na['waiting_for'] ?? 'internal') === $w ? ' selected' : '' ?>><?= t('next.waiting.' . $w) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="na-note-<?= $partKey ?>"><?= t('next.waiting_note') ?></label><input class="input" id="na-note-<?= $partKey ?>" name="waiting_for_note" maxlength="255" value="<?= e((string) ($na['waiting_for_note'] ?? '')) ?>"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
          </form>
        </dialog>
      <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>

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
      <hr>
      <?= $renderNext(0, (string) $project['code']) ?>
      <?php $partNext = array_filter($nextActions, static fn ($k) => $k > 0, ARRAY_FILTER_USE_KEY); ?>
      <?php if ($partNext): ?>
        <p class="small muted"><?= t('next.parts_summary') ?></p>
        <ul class="plain-list small"><?php foreach ($partNext as $na): ?><li><strong><?= e($na['part_name']) ?>:</strong> <?= e($na['description']) ?> <span class="muted">· <?= t('next.waiting.' . $na['waiting_for']) ?> · <?= $na['due_date'] ? fmt_date($na['due_date']) : '–' ?></span></li><?php endforeach; ?></ul>
      <?php endif; ?>
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
        <?php if ($pt['cancelled_at'] === null): ?><?= $renderNext((int) $pt['id'], (string) $pt['name']) ?><?php endif; ?>
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

<?php elseif ($tab === 'approvals'): ?>
<?php $approvals = (new ApprovalService())->search($user, ['project_id' => $id], 1, 100)['rows']; ?>
<section class="card" aria-labelledby="sec-appr">
  <div class="card-header"><h2 id="sec-appr"><?= t('approval.title') ?></h2><a class="btn btn-sm" href="<?= e(url('approvals.php', ['view' => 'history', 'project_id' => $id])) ?>"><?= t('approval.open_page') ?></a></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col"><?= t('approval.code') ?></th><th scope="col"><?= t('approval.type') ?></th><th scope="col"><?= t('process.process') ?></th><th scope="col"><?= t('common.status') ?></th><th scope="col"><?= t('approval.decided') ?></th></tr></thead>
      <tbody>
        <?php if (!$approvals): ?><tr><td colspan="5" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
        <?php foreach ($approvals as $a): ?>
          <tr>
            <td class="mono small"><?= e($a['code']) ?><div class="muted"><?= t('process.iteration_n', ['n' => (int) $a['iteration']]) ?></div></td>
            <td class="small"><?= t('approval.type.' . $a['approval_type']) ?><div class="muted"><?= t('approval.giver.' . $a['giver']) ?></div></td>
            <td class="small"><?php if ($a['process_id']): ?><a href="<?= e(url('process.php', ['id' => $a['process_id']])) ?>"><?= e(($a['part_name'] ? $a['part_name'] . ' › ' : '') . $a['process_code'] . ' ' . ProjectQuery::processName(['name' => $a['process_name'], 'name_en' => $a['process_name_en']])) ?></a><?php endif; ?></td>
            <td><?= status_badge((string) $a['status'], 'approval') ?></td>
            <td class="small"><?php if ($a['decided_at']): ?><?= fmt_datetime($a['decided_at']) ?> · <?= e($a['decided_by_name']) ?><?= $a['decision_maker_name'] ? ' · ' . e($a['decision_maker_name']) : '' ?><?= $a['comment'] ? '<div>' . e($a['comment']) . '</div>' : '' ?><?php else: ?>–<?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php elseif ($tab === 'documents'): ?>
<?php $docs = (new DocumentService())->forProject($id); ?>
<section class="card" aria-labelledby="sec-docs">
  <div class="card-header"><h2 id="sec-docs"><?= t('process.documents') ?></h2><a class="btn btn-sm" href="<?= e(url('documents.php', ['project_id' => $id])) ?>"><?= t('doc.open_center') ?></a></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col"><?= t('doc.location') ?></th><th scope="col"><?= t('process.doc_type') ?></th><th scope="col"><?= t('process.file') ?></th><th scope="col"><?= t('project.version') ?></th><th scope="col"><?= t('common.status') ?></th><th scope="col"><?= t('process.uploaded') ?></th></tr></thead>
      <tbody>
        <?php if (!$docs): ?><tr><td colspan="6" class="table-empty"><?= t('doc.empty') ?></td></tr><?php endif; ?>
        <?php foreach ($docs as $d): ?>
          <tr>
            <td class="small"><?php if ($d['process_id']): ?><a href="<?= e(url('process.php', ['id' => $d['process_id']])) ?>"><?= e(($d['part_name'] ? $d['part_name'] . ' › ' : '') . $d['process_code'] . ' ' . ProjectQuery::processName(['name' => $d['process_name'], 'name_en' => $d['process_name_en']])) ?></a><?php elseif ($d['npr_id']): ?><a href="<?= e(url('npr-edit.php', ['id' => $d['npr_id']])) ?>">NPR</a><?php else: ?>–<?php endif; ?></td>
            <td class="small"><?= e(MasterService::label('document_type', (string) $d['doc_type_code'])) ?></td>
            <td class="small"><a href="<?= e(url('download.php', ['v' => $d['version_id']])) ?>"><?= icon('download', 'icon icon-sm') ?> <?= e($d['original_name']) ?></a></td>
            <td class="small">v<?= (int) $d['version_no'] ?></td>
            <td><?= status_badge((string) $d['version_status'], 'docstatus') ?></td>
            <td class="small nowrap"><?= fmt_datetime($d['uploaded_at']) ?><div class="muted"><?= e($d['uploader_name']) ?></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php elseif ($tab === 'records'): ?>
<?php $rec = (new RecordService())->forProject($id); ?>
<?php foreach (['trial' => 'record.trial_title', 'material' => 'record.material_title', 'validation' => 'record.validation_title'] as $kind => $title): ?>
  <section class="card section" aria-labelledby="sec-rec-<?= $kind ?>">
    <div class="card-header"><h2 id="sec-rec-<?= $kind ?>"><?= t($title) ?></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr>
          <th scope="col"><?= t('process.process') ?></th>
          <?php if ($kind === 'material'): ?>
            <th scope="col"><?= t('record.f.material') ?></th><th scope="col"><?= t('record.f.batch_no') ?></th><th scope="col" class="right"><?= t('record.f.quantity_kg') ?></th><th scope="col"><?= t('record.f.received_date') ?></th><th scope="col"><?= t('record.f.supplier') ?></th><th scope="col">PIC</th>
          <?php elseif ($kind === 'trial'): ?>
            <th scope="col"><?= t('common.date') ?></th><th scope="col"><?= t('record.f.machine') ?> / <?= t('record.f.mold') ?></th><th scope="col"><?= t('record.f.problems') ?></th><th scope="col"><?= t('record.f.evaluation') ?></th><th scope="col"><?= t('record.f.result') ?></th>
          <?php else: ?>
            <th scope="col"><?= t('common.date') ?></th><th scope="col"><?= t('record.f.machine') ?> / <?= t('record.f.mold') ?></th><th scope="col"><?= t('record.f.production_qty') ?></th><th scope="col"><?= t('record.f.problems') ?></th><th scope="col"><?= t('record.f.result') ?></th>
          <?php endif; ?>
        </tr></thead>
        <tbody>
          <?php if (!$rec[$kind]): ?><tr><td colspan="7" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
          <?php foreach ($rec[$kind] as $r): ?>
            <tr>
              <td class="small"><a href="<?= e(url('process.php', ['id' => $r['process_id']])) ?>#sec-record"><?= e($r['part_name'] . ' › ' . $r['code'] . ' ' . $r['process_name']) ?></a><?= isset($r['iteration']) ? '<div class="muted">' . t('process.iteration_n', ['n' => (int) $r['iteration']]) . '</div>' : '' ?></td>
              <?php if ($kind === 'material'): ?>
                <td class="small"><?= e($r['material'] ?? '–') ?><?= $r['material_received'] ? '<div class="muted">' . e($r['material_received']) . '</div>' : '' ?></td><td class="small"><?= e($r['batch_no'] ?? '–') ?></td>
                <td class="small right"><?= $r['quantity_kg'] !== null ? e(fmt_number($r['quantity_kg'], 3)) : '–' ?></td><td class="small nowrap"><?= fmt_date($r['received_date']) ?></td><td class="small"><?= e($r['supplier'] ?? '–') ?></td><td class="small"><?= e($r['pic_name'] ?? '–') ?></td>
              <?php elseif ($kind === 'trial'): ?>
                <td class="small nowrap"><?= fmt_date($r['trial_date']) ?><div class="muted"><?= t('record.type.' . $r['trial_type']) ?></div></td><td class="small"><?= e(trim(($r['machine'] ?? '') . ' / ' . ($r['mold'] ?? ''), ' /') ?: '–') ?></td>
                <td class="small"><?= e($r['problems'] ?? '–') ?></td><td class="small"><?= e($r['evaluation'] ?? '–') ?></td><td class="small"><?= e($r['result'] ?? '–') ?></td>
              <?php else: ?>
                <td class="small nowrap"><?= fmt_date($r['validation_date']) ?></td><td class="small"><?= e(trim(($r['machine'] ?? '') . ' / ' . ($r['mold'] ?? ''), ' /') ?: '–') ?></td>
                <td class="small"><?= e($r['production_qty'] ?? '–') ?></td><td class="small"><?= e($r['problems'] ?? '–') ?></td><td><?= $r['result'] ? status_badge((string) $r['result'], 'validation') : '–' ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>

<?php elseif ($tab === 'activity'): ?>
<?php
$actPage = max(1, (int) Request::int('page', 1));
$actTotal = (int) \App\Core\Db::value('SELECT COUNT(*) FROM audit_logs WHERE project_id = ?', [$id]);
$activity = \App\Core\Db::fetchAll('SELECT action, entity_type, entity_id, user_name, reason, old_value, new_value, created_at FROM audit_logs WHERE project_id = ? ORDER BY id DESC LIMIT 50 OFFSET ' . (($actPage - 1) * 50), [$id]);
$actPages = max(1, (int) ceil($actTotal / 50));
?>
<section class="card" aria-labelledby="sec-act">
  <div class="card-header"><h2 id="sec-act"><?= t('project.tab.activity') ?></h2></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col"><?= t('common.time') ?></th><th scope="col"><?= t('audit.user') ?></th><th scope="col"><?= t('audit.action') ?></th><th scope="col"><?= t('audit.change') ?></th><th scope="col"><?= t('common.reason') ?></th></tr></thead>
      <tbody>
        <?php if (!$activity): ?><tr><td colspan="5" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
        <?php foreach ($activity as $a): ?>
          <?php $old = $a['old_value'] ? json_decode((string) $a['old_value'], true) : null; $new = $a['new_value'] ? json_decode((string) $a['new_value'], true) : null; ?>
          <tr>
            <td class="small nowrap"><?= fmt_datetime($a['created_at']) ?></td>
            <td class="small"><?= e($a['user_name'] ?? 'Sistem') ?></td>
            <td class="small"><?= I18n::has('audit.action.' . $a['action']) ? t('audit.action.' . $a['action']) : e($a['action']) ?><div class="muted mono"><?= e($a['entity_type'] . ' #' . $a['entity_id']) ?></div></td>
            <td class="small"><?php if (is_array($new) || is_array($old)): ?><details><summary class="small"><?= t('audit.show_change') ?></summary><pre class="pre small"><?= e(json_encode(['old' => $old, 'new' => $new], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php else: ?>–<?php endif; ?></td>
            <td class="small"><?= e($a['reason'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($actPages > 1): ?>
    <div class="pagination"><span><?= t('common.page_of', ['page' => $actPage, 'pages' => $actPages]) ?></span><span class="pagination-links">
      <?php if ($actPage > 1): ?><a class="btn btn-sm" href="<?= e(url('project.php', ['id' => $id, 'tab' => 'activity', 'page' => $actPage - 1])) ?>"><?= t('common.previous') ?></a><?php endif; ?>
      <?php if ($actPage < $actPages): ?><a class="btn btn-sm" href="<?= e(url('project.php', ['id' => $id, 'tab' => 'activity', 'page' => $actPage + 1])) ?>"><?= t('common.next') ?></a><?php endif; ?>
    </span></div>
  <?php endif; ?>
</section>

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
