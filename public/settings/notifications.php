<?php
declare(strict_types=1);

/** Pengaturan notifikasi & SMTP (Admin) — PRD §7.3. Password SMTP disimpan terenkripsi dan tidak ditampilkan. */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Notification\EmailTemplate;
use App\Notification\Notifier;
use App\Notification\SmtpTransport;
use App\Settings\NotificationSettings;

$user = require_permission('settings.manage');
$svc = new NotificationSettings();
$errors = [];
if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action', 'save');
    try {
        if ($action === 'test') {
            // email uji dikirim langsung (sinkron) ke Admin agar konfigurasi SMTP dapat diverifikasi
            $subject = I18n::t('nset.test_subject');
            $body = I18n::t('nset.test_body');
            (new SmtpTransport())->send($user->email, $user->name, $subject, EmailTemplate::render($subject, $body, null, I18n::locale()), EmailTemplate::text($body, null, I18n::locale()));
            Session::flash('success', I18n::t('nset.test_sent', ['email' => $user->email]));
        } else {
            $svc->save($user, $_POST);
            Session::flash('success', I18n::t('common.saved'));
        }
        Response::redirect(url('settings/notifications.php'));
    } catch (ValidationException $e) {
        $errors = $e->errors();
        http_response_code(422);
        Session::flash('error', I18n::t('validation.form_errors'));
    } catch (\RuntimeException $e) {
        Session::flash('error', I18n::t('nset.test_failed', ['error' => $e->getMessage()]));
        Response::redirect(url('settings/notifications.php'));
    }
}
$c = $svc->current();
if ($errors) {
    $c = array_merge($c, array_intersect_key($_POST, $c));
}
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error">' . e($errors[$k]) . '</p>' : '';

$pageTitle = I18n::t('nav.notif_settings');
$activeNav = 'notif_settings';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header"><div><h1><?= t('nav.notif_settings') ?></h1><p><?= t('nset.subtitle') ?></p></div></div>
<form method="post" class="stack">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <section class="card"><div class="card-header"><h2><?= t('nset.thresholds') ?></h2></div>
    <div class="card-body form-grid">
      <div class="field<?= isset($errors['due_soon_days']) ? ' has-error' : '' ?>"><label for="ns-due"><?= t('nset.due_soon') ?></label>
        <input class="input" type="number" min="1" max="30" id="ns-due" name="due_soon_days" value="<?= e((string) $c['due_soon_days']) ?>" required><?= $err('due_soon_days') ?><p class="field-hint"><?= t('nset.due_soon_hint') ?></p></div>
      <div class="field<?= isset($errors['no_update_days']) ? ' has-error' : '' ?>"><label for="ns-nu"><?= t('nset.no_update') ?></label>
        <input class="input" type="number" min="1" max="30" id="ns-nu" name="no_update_days" value="<?= e((string) $c['no_update_days']) ?>" required><?= $err('no_update_days') ?><p class="field-hint"><?= t('nset.no_update_hint') ?></p></div>
      <div class="field<?= isset($errors['hold_reminder_days']) ? ' has-error' : '' ?>"><label for="ns-hold"><?= t('nset.hold_reminder') ?></label>
        <input class="input" type="number" min="1" max="365" id="ns-hold" name="hold_reminder_days" value="<?= e((string) $c['hold_reminder_days']) ?>" required><?= $err('hold_reminder_days') ?><p class="field-hint"><?= t('nset.hold_reminder_hint') ?></p></div>
      <div class="field<?= isset($errors['hold_reminder_repeat_days']) ? ' has-error' : '' ?>"><label for="ns-hold-rep"><?= t('nset.hold_repeat') ?></label>
        <input class="input" type="number" min="1" max="90" id="ns-hold-rep" name="hold_reminder_repeat_days" value="<?= e((string) $c['hold_reminder_repeat_days']) ?>" required><?= $err('hold_reminder_repeat_days') ?><p class="field-hint"><?= t('nset.hold_repeat_hint') ?></p></div>
    </div></section>
  <section class="card"><div class="card-header"><h2><?= t('nset.email') ?></h2></div>
    <div class="card-body form-grid">
      <label class="check span-2<?= isset($errors['mail_enabled']) ? ' has-error' : '' ?>"><input type="checkbox" name="mail_enabled" value="1"<?= !empty($c['mail_enabled']) ? ' checked' : '' ?>> <span><?= t('nset.mail_enabled') ?></span></label>
      <?= $err('mail_enabled') ?>
      <div class="field<?= isset($errors['smtp_host']) ? ' has-error' : '' ?>"><label for="ns-host">SMTP host</label><input class="input" id="ns-host" name="smtp_host" value="<?= e((string) $c['smtp_host']) ?>" autocomplete="off"><?= $err('smtp_host') ?></div>
      <div class="field<?= isset($errors['smtp_port']) ? ' has-error' : '' ?>"><label for="ns-port">SMTP port</label><input class="input" type="number" id="ns-port" name="smtp_port" value="<?= e((string) $c['smtp_port']) ?>"><?= $err('smtp_port') ?></div>
      <div class="field"><label for="ns-enc"><?= t('nset.encryption') ?></label>
        <select class="input" id="ns-enc" name="smtp_encryption"><?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => t('common.none')] as $k => $lbl): ?><option value="<?= $k ?>"<?= $c['smtp_encryption'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="ns-user">SMTP username</label><input class="input" id="ns-user" name="smtp_username" value="<?= e((string) $c['smtp_username']) ?>" autocomplete="off"></div>
      <div class="field"><label for="ns-pw">SMTP password</label><input class="input" type="password" id="ns-pw" name="smtp_password" value="" autocomplete="new-password" placeholder="<?= $c['smtp_password_set'] ? t('nset.password_set') : '' ?>">
        <p class="field-hint"><?= t('nset.password_hint') ?></p>
        <?php if ($c['smtp_password_set']): ?><label class="check"><input type="checkbox" name="smtp_password_clear" value="1"> <span><?= t('nset.password_clear') ?></span></label><?php endif; ?></div>
      <div class="field<?= isset($errors['from_address']) ? ' has-error' : '' ?>"><label for="ns-from"><?= t('nset.from_address') ?></label><input class="input" type="email" id="ns-from" name="from_address" value="<?= e((string) $c['from_address']) ?>"><?= $err('from_address') ?></div>
      <div class="field"><label for="ns-fromname"><?= t('nset.from_name') ?></label><input class="input" id="ns-fromname" name="from_name" value="<?= e((string) $c['from_name']) ?>" maxlength="120"></div>
      <fieldset class="field span-2"><legend><?= t('nset.types') ?></legend>
        <div class="check-grid"><?php foreach (Notifier::EMAIL_TYPES as $tp): ?><label class="check"><input type="checkbox" name="types[<?= e($tp) ?>]" value="1"<?= !empty($c['types'][$tp]) ? ' checked' : '' ?>> <span><?= t('notifc.t.' . $tp) ?></span></label><?php endforeach; ?></div>
        <p class="field-hint"><?= t('nset.types_hint') ?></p></fieldset>
    </div></section>
  <div class="form-actions"><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
</form>
<form method="post" class="section"><?= csrf_field() ?><input type="hidden" name="action" value="test">
  <button type="submit" class="btn"><?= icon('bell') ?> <?= t('nset.test', ['email' => $user->email]) ?></button></form>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
