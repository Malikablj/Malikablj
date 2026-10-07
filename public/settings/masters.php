<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Master\MasterService;

$actor = require_permission('settings.manage');
$svc = new MasterService();
$category = (string) Request::input('category', 'part_name');
if (!in_array($category, MasterService::CATEGORIES, true)) {
    $category = 'part_name';
}

if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $id = (int) Request::int('id', 0);
    try {
        match ($action) {
            'create' => $svc->create($actor, $category, $_POST),
            'update' => $svc->update($actor, $id, $_POST),
            'activate' => $svc->setActive($actor, $id, true),
            'deactivate' => $svc->setActive($actor, $id, false),
            'up', 'down' => $svc->move($actor, $id, $action),
            'promote' => $svc->promotePartName($actor, (string) Request::post('name')),
            default => Response::error(400, I18n::t('validation.invalid')),
        };
        Session::flash('success', I18n::t($action === 'promote' ? 'master.promoted' : 'master.saved'));
    } catch (ValidationException $e) {
        Session::flash('error', implode(' ', $e->errors()));
    }
    Response::redirect(url('settings/masters.php', ['category' => $category]));
}

$options = MasterService::options($category, true);
$customParts = $category === 'part_name' ? $svc->customPartNames() : [];

$pageTitle = I18n::t('master.title');
$activeNav = 'masters';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div><h1><?= t('master.title') ?></h1><p><?= t('master.subtitle') ?></p></div>
</div>
<nav class="tabs" aria-label="<?= t('master.title') ?>">
  <?php foreach (MasterService::CATEGORIES as $cat): ?>
    <a href="<?= e(url('settings/masters.php', ['category' => $cat])) ?>"<?= $cat === $category ? ' class="active" aria-current="page"' : '' ?>><?= t('master.cat.' . $cat) ?></a>
  <?php endforeach; ?>
</nav>

<div class="grid grid-2 masters-grid">
  <section class="card span-2">
    <div class="card-header"><h2><?= t('master.cat.' . $category) ?></h2><span class="muted small"><?= count($options) ?></span></div>
    <div class="table-wrap">
      <table class="table table-cards">
        <thead><tr><th><?= t('master.code') ?></th><th><?= t('master.label_id') ?></th><th><?= t('master.label_en') ?></th><?php if ($category === 'test_method'): ?><th><?= t('master.units') ?></th><?php endif; ?><th><?= t('common.status') ?></th><th class="right"><?= t('common.actions') ?></th></tr></thead>
        <tbody>
          <?php foreach ($options as $i => $o): ?>
            <tr>
              <td data-label="<?= t('master.code') ?>"><span class="mono small"><?= e($o['code']) ?></span><?= $o['is_builtin'] ? ' <span class="badge badge-neutral">' . t('master.builtin') . '</span>' : '' ?></td>
              <td data-label="<?= t('master.label_id') ?>" colspan="<?= $category === 'test_method' ? 3 : 2 ?>">
                <form method="post" class="row inline-edit">
                  <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= e((string) $o['id']) ?>"><input type="hidden" name="category" value="<?= e($category) ?>">
                  <input class="input input-sm" name="label_id" value="<?= e($o['label_id']) ?>" aria-label="<?= t('master.label_id') ?>" maxlength="160" required>
                  <input class="input input-sm" name="label_en" value="<?= e($o['label_en']) ?>" aria-label="<?= t('master.label_en') ?>" maxlength="160">
                  <?php if ($category === 'test_method'): ?><input class="input input-sm" name="units" value="<?= e(implode(', ', $o['meta']['units'] ?? [])) ?>" aria-label="<?= t('master.units') ?>"><?php endif; ?>
                  <button type="submit" class="btn btn-sm"><?= t('common.save') ?></button>
                </form>
              </td>
              <td data-label="<?= t('common.status') ?>"><?= status_badge($o['is_active'] ? 'active' : 'inactive', 'common') ?></td>
              <td class="table-actions">
                <?php foreach (['up' => 'chevron-left', 'down' => 'chevron-right'] as $dir => $ic): ?>
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= e($dir) ?>"><input type="hidden" name="id" value="<?= e((string) $o['id']) ?>"><input type="hidden" name="category" value="<?= e($category) ?>">
                    <button type="submit" class="btn btn-sm btn-ghost" aria-label="<?= t($dir === 'up' ? 'master.move_up' : 'master.move_down') ?>" title="<?= t($dir === 'up' ? 'master.move_up' : 'master.move_down') ?>"<?= ($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($options) - 1) ? ' disabled' : '' ?>><?= $dir === 'up' ? '↑' : '↓' ?></button></form>
                <?php endforeach; ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $o['is_active'] ? 'deactivate' : 'activate' ?>"><input type="hidden" name="id" value="<?= e((string) $o['id']) ?>"><input type="hidden" name="category" value="<?= e($category) ?>">
                  <button type="submit" class="btn btn-sm <?= $o['is_active'] ? 'btn-ghost' : '' ?>"><?= $o['is_active'] ? t('user.deactivate') : t('user.activate') ?></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h2><?= t('master.new') ?></h2></div>
    <form method="post" class="card-body stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="category" value="<?= e($category) ?>">
      <div class="field"><label for="m-label-id"><?= t('master.label_id') ?></label><input class="input" id="m-label-id" name="label_id" required maxlength="160"></div>
      <div class="field"><label for="m-label-en"><?= t('master.label_en') ?></label><input class="input" id="m-label-en" name="label_en" maxlength="160"></div>
      <div class="field"><label for="m-code"><?= t('master.code') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label><input class="input mono" id="m-code" name="code" maxlength="60" pattern="[a-z0-9_]+"></div>
      <?php if ($category === 'test_method'): ?><div class="field"><label for="m-units"><?= t('master.units') ?></label><input class="input" id="m-units" name="units" placeholder="Kg/Cm², Meter"></div><?php endif; ?>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('plus') ?> <?= t('master.new') ?></button></div>
    </form>
  </section>

  <?php if ($category === 'part_name'): ?>
    <section class="card">
      <div class="card-header"><div><h2><?= t('master.custom_parts') ?></h2><p class="muted small"><?= t('master.custom_parts_hint') ?></p></div></div>
      <div class="card-body">
        <?php if (!$customParts): ?><p class="muted"><?= t('common.empty') ?></p><?php endif; ?>
        <ul class="plain-list">
          <?php foreach ($customParts as $cp): ?>
            <li class="row">
              <strong><?= e($cp['name']) ?></strong><span class="muted small"><?= t('master.uses', ['count' => $cp['uses']]) ?></span><span class="spacer"></span>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="promote"><input type="hidden" name="category" value="part_name"><input type="hidden" name="name" value="<?= e($cp['name']) ?>">
                <button type="submit" class="btn btn-sm"><?= t('master.promote') ?></button></form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
