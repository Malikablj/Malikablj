<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\User\UserService;

$actor = require_permission('user.manage');
$service = new UserService();

if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $id = Request::int('id');
    $back = $id ? url('settings/users.php', ['id' => $id]) : url('settings/users.php');
    try {
        switch ($action) {
            case 'create':
                $newId = $service->create($actor, $_POST);
                Session::flash('success', I18n::t('user.created'));
                $back = url('settings/users.php', ['id' => $newId]);
                break;
            case 'update':
                $service->update($actor, (int) $id, $_POST);
                Session::flash('success', I18n::t('user.updated'));
                break;
            case 'activate':
            case 'deactivate':
                $service->setActive($actor, (int) $id, $action === 'activate', Request::post('reason'));
                Session::flash('success', I18n::t($action === 'activate' ? 'user.activated' : 'user.deactivated'));
                break;
            case 'reset_password':
                $service->resetPassword($actor, (int) $id, (string) ($_POST['password'] ?? ''));
                Session::flash('success', I18n::t('user.password_reset_done'));
                break;
            default:
                Response::error(400, I18n::t('validation.invalid'));
        }
    } catch (ValidationException $e) {
        remember_form($_POST + ['_form' => $action], $e->errors());
        Session::flash('error', I18n::t('validation.form_errors'));
        if ($action === 'create') {
            $back = url('settings/users.php', ['new' => 1]);
        }
    }
    Response::redirect($back);
}

$roles = $service->roles();
$editId = Request::int('id');
$editing = $editId ? $service->get($editId) : null;
$showCreate = Request::query('new') === '1';

$search = Request::query('q');
$roleFilter = Request::query('role');
$statusFilter = Request::query('status');
$users = $service->list($search, $roleFilter, $statusFilter);

$pageTitle = I18n::t('user.title');
$activeNav = 'users';
$breadcrumbs = $editing ? [[I18n::t('user.title'), url('settings/users.php')], [$editing['name'], null]] : [];
require APP_ROOT . '/includes/layout/header.php';

