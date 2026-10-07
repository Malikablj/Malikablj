<?php
declare(strict_types=1);

/** Kalender (PRD §6.8): jadwal proses per kategori, agenda manual, hari libur. Tampilan bulan; daftar di HP. */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Calendar\CalendarService;
use App\Core\AppException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Scheduling\WorkingCalendar;

$user = require_permission('project.view');
$svc = new CalendarService();

$month = (string) Request::query('month', Clock::now()->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = Clock::now()->format('Y-m');
}
$filters = [
    'category' => Request::query('category'),
    'project_id' => Request::int('project_id'),
    'mine' => Request::query('mine') === '1',
];
$qs = array_filter(['month' => $month, 'category' => $filters['category'], 'project_id' => $filters['project_id'], 'mine' => $filters['mine'] ? '1' : null], static fn ($v) => $v !== null && $v !== '');

$errors = [];
$failed = null;
if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    try {
        if ($action === 'create') {
            $svc->createAgenda($user, $_POST);
            Session::flash('success', I18n::t('calendar.created'));
        } elseif ($action === 'delete') {
            $svc->deleteAgenda($user, (int) Request::int('event_id'));
            Session::flash('success', I18n::t('calendar.deleted'));
        } else {
            Response::error(400, I18n::t('validation.invalid'));
        }
        Response::redirect(url('calendar.php', $qs));
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
        Response::redirect(url('calendar.php', $qs));
    }
}

$first = $month . '-01';
$last = date('Y-m-t', strtotime($first . ' 12:00:00'));
$gridStart = WorkingCalendar::shift($first, -((int) date('N', strtotime($first . ' 12:00:00')) - 1));
$gridEnd = WorkingCalendar::shift($last, 7 - (int) date('N', strtotime($last . ' 12:00:00')));
$events = $svc->events($user, $gridStart, $gridEnd, $filters);
$holidays = $svc->holidays($gridStart, $gridEnd);
$today = Clock::todayString();
$prev = date('Y-m', strtotime($first . ' 12:00:00 -1 month'));
$next = date('Y-m', strtotime($first . ' 12:00:00 +1 month'));
$canManage = Gate::can($user, 'calendar.manage');
$projects = Db::fetchAll('SELECT id, code, name FROM projects WHERE is_archived = 0 ORDER BY code DESC LIMIT 500');
$title = I18n::monthName((int) substr($month, 5, 2)) . ' ' . substr($month, 0, 4);
$dows = I18n::locale() === 'en' ? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] : ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

$pageTitle = I18n::t('calendar.title');
$activeNav = 'calendar';
require APP_ROOT . '/includes/layout/header.php';

$renderEvent = static function (array $ev): string {
    $cls = 'cal-event cat-' . $ev['category'] . ($ev['overdue'] ? ' is-overdue' : '');
    $label = $ev['title'] . ' — ' . $ev['meta'] . ($ev['overdue'] ? ' · ' . I18n::t('status.overdue') : '');
    $inner = ($ev['overdue'] ? '⚠ ' : '') . $ev['title'];
    if ($ev['link']) {
        return '<a class="' . e($cls) . '" href="' . e(url($ev['link'])) . '" title="' . e($label) . '">' . e($inner) . '</a>';
    }
    return '<span class="' . e($cls) . '" title="' . e($label) . '">' . e($inner) . '</span>';
};
?>
<div class="page-header">
  <div>
    <h1><?= t('calendar.title') ?></h1>
    <p><?= t('calendar.subtitle') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('calendar.php', ['month' => $prev] + array_diff_key($qs, ['month' => 1]))) ?>" aria-label="<?= t('calendar.prev') ?>"><?= icon('chevron-left') ?></a>
    <a class="btn" href="<?= e(url('calendar.php', array_diff_key($qs, ['month' => 1]))) ?>"><?= t('gantt.today') ?></a>
    <a class="btn" href="<?= e(url('calendar.php', ['month' => $next] + array_diff_key($qs, ['month' => 1]))) ?>" aria-label="<?= t('calendar.next') ?>"><?= icon('chevron-right') ?></a>
    <?php if ($canManage): ?><button type="button" class="btn btn-primary" data-open-dialog="dlg-agenda"><?= icon('plus') ?> <?= t('calendar.new_agenda') ?></button><?php endif; ?>
  </div>
