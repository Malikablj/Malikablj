<?php
declare(strict_types=1);

/**
 * Resume project/part dengan jadwal baru (PRD §8.2): Target Finish baru wajib, tanggal mulai kembali,
 * durasi sisa per proses berjalan. Pratinjau dihitung di server; simpan hanya untuk isian yang sama
 * persis dengan pratinjau terakhir (diikat token HMAC per sesi).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AppException;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Project\HoldService;
use App\Project\ProjectQuery;

$user = require_permission('hold.manage');
$id = (int) (Request::int('project') ?? 0);
$partId = Request::int('part');
$project = (new ProjectQuery())->find($id);
$part = null;
if ($partId !== null) {
    $part = Db::fetch('SELECT * FROM project_parts WHERE id = ? AND project_id = ?', [$partId, $id]);
    if (!$part) {
        Response::error(404, I18n::t('error.not_found'));
    }
}
$holds = new HoldService();
$back = url('project.php', ['id' => $id]);
try {
    $ctx = $holds->resumeContext($id, $partId);
} catch (AppException $e) {
    Session::flash('error', $e->getMessage());
    Response::redirect($back);
}

/** @param array<string,mixed> $in */
$fingerprint = static function (array $in) use ($id, $partId, $ctx): string {
    $rem = [];
    foreach ($ctx['processes'] as $p) {
        $rem[(int) $p['id']] = trim((string) ($in['remaining'][(int) $p['id']] ?? ''));
    }
    ksort($rem);
    $data = json_encode([$id, $partId, trim((string) ($in['target_finish'] ?? '')), trim((string) ($in['restart_date'] ?? '')), $rem, trim((string) ($in['note'] ?? ''))]);
    return hash_hmac('sha256', (string) $data, Csrf::token());
};

$input = [
    'target_finish' => (string) ($ctx['target'] ?? ''),
    'restart_date' => (string) $ctx['restart'],
    'remaining' => array_combine(array_map(static fn ($p) => (int) $p['id'], $ctx['processes']), array_map(static fn ($p) => (string) $p['remaining'], $ctx['processes'])) ?: [],
    'note' => '',
];
$errors = [];
$preview = null;
$notice = null;
if (Request::isPost()) {
    require_post();
    $input = [
        'target_finish' => (string) Request::post('target_finish', ''),
        'restart_date' => (string) Request::post('restart_date', ''),
        'remaining' => array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', Request::array('remaining')),
        'note' => (string) Request::post('note', ''),
    ];
    try {
        if (Request::post('op') === 'save') {
            if (!hash_equals($fingerprint($input), (string) Request::post('preview_token', ''))) {
                $notice = I18n::t('resume.must_preview');
                $preview = $holds->previewResume($user, $id, $partId, $input);
            } else {
                $holds->resume($user, $id, $partId, $input);
                Session::flash('success', I18n::t('hold.resumed'));
                Response::redirect(url('project.php', ['id' => $id, 'tab' => 'history']));
            }
        } else {
            $preview = $holds->previewResume($user, $id, $partId, $input);
        }
    } catch (ValidationException $e) {
        $errors = $e->errors();
        http_response_code(422);
    } catch (AppException $e) {
        if ($e->httpStatus() === 403) {
            throw $e;
        }
        Session::flash('error', $e->getMessage());
        Response::redirect($back);
    }
}

$scope = $part ? (string) $part['name'] : (string) $project['code'];
$hold = $ctx['hold'];
$heldBy = Db::value('SELECT name FROM users WHERE id = ?', [(int) $hold['held_by']]);
$pageTitle = I18n::t('resume.title') . ' — ' . $scope;
$activeNav = 'projects';
require APP_ROOT . '/includes/layout/header.php';
?>
<nav class="breadcrumbs" aria-label="<?= t('common.breadcrumbs') ?>">
  <a href="<?= e(url('projects.php')) ?>"><?= t('project.list_title') ?></a><span class="breadcrumbs-sep">/</span>
  <a href="<?= e($back) ?>" class="mono"><?= e($project['code']) ?></a><span class="breadcrumbs-sep">/</span>
  <span aria-current="page"><?= t('hold.resume') ?><?= $part ? ' · ' . e($part['name']) : '' ?></span>