$roleOptions = static function (?string $selected) use ($roles): string {
    $html = '';
    foreach ($roles as $r) {
        $html .= '<option value="' . e($r['code']) . '"' . ($selected === $r['code'] ? ' selected' : '') . '>' . role_label($r['code']) . '</option>';
    }
    return $html;
};
?>
<?php if ($editing): ?>
  <div class="page-header">
    <div>
      <h1><?= e($editing['name']) ?></h1>
      <p><?= e($editing['email']) ?> · <?= role_label($editing['role_code']) ?> · <?= status_badge($editing['is_active'] ? 'active' : 'inactive', 'common') ?></p>
    </div>
    <div class="page-actions">
      <a class="btn" href="<?= e(url('settings/users.php')) ?>"><?= icon('arrow-left') ?> <?= t('common.back') ?></a>
    </div>
  </div>
  <div class="grid grid-2">
    <section class="card">
      <div class="card-header"><h2><?= t('user.edit') ?></h2></div>
      <form method="post" class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>">
        <div class="form-grid">
          <div class="field span-2">
            <label for="u-name"><?= t('common.name') ?></label>
            <input class="input" id="u-name" name="name" value="<?= e(old('name', $editing['name'])) ?>" required maxlength="120">
            <?= field_error('name') ?>
          </div>
          <div class="field span-2">
            <label for="u-email"><?= t('common.email') ?></label>
            <input class="input" type="email" id="u-email" name="email" value="<?= e(old('email', $editing['email'])) ?>" required maxlength="190">
            <?= field_error('email') ?>
          </div>
          <div class="field">
            <label for="u-role"><?= t('common.role') ?></label>
            <select class="input" id="u-role" name="role"><?= $roleOptions(old('role', $editing['role_code'])) ?></select>
            <?= field_error('role') ?>
          </div>
          <div class="field">
            <label for="u-lang"><?= t('common.language') ?></label>
            <select class="input" id="u-lang" name="language">
              <?php foreach (I18n::SUPPORTED as $lang): ?>
                <option value="<?= e($lang) ?>"<?= old('language', $editing['language']) === $lang ? ' selected' : '' ?>><?= t('lang.' . $lang) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="u-title"><?= t('user.job_title') ?></label>
            <input class="input" id="u-title" name="job_title" value="<?= e(old('job_title', (string) $editing['job_title'])) ?>" maxlength="120">
            <?= field_error('job_title') ?>
          </div>
          <div class="field">
            <label for="u-phone"><?= t('user.phone') ?></label>
            <input class="input" id="u-phone" name="phone" value="<?= e(old('phone', (string) $editing['phone'])) ?>" maxlength="40">
            <?= field_error('phone') ?>
          </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
      </form>
    </section>

    <div class="stack">
      <section class="card">
        <div class="card-header"><h2><?= t('user.reset_password') ?></h2></div>
        <form method="post" class="card-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_password">
          <input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>">
          <div class="field">
            <label for="u-reset"><?= t('auth.password') ?></label>
            <input class="input" type="password" id="u-reset" name="password" autocomplete="new-password" required maxlength="128">
            <p class="field-hint"><?= t('user.reset_password_hint') ?></p>
            <?= field_error('password') ?>
          </div>
          <div class="form-actions"><button type="submit" class="btn"><?= icon('key') ?> <?= t('user.reset_password') ?></button></div>
        </form>
      </section>

      <?php if ((int) $editing['id'] !== $actor->id): ?>
        <section class="card">
          <div class="card-header"><h2><?= $editing['is_active'] ? t('user.deactivate') : t('user.activate') ?></h2></div>
          <form method="post" class="card-body" <?= $editing['is_active'] ? 'data-confirm="' . t('user.deactivate') . ': ' . e($editing['name']) . '?" data-confirm-danger data-confirm-ok="' . t('user.deactivate') . '" data-confirm-cancel="' . t('common.cancel') . '"' : '' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing['is_active'] ? 'deactivate' : 'activate' ?>">
            <input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>">
            <div class="field">
              <label for="u-reason"><?= t('common.reason') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label>
              <input class="input" id="u-reason" name="reason" maxlength="500">
            </div>
            <div class="form-actions">
              <button type="submit" class="btn <?= $editing['is_active'] ? 'btn-danger' : 'btn-primary' ?>"><?= icon('power') ?> <?= $editing['is_active'] ? t('user.deactivate') : t('user.activate') ?></button>
            </div>
          </form>
        </section>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <div class="page-header">
    <div>
      <h1><?= t('user.title') ?></h1>
      <p><?= t('user.count', ['count' => count($users)]) ?></p>
    </div>
    <div class="page-actions">
      <button type="button" class="btn btn-primary" data-open-dialog="dlg-new-user"><?= icon('plus') ?> <?= t('user.new') ?></button>
    </div>
  </div>

  <form method="get" class="filter-bar" role="search">
    <div class="field">
      <label for="f-q"><?= t('common.search') ?></label>
      <input class="input" id="f-q" name="q" value="<?= e($search) ?>" placeholder="<?= t('common.name') ?> / <?= t('common.email') ?>">
    </div>
    <div class="field">
      <label for="f-role"><?= t('common.role') ?></label>
      <select class="input" id="f-role" name="role">
        <option value=""><?= t('user.filter_role') ?></option>
        <?= $roleOptions($roleFilter) ?>
      </select>
    </div>
    <div class="field">
      <label for="f-status"><?= t('common.status') ?></label>
      <select class="input" id="f-status" name="status">
        <option value=""><?= t('user.filter_status') ?></option>
        <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>><?= t('common.active') ?></option>
        <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : '' ?>><?= t('common.inactive') ?></option>
      </select>
    </div>
    <div class="filter-actions">
      <button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button>
      <a class="btn btn-ghost" href="<?= e(url('settings/users.php')) ?>"><?= t('common.reset') ?></a>
    </div>
  </form>

  <div class="card">
    <div class="table-wrap">
      <table class="table table-cards">
        <thead>
          <tr>
            <th><?= t('common.name') ?></th>
            <th><?= t('common.email') ?></th>
            <th><?= t('common.role') ?></th>
            <th><?= t('user.job_title') ?></th>
            <th><?= t('common.status') ?></th>
            <th><?= t('user.last_login') ?></th>
            <th class="right"><?= t('common.actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$users): ?>
            <tr><td colspan="7" class="table-empty"><?= t('common.empty') ?></td></tr>
          <?php endif; ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td data-label="<?= t('common.name') ?>"><strong><?= e($u['name']) ?></strong><?= $u['must_change_password'] ? ' <span class="badge badge-warning">' . t('user.must_change') . '</span>' : '' ?></td>
              <td data-label="<?= t('common.email') ?>"><?= e($u['email']) ?></td>
              <td data-label="<?= t('common.role') ?>"><?= role_label($u['role_code']) ?></td>
              <td data-label="<?= t('user.job_title') ?>"><?= e($u['job_title'] ?? '–') ?></td>
              <td data-label="<?= t('common.status') ?>"><?= status_badge($u['is_active'] ? 'active' : 'inactive', 'common') ?></td>
              <td data-label="<?= t('user.last_login') ?>" class="nowrap"><?= $u['last_login_at'] ? fmt_datetime($u['last_login_at']) : '<span class="muted">' . t('common.never') . '</span>' ?></td>
              <td class="table-actions"><a class="btn btn-sm" href="<?= e(url('settings/users.php', ['id' => $u['id']])) ?>"><?= icon('edit', 'icon icon-sm') ?> <?= t('common.edit') ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php
  $permRows = Db::fetchAll('SELECT p.code, p.description FROM permissions p ORDER BY p.module, p.code');
  ?>
  <section class="section">
    <div class="section-title"><h2><?= t('user.permissions_matrix') ?></h2></div>
    <p class="muted small"><?= t('user.permissions_matrix_hint') ?></p>
    <div class="card">
      <div class="table-wrap">
        <table class="table matrix">
          <thead>
            <tr>
              <th class="col-sticky"><?= t('user.permission') ?></th>
              <?php foreach ($roles as $r): ?><th><?= role_label($r['code']) ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($permRows as $p): ?>
              <tr>
                <td class="col-sticky"><span class="mono small"><?= e($p['code']) ?></span><br><span class="muted small"><?= I18n::has('perm.' . $p['code']) ? t('perm.' . $p['code']) : e($p['description']) ?></span></td>
                <?php foreach ($roles as $r): $scope = Gate::permissionsFor($r['code'])[$p['code']] ?? null; ?>
                  <td><?= $scope === 'all' ? '<span class="badge badge-success">' . t('user.scope_all') . '</span>' : ($scope === 'own' ? '<span class="badge badge-accent">' . t('user.scope_own') . '</span>' : '<span class="muted">–</span>') ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <dialog class="modal" id="dlg-new-user"<?= $showCreate ? ' data-autoopen' : '' ?> aria-labelledby="dlg-new-user-title">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h2 id="dlg-new-user-title"><?= t('user.new') ?></h2>
        <button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="field span-2">
            <label for="n-name"><?= t('common.name') ?></label>
            <input class="input" id="n-name" name="name" value="<?= e(old('name')) ?>" required maxlength="120">
            <?= field_error('name') ?>
          </div>
          <div class="field span-2">
            <label for="n-email"><?= t('common.email') ?></label>
            <input class="input" type="email" id="n-email" name="email" value="<?= e(old('email')) ?>" required maxlength="190">
            <?= field_error('email') ?>
          </div>
          <div class="field">
            <label for="n-role"><?= t('common.role') ?></label>
            <select class="input" id="n-role" name="role"><?= $roleOptions(old('role', 'admin_sales')) ?></select>
            <?= field_error('role') ?>
          </div>
          <div class="field">
            <label for="n-title"><?= t('user.job_title') ?></label>
            <input class="input" id="n-title" name="job_title" value="<?= e(old('job_title')) ?>" maxlength="120">
          </div>
          <div class="field">
            <label for="n-phone"><?= t('user.phone') ?></label>
            <input class="input" id="n-phone" name="phone" value="<?= e(old('phone')) ?>" maxlength="40">
          </div>
          <div class="field">
            <label for="n-lang"><?= t('common.language') ?></label>
            <select class="input" id="n-lang" name="language">
              <?php foreach (I18n::SUPPORTED as $lang): ?><option value="<?= e($lang) ?>"><?= t('lang.' . $lang) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field span-2">
            <label for="n-pass"><?= t('user.password_initial') ?></label>
            <input class="input" type="password" id="n-pass" name="password" autocomplete="new-password" required maxlength="128">
            <p class="field-hint"><?= t('user.password_initial_hint') ?></p>
            <?= field_error('password') ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button>
        <button type="submit" class="btn btn-primary"><?= t('common.save') ?></button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
