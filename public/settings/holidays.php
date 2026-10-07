<?php
declare(strict_types=1);

/** Pengaturan hari libur (PRD §6.2) — hanya Admin. Perubahan menghitung ulang jadwal project berjalan. */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Settings\HolidayService;

$actor = require_permission('settings.manage');
$svc = new HolidayService();
$year = (int) (Request::int('year') ?? (int) substr(Clock::todayString(), 0, 4));
$year = max(2000, min(2100, $year));
$editId = Request::int('id');
$errors = [];

if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $back = url('settings/holidays.php', ['year' => $year]);
    try {
        $res = match ($action) {
            'save' => $svc->save($actor, Request::int('id'), $_POST),
            'delete' => $svc->delete($actor, (int) Request::int('id', 0)),
            'import' => $svc->import($actor, (string) Request::post('lines', '')),
            default => Response::error(400, I18n::t('validation.invalid')),
        };
        $msg = $action === 'import' ? I18n::t('holiday.imported', ['added' => $res['added'], 'skipped' => $res['skipped']]) . ' ' : '';
        Session::flash('success', $msg . I18n::t('holiday.saved', ['projects' => $res['projects'], 'shifted' => $res['shifted']]));
        Response::redirect($back);
    } catch (ValidationException $e) {
        $errors = $e->errors();
        http_response_code(422);
    }
}

$rows = $svc->forYear($year);
$editing = $editId ? Db::fetch('SELECT * FROM holidays WHERE id = ?', [$editId]) : null;
$form = $errors && Request::post('action') === 'save' ? $_POST : ($editing ?? ['holiday_date' => '', 'name' => '', 'is_recurring' => 0]);

$pageTitle = I18n::t('nav.holidays');
$activeNav = 'holidays';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('nav.holidays') ?></h1>
    <p><?= t('holiday.subtitle') ?></p>
  </div>
</div>
<?php if ($errors): ?><div class="flash flash-error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>

<div class="grid grid-2">
  <section class="card" aria-labelledby="h-list">
    <div class="card-header">
      <h2 id="h-list"><?= t('holiday.list', ['year' => $year]) ?></h2>
      <span class="inline-edit">
        <a class="btn btn-sm" href="<?= e(url('settings/holidays.php', ['year' => $year - 1])) ?>" aria-label="<?= t('common.previous') ?>"><?= icon('chevron-left') ?></a>
        <a class="btn btn-sm" href="<?= e(url('settings/holidays.php', ['year' => $year + 1])) ?>" aria-label="<?= t('common.next') ?>"><?= icon('chevron-right') ?></a>
      </span>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col"><?= t('common.date') ?></th><th scope="col"><?= t('holiday.name') ?></th><th scope="col"><?= t('holiday.recurring') ?></th><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="4" class="table-empty"><?= t('holiday.empty') ?></td></tr><?php endif; ?>
          <?php foreach ($rows as $h): ?>
            <tr>
              <td class="nowrap"><?= fmt_date($h['date_in_year']) ?><div class="muted small"><?= e(I18n::dayName((int) (new DateTimeImmutable($h['date_in_year']))->format('N'))) ?></div></td>
              <td><?= e($h['name']) ?></td>
              <td><?= (int) $h['is_recurring'] === 1 ? '<span class="badge badge-accent">' . t('holiday.yearly') . '</span>' : '<span class="muted small">–</span>' ?></td>
              <td class="right nowrap">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('settings/holidays.php', ['year' => $year, 'id' => $h['id']])) ?>"><?= icon('edit', 'icon icon-sm') ?> <?= t('common.edit') ?></a>
                <form method="post" class="inline-form" data-confirm="<?= t('holiday.delete_confirm', ['name' => $h['name']]) ?>" data-confirm-danger>
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-ghost"><?= icon('trash', 'icon icon-sm') ?> <?= t('common.delete') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <div class="stack">
    <section class="card" aria-labelledby="h-form">
      <div class="card-header"><h2 id="h-form"><?= $editing ? t('holiday.edit') : t('holiday.add') ?></h2></div>
      <form method="post" class="card-body form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="field<?= isset($errors['holiday_date']) ? ' has-error' : '' ?>"><label for="h-date"><?= t('common.date') ?></label>
          <input class="input" type="date" id="h-date" name="holiday_date" required value="<?= e((string) $form['holiday_date']) ?>">
          <?php if (isset($errors['holiday_date'])): ?><p class="field-error"><?= e($errors['holiday_date']) ?></p><?php endif; ?></div>
        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>"><label for="h-name"><?= t('holiday.name') ?></label>
          <input class="input" id="h-name" name="name" required maxlength="160" value="<?= e((string) $form['name']) ?>">
          <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?></div>
        <label class="check span-2"><input type="checkbox" name="is_recurring" value="1"<?= !empty($form['is_recurring']) ? ' checked' : '' ?>> <span><?= t('holiday.recurring_hint') ?></span></label>
        <p class="small muted span-2"><?= t('holiday.recalc_note') ?></p>
        <div class="span-2 form-actions">
          <?php if ($editing): ?><a class="btn" href="<?= e(url('settings/holidays.php', ['year' => $year])) ?>"><?= t('common.cancel') ?></a><?php endif; ?>
          <button type="submit" class="btn btn-primary"><?= t('common.save') ?></button>
        </div>
      </form>
    </section>
    <section class="card" aria-labelledby="h-import">
      <div class="card-header"><h2 id="h-import"><?= t('holiday.import') ?></h2></div>
      <form method="post" class="card-body stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="import">
        <div class="field<?= isset($errors['import']) ? ' has-error' : '' ?>"><label for="h-lines"><?= t('holiday.import_label') ?></label>
          <textarea class="input mono small" id="h-lines" name="lines" rows="6" placeholder="2026-12-25;Hari Raya Natal&#10;2026-12-26;Cuti bersama Natal"><?= e($errors && Request::post('action') === 'import' ? (string) Request::post('lines') : '') ?></textarea>
          <?php if (isset($errors['import'])): ?><p class="field-error"><?= e($errors['import']) ?></p><?php endif; ?></div>
        <div class="form-actions"><button type="submit" class="btn"><?= icon('upload') ?> <?= t('holiday.import_btn') ?></button></div>
      </form>
    </section>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