</nav>
<div class="page-header">
  <div>
    <p class="eyebrow mono"><?= e($project['code']) ?><?= $part ? ' · ' . e($part['name']) : '' ?></p>
    <h1><?= t('resume.title') ?></h1>
    <p><?= t('resume.subtitle') ?></p>
  </div>
</div>

<div class="flash flash-warning" role="status"><?= icon('pause') ?><span><?= t('resume.held_since', ['date' => I18n::dateTime($hold['held_at']), 'user' => (string) ($heldBy ?: '–'), 'reason' => (string) $hold['reason']]) ?></span></div>
<?php if ($notice): ?><div class="flash flash-info" role="status"><?= icon('info') ?><span><?= e($notice) ?></span></div><?php endif; ?>
<?php if ($errors): ?><div class="flash flash-error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>

<form method="post" class="stack" novalidate>
  <?= csrf_field() ?>
  <section class="card" aria-labelledby="sec-resume">
    <div class="card-header"><h2 id="sec-resume"><?= t('resume.title') ?></h2></div>
    <div class="card-body form-grid">
      <div class="field<?= isset($errors['target_finish']) ? ' has-error' : '' ?>">
        <label for="rs-target"><?= t('resume.target_new') ?> *</label>
        <input class="input" type="date" id="rs-target" name="target_finish" required value="<?= e($input['target_finish']) ?>">
        <p class="field-hint"><?= t('resume.target_current', ['date' => I18n::date($ctx['target'])]) ?></p>
        <?php if (isset($errors['target_finish'])): ?><p class="field-error"><?= e($errors['target_finish']) ?></p><?php endif; ?>
      </div>
      <div class="field<?= isset($errors['restart_date']) ? ' has-error' : '' ?>">
        <label for="rs-restart"><?= t('resume.restart') ?></label>
        <input class="input" type="date" id="rs-restart" name="restart_date" value="<?= e($input['restart_date']) ?>">
        <p class="field-hint"><?= t('resume.restart_hint') ?></p>
        <?php if (isset($errors['restart_date'])): ?><p class="field-error"><?= e($errors['restart_date']) ?></p><?php endif; ?>
      </div>
      <div class="field span-2">
        <label for="rs-note"><?= t('resume.note') ?></label>
        <textarea class="input" id="rs-note" name="note" rows="2" maxlength="1000"><?= e($input['note']) ?></textarea>
      </div>
    </div>
  </section>

  <section class="card" aria-labelledby="sec-active">
    <div class="card-header"><h2 id="sec-active"><?= t('resume.active') ?></h2></div>
    <?php if (!$ctx['processes']): ?>
      <div class="card-body"><p class="muted"><?= t('resume.active_empty') ?></p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th scope="col"><?= t('process.process') ?></th><th scope="col">PIC</th>
            <th scope="col" class="nowrap"><?= t('resume.actual_start') ?></th><th scope="col" class="nowrap"><?= t('resume.old_finish') ?></th>
            <th scope="col" class="right"><?= t('resume.duration') ?></th><th scope="col" class="right"><?= t('resume.used') ?></th>
            <th scope="col"><?= t('resume.remaining') ?></th><th scope="col" class="nowrap"><?= t('resume.new_finish') ?></th>
          </tr></thead>
          <tbody>
            <?php $newFinish = []; foreach ($preview['active'] ?? [] as $a) { $newFinish[(int) $a['id']] = $a['new_finish']; } ?>
            <?php foreach ($ctx['processes'] as $p): ?>
              <?php $pid = (int) $p['id']; $err = $errors['remaining.' . $pid] ?? null; ?>
              <tr>
                <td class="small"><?= e(($p['part_name'] ? $p['part_name'] . ' › ' : '') . $p['code'] . ' ' . ProjectQuery::processName($p)) ?></td>
                <td class="small"><?= e($p['pic_name'] ?? I18n::t('project.no_pic')) ?></td>
                <td class="small nowrap"><?= fmt_date($p['actual_start']) ?></td>
                <td class="small nowrap"><?= fmt_date($p['planned_finish']) ?></td>
                <td class="right small"><?= (int) $p['duration'] ?></td>
                <td class="right small"><?= (int) $p['used'] ?></td>
                <td class="<?= $err ? 'has-error' : '' ?>">
                  <label class="visually-hidden" for="rs-rem-<?= $pid ?>"><?= t('resume.remaining') ?></label>
                  <input class="input input-sm" type="number" min="1" max="365" id="rs-rem-<?= $pid ?>" name="remaining[<?= $pid ?>]" value="<?= e($input['remaining'][$pid] ?? '') ?>" style="width: 88px">
                  <?php if ($err): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
                </td>
                <td class="small nowrap"><?= isset($newFinish[$pid]) ? fmt_date($newFinish[$pid]) : '–' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($preview): ?>
    <section class="card" aria-labelledby="sec-preview" id="preview">
      <div class="card-header"><h2 id="sec-preview"><?= t('resume.preview_title') ?></h2></div>
      <div class="card-body stack">
        <p><?= t('resume.forecast', ['old' => I18n::date($preview['project_forecast_old']), 'new' => I18n::date($preview['project_forecast_new'])]) ?></p>
        <?php if ($preview['past_target']): ?>
          <div class="flash flash-warning" role="status"><?= icon('flag') ?><span><?= t('resume.past_target', ['forecast' => I18n::date($preview['project_forecast_new']), 'target' => I18n::date($preview['target_new'])]) ?></span></div>
        <?php else: ?>
          <div class="flash flash-success" role="status"><?= icon('check') ?><span><?= t('resume.within_target') ?></span></div>
        <?php endif; ?>
        <p class="small muted"><?= $preview['changes'] ? t('resume.changes', ['count' => count($preview['changes'])]) : t('resume.no_changes') ?></p>
      </div>
      <?php if ($preview['changes']): ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th scope="col"><?= t('process.process') ?></th><th scope="col" class="nowrap"><?= t('project.old') ?></th><th scope="col" class="nowrap"><?= t('project.new') ?></th><th scope="col" class="right"><?= t('project.shift') ?></th></tr></thead>
            <tbody>
              <?php foreach ($preview['changes'] as $c): ?>
                <?php $pn = $c['part_id'] !== null ? (string) Db::value('SELECT name FROM project_parts WHERE id = ?', [(int) $c['part_id']]) : ''; ?>
                <tr>
                  <td class="small"><?= e(($pn !== '' ? $pn . ' › ' : '') . $c['code'] . ' ' . $c['name']) ?></td>
                  <td class="small nowrap"><?= fmt_date($c['old_start']) ?> – <?= fmt_date($c['old_finish']) ?></td>
                  <td class="small nowrap"><?= fmt_date($c['new_start']) ?> – <?= fmt_date($c['new_finish']) ?></td>
                  <td class="right small"><?= $c['shift'] === null ? '–' : e(sprintf('%+d', (int) $c['shift'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <input type="hidden" name="preview_token" value="<?= e($fingerprint($input)) ?>">
  <?php endif; ?>

  <div class="form-actions">
    <a class="btn" href="<?= e($back) ?>"><?= t('common.cancel') ?></a>
    <button type="submit" class="btn" name="op" value="preview"><?= icon('eye') ?> <?= t('resume.preview') ?></button>
    <?php if ($preview && !$errors): ?>
      <button type="submit" class="btn btn-primary" name="op" value="save"><?= icon('play') ?> <?= t('resume.save') ?></button>
    <?php endif; ?>
  </div>
</form>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
