<?php
declare(strict_types=1);

/**
 * Pengaturan Workflow (PRD §5.8, §5.5) — hanya Admin. Perubahan dibuat pada draf berversi, diterbitkan,
 * lalu dapat diterapkan ke proses yang belum dimulai pada part berjalan (dengan pratinjau).
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Approval\ApprovalService;
use App\Calendar\CalendarService;
use App\Core\AppException;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Master\MasterService;
use App\Record\RecordService;
use App\Workflow\WorkflowTemplateService;

$actor = require_permission('settings.manage');
$svc = new WorkflowTemplateService();
$templateId = Request::int('template');
$stepId = Request::int('step');
$applyView = Request::query('apply') === 'preview';
$errors = [];

if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $tid = (int) Request::int('template', 0);
    $vid = (int) Request::int('version', 0);
    $back = url('settings/workflow.php', ['template' => $tid]);
    try {
        switch ($action) {
            case 'draft':
                $svc->draftFor($actor, $tid);
                Session::flash('success', I18n::t('wft.draft_created'));
                break;
            case 'update_step':
                $sid = (int) Request::int('step', 0);
                $in = $_POST;
                foreach (['is_mandatory', 'is_skippable', 'is_external', 'is_customer_approval', 'is_active'] as $k) {
                    $in[$k] = isset($_POST[$k]) ? '1' : '';
                }
                foreach (['executor_roles', 'uploader_roles', 'required_doc_types', 'suggested_doc_types'] as $k) {
                    $in[$k] = is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
                }
                if (is_array($_POST['options'] ?? null)) {
                    foreach ($in['options'] as $i => $o) {
                        $in['options'][$i]['comment_required'] = !empty($o['comment_required']);
                    }
                }
                // atribut & dependency disimpan dalam satu transaksi (gagal salah satu → tidak ada yang tersimpan)
                Db::transaction(static function () use ($svc, $actor, $vid, $sid, $in): void {
                    $svc->updateStep($actor, $vid, $sid, $in);
                    $svc->setDependencies($actor, $vid, $sid, is_array($_POST['deps'] ?? null) ? $_POST['deps'] : [], (string) Request::post('dep_reason', ''));
                });
                Session::flash('success', I18n::t('common.saved'));
                $back = url('settings/workflow.php', ['template' => $tid, 'step' => $sid]);
                break;
            case 'add_step':
                $sid = $svc->addStep($actor, $vid, $_POST);
                Session::flash('success', I18n::t('wft.step_added'));
                $back = url('settings/workflow.php', ['template' => $tid, 'step' => $sid]);
                break;
            case 'remove_step':
                $svc->removeStep($actor, $vid, (int) Request::int('step', 0));
                Session::flash('success', I18n::t('wft.step_removed'));
                break;
            case 'move_up':
            case 'move_down':
                $svc->moveStep($actor, $vid, (int) Request::int('step', 0), $action === 'move_up' ? -1 : 1);
                break;
            case 'publish':
                $no = $svc->publish($actor, $vid, (string) Request::post('notes', ''));
                Session::flash('success', I18n::t('wft.published', ['version' => $no]));
                break;
            case 'discard':
                $svc->discard($actor, $vid);
                Session::flash('success', I18n::t('wft.discarded'));
                break;
            case 'apply':
                $n = $svc->apply($actor, $tid, array_map('intval', is_array($_POST['parts'] ?? null) ? $_POST['parts'] : []));
                Session::flash('success', I18n::t('wft.applied', ['count' => $n]));
                break;
            default:
                Response::error(400, I18n::t('validation.invalid'));
        }
        Response::redirect($back);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        http_response_code(422);
        Session::flash('error', implode(' ', $errors));
        if ($action === 'update_step') {
            $stepId = (int) Request::int('step', 0);
        }
    } catch (AppException $e) {
        if ($e->httpStatus() === 403) {
            throw $e;
        }
        Session::flash('error', $e->getMessage());
        Response::redirect($back . ($action === 'update_step' ? '&step=' . (int) Request::int('step', 0) : ''));
    }
}

$roles = Db::fetchAll("SELECT code FROM roles WHERE code <> 'management' ORDER BY id");
$roleCodes = array_column($roles, 'code');
$docTypes = MasterService::options('document_type');

if ($templateId !== null) {
    // dimuat sebelum layout agar template yang tidak ada → 404 sungguhan
    $tpl = $svc->template($templateId);
    $draftId = Db::value("SELECT id FROM workflow_template_versions WHERE template_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$templateId]);
    $versionId = $draftId ? (int) $draftId : (int) $tpl['current_version_id'];
    $version = $svc->version($versionId);
    $isDraft = $version['status'] === 'draft';
    $steps = $svc->steps($versionId);
    $byId = array_column($steps, null, 'id');
    $codes = array_column($steps, 'code');
    $predChoices = array_merge($codes, $tpl['scope'] === 'part' ? ['P2', 'G1'] : []);
    $problems = $isDraft ? $svc->validate($versionId) : [];
    $stepLabel = static fn (array $s): string => $s['code'] . ' · ' . (I18n::locale() === 'en' && $s['name_en'] ? $s['name_en'] : $s['name']);
}
$pageTitle = I18n::t('nav.workflow');
$activeNav = 'workflow';
require APP_ROOT . '/includes/layout/header.php';
?>
<?php if ($templateId === null): ?>
<?php $templates = $svc->templates(); ?>
<div class="page-header">
  <div><h1><?= t('nav.workflow') ?></h1><p><?= t('wft.subtitle') ?></p></div>
</div>
<div class="grid grid-3">
  <?php foreach ($templates as $tp): ?>
    <section class="card" aria-labelledby="tpl-<?= (int) $tp['id'] ?>">
      <div class="card-header"><h2 id="tpl-<?= (int) $tp['id'] ?>"><?= e($tp['name']) ?></h2><span class="badge badge-neutral mono"><?= e($tp['code']) ?></span></div>
      <div class="card-body">
        <dl class="kv small">
          <dt><?= t('wft.current_version') ?></dt><dd>v<?= (int) $tp['current_no'] ?><?= $tp['published_at'] ? ' · ' . fmt_date(substr((string) $tp['published_at'], 0, 10)) : '' ?></dd>
          <dt><?= t('wft.active_steps') ?></dt><dd><?= (int) $tp['active_steps'] ?></dd>
          <dt><?= t('wft.draft') ?></dt><dd><?= $tp['draft_id'] ? '<span class="badge badge-warning">' . t('wft.draft_open') . '</span>' : '–' ?></dd>
          <?php if ($tp['scope'] === 'part'): ?><dt><?= t('wft.outdated_parts') ?></dt><dd><?= (int) $tp['outdated_parts'] ?></dd><?php endif; ?>
        </dl>
      </div>
      <div class="card-footer"><a class="btn btn-sm btn-primary" href="<?= e(url('settings/workflow.php', ['template' => $tp['id']])) ?>"><?= icon('edit', 'icon icon-sm') ?> <?= t('wft.open') ?></a></div>
    </section>
  <?php endforeach; ?>
</div>

<?php else: ?>
<nav class="breadcrumbs" aria-label="<?= t('common.breadcrumbs') ?>">
  <a href="<?= e(url('settings/workflow.php')) ?>"><?= t('nav.workflow') ?></a><span class="breadcrumbs-sep">/</span>
  <?php if ($stepId || $applyView): ?><a href="<?= e(url('settings/workflow.php', ['template' => $templateId])) ?>"><?= e($tpl['name']) ?></a><span class="breadcrumbs-sep">/</span><span aria-current="page"><?= $applyView ? t('wft.apply_title') : e($stepLabel($byId[$stepId] ?? ['code' => '', 'name' => '', 'name_en' => ''])) ?></span>
  <?php else: ?><span aria-current="page"><?= e($tpl['name']) ?></span><?php endif; ?>
</nav>
<div class="page-header">
  <div>
    <p class="eyebrow mono"><?= e($tpl['code']) ?> · v<?= (int) $version['version_no'] ?> · <?= t('wft.status.' . $version['status']) ?></p>
    <h1><?= e($tpl['name']) ?></h1>
    <p><?= $isDraft ? t('wft.draft_hint') : t('wft.published_hint') ?></p>
  </div>
  <div class="page-actions">
    <?php if (!$isDraft): ?>
      <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="draft"><input type="hidden" name="template" value="<?= $templateId ?>">
        <button type="submit" class="btn btn-primary"><?= icon('edit') ?> <?= t('wft.create_draft') ?></button></form>
      <?php if ($tpl['scope'] === 'part' && !$applyView): ?><a class="btn" href="<?= e(url('settings/workflow.php', ['template' => $templateId, 'apply' => 'preview'])) ?>"><?= icon('refresh') ?> <?= t('wft.apply_btn') ?></a><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($applyView): ?>
  <?php $preview = $svc->previewApply($actor, $templateId); ?>
  <section class="card">
    <div class="card-header"><h2><?= t('wft.apply_title') ?></h2></div>
    <div class="card-body"><p class="small muted"><?= t('wft.apply_hint', ['version' => (int) $version['version_no']]) ?></p></div>
    <?php if (!$preview): ?>
      <div class="card-body"><p class="muted"><?= t('wft.apply_nothing') ?></p></div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="template" value="<?= $templateId ?>">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th scope="col"><span class="visually-hidden"><?= t('wft.apply_select') ?></span></th><th scope="col"><?= t('project.project') ?></th><th scope="col"><?= t('project.part') ?></th>
              <th scope="col"><?= t('wft.from_version') ?></th><th scope="col"><?= t('wft.changes') ?></th><th scope="col" class="nowrap"><?= t('project.forecast_finish') ?></th><th scope="col" class="right"><?= t('wft.shifted') ?></th></tr></thead>
            <tbody>
              <?php foreach ($preview as $pv): ?>
                <tr<?= $pv['target'] && $pv['forecast_new'] > $pv['target'] ? ' class="row-warning"' : '' ?>>
                  <td><input type="checkbox" name="parts[]" value="<?= (int) $pv['part']['id'] ?>" checked aria-label="<?= e($pv['part']['project_code'] . ' ' . $pv['part']['name']) ?>"></td>
                  <td class="mono small nowrap"><a href="<?= e(url('project.php', ['id' => $pv['part']['project_id']])) ?>"><?= e($pv['part']['project_code']) ?></a></td>
                  <td class="small"><?= e($pv['part']['name']) ?></td>
                  <td class="small">v<?= (int) $pv['part']['version_no'] ?></td>
                  <td class="small">
                    <?php foreach ($pv['plan']['changes'] as $c): ?><div><strong><?= e($c['code']) ?></strong> <?= e(implode(', ', array_map(static fn ($k) => I18n::t('wft.field.' . $k), array_keys($c['diff'])))) ?>
                      <?php if (isset($c['diff']['duration'])): ?><span class="muted">(<?= (int) $c['diff']['duration'][0] ?> → <?= (int) $c['diff']['duration'][1] ?> <?= t('wft.wd') ?>)</span><?php endif; ?></div><?php endforeach; ?>
                    <?php foreach ($pv['plan']['add'] as $a): ?><div><span class="badge badge-accent"><?= t('wft.added') ?></span> <?= e($a['code'] . ' ' . $a['name']) ?></div><?php endforeach; ?>
                    <?php foreach ($pv['plan']['skip'] as $sk): ?><div><span class="badge badge-neutral"><?= t('wft.deactivated') ?></span> <?= e($sk['code'] . ' ' . $sk['name']) ?></div><?php endforeach; ?>
                  </td>
                  <td class="small nowrap"><?= fmt_date($pv['forecast_old']) ?> → <strong><?= fmt_date($pv['forecast_new']) ?></strong><?= $pv['target'] && $pv['forecast_new'] > $pv['target'] ? '<div class="text-danger">' . t('project.past_target') . '</div>' : '' ?></td>
                  <td class="right small"><?= (int) $pv['shifted'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer"><a class="btn" href="<?= e(url('settings/workflow.php', ['template' => $templateId])) ?>"><?= t('common.cancel') ?></a>
          <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= t('wft.apply_confirm') ?></button></div>
      </form>
    <?php endif; ?>
  </section>

<?php elseif ($stepId && isset($byId[$stepId])): ?>
  <?php $s = $byId[$stepId]; $ro = !$isDraft; $structural = in_array($s['step_type'], WorkflowTemplateService::STRUCTURAL, true); ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="update_step"><input type="hidden" name="template" value="<?= $templateId ?>"><input type="hidden" name="version" value="<?= $versionId ?>"><input type="hidden" name="step" value="<?= $stepId ?>">
    <fieldset class="card"<?= $ro ? ' disabled' : '' ?>>
      <legend class="visually-hidden"><?= t('wft.step_attributes') ?></legend>
      <div class="card-header"><h2><?= e($stepLabel($s)) ?></h2><span><?= (int) $s['is_builtin'] === 1 ? '<span class="badge badge-neutral">' . t('wft.builtin') . '</span>' : '<span class="badge badge-accent">' . t('wft.custom') . '</span>' ?></span></div>
      <div class="card-body form-grid">
        <div class="field"><label for="s-name"><?= t('wft.name') ?></label><input class="input" id="s-name" name="name" required maxlength="160" value="<?= e($s['name']) ?>"></div>
        <div class="field"><label for="s-name-en"><?= t('wft.name_en') ?></label><input class="input" id="s-name-en" name="name_en" maxlength="160" value="<?= e((string) $s['name_en']) ?>"></div>
        <div class="field"><label for="s-short"><?= t('wft.short_name') ?></label><input class="input" id="s-short" name="short_name" maxlength="40" value="<?= e((string) $s['short_name']) ?>"></div>
        <div class="field"><label for="s-type"><?= t('wft.type') ?></label>
          <select class="input" id="s-type" name="step_type"<?= (int) $s['is_builtin'] === 1 ? ' disabled' : '' ?>>
            <?php foreach ((int) $s['is_builtin'] === 1 ? [$s['step_type']] : WorkflowTemplateService::CUSTOM_TYPES as $ty): ?><option value="<?= e($ty) ?>"<?= $s['step_type'] === $ty ? ' selected' : '' ?>><?= t('wft.type.' . $ty) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field span-2"><label for="s-desc"><?= t('wft.description') ?></label><textarea class="input" id="s-desc" name="description" rows="2" maxlength="500"><?= e((string) $s['description']) ?></textarea></div>
        <div class="field"><label for="s-role"><?= t('wft.pic_role') ?></label>
          <select class="input" id="s-role" name="pic_role"><?php foreach ($roleCodes as $rc): ?><option value="<?= e($rc) ?>"<?= $s['pic_role_code'] === $rc ? ' selected' : '' ?>><?= role_label($rc) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s-dur"><?= t('wft.duration') ?></label><input class="input" type="number" min="1" max="365" id="s-dur" name="default_duration" value="<?= (int) $s['default_duration'] ?>"></div>
        <fieldset class="field"><legend><?= t('wft.executor_roles') ?></legend><div class="check-grid">
          <?php foreach ($roleCodes as $rc): ?><label class="check"><input type="checkbox" name="executor_roles[]" value="<?= e($rc) ?>"<?= in_array($rc, $s['executor_roles'], true) ? ' checked' : '' ?>> <span><?= role_label($rc) ?></span></label><?php endforeach; ?></div></fieldset>
        <fieldset class="field"><legend><?= t('wft.uploader_roles') ?></legend><div class="check-grid">
          <?php foreach ($roleCodes as $rc): ?><label class="check"><input type="checkbox" name="uploader_roles[]" value="<?= e($rc) ?>"<?= in_array($rc, $s['uploader_roles'], true) ? ' checked' : '' ?>> <span><?= role_label($rc) ?></span></label><?php endforeach; ?></div></fieldset>
        <div class="field span-2 check-grid">
          <label class="check"><input type="checkbox" name="is_mandatory" value="1"<?= (int) $s['is_mandatory'] === 1 ? ' checked' : '' ?>> <span><?= t('wft.mandatory') ?></span></label>
          <label class="check"><input type="checkbox" name="is_skippable" value="1"<?= (int) $s['is_skippable'] === 1 ? ' checked' : '' ?>> <span><?= t('wft.skippable') ?></span></label>
          <label class="check"><input type="checkbox" name="is_external" value="1"<?= (int) $s['is_external'] === 1 ? ' checked' : '' ?>> <span><?= t('wft.external') ?></span></label>
          <label class="check"><input type="checkbox" name="is_customer_approval" value="1"<?= (int) $s['is_customer_approval'] === 1 ? ' checked' : '' ?>> <span><?= t('wft.customer_approval') ?></span></label>
          <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $s['is_active'] === 1 ? ' checked' : '' ?><?= $structural ? ' disabled checked' : '' ?>> <span><?= t('wft.active') ?></span></label>
          <?php if ($structural): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
        </div>
        <div class="field"><label for="s-group"><?= t('wft.skip_group') ?></label><input class="input mono" id="s-group" name="skip_group" maxlength="30" value="<?= e((string) $s['skip_group']) ?>"><p class="field-hint"><?= t('wft.skip_group_hint') ?></p></div>
        <div class="field"><label for="s-cal"><?= t('wft.calendar_category') ?></label>
          <select class="input" id="s-cal" name="calendar_category"><option value="">–</option><?php foreach (array_diff(CalendarService::CATEGORIES, ['agenda']) as $c): ?><option value="<?= e($c) ?>"<?= $s['calendar_category'] === $c ? ' selected' : '' ?>><?= t('calendar.cat.' . $c) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s-appr"><?= t('wft.approval_type') ?></label>
          <select class="input" id="s-appr" name="approval_type"><option value="">–</option><?php foreach (ApprovalService::TYPES as $at): ?><option value="<?= e($at) ?>"<?= $s['approval_type'] === $at ? ' selected' : '' ?>><?= t('approval.type.' . $at) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s-giver"><?= t('wft.approval_giver') ?></label>
          <select class="input" id="s-giver" name="approval_giver"><option value="">–</option><?php foreach (['customer', 'internal'] as $gv): ?><option value="<?= $gv ?>"<?= $s['approval_giver'] === $gv ? ' selected' : '' ?>><?= t('approval.giver.' . $gv) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s-rec"><?= t('wft.record_type') ?></label>
          <select class="input" id="s-rec" name="record_type"><option value="">–</option><?php foreach (array_keys(RecordService::KINDS) as $rk): ?><option value="<?= e($rk) ?>"<?= $s['record_type'] === $rk ? ' selected' : '' ?>><?= t('wft.record.' . $rk) ?></option><?php endforeach; ?></select></div>
        <fieldset class="field span-2"><legend><?= t('wft.required_docs') ?></legend><div class="check-grid">
          <?php foreach ($docTypes as $dt): ?><label class="check"><input type="checkbox" name="required_doc_types[]" value="<?= e($dt['code']) ?>"<?= in_array($dt['code'], $s['required_doc_types'], true) ? ' checked' : '' ?>> <span><?= e(MasterService::label('document_type', (string) $dt['code'])) ?></span></label><?php endforeach; ?></div></fieldset>
        <fieldset class="field span-2"><legend><?= t('wft.suggested_docs') ?></legend><div class="check-grid">
          <?php foreach ($docTypes as $dt): ?><label class="check"><input type="checkbox" name="suggested_doc_types[]" value="<?= e($dt['code']) ?>"<?= in_array($dt['code'], $s['suggested_doc_types'], true) ? ' checked' : '' ?>> <span><?= e(MasterService::label('document_type', (string) $dt['code'])) ?></span></label><?php endforeach; ?></div></fieldset>
      </div>
    </fieldset>

    <?php if ($s['decision_options']): ?>
      <fieldset class="card"<?= $ro ? ' disabled' : '' ?>>
        <legend class="visually-hidden"><?= t('wft.decisions') ?></legend>
        <div class="card-header"><h2><?= t('wft.decisions') ?></h2></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th scope="col"><?= t('wft.option_code') ?></th><th scope="col"><?= t('wft.label_id') ?></th><th scope="col"><?= t('wft.label_en') ?></th><th scope="col"><?= t('wft.effect') ?></th><th scope="col"><?= t('wft.loop_to') ?></th><th scope="col"><?= t('wft.comment_required') ?></th></tr></thead>
            <tbody>
              <?php foreach ($s['decision_options'] as $i => $o): ?>
                <tr>
                  <td class="mono small"><?= e($o['code']) ?></td>
                  <td><label class="visually-hidden" for="o-id-<?= $i ?>"><?= t('wft.label_id') ?></label><input class="input input-sm" id="o-id-<?= $i ?>" name="options[<?= $i ?>][label_id]" maxlength="60" value="<?= e((string) ($o['label_id'] ?? '')) ?>"></td>
                  <td><label class="visually-hidden" for="o-en-<?= $i ?>"><?= t('wft.label_en') ?></label><input class="input input-sm" id="o-en-<?= $i ?>" name="options[<?= $i ?>][label_en]" maxlength="60" value="<?= e((string) ($o['label_en'] ?? '')) ?>"></td>
                  <td class="small"><?= t('wft.effect.' . ($o['effect'] ?? 'continue')) ?><?= isset($o['activate']) ? ' · ' . e($o['activate'] . ' → ' . ($o['reopen'] ?? '')) : '' ?></td>
                  <td><?php if (($o['effect'] ?? null) === 'loop'): ?>
                    <label class="visually-hidden" for="o-loop-<?= $i ?>"><?= t('wft.loop_to') ?></label>
                    <select class="input input-sm" id="o-loop-<?= $i ?>" name="options[<?= $i ?>][loop_to]"><?php foreach ($steps as $st): ?><option value="<?= e($st['code']) ?>"<?= (($o['loop_to'] ?? [])[0] ?? null) === $st['code'] ? ' selected' : '' ?>><?= e($stepLabel($st)) ?></option><?php endforeach; ?></select>
                  <?php else: ?><span class="muted">–</span><?php endif; ?></td>
                  <td><input type="checkbox" name="options[<?= $i ?>][comment_required]" value="1"<?= !empty($o['comment_required']) ? ' checked' : '' ?> aria-label="<?= t('wft.comment_required') ?>"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </fieldset>
    <?php endif; ?>

    <fieldset class="card"<?= $ro ? ' disabled' : '' ?>>
      <legend class="visually-hidden"><?= t('wft.dependencies') ?></legend>
      <div class="card-header"><h2><?= t('wft.dependencies') ?></h2></div>
      <div class="card-body"><p class="small muted"><?= t('wft.deps_hint') ?></p></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col"><?= t('wft.predecessor') ?></th><th scope="col"><?= t('wft.dep_type') ?></th><th scope="col"><?= t('wft.lag') ?></th><?php if ($tpl['scope'] === 'part'): ?><th scope="col"><?= t('wft.only_when_gate') ?></th><?php endif; ?></tr></thead>
          <tbody>
            <?php $rows = array_merge($s['deps'], array_fill(0, 3, ['predecessor_code' => '', 'dep_type' => 'FS', 'lag_days' => 0, 'only_when_gate' => 0])); ?>
            <?php foreach ($rows as $i => $d): ?>
              <tr<?= isset($errors['deps.' . $i]) ? ' class="row-danger"' : '' ?>>
                <td><label class="visually-hidden" for="d-pred-<?= $i ?>"><?= t('wft.predecessor') ?></label>
                  <select class="input input-sm" id="d-pred-<?= $i ?>" name="deps[<?= $i ?>][predecessor_code]"><option value="">–</option>
                    <?php foreach ($predChoices as $pc): ?><?php if ($pc === $s['code']) { continue; } ?><option value="<?= e($pc) ?>"<?= $d['predecessor_code'] === $pc ? ' selected' : '' ?>><?= e(isset(array_column($steps, null, 'code')[$pc]) ? $stepLabel(array_column($steps, null, 'code')[$pc]) : $pc . ' · ' . I18n::t('project.project_level')) ?></option><?php endforeach; ?>
                  </select><?php if (isset($errors['deps.' . $i])): ?><p class="field-error"><?= e($errors['deps.' . $i]) ?></p><?php endif; ?></td>
                <td><label class="visually-hidden" for="d-type-<?= $i ?>"><?= t('wft.dep_type') ?></label>
                  <select class="input input-sm" id="d-type-<?= $i ?>" name="deps[<?= $i ?>][dep_type]"><?php foreach (WorkflowTemplateService::DEP_TYPES as $dt): ?><option value="<?= $dt ?>"<?= $d['dep_type'] === $dt ? ' selected' : '' ?>><?= t('dep_type.' . $dt) ?></option><?php endforeach; ?></select></td>
                <td><label class="visually-hidden" for="d-lag-<?= $i ?>"><?= t('wft.lag') ?></label><input class="input input-sm" type="number" min="-30" max="30" id="d-lag-<?= $i ?>" name="deps[<?= $i ?>][lag_days]" value="<?= (int) $d['lag_days'] ?>" style="width: 80px"></td>
                <?php if ($tpl['scope'] === 'part'): ?><td><input type="checkbox" name="deps[<?= $i ?>][only_when_gate]" value="1"<?= (int) $d['only_when_gate'] === 1 ? ' checked' : '' ?> aria-label="<?= t('wft.only_when_gate') ?>"></td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body"><div class="field"><label for="d-reason"><?= t('wft.dep_reason') ?></label><input class="input" id="d-reason" name="dep_reason" maxlength="500"></div></div>
    </fieldset>
    <div class="form-actions">
      <a class="btn" href="<?= e(url('settings/workflow.php', ['template' => $templateId])) ?>"><?= t('common.back') ?></a>
      <?php if ($isDraft): ?><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button><?php endif; ?>
    </div>
  </form>

<?php else: ?>
  <?php if ($isDraft && $problems): ?><div class="flash flash-warning" role="status"><?= icon('alert') ?><div><strong><?= t('wft.problems') ?></strong><?php foreach ($problems as $pr): ?><div class="small"><?= e($pr) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <section class="card section">
    <div class="card-header"><h2><?= t('wft.steps') ?></h2><span class="muted small"><?= t('wft.steps_count', ['count' => count($steps)]) ?></span></div>
    <div class="table-wrap">
      <table class="table" data-wft-steps>
        <thead><tr><th scope="col"><?= t('wft.step') ?></th><th scope="col"><?= t('wft.type') ?></th><th scope="col"><?= t('wft.pic_role') ?></th><th scope="col" class="right"><?= t('wft.duration_short') ?></th>
          <th scope="col"><?= t('wft.dependencies') ?></th><th scope="col"><?= t('wft.flags') ?></th><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th></tr></thead>
        <tbody>
          <?php foreach ($steps as $i => $s): ?>
            <tr<?= (int) $s['is_active'] === 0 ? ' class="row-skipped"' : '' ?>>
              <td><a href="<?= e(url('settings/workflow.php', ['template' => $templateId, 'step' => $s['id']])) ?>"><?= e($stepLabel($s)) ?></a>
                <?= (int) $s['is_builtin'] === 0 ? ' <span class="badge badge-accent">' . t('wft.custom') . '</span>' : '' ?><?= (int) $s['is_active'] === 0 ? ' <span class="badge badge-neutral">' . t('wft.inactive') . '</span>' : '' ?></td>
              <td class="small"><?= t('wft.type.' . $s['step_type']) ?></td>
              <td class="small"><?= role_label((string) $s['pic_role_code']) ?></td>
              <td class="right small"><?= (int) $s['default_duration'] ?></td>
              <td class="small mono"><?= e(implode(', ', array_map(static fn ($d) => $d['predecessor_code'] . ($d['dep_type'] !== 'FS' ? ' ' . $d['dep_type'] : '') . ((int) $d['lag_days'] !== 0 ? sprintf('%+d', (int) $d['lag_days']) : ''), $s['deps'])) ?: '–') ?></td>
              <td class="small">
                <?= (int) $s['is_mandatory'] === 1 ? '<span class="badge badge-neutral">' . t('wft.mandatory') . '</span> ' : '' ?>
                <?= (int) $s['is_skippable'] === 1 ? '<span class="badge badge-neutral">' . t('wft.skippable') . ($s['skip_group'] ? ' · ' . e($s['skip_group']) : '') . '</span> ' : '' ?>
                <?= $s['required_doc_types'] ? '<span class="badge badge-warning">' . t('wft.docs_n', ['count' => count($s['required_doc_types'])]) . '</span> ' : '' ?>
                <?= $s['approval_type'] ? '<span class="badge badge-accent">' . t('approval.type.' . $s['approval_type']) . '</span>' : '' ?>
              </td>
              <td class="right nowrap">
                <?php if ($isDraft): ?>
                  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="template" value="<?= $templateId ?>"><input type="hidden" name="version" value="<?= $versionId ?>"><input type="hidden" name="step" value="<?= (int) $s['id'] ?>">
                    <button type="submit" name="action" value="move_up" class="icon-btn" aria-label="<?= t('master.move_up') ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('chevron-down', 'icon icon-sm flip-y') ?></button>
                    <button type="submit" name="action" value="move_down" class="icon-btn" aria-label="<?= t('master.move_down') ?>"<?= $i === count($steps) - 1 ? ' disabled' : '' ?>><?= icon('chevron-down', 'icon icon-sm') ?></button>
                  </form>
                  <?php if ((int) $s['is_builtin'] === 0): ?>
                    <form method="post" class="inline-form" data-confirm="<?= t('wft.remove_confirm', ['code' => $s['code']]) ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="remove_step"><input type="hidden" name="template" value="<?= $templateId ?>"><input type="hidden" name="version" value="<?= $versionId ?>"><input type="hidden" name="step" value="<?= (int) $s['id'] ?>">
                      <button type="submit" class="icon-btn" aria-label="<?= t('common.delete') ?>"><?= icon('trash', 'icon icon-sm') ?></button></form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if ($isDraft): ?>
    <div class="grid grid-2 section">
      <section class="card" aria-labelledby="add-step">
        <div class="card-header"><h2 id="add-step"><?= t('wft.add_step') ?></h2></div>
        <form method="post" class="card-body form-grid">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_step"><input type="hidden" name="template" value="<?= $templateId ?>"><input type="hidden" name="version" value="<?= $versionId ?>">
          <div class="field span-2"><label for="a-name"><?= t('wft.name') ?></label><input class="input" id="a-name" name="name" required maxlength="160"></div>
          <div class="field"><label for="a-type"><?= t('wft.type') ?></label><select class="input" id="a-type" name="step_type"><?php foreach (WorkflowTemplateService::CUSTOM_TYPES as $ty): ?><option value="<?= $ty ?>"><?= t('wft.type.' . $ty) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="a-role"><?= t('wft.pic_role') ?></label><select class="input" id="a-role" name="pic_role"><?php foreach ($roleCodes as $rc): ?><option value="<?= e($rc) ?>"<?= $rc === 'npd_staff' ? ' selected' : '' ?>><?= role_label($rc) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="a-dur"><?= t('wft.duration') ?></label><input class="input" type="number" min="1" max="365" id="a-dur" name="default_duration" value="2"></div>
          <div class="field"><label for="a-after"><?= t('wft.after_step') ?></label><select class="input" id="a-after" name="after_step_id"><?php foreach ($steps as $st): ?><option value="<?= (int) $st['id'] ?>"><?= e($stepLabel($st)) ?></option><?php endforeach; ?></select></div>
          <div class="span-2 form-actions"><button type="submit" class="btn"><?= icon('plus') ?> <?= t('wft.add_step') ?></button></div>
        </form>
      </section>
      <section class="card" aria-labelledby="publish">
        <div class="card-header"><h2 id="publish"><?= t('wft.publish') ?></h2></div>
        <form method="post" class="card-body stack">
          <?= csrf_field() ?><input type="hidden" name="template" value="<?= $templateId ?>"><input type="hidden" name="version" value="<?= $versionId ?>">
          <p class="small muted"><?= t('wft.publish_hint') ?></p>
          <div class="field"><label for="p-notes"><?= t('wft.notes') ?></label><textarea class="input" id="p-notes" name="notes" rows="2" maxlength="500"></textarea></div>
          <div class="form-actions">
            <button type="submit" name="action" value="publish" class="btn btn-primary"<?= $problems ? ' disabled' : '' ?>><?= icon('check') ?> <?= t('wft.publish_btn', ['version' => (int) $version['version_no']]) ?></button>
          </div>
        </form>
        <form method="post" class="card-footer" data-confirm="<?= t('wft.discard_confirm') ?>" data-confirm-danger>
          <?= csrf_field() ?><input type="hidden" name="action" value="discard"><input type="hidden" name="template" value="<?= $templateId ?>"><input type="hidden" name="version" value="<?= $versionId ?>">
          <button type="submit" class="btn btn-sm btn-danger"><?= icon('trash', 'icon icon-sm') ?> <?= t('wft.discard') ?></button>
        </form>
      </section>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