</div>

<form method="get" class="filter-bar">
  <input type="hidden" name="month" value="<?= e($month) ?>">
  <div class="field"><label for="c-cat"><?= t('calendar.category') ?></label>
    <select class="input" id="c-cat" name="category"><option value=""><?= t('common.all') ?></option>
      <?php foreach (CalendarService::CATEGORIES as $c): ?><option value="<?= $c ?>"<?= $filters['category'] === $c ? ' selected' : '' ?>><?= t('calendar.cat.' . $c) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="c-proj"><?= t('project.project') ?></label>
    <select class="input" id="c-proj" name="project_id"><option value=""><?= t('common.all') ?></option>
      <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $filters['project_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?>
    </select></div>
  <label class="check"><input type="checkbox" name="mine" value="1"<?= $filters['mine'] ? ' checked' : '' ?>> <span><?= t('calendar.mine') ?></span></label>
  <div class="field"><button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button></div>
</form>

<h2 class="section-title"><?= e($title) ?></h2>
<div class="npr-legend">
  <?php foreach (CalendarService::CATEGORIES as $c): ?><span class="legend-item"><span class="cal-event cat-<?= $c ?>">&nbsp;</span><?= t('calendar.cat.' . $c) ?></span><?php endforeach; ?>
  <span class="legend-item"><span class="swatch" style="background: var(--gantt-holiday)"></span><?= t('calendar.holiday') ?></span>
</div>

<div class="cal-grid" role="grid" aria-label="<?= e($title) ?>">
  <?php foreach ($dows as $dw): ?><div class="cal-dow" role="columnheader"><?= e($dw) ?></div><?php endforeach; ?>
  <?php for ($d = $gridStart; $d <= $gridEnd; $d = WorkingCalendar::shift($d, 1)): ?>
    <?php
    $cls = ['cal-day'];
    if (substr($d, 0, 7) !== $month) { $cls[] = 'is-other'; }
    if ((int) date('N', strtotime($d . ' 12:00:00')) >= 6) { $cls[] = 'is-weekend'; }
    if (isset($holidays[$d])) { $cls[] = 'is-holiday'; }
    if ($d === $today) { $cls[] = 'is-today'; }
    $list = $events[$d] ?? [];
    ?>
    <div class="<?= e(implode(' ', $cls)) ?>" role="gridcell" aria-label="<?= e(I18n::date($d)) ?>">
      <span class="cal-num"><?= (int) substr($d, 8, 2) ?></span>
      <?php if (isset($holidays[$d])): ?><span class="cal-holiday"><?= e($holidays[$d] ?: I18n::t('calendar.holiday')) ?></span><?php endif; ?>
      <?php foreach (array_slice($list, 0, 4) as $ev): ?><?= $renderEvent($ev) ?><?php endforeach; ?>
      <?php if (count($list) > 4): ?><span class="cal-more"><?= t('project.more_n', ['count' => count($list) - 4]) ?></span><?php endif; ?>
    </div>
  <?php endfor; ?>
</div>

<div class="cal-list">
  <?php $any = false; ?>
  <?php for ($d = $first; $d <= $last; $d = WorkingCalendar::shift($d, 1)): ?>
    <?php $list = $events[$d] ?? []; if (!$list && !isset($holidays[$d])) { continue; } $any = true; ?>
    <section class="card section">
      <div class="card-header"><h3><?= e(I18n::date($d)) ?><?= $d === $today ? ' · ' . t('gantt.today') : '' ?></h3><?php if (isset($holidays[$d])): ?><span class="badge badge-danger"><?= e($holidays[$d] ?: I18n::t('calendar.holiday')) ?></span><?php endif; ?></div>
      <div class="card-body stack">
        <?php foreach ($list as $ev): ?><div><?= $renderEvent($ev) ?><div class="small muted"><?= e($ev['meta']) ?></div></div><?php endforeach; ?>
      </div>
    </section>
  <?php endfor; ?>
  <?php if (!$any): ?><p class="muted"><?= t('calendar.empty') ?></p><?php endif; ?>
</div>

<?php $agenda = []; foreach ($events as $d => $list) { foreach ($list as $ev) { if ($ev['type'] === 'agenda' && substr($d, 0, 7) === $month) { $agenda[] = $ev + ['date' => $d]; } } } ?>
<?php if ($agenda): ?>
  <section class="card section" aria-labelledby="sec-agenda">
    <div class="card-header"><h2 id="sec-agenda"><?= t('calendar.agenda_month') ?></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col"><?= t('common.date') ?></th><th scope="col"><?= t('calendar.agenda') ?></th><th scope="col"><?= t('common.details') ?></th><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th></tr></thead>
        <tbody>
          <?php foreach ($agenda as $ev): ?>
            <tr>
              <td class="nowrap small"><?= fmt_date($ev['date']) ?></td>
              <td><?= e($ev['title']) ?></td>
              <td class="small"><?= e($ev['meta']) ?><?= $ev['notes'] ? '<div class="muted">' . e($ev['notes']) . '</div>' : '' ?></td>
              <td><?php if ($ev['can_delete']): ?>
                <form method="post" data-confirm="<?= t('calendar.delete_confirm') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
                  <button type="submit" class="icon-btn icon-btn-sm" aria-label="<?= t('calendar.delete') ?>"><?= icon('trash', 'icon icon-sm') ?></button></form>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if ($canManage): ?>
  <dialog class="modal" id="dlg-agenda" aria-labelledby="dlg-agenda-title"<?= $failed === 'create' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="modal-header"><h2 id="dlg-agenda-title"><?= t('calendar.new_agenda') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body form-grid">
        <?php $pv = static fn (string $k, string $d = ''): string => $failed === 'create' && isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : $d; ?>
        <div class="field span-2<?= isset($errors['title']) ? ' has-error' : '' ?>"><label for="ag-title"><?= t('calendar.f_title') ?></label>
          <input class="input" id="ag-title" name="title" maxlength="190" required value="<?= e($pv('title')) ?>"><?= isset($errors['title']) ? '<p class="field-error">' . e($errors['title']) . '</p>' : '' ?></div>
        <div class="field"><label for="ag-type"><?= t('calendar.f_type') ?></label>
          <select class="input" id="ag-type" name="event_type"><?php foreach (CalendarService::AGENDA_TYPES as $tp): ?><option value="<?= $tp ?>"<?= $pv('event_type') === $tp ? ' selected' : '' ?>><?= t('calendar.type.' . $tp) ?></option><?php endforeach; ?></select></div>
        <div class="field<?= isset($errors['event_date']) ? ' has-error' : '' ?>"><label for="ag-date"><?= t('common.date') ?></label>
          <input class="input" type="date" id="ag-date" name="event_date" required value="<?= e($pv('event_date', $today)) ?>"><?= isset($errors['event_date']) ? '<p class="field-error">' . e($errors['event_date']) . '</p>' : '' ?></div>
        <div class="field<?= isset($errors['start_time']) ? ' has-error' : '' ?>"><label for="ag-start"><?= t('calendar.f_start') ?></label><input class="input" type="time" id="ag-start" name="start_time" value="<?= e($pv('start_time')) ?>"></div>
        <div class="field<?= isset($errors['end_time']) ? ' has-error' : '' ?>"><label for="ag-end"><?= t('calendar.f_end') ?></label><input class="input" type="time" id="ag-end" name="end_time" value="<?= e($pv('end_time')) ?>"><?= isset($errors['end_time']) ? '<p class="field-error">' . e($errors['end_time']) . '</p>' : '' ?></div>
        <div class="field"><label for="ag-proj"><?= t('project.project') ?> <span class="muted">(<?= t('common.optional') ?>)</span></label>
          <select class="input" id="ag-proj" name="project_id"><option value="">–</option><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $pv('project_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="ag-loc"><?= t('calendar.f_location') ?></label><input class="input" id="ag-loc" name="location" maxlength="190" value="<?= e($pv('location')) ?>"></div>
        <div class="field span-2"><label for="ag-notes"><?= t('process.notes') ?></label><textarea class="input" id="ag-notes" name="notes" rows="3" maxlength="2000"><?= e($pv('notes')) ?></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('common.save') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
