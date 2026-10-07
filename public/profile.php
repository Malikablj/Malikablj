<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\User\UserService;

$user = require_login();
$service = new UserService();

if (Request::isPost()) {
    require_post();
    $action = Request::post('action');
    try {
        if ($action === 'password') {
            $service->changeOwnPassword(
                $user,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );
            Session::flash('success', I18n::t('profile.password_changed'));
        } elseif ($action === 'preferences') {
            $service->updatePreferences($user, Request::post('language'), Request::post('theme'));
            Session::flash('success', I18n::t('profile.preferences_saved'));
        }
    } catch (ValidationException $e) {
        remember_form($_POST, $e->errors());
        Session::flash('error', I18n::t('validation.form_errors'));
    }
    Response::redirect('profile.php');
}

$row = $service->get($user->id);
$pageTitle = I18n::t('profile.title');
$activeNav = '';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('profile.title') ?></h1>
    <p><?= e($row['email']) ?></p>
  </div>
</div>

<div class="grid grid-2">
  <section class="card">
    <div class="card-header"><h2><?= t('profile.account') ?></h2></div>
    <div class="card-body">
      <dl class="kv">
        <dt><?= t('common.name') ?></dt><dd><?= e($row['name']) ?></dd>
        <dt><?= t('common.email') ?></dt><dd><?= e($row['email']) ?></dd>
        <dt><?= t('common.role') ?></dt><dd><?= role_label($row['role_code']) ?></dd>
        <dt><?= t('user.job_title') ?></dt><dd><?= e($row['job_title'] ?? '–') ?></dd>
        <dt><?= t('user.last_login') ?></dt><dd><?= $row['last_login_at'] ? fmt_datetime($row['last_login_at']) : t('common.never') ?></dd>
      </dl>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h2><?= t('profile.preferences') ?></h2></div>
    <form method="post" class="card-body stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preferences">
      <div class="field">
        <label for="pref-language"><?= t('common.language') ?></label>
        <select class="input" id="pref-language" name="language">
          <?php foreach (I18n::SUPPORTED as $lang): ?>
            <option value="<?= e($lang) ?>"<?= $row['language'] === $lang ? ' selected' : '' ?>><?= t('lang.' . $lang) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pref-theme"><?= t('common.theme') ?></label>
        <select class="input" id="pref-theme" name="theme">
          <?php foreach (UserService::THEMES as $theme): ?>
            <option value="<?= e($theme) ?>"<?= $row['theme'] === $theme ? ' selected' : '' ?>><?= t('theme.' . $theme) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
    </form>
  </section>

  <section class="card span-2" id="password">
    <div class="card-header"><h2><?= t('profile.change_password') ?></h2></div>
    <form method="post" class="card-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <?php if ($user->mustChangePassword): ?>
        <div class="alert alert-warning" role="status"><?= icon('alert') ?><div><?= t('auth.must_change_password') ?></div></div>
      <?php endif; ?>
      <div class="form-grid">
        <div class="field span-2">
          <label for="current_password"><?= t('profile.current_password') ?></label>
          <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password" required maxlength="128">
          <?= field_error('current_password') ?>
        </div>
        <div class="field">
          <label for="new_password"><?= t('profile.new_password') ?></label>
          <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password" required maxlength="128">
          <p class="field-hint"><?= t('user.password_length', ['min' => 8]) ?> <?= t('user.password_complexity') ?></p>
          <?= field_error('new_password') ?>
        </div>
        <div class="field">
          <label for="password_confirmation"><?= t('profile.confirm_password') ?></label>
          <input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required maxlength="128">
          <?= field_error('password_confirmation') ?>
        </div>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn-primary"><?= t('profile.change_password') ?></button></div>
    </form>
  </section>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
