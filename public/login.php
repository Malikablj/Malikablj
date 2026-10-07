<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AppException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\CsrfException;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TooManyAttemptsException;

if (Auth::check()) {
    Response::redirect('dashboard.php');
}

$error = null;
$email = '';
if (Request::isPost()) {
    $email = (string) Request::post('email', '');
    try {
        Csrf::verify();
        $user = Auth::attempt($email, (string) ($_POST['password'] ?? ''), Request::ip());
        Auth::login($user);
        $return = Request::safeReturnPath((string) Session::pull('_return_to', ''), '');
        Session::flash('success', I18n::t('auth.welcome', ['name' => $user->name]));
        I18n::setLocale($user->language);
        Response::redirect($return !== '' ? $return : url('dashboard.php'));
    } catch (TooManyAttemptsException $e) {
        http_response_code(429);
        header('Retry-After: ' . $e->retryAfter());
        $error = $e->getMessage();
    } catch (CsrfException $e) {
        http_response_code(419);
        $error = $e->getMessage();
    } catch (AppException $e) {
        http_response_code(401);
        $error = $e->getMessage();
    }
}

$pageTitle = I18n::t('auth.title');
require APP_ROOT . '/includes/layout/head.php';
?>
<body class="guest">
<main class="guest-main" id="main">
  <section class="guest-card" aria-labelledby="login-title">
    <img class="auth-logo auth-logo-light" src="<?= e(asset('images/logo-light.png')) ?>" alt="PT. Permata Indo Kemas" width="135" height="72">
    <img class="auth-logo auth-logo-dark" src="<?= e(asset('images/logo-dark.png')) ?>" alt="" aria-hidden="true" width="135" height="72">
    <h1 id="login-title"><?= t('app.name') ?></h1>
    <p class="lead"><?= t('auth.subtitle') ?></p>

    <?php foreach (Session::takeFlashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= icon('info') ?><span><?= e($flash['message']) ?></span></div>
    <?php endforeach; ?>
    <?php if ($error !== null): ?>
      <div class="alert alert-danger" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login.php')) ?>" class="stack" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="email"><?= t('auth.email') ?></label>
        <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus maxlength="190">
      </div>
      <div class="field">
        <label for="password"><?= t('auth.password') ?></label>
        <div class="password-wrap">
          <input class="input" type="password" id="password" name="password" autocomplete="current-password" required maxlength="128">
          <button type="button" class="icon-btn" data-toggle-password aria-label="<?= t('auth.show_password') ?>" aria-pressed="false"><?= icon('eye') ?></button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= t('auth.submit') ?></button>
    </form>

    <div class="guest-tools">
      <form method="post" action="<?= e(url('api/preferences.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e(url('login.php')) ?>">
        <div class="segmented" role="group" aria-label="<?= t('lang.switch') ?>">
          <?php foreach (I18n::SUPPORTED as $lang): ?>
            <button type="submit" name="language" value="<?= e($lang) ?>" class="segmented-item<?= I18n::locale() === $lang ? ' is-active' : '' ?>" aria-pressed="<?= I18n::locale() === $lang ? 'true' : 'false' ?>"><?= e(strtoupper($lang)) ?></button>
          <?php endforeach; ?>
        </div>
      </form>
      <button type="button" class="icon-btn" data-theme-toggle aria-label="<?= t('theme.toggle') ?>"
              data-label-system="<?= t('theme.system') ?>" data-label-light="<?= t('theme.light') ?>" data-label-dark="<?= t('theme.dark') ?>">
        <span class="theme-icon theme-icon-system"><?= icon('monitor') ?></span>
        <span class="theme-icon theme-icon-light"><?= icon('sun') ?></span>
        <span class="theme-icon theme-icon-dark"><?= icon('moon') ?></span>
      </button>
    </div>
    <p class="guest-foot"><?= t('app.company') ?> · <?= t('app.department') ?></p>
  </section>
</main>
<div class="toast-region" aria-live="polite" data-toasts></div>
</body>
</html>
