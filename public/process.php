<?php
declare(strict_types=1);

/**
 * Halaman proses (PRD §5, §6.1, §9): detail & jadwal, penyelesaian + keputusan/loop, Mulai lebih awal,
 * Tidak dijalankan, planning (pratinjau), dependency (pratinjau + validasi siklus), dokumen, riwayat iterasi.
 * Seluruh aturan & hak akses ada di service (WorkflowEngine, DependencyService, DocumentService).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AppException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Document\DocumentService;
use App\Master\MasterService;
use App\Project\CommentService;
use App\Project\ProjectQuery;
use App\Record\RecordService;
use App\Scheduling\Lateness;
use App\Workflow\DependencyService;
use App\Workflow\WorkflowEngine;

$user = require_permission('project.view');
$engine = new WorkflowEngine();
$depSvc = new DependencyService();
$docSvc = new DocumentService();
$id = (int) Request::int('id', 0);
$p = $engine->load($id);

$errors = [];
$failed = null;
if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $back = url('process.php', ['id' => $id]);
    try {
        switch ($action) {
            case 'complete':
                $gateParts = array_filter(is_array($_POST['gate_parts'] ?? null) ? $_POST['gate_parts'] : [], static fn ($v) => is_string($v) && $v !== '');
                // bukti approval customer (lampiran) — disimpan sebagai dokumen proses tipe "Approval" (PRD §9.2)
                $evidenceId = null;
                $ev = $_FILES['evidence'] ?? null;
                if (is_array($ev) && (int) ($ev['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && $p['approval_type'] && $p['approval_type'] !== 'npr') {
                    $evidenceId = $docSvc->addProcessDocument($user, $id, 'approval', $ev, I18n::t('approval.evidence_note', [], 'id'));
                }
                $r = $engine->complete($user, $id, [
                    'actual_finish' => (string) Request::post('actual_finish', ''),
                    'outcome' => Request::post('outcome'),
                    'comment' => Request::post('comment'),
                    'loop_to' => Request::post('loop_to'),
                    'gate_parts' => $gateParts,
                    'decision_maker' => Request::post('decision_maker'),
                    'lock_version' => Request::int('lock_version'),
                    'evidence_document_id' => $evidenceId,
                ]);
                Session::flash('success', I18n::t('process.completed_msg.' . $r['effect']) . ($r['activated'] ? ' ' . I18n::t('process.activated_n', ['count' => count($r['activated'])]) : ''));
                break;
            case 'start_early':
                $engine->startEarly($user, $id);
                Session::flash('success', I18n::t('process.started_msg'));
                break;
            case 'skip':
                $engine->skip($user, $id, (string) Request::post('reason'));
                Session::flash('success', I18n::t('process.skipped_msg'));
                break;
            case 'unskip':
                $engine->unskip($user, $id, Request::post('reason'));
                Session::flash('success', I18n::t('process.unskipped_msg'));
                break;
            case 'plan':
                $engine->plan($user, $id, is_array($_POST['plan'] ?? null) ? $_POST['plan'] + ['lock_version' => Request::post('lock_version')] : [], Request::post('reason'));
                Session::flash('success', I18n::t('process.plan_saved'));
                $back = Request::safeReturnPath(Request::post('return'), $back); // mis. kembali ke timeline part
                break;
            case 'deps':
                $depSvc->save($user, $id, is_array($_POST['deps'] ?? null) ? array_values($_POST['deps']) : [], Request::post('reason') ?: null, Request::int('lock_version'));
                Session::flash('success', I18n::t('process.deps_saved'));
                break;
            case 'manual_move':
                $engine->manualMove($user, $id, (string) Request::post('target'), (string) Request::post('reason'), Request::post('manual_start') ?: null);
                Session::flash('success', I18n::t('process.moved_msg'));
                break;
            case 'record':
                (new RecordService())->saveIteration($user, $id, is_array($_POST['rec'] ?? null) ? $_POST['rec'] : []);
                Session::flash('success', I18n::t('record.saved'));
                $back .= '#sec-record';
                break;
            case 'material':
                (new RecordService())->saveMaterial($user, $id, is_array($_POST['rec'] ?? null) ? $_POST['rec'] : [], Request::int('record_id'));
                Session::flash('success', I18n::t('record.saved'));
                $back .= '#sec-record';
                break;
            case 'comment':
                (new CommentService())->add($user, (int) $p['project_id'], $id, (string) Request::post('body'));
                Session::flash('success', I18n::t('comment.added'));
                $back .= '#sec-comments';
                break;
            case 'upload':
                $file = $_FILES['file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
                $docSvc->addProcessDocument($user, $id, (string) Request::post('doc_type'), is_array($file) ? $file : ['error' => UPLOAD_ERR_NO_FILE], Request::post('notes'));
                Session::flash('success', I18n::t('process.doc_uploaded'));
                break;
            default:
                Response::error(400, I18n::t('validation.invalid'));
        }
        Response::redirect($back);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $failed = $action;
        http_response_code(422);
        Session::flash('error', $e->getMessage());
    } catch (AppException $e) {
        if ($e->httpStatus() === 403) {
            throw $e;
        }
        Session::flash('error', $e->getMessage());
        Response::redirect($back);
    }
    $p = $engine->load($id);
}

$projectId = (int) $p['project_id'];
$partId = $p['part_id'] !== null ? (int) $p['part_id'] : null;
$query = new ProjectQuery();
$cal = $query->calendar();
$today = Clock::todayString();
$status = (string) $p['status'];
$isActive = in_array($status, WorkflowEngine::ACTIVE, true);
$held = $engine->isOnHold($p);
$isNprStep = in_array($p['step_type'], ['request', 'feedback'], true);
$projectClosed = $p['project_finished_at'] !== null || $p['project_status'] === 'cancelled' || $p['part_cancelled_at'] !== null;
$canExecute = $engine->canExecute($user, $p);
$overdue = $isActive && !$held ? Lateness::overdueDays($cal, $p['planned_finish'], $today) : 0;
$options = $engine->decisionOptions($p);
$locale = I18n::locale();
$optLabel = static fn (array $o): string => (string) ($locale === 'en' ? ($o['label_en'] ?? $o['code']) : ($o['label_id'] ?? $o['code']));

$canComplete = $isActive && $canExecute && !$held && !$isNprStep && !$projectClosed;
$canStartEarly = $status === 'not_started' && $p['activation'] === 'auto' && $canExecute && !$isNprStep && !$held && !$projectClosed
    && ($partId === null || $p['part_start_date'] !== null) && $engine->dependenciesSatisfied($id);
$canSkip = Gate::can($user, 'process.skip') && !$projectClosed && $engine->canSkipNow($p);
$canUnskip = $status === 'skipped' && Gate::can($user, 'process.skip') && !$projectClosed;
$canPlan = Gate::can($user, 'schedule.plan') && !in_array($status, ['completed', 'skipped'], true) && !$projectClosed;
$canDeps = Gate::can($user, 'dependency.edit') && !in_array($status, ['completed', 'skipped'], true) && !$isNprStep && !$projectClosed;
$canManual = Gate::can($user, 'process.manual_move') && !$projectClosed && !$isNprStep;
$canUpload = $docSvc->canUploadToProcess($user, $p) && !$projectClosed;

$ffBlockers = $isActive ? $engine->ffBlockers($id) : [];
$missingDocs = $isActive ? $engine->missingDocuments($p) : [];
$deps = $depSvc->forProcess($id);
$waitingFor = [];
foreach ($deps['predecessors'] as $d) {
    $done = in_array($d['status'], ['completed', 'skipped'], true);
    if (($d['dep_type'] === 'FS' && !$done) || ($d['dep_type'] === 'SS' && !$done && $d['status'] === 'not_started')) {
        $waitingFor[] = $d;
    }
}
$docs = $docSvc->forProcess($id);
$required = $p['required_doc_types_json'] ? (json_decode((string) $p['required_doc_types_json'], true) ?: []) : [];
$suggested = $p['workflow_step_id'] ? (json_decode((string) (Db::value('SELECT suggested_doc_types_json FROM workflow_steps WHERE id = ?', [(int) $p['workflow_step_id']]) ?? '[]'), true) ?: []) : [];
$runs = Db::fetchAll('SELECT r.*, u.name AS pic_name, c.name AS completed_by_name FROM process_runs r LEFT JOIN users u ON u.id = r.pic_user_id LEFT JOIN users c ON c.id = r.completed_by WHERE r.process_id = ? ORDER BY r.iteration DESC', [$id]);
$approvals = Db::fetchAll('SELECT a.*, u.name AS decided_by_name FROM approvals a LEFT JOIN users u ON u.id = a.decided_by WHERE a.process_id = ? ORDER BY a.id DESC', [$id]);
$activity = Db::fetchAll("SELECT action, user_name, reason, old_value, new_value, created_at FROM audit_logs WHERE entity_type = 'process' AND entity_id = ? ORDER BY id DESC LIMIT 30", [(string) $id]);
$recSvc = new RecordService();
$recKind = RecordService::kindFor($p);
$records = $recKind ? $recSvc->forProcess($p) : [];
$canRecord = $recKind !== null && $recSvc->canEdit($user, $p) && !$projectClosed;
$comments = (new CommentService())->forProcess($id);
$canComment = Gate::can($user, 'comment.create') && !$projectClosed;
$picChoices = $canPlan ? Db::fetchAll("SELECT u.id, u.name, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND (u.role_id = ? OR r.code = 'admin') ORDER BY r.code = 'admin', u.name", [(int) $p['pic_role_id']]) : [];
$candidates = $canDeps ? $depSvc->candidates($p) : [];
$skipGroup = $p['skip_group'] ? Db::fetchAll('SELECT code, name, name_en FROM processes WHERE project_id = ? AND part_id <=> ? AND skip_group = ? ORDER BY sort_order', [$projectId, $partId, $p['skip_group']]) : [];
$gateParts = [];
if ($canComplete && $p['step_type'] === 'gate') {
    foreach (Db::fetchAll('SELECT id, name, part_type FROM project_parts WHERE project_id = ? AND cancelled_at IS NULL AND start_date IS NOT NULL ORDER BY sort_order, id', [$projectId]) as $pt) {
        $procs = Db::fetchAll("SELECT id, code, name, name_en, is_gate_milestone FROM processes WHERE part_id = ? AND activation = 'auto' ORDER BY sort_order", [(int) $pt['id']]);
        // OQ-09: bawaan dibuka kembali — New Mold: T0 Trial (milestone); Subcont: Trial & Evaluation
        $default = null;
        foreach ($procs as $pr) {
            if (($pt['part_type'] === 'subcont' && $pr['code'] === 'S5') || ($pt['part_type'] !== 'subcont' && (int) $pr['is_gate_milestone'] === 1)) {
                $default = (int) $pr['id'];
            }
        }
        $gateParts[] = ['part' => $pt, 'processes' => $procs, 'default' => $default];
    }
}
$v = static fn (string $k, mixed $d = '') => $failed !== null && isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : $d;
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error">' . e($errors[$k]) . '</p>' : '';
$procLabel = static fn (array $r): string => ($r['part_name'] ?? null ? $r['part_name'] . ' › ' : '') . $r['code'] . ' ' . ProjectQuery::processName($r);

$pageTitle = $p['code'] . ' ' . ProjectQuery::processName($p) . ' — ' . $p['project_code'];
$activeNav = 'projects';
$pageScripts = ['js/process.js'];
require APP_ROOT . '/includes/layout/header.php';
?>
<nav class="breadcrumbs" aria-label="<?= t('common.breadcrumbs') ?>">
  <a href="<?= e(url('projects.php')) ?>"><?= t('project.list_title') ?></a><span class="breadcrumbs-sep">/</span>
  <a href="<?= e(url('project.php', ['id' => $projectId])) ?>" class="mono"><?= e($p['project_code']) ?></a><span class="breadcrumbs-sep">/</span>
  <?php if ($p['part_name']): ?><a href="<?= e(url('project.php', ['id' => $projectId, 'tab' => 'processes'])) ?>#proc-part-<?= (int) $partId ?>"><?= e($p['part_name']) ?></a><span class="breadcrumbs-sep">/</span><?php endif; ?>
  <span><?= e($p['code']) ?></span>
</nav>
<div class="page-header">
  <div>
    <p class="eyebrow"><?= e($p['project_name']) ?><?= $p['part_name'] ? ' · ' . e($p['part_name']) : ' · ' . t('project.project_level') ?></p>
    <h1><?= e($p['code']) ?> · <?= e(ProjectQuery::processName($p)) ?></h1>
    <p>
      <?= status_badge($status) ?>
      <?php if ($overdue > 0): ?><span class="badge badge-danger"><?= t('project.overdue_n', ['days' => $overdue]) ?></span><?php endif; ?>
      <?php if ($held): ?><span class="badge badge-neutral"><?= icon('pause', 'icon icon-sm') ?> <?= t('status.hold') ?></span><?php endif; ?>
      <?php if ((int) $p['iteration'] > 1): ?><span class="badge badge-neutral"><?= t('process.iteration_n', ['n' => (int) $p['iteration']]) ?></span><?php endif; ?>
      <?php if ((int) $p['is_external'] === 1): ?><span class="badge badge-neutral"><?= t('process.external') ?></span><?php endif; ?>
      <?php if ((int) $p['is_customer_approval'] === 1): ?><span class="badge badge-neutral"><?= t('process.customer_approval') ?></span><?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($canStartEarly): ?>
      <form method="post" data-confirm="<?= t('process.start_early_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start_early">
        <button type="submit" class="btn"><?= icon('play') ?> <?= t('process.start_early') ?></button></form>
    <?php endif; ?>
    <?php if ($canSkip): ?><button type="button" class="btn" data-open-dialog="dlg-skip"><?= icon('skip') ?> <?= t('process.skip') ?></button><?php endif; ?>
    <?php if ($canUnskip): ?><button type="button" class="btn" data-open-dialog="dlg-unskip"><?= icon('refresh') ?> <?= t('process.unskip') ?></button><?php endif; ?>
    <?php if ($canManual): ?><button type="button" class="btn btn-ghost" data-open-dialog="dlg-manual"><?= icon('shield') ?> <?= t('process.manual_move') ?></button><?php endif; ?>
  </div>
</div>

<div class="grid grid-2 process-layout">
  <div class="stack">
    <section class="card" aria-labelledby="sec-detail">
      <div class="card-header"><h2 id="sec-detail"><?= t('process.detail') ?></h2></div>
      <div class="card-body">
        <dl class="kv">
          <dt>PIC</dt><dd><?= e($p['pic_user_id'] ? (string) Db::value('SELECT name FROM users WHERE id = ?', [(int) $p['pic_user_id']]) : I18n::t('project.no_pic')) ?> <span class="muted">· <?= role_label((string) $p['pic_role_code']) ?></span></dd>
          <dt><?= t('process.duration') ?></dt><dd><?= $status === 'skipped' ? '0' : (int) $p['duration'] ?> <?= t('process.working_days') ?></dd>
          <dt><?= t('process.planned') ?></dt><dd><?= $p['planned_start'] ? fmt_date($p['planned_start']) . ' – ' . fmt_date($p['planned_finish']) : '–' ?></dd>
          <dt><?= t('process.forecast') ?></dt><dd><?= $p['forecast_start'] ? fmt_date($p['forecast_start']) . ' – ' . fmt_date($p['forecast_finish']) : '–' ?></dd>
          <dt><?= t('process.actual') ?></dt><dd><?= $p['actual_start'] ? fmt_date($p['actual_start']) . ' – ' . ($p['actual_finish'] ? fmt_date($p['actual_finish']) : '…') : '–' ?></dd>
          <?php if ($p['manual_start'] || $p['manual_finish']): ?><dt><?= t('process.manual_dates') ?></dt><dd><?= fmt_date($p['manual_start']) ?> / <?= fmt_date($p['manual_finish']) ?></dd><?php endif; ?>
          <?php if ($p['outcome'] && !in_array($p['outcome'], ['completed', 'submitted', 'resubmitted'], true)): ?>
            <?php $lastOpt = array_values(array_filter($options, static fn ($o) => $o['code'] === $p['outcome']))[0] ?? null; ?>
            <dt><?= t('process.last_outcome') ?></dt><dd><?= e($lastOpt ? $optLabel($lastOpt) : (I18n::has('outcome.' . $p['outcome']) ? I18n::t('outcome.' . $p['outcome']) : $p['outcome'])) ?><?= $p['outcome_comment'] ? ' — ' . e($p['outcome_comment']) : '' ?></dd>
          <?php endif; ?>
          <?php if ($status === 'skipped'): ?><dt><?= t('process.skip_reason') ?></dt><dd><?= e($p['skip_reason']) ?></dd><?php endif; ?>
          <?php if ($p['schedule_warning']): ?><dt><?= t('process.warning') ?></dt><dd><?= I18n::has('sched.warning.' . $p['schedule_warning']) ? t('sched.warning.' . $p['schedule_warning']) : e($p['schedule_warning']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($status === 'not_started' && $p['activation'] === 'loop_only'): ?>
          <p class="flash flash-info small"><?= t('process.loop_only_hint') ?></p>
        <?php elseif ($status === 'not_started'): ?>
          <?php if ($waitingFor): ?>
            <p class="small section-gap"><strong><?= t('process.waiting_for') ?></strong></p>
            <ul class="plain-list small"><?php foreach ($waitingFor as $w): ?><li><a href="<?= e(url('process.php', ['id' => $w['predecessor_id']])) ?>"><?= e($procLabel($w)) ?></a> · <?= status_badge((string) $w['status']) ?> <span class="muted"><?= e($w['dep_type']) ?></span></li><?php endforeach; ?></ul>
          <?php elseif ($p['planned_start'] && $p['planned_start'] > $today): ?>
            <p class="small muted"><?= t('process.scheduled_start', ['date' => I18n::date($p['planned_start'])]) ?></p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($isNprStep): ?><p class="flash flash-info small"><?= t('wf.complete_via_npr') ?> <a href="<?= e(url('npr-edit.php', ['id' => (int) Db::value('SELECT npr_id FROM projects WHERE id = ?', [$projectId])])) ?>"><?= t('project.open_npr') ?></a></p><?php endif; ?>
      </div>
    </section>

    <?php if ($canComplete): ?>
      <section class="card" aria-labelledby="sec-complete">
        <div class="card-header"><h2 id="sec-complete"><?= $p['step_type'] === 'finish' && $partId === null ? t('process.finish_project') : t('process.complete') ?></h2></div>
        <form method="post" class="card-body stack" data-complete-form enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="lock_version" value="<?= (int) $p['lock_version'] ?>">
          <?php if ($ffBlockers): ?><p class="flash flash-warning small"><?= t('wf.ff_blocked', ['list' => implode(', ', array_map(static fn ($b) => $b['code'] . ' ' . $b['name'], $ffBlockers))]) ?></p><?php endif; ?>
          <?php if ($missingDocs): ?><p class="flash flash-warning small"><?= t('wf.missing_docs', ['list' => implode(', ', array_map(static fn ($t) => MasterService::label('document_type', $t), $missingDocs))]) ?></p><?php endif; ?>
          <?php if ($options): ?>
            <fieldset class="field<?= isset($errors['outcome']) ? ' has-error' : '' ?>">
              <legend><?= t('process.outcome') ?></legend>
              <div class="radio-row">
                <?php foreach ($options as $o): ?>
                  <label class="check"><input type="radio" name="outcome" value="<?= e($o['code']) ?>" required data-effect="<?= e($o['effect'] ?? 'continue') ?>"<?= !empty($o['comment_required']) ? ' data-comment-required' : '' ?><?= $v('outcome') === $o['code'] ? ' checked' : '' ?>> <span><?= e($optLabel($o)) ?></span></label>
                <?php endforeach; ?>
              </div>
              <?= $err('outcome') ?>
              <p class="field-hint"><?= t('process.outcome_hint') ?></p>
            </fieldset>
            <?php foreach ($options as $o): ?>
              <?php if (($o['effect'] ?? '') === 'loop' && count((array) ($o['loop_to'] ?? [])) > 1): ?>
                <div class="field" data-when-outcome="<?= e($o['code']) ?>">
                  <label for="loop-to"><?= t('process.loop_to') ?></label>
                  <select class="input" id="loop-to" name="loop_to">
                    <?php foreach ((array) $o['loop_to'] as $code): ?>
                      <?php $tgt = Db::fetch('SELECT name, name_en FROM processes WHERE project_id = ? AND part_id <=> ? AND code = ?', [$projectId, $partId, $code]); ?>
                      <option value="<?= e($code) ?>"><?= e($code . ' ' . ($tgt ? ProjectQuery::processName($tgt) : '')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
              <?php if (($o['effect'] ?? '') === 'gate_fail' && $gateParts): ?>
                <fieldset class="field<?= isset($errors['gate_parts']) ? ' has-error' : '' ?>" data-when-outcome="<?= e($o['code']) ?>">
                  <legend><?= t('process.gate_parts') ?></legend>
                  <p class="field-hint"><?= t('process.gate_parts_hint') ?></p>
                  <?php foreach ($gateParts as $gp): ?>
                    <div class="field">
                      <label for="gp-<?= (int) $gp['part']['id'] ?>"><?= e($gp['part']['name']) ?></label>
                      <select class="input" id="gp-<?= (int) $gp['part']['id'] ?>" name="gate_parts[<?= (int) $gp['part']['id'] ?>]">
                        <option value=""><?= t('process.gate_part_keep') ?></option>
                        <?php foreach ($gp['processes'] as $pr): ?><option value="<?= (int) $pr['id'] ?>"<?= (int) $pr['id'] === $gp['default'] ? ' data-default' : '' ?>><?= e($pr['code'] . ' ' . ProjectQuery::processName($pr)) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                  <?php endforeach; ?>
                  <?= $err('gate_parts') ?>
                </fieldset>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($p['approval_type'] && $p['approval_type'] !== 'npr'): ?>
            <div class="field<?= isset($errors['file']) ? ' has-error' : '' ?>"><label for="evidence"><?= t('approval.evidence') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label>
              <input class="input" type="file" id="evidence" name="evidence"><?= $err('file') ?>
              <p class="field-hint"><?= t('approval.evidence_hint') ?></p></div>
          <?php endif; ?>
          <?php if ($p['approval_giver'] === 'customer'): ?>
            <div class="field"><label for="decision-maker"><?= t('process.decision_maker') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label>
              <input class="input" id="decision-maker" name="decision_maker" maxlength="190" value="<?= e($v('decision_maker')) ?>"></div>
          <?php endif; ?>
          <div class="field<?= isset($errors['comment']) ? ' has-error' : '' ?>">
            <label for="comment"><?= t('process.comment') ?> <span class="muted" data-comment-optional>(<?= t('common.optional') ?>)</span></label>
            <textarea class="input" id="comment" name="comment" rows="3" maxlength="2000"><?= e($v('comment')) ?></textarea>
            <?= $err('comment') ?>
          </div>
          <div class="field<?= isset($errors['actual_finish']) ? ' has-error' : '' ?>">
            <label for="actual-finish"><?= t('process.actual_finish') ?></label>
            <input class="input" type="date" id="actual-finish" name="actual_finish" value="<?= e($v('actual_finish', $today)) ?>" max="<?= e($today) ?>" min="<?= e((string) $p['actual_start']) ?>" required>
            <?= $err('actual_finish') ?>
            <p class="field-hint"><?= t('process.actual_finish_hint') ?></p>
          </div>
          <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $p['step_type'] === 'finish' && $partId === null ? t('process.finish_project') : t('process.complete') ?></button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($recKind !== null): ?>
      <section class="card" aria-labelledby="sec-record" id="sec-record">
        <div class="card-header"><h2 id="sec-record-title"><?= t('record.kind.' . $recKind['kind']) ?></h2><span class="muted small"><?= t('process.iteration_n', ['n' => (int) $p['iteration']]) ?></span></div>
        <div class="card-body stack">
          <?php if ($recKind['table'] === 'material_requests'): ?>
            <?php if ($records): ?>
              <div class="table-wrap">
                <table class="table">
                  <thead><tr><th scope="col"><?= t('record.f.material') ?></th><th scope="col"><?= t('record.f.batch_no') ?></th><th scope="col" class="right"><?= t('record.f.quantity_kg') ?></th><th scope="col"><?= t('record.f.received_date') ?></th><th scope="col"><?= t('record.f.supplier') ?></th><th scope="col">PIC</th><?php if ($canRecord): ?><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th><?php endif; ?></tr></thead>
                  <tbody>
                    <?php foreach ($records as $r): ?>
                      <tr>
                        <td class="small"><?= e($r['material'] ?? '–') ?><?= $r['material_received'] ? '<div class="muted">' . t('record.f.material_received') . ': ' . e($r['material_received']) . '</div>' : '' ?></td>
                        <td class="small"><?= e($r['batch_no'] ?? '–') ?></td><td class="small right"><?= $r['quantity_kg'] !== null ? e(fmt_number($r['quantity_kg'], 3)) : '–' ?></td>
                        <td class="small nowrap"><?= fmt_date($r['received_date']) ?></td><td class="small"><?= e($r['supplier'] ?? '–') ?></td><td class="small"><?= e($r['pic_name'] ?? '–') ?></td>
                        <?php if ($canRecord): ?><td><button type="button" class="icon-btn icon-btn-sm" data-open-dialog="dlg-mat-<?= (int) $r['id'] ?>" aria-label="<?= t('common.edit') ?>"><?= icon('edit', 'icon icon-sm') ?></button></td><?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?><p class="muted small"><?= t('common.empty') ?></p><?php endif; ?>
            <?php if ($canRecord): ?>
              <button type="button" class="btn btn-sm" data-open-dialog="dlg-mat-new"><?= icon('plus', 'icon icon-sm') ?> <?= t('record.add_material') ?></button>
              <?php foreach (array_merge([['id' => 'new']], $records) as $r): ?>
                <dialog class="modal" id="dlg-mat-<?= e((string) $r['id']) ?>" aria-labelledby="dlg-mat-title-<?= e((string) $r['id']) ?>"<?= $failed === 'material' && (string) (Request::post('record_id') ?: 'new') === (string) $r['id'] ? ' data-autoopen' : '' ?>>
                  <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="material"><?php if ($r['id'] !== 'new'): ?><input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>"><?php endif; ?>
                    <div class="modal-header"><h2 id="dlg-mat-title-<?= e((string) $r['id']) ?>"><?= t('record.kind.' . $recKind['kind']) ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
                    <div class="modal-body form-grid">
                      <?php foreach (RecordService::MATERIAL_FIELDS as $f => $rule): ?>
                        <?php $val = $failed === 'material' ? (string) ($_POST['rec'][$f] ?? '') : (string) ($r[$f] ?? ''); $fid = 'mat-' . $r['id'] . '-' . $f; ?>
                        <div class="field<?= $rule === 4000 ? ' span-2' : '' ?><?= isset($errors[$f]) ? ' has-error' : '' ?>"><label for="<?= e($fid) ?>"><?= t('record.f.' . $f) ?></label>
                          <?php if ($rule === 'user'): ?>
                            <select class="input" id="<?= e($fid) ?>" name="rec[<?= $f ?>]"><option value="">–</option><?php foreach (Db::fetchAll("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ('purchasing', 'npd_staff', 'admin') ORDER BY u.name") as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $val === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
                          <?php elseif ($rule === 4000): ?><textarea class="input" id="<?= e($fid) ?>" name="rec[<?= $f ?>]" rows="2" maxlength="4000"><?= e($val) ?></textarea>
                          <?php else: ?><input class="input" id="<?= e($fid) ?>" name="rec[<?= $f ?>]" value="<?= e($val) ?>"<?= $rule === 'date' ? ' type="date"' : ($rule === 'decimal' ? ' inputmode="decimal"' : ' maxlength="' . (int) $rule . '"') ?>><?php endif; ?>
                          <?= $err($f) ?></div>
                      <?php endforeach; ?>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
                  </form>
                </dialog>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php else: ?>
            <?php
            $fields = $recKind['table'] === 'trial_records' ? RecordService::TRIAL_FIELDS : RecordService::VALIDATION_FIELDS;
            $current = array_values(array_filter($records, static fn ($r) => (int) $r['iteration'] === (int) $p['iteration']))[0] ?? [];
            $older = array_values(array_filter($records, static fn ($r) => (int) $r['iteration'] !== (int) $p['iteration']));
            ?>
            <?php if ($canRecord): ?>
              <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="record">
                <?php foreach ($fields as $f => $rule): ?>
                  <?php $val = $failed === 'record' ? (string) ($_POST['rec'][$f] ?? '') : (string) ($current[$f] ?? ''); ?>
                  <div class="field<?= $rule === 4000 ? ' span-2' : '' ?><?= isset($errors[$f]) ? ' has-error' : '' ?>"><label for="rec-<?= $f ?>"><?= t('record.f.' . $f) ?></label>
                    <?php if ($rule === 4000): ?><textarea class="input" id="rec-<?= $f ?>" name="rec[<?= $f ?>]" rows="2" maxlength="4000"><?= e($val) ?></textarea>
                    <?php else: ?><input class="input" id="rec-<?= $f ?>" name="rec[<?= $f ?>]" value="<?= e($val) ?>"<?= $rule === 'date' ? ' type="date"' : ' maxlength="' . (int) $rule . '"' ?>><?php endif; ?>
                    <?= $err($f) ?></div>
                <?php endforeach; ?>
                <div class="field"><label for="rec-result"><?= t('record.f.result') ?></label>
                  <?php if ($recKind['table'] === 'validation_records'): ?>
                    <select class="input" id="rec-result" name="rec[result]"><option value="">–</option><?php foreach (['pass', 'pass_with_condition', 'fail'] as $rv): ?><option value="<?= $rv ?>"<?= ($current['result'] ?? '') === $rv ? ' selected' : '' ?>><?= t('validation.' . $rv) ?></option><?php endforeach; ?></select>
                    <p class="field-hint"><?= t('record.result_hint') ?></p>
                  <?php else: ?><input class="input" id="rec-result" name="rec[result]" maxlength="30" value="<?= e((string) ($current['result'] ?? '')) ?>"><?php endif; ?>
                </div>
                <div class="form-actions span-2"><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
              </form>
            <?php elseif ($current): ?>
              <dl class="kv small"><?php foreach ($fields + ['result' => 30] as $f => $rule): ?><?php if (($current[$f] ?? '') !== '' && $current[$f] !== null): ?><dt><?= t('record.f.' . $f) ?></dt><dd class="pre"><?= e($rule === 'date' ? I18n::date((string) $current[$f]) : (string) $current[$f]) ?></dd><?php endif; ?><?php endforeach; ?></dl>
            <?php else: ?><p class="muted small"><?= t('common.empty') ?></p><?php endif; ?>
            <?php if ($older): ?>
              <details><summary class="btn btn-sm"><?= t('record.previous') ?> (<?= count($older) ?>)</summary>
                <?php foreach ($older as $r): ?>
                  <p class="small"><strong><?= t('process.iteration_n', ['n' => (int) $r['iteration']]) ?></strong> · <?= e($r['updated_by_name'] ?? '') ?></p>
                  <dl class="kv small"><?php foreach ($fields + ['result' => 30] as $f => $rule): ?><?php if (($r[$f] ?? '') !== '' && $r[$f] !== null): ?><dt><?= t('record.f.' . $f) ?></dt><dd class="pre"><?= e($rule === 'date' ? I18n::date((string) $r[$f]) : (string) $r[$f]) ?></dd><?php endif; ?><?php endforeach; ?></dl>
                <?php endforeach; ?>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="sec-docs">
      <div class="card-header"><h2 id="sec-docs"><?= t('process.documents') ?></h2></div>
      <div class="card-body stack">
        <?php if ($required || $suggested): ?>
          <ul class="plain-list small">
            <?php foreach (array_unique(array_merge($required, $suggested)) as $type): ?>
              <?php $has = (bool) array_filter($docs, static fn ($d) => $d['doc_type_code'] === $type); ?>
              <li><?= $has ? icon('check-circle', 'icon icon-sm') : icon('clock', 'icon icon-sm') ?> <?= e(MasterService::label('document_type', $type)) ?>
                <span class="muted">· <?= in_array($type, $required, true) ? t('common.required') : t('process.suggested') ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($docs): ?>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th scope="col"><?= t('process.doc_type') ?></th><th scope="col"><?= t('process.file') ?></th><th scope="col"><?= t('project.version') ?></th><th scope="col"><?= t('process.uploaded') ?></th></tr></thead>
              <tbody>
                <?php foreach ($docs as $d): ?>
                  <tr>
                    <td class="small"><?= e(MasterService::label('document_type', (string) $d['doc_type_code'])) ?></td>
                    <td class="small"><a href="<?= e(url('download.php', ['v' => $d['version_id']])) ?>"><?= icon('download', 'icon icon-sm') ?> <?= e($d['original_name']) ?></a>
                      <?php if (in_array($d['extension'], ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true)): ?> · <a href="<?= e(url('download.php', ['v' => $d['version_id'], 'inline' => 1])) ?>" target="_blank" rel="noopener"><?= t('common.view') ?></a><?php endif; ?>
                      <?php if ($d['notes']): ?><div class="muted"><?= e($d['notes']) ?></div><?php endif; ?></td>
                    <td class="small">v<?= (int) $d['version_no'] ?></td>
                    <td class="small nowrap"><?= fmt_datetime($d['uploaded_at']) ?><div class="muted"><?= e($d['uploader_name']) ?></div></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php elseif (!$required && !$suggested): ?>
          <p class="muted small"><?= t('common.empty') ?></p>
        <?php endif; ?>
        <?php if ($canUpload): ?>
          <form method="post" enctype="multipart/form-data" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload">
            <div class="field<?= isset($errors['doc_type']) ? ' has-error' : '' ?>"><label for="doc-type"><?= t('process.doc_type') ?></label>
              <select class="input" id="doc-type" name="doc_type" required>
                <option value="">– <?= t('process.choose_doc_type') ?> –</option>
                <?php $types = MasterService::options('document_type'); $preferred = array_unique(array_merge($required, $suggested)); ?>
                <?php foreach ($preferred as $type): ?><option value="<?= e($type) ?>"><?= e(MasterService::label('document_type', $type)) ?></option><?php endforeach; ?>
                <?php foreach ($types as $opt): ?><?php if (!in_array($opt['code'], $preferred, true)): ?><option value="<?= e($opt['code']) ?>"><?= e(MasterService::label('document_type', (string) $opt['code'])) ?></option><?php endif; ?><?php endforeach; ?>
              </select><?= $err('doc_type') ?></div>
            <div class="field<?= isset($errors['file']) ? ' has-error' : '' ?>"><label for="doc-file"><?= t('process.file') ?></label>
              <input class="input" type="file" id="doc-file" name="file" required><?= $err('file') ?>
              <p class="field-hint"><?= t('process.upload_hint') ?></p></div>
            <div class="field span-2"><label for="doc-notes"><?= t('process.notes') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label>
              <input class="input" id="doc-notes" name="notes" maxlength="500"></div>
            <div class="form-actions span-2"><button type="submit" class="btn"><?= icon('upload') ?> <?= t('process.upload') ?></button></div>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="stack">
    <?php if ($canPlan): ?>
      <section class="card" aria-labelledby="sec-plan">
        <div class="card-header"><h2 id="sec-plan"><?= t('process.planning') ?></h2></div>
        <form method="post" class="card-body stack" data-preview-form="plan" data-process-id="<?= $id ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="lock_version" value="<?= (int) $p['lock_version'] ?>">
          <div class="form-grid">
            <?php if ($status === 'not_started'): ?>
              <div class="field<?= isset($errors['duration']) ? ' has-error' : '' ?>"><label for="pl-duration"><?= t('process.duration') ?> (<?= t('process.working_days') ?>)</label>
                <input class="input" type="number" min="1" max="365" id="pl-duration" name="plan[duration]" value="<?= e($_POST['plan']['duration'] ?? (string) $p['duration']) ?>"><?= $err('duration') ?></div>
              <div class="field<?= isset($errors['manual_start']) ? ' has-error' : '' ?>"><label for="pl-mstart"><?= t('process.manual_start') ?></label>
                <input class="input" type="date" id="pl-mstart" name="plan[manual_start]" value="<?= e($_POST['plan']['manual_start'] ?? (string) $p['manual_start']) ?>"><?= $err('manual_start') ?>
                <p class="field-hint"><?= t('process.manual_start_hint') ?></p></div>
              <div class="field<?= isset($errors['manual_finish']) ? ' has-error' : '' ?>"><label for="pl-mfinish"><?= t('process.manual_finish') ?></label>
                <input class="input" type="date" id="pl-mfinish" name="plan[manual_finish]" value="<?= e($_POST['plan']['manual_finish'] ?? (string) $p['manual_finish']) ?>"><?= $err('manual_finish') ?>
                <p class="field-hint"><?= t('process.manual_finish_hint') ?></p></div>
            <?php else: ?>
              <div class="field<?= isset($errors['planned_finish']) ? ' has-error' : '' ?>"><label for="pl-pfinish"><?= t('process.planned_finish') ?></label>
                <input class="input" type="date" id="pl-pfinish" name="plan[planned_finish]" value="<?= e($_POST['plan']['planned_finish'] ?? '') ?>" min="<?= e((string) $p['actual_start']) ?>"><?= $err('planned_finish') ?>
                <p class="field-hint"><?= t('process.planned_finish_hint', ['date' => I18n::date($p['planned_finish'])]) ?></p></div>
            <?php endif; ?>
            <div class="field<?= isset($errors['pic_user_id']) ? ' has-error' : '' ?>"><label for="pl-pic">PIC</label>
              <select class="input" id="pl-pic" name="plan[pic_user_id]">
                <option value="">–</option>
                <?php foreach ($picChoices as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $p['pic_user_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?><?= $u['code'] === 'admin' ? ' (Admin)' : '' ?></option><?php endforeach; ?>
              </select><?= $err('pic_user_id') ?></div>
            <div class="field span-2<?= isset($errors['reason']) && $failed === 'plan' ? ' has-error' : '' ?>"><label for="pl-reason"><?= t('common.reason') ?> <span class="muted">(<?= $status === 'not_started' ? t('common.optional') : t('process.reason_if_finish') ?>)</span></label>
              <input class="input" id="pl-reason" name="reason" maxlength="500" value="<?= e($failed === 'plan' ? (string) Request::post('reason') : '') ?>"><?= $failed === 'plan' ? $err('reason') : '' ?></div>
          </div>
          <div class="preview-result" data-preview-result hidden></div>
          <div class="form-actions">
            <button type="button" class="btn" data-preview-button><?= icon('eye') ?> <?= t('process.preview') ?></button>
            <button type="submit" class="btn btn-primary"><?= t('common.save') ?></button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="sec-deps">
      <div class="card-header"><h2 id="sec-deps"><?= t('process.dependencies') ?></h2></div>
      <div class="card-body stack">
        <div>
          <p class="small"><strong><?= t('process.predecessors') ?></strong></p>
          <?php if (!$deps['predecessors']): ?><p class="muted small"><?= $partId !== null ? t('process.no_pred_part') : t('common.none') ?></p><?php endif; ?>
          <ul class="plain-list small">
            <?php foreach ($deps['predecessors'] as $d): ?>
              <li><a href="<?= e(url('process.php', ['id' => $d['predecessor_id']])) ?>"><?= e($procLabel($d)) ?></a> · <span class="mono"><?= e($d['dep_type']) ?><?= $d['dep_type'] !== 'PARALLEL' && (int) $d['lag_days'] !== 0 ? e(sprintf(' %+d', (int) $d['lag_days'])) : '' ?></span> <?= status_badge((string) $d['status']) ?><?= $d['source'] === 'override' ? ' <span class="badge badge-neutral">' . t('process.override') . '</span>' : '' ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div>
          <p class="small"><strong><?= t('process.successors') ?></strong></p>
          <?php if (!$deps['successors']): ?><p class="muted small"><?= t('common.none') ?></p><?php endif; ?>
          <ul class="plain-list small">
            <?php foreach ($deps['successors'] as $d): ?>
              <li><a href="<?= e(url('process.php', ['id' => $d['process_id']])) ?>"><?= e($procLabel($d)) ?></a> · <span class="mono"><?= e($d['dep_type']) ?></span> <?= status_badge((string) $d['status']) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php if ($canDeps): ?>
          <details<?= $failed === 'deps' ? ' open' : '' ?>>
            <summary class="btn btn-sm"><?= icon('link', 'icon icon-sm') ?> <?= t('process.edit_dependencies') ?></summary>
            <form method="post" class="stack" data-preview-form="dependency" data-process-id="<?= $id ?>">
              <?= csrf_field() ?><input type="hidden" name="action" value="deps"><input type="hidden" name="lock_version" value="<?= (int) $p['lock_version'] ?>">
              <p class="muted small"><?= t('process.deps_hint') ?></p>
              <?php $rows = $failed === 'deps' && is_array($_POST['deps'] ?? null) ? array_values($_POST['deps']) : array_map(static fn ($d) => ['predecessor_id' => (string) $d['predecessor_id'], 'type' => $d['dep_type'], 'lag' => (string) $d['lag_days']], $deps['predecessors']); ?>
              <?php $renderRow = static function (int $i, array $row) use ($candidates, $procLabel, $errors): string {
                  ob_start(); ?>
                  <div class="dep-row" data-dep-row>
                    <div class="field"><label class="visually-hidden" for="dep-<?= $i ?>-pred"><?= t('process.predecessor') ?></label>
                      <select class="input" id="dep-<?= $i ?>-pred" name="deps[<?= $i ?>][predecessor_id]">
                        <option value="">– <?= t('process.predecessor') ?> –</option>
                        <?php foreach ($candidates as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) ($row['predecessor_id'] ?? '') === (string) $c['id'] ? ' selected' : '' ?>><?= e($procLabel($c)) ?></option><?php endforeach; ?>
                      </select></div>
                    <div class="field"><label class="visually-hidden" for="dep-<?= $i ?>-type"><?= t('process.dep_type') ?></label>
                      <select class="input" id="dep-<?= $i ?>-type" name="deps[<?= $i ?>][type]">
                        <?php foreach (DependencyService::TYPES as $tp): ?><option value="<?= $tp ?>"<?= ($row['type'] ?? 'FS') === $tp ? ' selected' : '' ?>><?= t('dep_type.' . $tp) ?></option><?php endforeach; ?>
                      </select></div>
                    <div class="field"><label class="visually-hidden" for="dep-<?= $i ?>-lag"><?= t('process.lag') ?></label>
                      <input class="input" type="number" id="dep-<?= $i ?>-lag" name="deps[<?= $i ?>][lag]" min="<?= DependencyService::LAG_MIN ?>" max="<?= DependencyService::LAG_MAX ?>" value="<?= e((string) ($row['lag'] ?? '0')) ?>" aria-describedby="lag-hint"></div>
                    <button type="button" class="icon-btn" data-dep-remove aria-label="<?= t('process.remove_row') ?>"><?= icon('trash') ?></button>
                    <?php if (isset($errors['deps.' . ($i + 1)])): ?><p class="field-error span-all"><?= e($errors['deps.' . ($i + 1)]) ?></p><?php endif; ?>
                  </div>
                  <?php return (string) ob_get_clean();
              }; ?>
              <div class="dep-rows" data-dep-rows>
                <?php foreach ($rows as $i => $row): ?><?= $renderRow($i, $row) ?><?php endforeach; ?>
                <?= $renderRow(count($rows), []) ?>
              </div>
              <template data-dep-template><?= $renderRow(999, []) ?></template>
              <p class="field-hint" id="lag-hint"><?= t('process.lag_hint') ?></p>
              <button type="button" class="btn btn-sm" data-dep-add><?= icon('plus', 'icon icon-sm') ?> <?= t('process.add_row') ?></button>
              <div class="field"><label for="dep-reason"><?= t('common.reason') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label>
                <input class="input" id="dep-reason" name="reason" maxlength="500"></div>
              <div class="preview-result" data-preview-result hidden></div>
              <div class="form-actions">
                <button type="button" class="btn" data-preview-button><?= icon('eye') ?> <?= t('process.preview') ?></button>
                <button type="submit" class="btn btn-primary"><?= t('common.save') ?></button>
              </div>
            </form>
          </details>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="sec-runs">
      <div class="card-header"><h2 id="sec-runs"><?= t('process.iterations') ?></h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">#</th><th scope="col"><?= t('process.planned_finish_at_activation') ?></th><th scope="col"><?= t('process.actual') ?></th><th scope="col"><?= t('process.outcome') ?></th><th scope="col">PIC</th></tr></thead>
          <tbody>
            <?php if (!$runs): ?><tr><td colspan="5" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
            <?php foreach ($runs as $r): ?>
              <tr>
                <td><?= (int) $r['iteration'] ?></td>
                <td class="small nowrap"><?= fmt_date($r['planned_finish_at_activation']) ?></td>
                <td class="small nowrap"><?= $r['actual_start'] ? fmt_date($r['actual_start']) . ' – ' . ($r['actual_finish'] ? fmt_date($r['actual_finish']) : '…') : '–' ?></td>
                <td class="small"><?= status_badge((string) $r['status'], 'run') ?> <?= e($r['outcome'] && !in_array($r['outcome'], ['completed', 'reset', 'skipped'], true) ? $r['outcome'] : '') ?></td>
                <td class="small"><?= e($r['pic_name'] ?? '–') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <?php if ($approvals): ?>
      <section class="card" aria-labelledby="sec-approvals">
        <div class="card-header"><h2 id="sec-approvals"><?= t('process.approvals') ?></h2></div>
        <div class="card-body">
          <ul class="plain-list">
            <?php foreach ($approvals as $a): ?>
              <li><span class="mono small"><?= e($a['code']) ?></span> <?= status_badge((string) $a['status'], 'approval') ?> · <?= t('process.iteration_n', ['n' => (int) $a['iteration']]) ?>
                <?php if ($a['decision_maker_name']): ?><span class="small"> · <?= e($a['decision_maker_name']) ?></span><?php endif; ?>
                <?php if ($a['comment']): ?><div class="small"><?= e($a['comment']) ?></div><?php endif; ?>
                <div class="timeline-meta"><?= fmt_datetime($a['decided_at']) ?> · <?= e($a['decided_by_name'] ?? '') ?></div></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="sec-comments-title" id="sec-comments">
      <div class="card-header"><h2 id="sec-comments-title"><?= t('comment.title') ?></h2></div>
      <div class="card-body stack">
        <?php if (!$comments): ?><p class="muted small"><?= t('comment.empty') ?></p><?php endif; ?>
        <ol class="timeline-list">
          <?php foreach ($comments as $c): ?>
            <li><div class="small pre"><?= e($c['body']) ?></div><div class="timeline-meta"><?= fmt_datetime($c['created_at']) ?> · <?= e($c['user_name'] ?? '') ?></div></li>
          <?php endforeach; ?>
        </ol>
        <?php if ($canComment): ?>
          <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="comment">
            <div class="field<?= isset($errors['body']) ? ' has-error' : '' ?>"><label for="comment-body" class="visually-hidden"><?= t('comment.title') ?></label>
              <textarea class="input" id="comment-body" name="body" rows="2" maxlength="4000" required placeholder="<?= t('comment.placeholder') ?>"></textarea><?= $err('body') ?></div>
            <div class="form-actions"><button type="submit" class="btn btn-sm"><?= t('comment.send') ?></button></div>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="sec-activity">
      <div class="card-header"><h2 id="sec-activity"><?= t('process.activity') ?></h2></div>
      <div class="card-body">
        <?php if (!$activity): ?><p class="muted small"><?= t('common.empty') ?></p><?php endif; ?>
        <ol class="timeline-list">
          <?php foreach ($activity as $a): ?>
            <li><div class="small"><?= I18n::has('audit.action.' . $a['action']) ? t('audit.action.' . $a['action']) : e($a['action']) ?></div>
              <?php if ($a['reason']): ?><div class="small"><?= t('common.reason') ?>: <?= e($a['reason']) ?></div><?php endif; ?>
              <div class="timeline-meta"><?= fmt_datetime($a['created_at']) ?> · <?= e($a['user_name'] ?? 'Sistem') ?></div></li>
          <?php endforeach; ?>
        </ol>
      </div>
    </section>
  </div>
</div>

<?php if ($canSkip): ?>
  <dialog class="modal" id="dlg-skip" aria-labelledby="dlg-skip-title"<?= $failed === 'skip' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="skip">
      <div class="modal-header"><h2 id="dlg-skip-title"><?= t('process.skip') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body stack">
        <p class="small"><?= t('process.skip_hint') ?></p>
        <?php if (count($skipGroup) > 1): ?><p class="small"><strong><?= t('process.skip_group') ?></strong> <?= e(implode(', ', array_map(static fn ($g) => $g['code'] . ' ' . ProjectQuery::processName($g), $skipGroup))) ?></p><?php endif; ?>
        <div class="field<?= isset($errors['reason']) ? ' has-error' : '' ?>"><label for="skip-reason"><?= t('common.reason_required') ?></label>
          <textarea class="input" id="skip-reason" name="reason" rows="3" required maxlength="2000"></textarea><?= $err('reason') ?></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('process.skip') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php if ($canUnskip): ?>
  <dialog class="modal" id="dlg-unskip" aria-labelledby="dlg-unskip-title">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="unskip">
      <div class="modal-header"><h2 id="dlg-unskip-title"><?= t('process.unskip') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body stack"><p class="small"><?= t('process.unskip_hint') ?></p>
        <div class="field"><label for="unskip-reason"><?= t('common.reason') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label><textarea class="input" id="unskip-reason" name="reason" rows="2" maxlength="2000"></textarea></div></div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('process.unskip') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php if ($canManual): ?>
  <dialog class="modal" id="dlg-manual" aria-labelledby="dlg-manual-title"<?= $failed === 'manual_move' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="manual_move">
      <div class="modal-header"><h2 id="dlg-manual-title"><?= t('process.manual_move') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body stack">
        <p class="small muted"><?= t('process.manual_move_hint') ?></p>
        <div class="field"><label for="mm-target"><?= t('process.move_to') ?></label>
          <select class="input" id="mm-target" name="target">
            <?php foreach (['current', 'completed', 'not_started'] as $tg): ?><?php if ($tg !== $status): ?><option value="<?= $tg ?>"><?= t('status.' . $tg) ?></option><?php endif; ?><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="mm-start"><?= t('process.manual_start') ?> <span class="muted">(<?= t('process.only_not_started') ?>)</span></label><input class="input" type="date" id="mm-start" name="manual_start"></div>
        <div class="field<?= isset($errors['reason']) ? ' has-error' : '' ?>"><label for="mm-reason"><?= t('common.reason_required') ?></label><textarea class="input" id="mm-reason" name="reason" rows="3" required maxlength="2000"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-danger"><?= t('process.manual_move') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<template id="preview-labels" data-process="<?= t('process.process') ?>" data-old="<?= t('project.old') ?>" data-new="<?= t('project.new') ?>" data-shift="<?= t('project.shift') ?>"
  data-none="<?= t('process.preview_none') ?>" data-forecast="<?= t('process.preview_forecast') ?>" data-risk="<?= t('project.past_target') ?>" data-title="<?= t('process.preview_title') ?>"></template>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
