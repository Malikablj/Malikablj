<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Master\CustomerService;

$actor = require_permission('customer.manage');
$svc = new CustomerService();
$editId = Request::int('id');

if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $id = (int) Request::int('id', 0);
    $back = url('settings/customers.php', $id ? ['id' => $id] : []);
    try {
        switch ($action) {
            case 'create':
                $id = $svc->create($actor, $_POST);
                $back = url('settings/customers.php', ['id' => $id]);
                break;
            case 'update':
                $svc->update($actor, $id, $_POST);
                break;
            case 'activate':
            case 'deactivate':
                $svc->setActive($actor, $id, $action === 'activate');
                break;
            case 'add_contact':
                $svc->addContact($actor, $id, [
                    'name' => Request::post('contact_name'), 'position' => Request::post('contact_position'),
                    'phone' => Request::post('contact_phone'), 'email' => Request::post('contact_email'),
                    'is_primary' => Request::post('contact_primary'),
                ]);
                break;
            case 'remove_contact':
                $svc->removeContact($actor, $id, (int) Request::int('contact_id', 0));
                break;
            default:
                Response::error(400, I18n::t('validation.invalid'));
        }
        Session::flash('success', I18n::t('customer.saved'));
    } catch (ValidationException $e) {
        remember_form($_POST, $e->errors());
        Session::flash('error', I18n::t('validation.form_errors'));
        if ($action === 'create') {
            $back = url('settings/customers.php', ['new' => 1]);
        }
    }
    Response::redirect($back);
}

$editing = $editId ? $svc->get($editId) : null;
$rows = $editing ? [] : $svc->list(Request::query('q'));
$pageTitle = I18n::t('customer.title');
$activeNav = 'customers';
$breadcrumbs = $editing ? [[I18n::t('customer.title'), url('settings/customers.php')], [$editing['name'], null]] : [];
require APP_ROOT . '/includes/layout/header.php';

$fields = static function (array $c): string {
    $o = '';
    foreach ([['code', 'customer.code', 30, 'input'], ['name', 'customer.name', 190, 'input'], ['phone', 'customer.phone', 60, 'input'], ['email', 'customer.email', 190, 'input'],
              ['invoice_address', 'customer.invoice_address', 2000, 'textarea'], ['shipping_address', 'customer.shipping_address', 2000, 'textarea']] as [$k, $label, $max, $type]) {
        $val = old($k, (string) ($c[$k] ?? ''));
        $id = 'c-' . $k;
        $o .= '<div class="field' . ($type === 'textarea' ? ' span-2' : '') . '"><label for="' . $id . '">' . t($label) . '</label>'
            . ($type === 'textarea'
                ? '<textarea class="input" id="' . $id . '" name="' . $k . '" rows="2" maxlength="' . $max . '">' . e($val) . '</textarea>'
                : '<input class="input" id="' . $id . '" name="' . $k . '" value="' . e($val) . '" maxlength="' . $max . '"' . ($k === 'code' || $k === 'name' ? ' required' : '') . '>')
            . field_error($k) . '</div>';
    }
    return $o;
};
?>
<?php if ($editing): ?>
  <div class="page-header"><div><h1><?= e($editing['name']) ?></h1><p><span class="mono"><?= e($editing['code']) ?></span> · <?= status_badge($editing['is_active'] ? 'active' : 'inactive', 'common') ?></p></div>
    <div class="page-actions"><a class="btn" href="<?= e(url('settings/customers.php')) ?>"><?= icon('arrow-left') ?> <?= t('common.back') ?></a></div></div>
  <div class="grid grid-2">
    <section class="card">
      <div class="card-header"><h2><?= t('customer.edit') ?></h2></div>
      <form method="post" class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>">
        <div class="form-grid"><?= $fields($editing) ?></div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= t('common.save') ?></button>
        </div>
      </form>
      <form method="post" class="card-footer">
        <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editing['is_active'] ? 'deactivate' : 'activate' ?>"><input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>">
        <button type="submit" class="btn <?= $editing['is_active'] ? 'btn-danger' : '' ?>"><?= $editing['is_active'] ? t('user.deactivate') : t('user.activate') ?></button>
      </form>
    </section>
    <section class="card">
      <div class="card-header"><h2><?= t('customer.contacts') ?></h2></div>
      <div class="card-body stack">
        <?php if (!$editing['contacts']): ?><p class="muted"><?= t('common.empty') ?></p><?php endif; ?>
        <ul class="plain-list">
          <?php foreach ($editing['contacts'] as $ct): ?>
            <li class="row">
              <div><strong><?= e($ct['name']) ?></strong><?= $ct['is_primary'] ? ' <span class="badge badge-accent">' . t('customer.contact_primary') . '</span>' : '' ?><br>
                <span class="muted small"><?= e(implode(' · ', array_filter([$ct['position'], $ct['phone'], $ct['email']]))) ?></span></div>
              <span class="spacer"></span>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove_contact"><input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>"><input type="hidden" name="contact_id" value="<?= e((string) $ct['id']) ?>">
                <button type="submit" class="btn btn-sm btn-ghost" aria-label="<?= t('customer.remove_contact') ?>"><?= icon('trash', 'icon icon-sm') ?></button></form>
            </li>
          <?php endforeach; ?>
        </ul>
        <form method="post" class="form-grid">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_contact"><input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>">
          <div class="field"><label for="ct-name"><?= t('customer.contact_name') ?></label><input class="input" id="ct-name" name="contact_name" required maxlength="120"><?= field_error('contact_name') ?></div>
          <div class="field"><label for="ct-pos"><?= t('customer.contact_position') ?></label><input class="input" id="ct-pos" name="contact_position" maxlength="120"></div>
          <div class="field"><label for="ct-phone"><?= t('customer.phone') ?></label><input class="input" id="ct-phone" name="contact_phone" maxlength="60"></div>
          <div class="field"><label for="ct-email"><?= t('customer.email') ?></label><input class="input" id="ct-email" name="contact_email" maxlength="190"><?= field_error('contact_email') ?></div>
          <label class="check span-2"><input type="checkbox" name="contact_primary" value="1"> <span><?= t('customer.contact_primary') ?></span></label>
          <div class="form-actions span-2"><button type="submit" class="btn"><?= icon('plus') ?> <?= t('customer.add_contact') ?></button></div>
        </form>
      </div>
    </section>
  </div>
<?php else: ?>
  <div class="page-header"><div><h1><?= t('customer.title') ?></h1><p><?= t('customer.subtitle') ?></p></div>
    <div class="page-actions"><button type="button" class="btn btn-primary" data-open-dialog="dlg-new-customer"><?= icon('plus') ?> <?= t('customer.new') ?></button></div></div>
  <form method="get" class="filter-bar" role="search">
    <div class="field"><label for="f-q"><?= t('common.search') ?></label><input class="input" id="f-q" name="q" value="<?= e(Request::query('q')) ?>"></div>
    <div class="filter-actions"><button type="submit" class="btn"><?= icon('search') ?> <?= t('common.search') ?></button></div>
  </form>
  <div class="card">
    <div class="table-wrap">
      <table class="table table-cards">
        <thead><tr><th><?= t('customer.code') ?></th><th><?= t('customer.name') ?></th><th><?= t('customer.phone') ?></th><th class="right"><?= t('customer.projects') ?></th><th><?= t('common.status') ?></th><th class="right"><?= t('common.actions') ?></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="6" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
          <?php foreach ($rows as $c): ?>
            <tr>
              <td data-label="<?= t('customer.code') ?>" class="mono"><?= e($c['code']) ?></td>
              <td data-label="<?= t('customer.name') ?>"><strong><?= e($c['name']) ?></strong></td>
              <td data-label="<?= t('customer.phone') ?>"><?= e($c['phone'] ?? '–') ?></td>
              <td data-label="<?= t('customer.projects') ?>" class="right"><?= e((string) $c['project_count']) ?></td>
              <td data-label="<?= t('common.status') ?>"><?= status_badge($c['is_active'] ? 'active' : 'inactive', 'common') ?></td>
              <td class="table-actions"><a class="btn btn-sm" href="<?= e(url('settings/customers.php', ['id' => $c['id']])) ?>"><?= icon('edit', 'icon icon-sm') ?> <?= t('common.edit') ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pagination"><span><?= t('customer.count', ['count' => count($rows)]) ?></span></div>
  </div>
  <dialog class="modal" id="dlg-new-customer"<?= Request::query('new') === '1' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="modal-header"><h2><?= t('customer.new') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body"><div class="form-grid"><?= $fields([]) ?></div></div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
