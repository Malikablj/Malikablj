<?php
declare(strict_types=1);

/**
 * Laporan (PRD §10.2–10.3): Weekly NPD Report, Analytics, KPI per PIC.
 * Periode Mingguan/Bulanan/rentang bebas. Tab KPI hanya untuk Admin & Management (izin kpi.view) —
 * role lain tidak melihat tab dan ditolak server (403), termasuk export-nya.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Report\AnalyticsService;
use App\Report\KpiService;
use App\Report\ReportPeriod;
use App\Report\WeeklyReport;

$user = require_permission('report.view');
$canKpi = Gate::can($user, 'kpi.view');
$tab = in_array(Request::query('tab'), ['weekly', 'analytics', 'kpi'], true) ? (string) Request::query('tab') : 'weekly';
if ($tab === 'kpi' && !$canKpi) {
    Response::error(403, I18n::t('error.forbidden'));
}
$period = ReportPeriod::fromInput($_GET, Clock::todayString());
$customers = Db::fetchAll('SELECT id, name FROM customers ORDER BY name');
$canExport = Gate::can($user, 'export.report');

if ($tab === 'weekly') {
    $f = WeeklyReport::cleanFilters($_GET);
    $svc = new WeeklyReport();
    $report = $svc->build($period, $f);
    $text = $svc->text($report);
    $npdUsers = Db::fetchAll("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code IN ('npd_staff', 'admin') AND u.is_active = 1 ORDER BY u.name");
} elseif ($tab === 'analytics') {
    $f = AnalyticsService::cleanFilters($_GET);
    $analytics = (new AnalyticsService())->build($period, $f);
} else {
    $f = KpiService::cleanFilters($_GET);
    $kpi = (new KpiService())->build($user, $period, $f);
    $processOptions = KpiService::processOptions();
}
$fq = array_filter($f, static fn ($v) => $v !== null && $v !== '');
$keep = $period->query() + array_diff_key($fq, ['pic' => 1]);
$selfUrl = static fn (array $extra = []): string => url('reports.php', ['tab' => $tab] + $keep + $extra);
$num = static fn ($v, string $suffix = ''): string => $v === null ? '–' : e(I18n::number($v, is_float($v) && floor($v) != $v ? 1 : 0) . $suffix);
$signed = static fn ($v): string => $v === null ? '–' : e(($v > 0 ? '+' : '') . I18n::number($v, 1));

$pageTitle = I18n::t('report.title');
$activeNav = 'reports';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('report.title') ?></h1>
    <p><?= t('report.subtitle') ?></p>
  </div>
  <?php if ($canExport || ($tab === 'kpi' && $canKpi)): ?>
    <div class="page-actions">
      <?php if ($tab === 'weekly' && $canExport): ?><a class="btn" href="<?= e(url('export.php', ['type' => 'weekly_xlsx'] + $keep)) ?>"><?= icon('download') ?> <?= t('report.export_excel') ?></a><?php endif; ?>
      <?php if ($tab === 'analytics' && $canExport): ?><a class="btn" href="<?= e(url('export.php', ['type' => 'analytics_xlsx'] + $keep)) ?>"><?= icon('download') ?> <?= t('report.export_excel') ?></a><?php endif; ?>
      <?php if ($tab === 'kpi'): ?>
        <a class="btn" href="<?= e(url('export.php', ['type' => 'kpi_xlsx'] + $period->query() + $fq)) ?>"><?= icon('download') ?> <?= t('report.export_excel') ?></a>
        <a class="btn" href="<?= e(url('export.php', ['type' => 'kpi_pdf'] + $period->query() + $fq)) ?>"><?= icon('file') ?> <?= t('report.export_pdf') ?></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<nav class="tabs" aria-label="<?= t('report.tabs') ?>">
  <?php foreach (['weekly' => 'report.tab.weekly', 'analytics' => 'report.tab.analytics'] + ($canKpi ? ['kpi' => 'report.tab.kpi'] : []) as $k => $label): ?>
    <a href="<?= e(url('reports.php', ['tab' => $k] + $period->query())) ?>"<?= $tab === $k ? ' class="active" aria-current="page"' : '' ?>><?= t($label) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" class="filter-bar period-form" data-period-form aria-label="<?= t('report.period') ?>">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php if ($tab === 'kpi' && $f['pic'] !== null): ?><input type="hidden" name="pic" value="<?= (int) $f['pic'] ?>"><?php endif; ?>
  <div class="field">
    <label for="r-period"><?= t('report.period') ?></label>
    <select class="input" id="r-period" name="period" data-period-type>
      <?php foreach (ReportPeriod::TYPES as $pt): ?><option value="<?= $pt ?>"<?= $period->type === $pt ? ' selected' : '' ?>><?= t('report.period.' . $pt) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field" data-period-group="week"><label for="r-week"><?= t('report.week') ?></label><input class="input" type="week" id="r-week" name="week" value="<?= e($period->week) ?>"></div>
  <div class="field" data-period-group="month"><label for="r-month"><?= t('report.month') ?></label><input class="input" type="month" id="r-month" name="month" value="<?= e($period->month) ?>"></div>
  <div class="field" data-period-group="range"><label for="r-from"><?= t('report.from') ?></label><input class="input" type="date" id="r-from" name="from" value="<?= e($period->from) ?>"></div>
  <div class="field" data-period-group="range"><label for="r-to"><?= t('report.to') ?></label><input class="input" type="date" id="r-to" name="to" value="<?= e($period->to) ?>"></div>
  <div class="field">
    <label for="r-type"><?= t('report.part_type') ?></label>
    <select class="input" id="r-type" name="part_type">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach (['new_mold', 'subcont'] as $pt): ?><option value="<?= $pt ?>"<?= ($f['part_type'] ?? null) === $pt ? ' selected' : '' ?>><?= t('part_type.' . $pt) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="r-cust"><?= t('npr.customer') ?></label>
    <select class="input" id="r-cust" name="customer_id">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= ($f['customer_id'] ?? null) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php if ($tab === 'weekly'): ?>
    <div class="field">
      <label for="r-npd"><?= t('project.npd_pic') ?></label>
      <select class="input" id="r-npd" name="npd_pic_id">
        <option value=""><?= t('common.all') ?></option>
        <?php foreach ($npdUsers as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $f['npd_pic_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
  <?php elseif ($tab === 'kpi'): ?>
    <div class="field">
      <label for="r-role"><?= t('kpi.role') ?></label>
      <select class="input" id="r-role" name="role">
        <option value=""><?= t('common.all') ?></option>
        <?php foreach (KpiService::ROLES as $r): ?><option value="<?= $r ?>"<?= $f['role'] === $r ? ' selected' : '' ?>><?= role_label($r) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="r-proc"><?= t('kpi.process_type') ?></label>
      <select class="input" id="r-proc" name="process">
        <option value=""><?= t('common.all') ?></option>
        <?php foreach ($processOptions as $o): ?><option value="<?= e($o['code']) ?>"<?= $f['process'] === $o['code'] ? ' selected' : '' ?>><?= e($o['code'] . ' ' . $o['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <div class="field"><button type="submit" class="btn btn-primary"><?= icon('filter') ?> <?= t('report.apply') ?></button></div>
</form>

<div class="period-nav">
  <a class="btn btn-sm" href="<?= e(url('reports.php', ['tab' => $tab] + $period->shift(-1)->query() + $fq)) ?>"><?= icon('chevron-left') ?> <?= t('report.prev') ?></a>
  <strong><?= e($period->label()) ?></strong>
  <a class="btn btn-sm" href="<?= e(url('reports.php', ['tab' => $tab] + $period->shift(1)->query() + $fq)) ?>"><?= t('report.next') ?> <?= icon('chevron-right') ?></a>
</div>

<?php if ($tab === 'weekly'): ?>
<?php $r = $report; ?>
<section class="stat-grid" aria-label="<?= t('report.summary') ?>">
  <?php foreach ($r['summary'] as $k => $v): ?>
    <div class="card stat<?= $k === 'overdue_now' && $v > 0 ? ' stat-danger' : '' ?>"><div class="stat-label"><?= t('report.s.' . $k) ?></div><div class="stat-value"><?= (int) $v ?></div></div>
  <?php endforeach; ?>
</section>

<h2 class="section-title"><?= t('report.top_issues') ?></h2>
<section class="card section" aria-labelledby="w-overdue">
  <div class="card-header"><h3 id="w-overdue"><?= t('report.overdue_list') ?> <span class="badge <?= $r['overdue'] ? 'badge-danger' : 'badge-neutral' ?>"><?= count($r['overdue']) ?></span></h3></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col"><?= t('project.project') ?></th><th scope="col"><?= t('project.part') ?></th><th scope="col"><?= t('process.process') ?></th><th scope="col">PIC</th><th scope="col" class="nowrap"><?= t('timeline.planned_finish') ?></th><th scope="col" class="right"><?= t('report.days_late') ?></th><th scope="col"><?= t('report.waiting_for') ?></th></tr></thead>
      <tbody>
        <?php if (!$r['overdue']): ?><tr><td colspan="7" class="table-empty"><?= t('report.none') ?></td></tr><?php endif; ?>
        <?php foreach ($r['overdue'] as $o): ?>
          <tr class="row-danger"><td class="mono small nowrap"><a href="<?= e(url('project.php', ['id' => $o['project_id']])) ?>"><?= e($o['project_code']) ?></a></td><td class="small"><?= e($o['part_name'] ?? '–') ?></td>
            <td class="small"><a href="<?= e(url('process.php', ['id' => $o['id']])) ?>"><?= e($o['process_label']) ?></a></td><td class="small"><?= e($o['pic_name'] ?? '–') ?></td>
            <td class="small nowrap"><?= fmt_date($o['planned_finish']) ?></td><td class="right"><?= (int) $o['overdue_days'] ?></td><td class="small"><?= t('report.waiting.' . $o['waiting']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<div class="grid grid-2 section-gap">
  <section class="card" aria-labelledby="w-problem">
    <div class="card-header"><h3 id="w-problem"><?= t('report.problem_list') ?> <span class="badge badge-neutral"><?= count($r['problems']) + count($r['stuck']) ?></span></h3></div>
    <div class="card-body">
      <?php if (!$r['problems'] && !$r['stuck']): ?><p class="muted small"><?= t('report.none') ?></p><?php endif; ?>
      <ul class="plain-list small">
        <?php foreach ($r['stuck'] as $s): ?>
          <li><span class="badge badge-danger"><?= t('status.problem') ?></span> <a href="<?= e(url('process.php', ['id' => $s['process_id']])) ?>"><span class="mono"><?= e($s['project_code']) ?></span> · <?= e($s['label']) ?></a> <span class="muted">· <?= e($s['pic_name'] ?? '–') ?></span></li>
        <?php endforeach; ?>
        <?php foreach ($r['problems'] as $p): ?>
          <li><span class="mono"><?= e($p['project_code']) ?></span> · <?= e($p['summary']) ?><?= $p['comment'] !== '' ? ' — ' . e($p['comment']) : '' ?>
            <div class="muted"><?= fmt_datetime($p['created_at']) ?><?= $p['label'] !== '' ? ' · PIC: ' . e($p['pic_name'] ?? '–') : '' ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <section class="card" aria-labelledby="w-reject">
    <div class="card-header"><h3 id="w-reject"><?= t('report.rejection_list') ?> <span class="badge badge-neutral"><?= count($r['rejections']) + count($r['npr_returns']) ?></span></h3></div>
    <div class="card-body">
      <?php if (!$r['rejections'] && !$r['npr_returns']): ?><p class="muted small"><?= t('report.none') ?></p><?php endif; ?>
      <ul class="plain-list small">
        <?php foreach ($r['rejections'] as $x): ?>
          <li><span class="mono"><?= e($x['project_code']) ?></span> · <?= e($x['label']) ?> · <?= t('approval.type.' . $x['approval_type']) ?><?= $x['comment'] ? ' — ' . e($x['comment']) : '' ?>
            <div class="muted"><?= fmt_datetime($x['decided_at']) ?> · PIC: <?= e($x['pic_name'] ?? '–') ?></div></li>
        <?php endforeach; ?>
        <?php foreach ($r['npr_returns'] as $n): ?>
          <li><span class="mono"><?= e($n['project_code']) ?></span> · <a href="<?= e(url('npr-edit.php', ['id' => $n['npr_id']])) ?>">NPR <?= e($n['npr_number']) ?></a> <?= t('report.npr_returned') ?><?= $n['reason'] !== '' ? ' — ' . e($n['reason']) : '' ?>
            <div class="muted"><?= fmt_datetime($n['created_at']) ?> · <?= e($n['by_name'] ?? '–') ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
</div>

<h2 class="section-title"><?= t('report.action_required') ?></h2>
<section class="card section">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col" class="nowrap"><?= t('next.due') ?></th><th scope="col"><?= t('project.project') ?></th><th scope="col"><?= t('project.part') ?></th><th scope="col"><?= t('report.action') ?></th><th scope="col">PIC</th><th scope="col"><?= t('common.status') ?></th></tr></thead>
      <tbody>
        <?php if (!$r['actions'] && !$r['due']): ?><tr><td colspan="6" class="table-empty"><?= t('report.none') ?></td></tr><?php endif; ?>
        <?php foreach ($r['actions'] as $a): ?>
          <tr<?= $a['late'] ? ' class="row-danger"' : '' ?>><td class="small nowrap"><?= fmt_date($a['due_date']) ?></td><td class="mono small nowrap"><a href="<?= e(url('project.php', ['id' => $a['project_id']])) ?>"><?= e($a['project_code']) ?></a></td>
            <td class="small"><?= e($a['part_name'] ?? '–') ?></td><td class="small"><?= e($a['description']) ?><div class="muted"><?= t('next.waiting_for') ?>: <?= t('next.waiting.' . $a['waiting_for']) ?><?= $a['waiting_for_note'] ? ' (' . e($a['waiting_for_note']) . ')' : '' ?></div></td>
            <td class="small"><?= e($a['owner_name'] ?? '–') ?></td><td><?= $a['late'] ? '<span class="badge badge-danger">' . t('next.late') . '</span>' : '<span class="badge badge-warning">' . t('report.next_action') . '</span>' ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($r['due'] as $d): ?>
          <tr><td class="small nowrap"><?= fmt_date($d['planned_finish']) ?></td><td class="mono small nowrap"><a href="<?= e(url('project.php', ['id' => $d['project_id']])) ?>"><?= e($d['project_code']) ?></a></td>
            <td class="small"><?= e($d['part_name'] ?? '–') ?></td><td class="small"><a href="<?= e(url('process.php', ['id' => $d['process_id']])) ?>"><?= e($d['label']) ?></a></td>
            <td class="small"><?= e($d['pic_name'] ?? '–') ?></td><td><span class="badge badge-neutral"><?= t('report.process_due') ?></span></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card section" aria-labelledby="w-text">
  <div class="card-header"><h3 id="w-text"><?= t('report.copy_text') ?></h3><button type="button" class="btn btn-sm" data-copy-target="weekly-text" data-copied="<?= t('report.copied') ?>"><?= icon('file-text') ?> <?= t('report.copy') ?></button></div>
  <div class="card-body"><label class="visually-hidden" for="weekly-text"><?= t('report.copy_text') ?></label><textarea class="input mono small report-text" id="weekly-text" rows="14" readonly><?= e($text) ?></textarea></div>
</section>

<?php elseif ($tab === 'analytics'): ?>
<?php $a = $analytics; ?>
<section class="stat-grid" aria-label="<?= t('report.summary') ?>">
  <div class="card stat"><div class="stat-label"><?= t('report.samples') ?></div><div class="stat-value"><?= (int) $a['totals']['count'] ?></div><div class="stat-sub"><?= t('report.samples_hint') ?></div></div>
  <div class="card stat"><div class="stat-label"><?= t('report.avg_actual') ?></div><div class="stat-value"><?= $num($a['totals']['avg_actual']) ?></div><div class="stat-sub"><?= t('report.working_days') ?></div></div>
  <div class="card stat"><div class="stat-label"><?= t('report.avg_planned') ?></div><div class="stat-value"><?= $num($a['totals']['avg_planned']) ?></div><div class="stat-sub"><?= t('report.working_days') ?></div></div>
  <div class="card stat"><div class="stat-label"><?= t('report.on_time_rate') ?></div><div class="stat-value"><?= $num($a['totals']['on_time_rate'], '%') ?></div></div>
</section>

<h2 class="section-title"><?= t('report.loops') ?></h2>
<div class="grid grid-3">
  <?php foreach ($a['loops'] as $key => $l): ?>
    <section class="card" aria-labelledby="loop-<?= $key ?>" data-loop="<?= e($key) ?>">
      <div class="card-header"><h3 id="loop-<?= $key ?>"><?= t('report.loop.' . $key) ?></h3><span class="badge <?= $l['count'] ? 'badge-warning' : 'badge-neutral' ?>"><?= (int) $l['count'] ?></span></div>
      <div class="card-body">
        <p class="small muted"><?= t('report.loop_hint.' . $key) ?> · <?= t('report.loop_parts', ['count' => $l['parts']]) ?></p>
        <ul class="plain-list small">
          <?php foreach (array_slice($l['items'], 0, 6) as $it): ?>
            <li><span class="mono"><?= e($it['project_code']) ?></span> · <?= e($it['label']) ?> <span class="muted">· <?= t('process.iteration_n', ['n' => (int) $it['iteration']]) ?> · <?= fmt_date($it['actual_finish']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<h2 class="section-title"><?= t('report.process_stats') ?></h2>
<section class="card section">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col"><?= t('process.process') ?></th><th scope="col"><?= t('report.part_type') ?></th><th scope="col" class="right"><?= t('report.samples') ?></th>
        <th scope="col" class="right"><?= t('report.avg_actual') ?></th><th scope="col" class="right"><?= t('report.avg_planned') ?></th><th scope="col" class="right"><?= t('report.avg_diff') ?></th>
        <th scope="col"><?= t('report.actual_vs_planned') ?></th><th scope="col" class="right"><?= t('report.on_time_rate') ?></th><th scope="col" class="right"><?= t('report.rejections') ?></th></tr></thead>
      <tbody>
        <?php if (!$a['stats']): ?><tr><td colspan="9" class="table-empty"><?= t('report.no_samples') ?></td></tr><?php endif; ?>
        <?php $maxDur = max(1, ...array_map(static fn ($s) => max($s['avg_actual'], $s['avg_planned']), $a['stats'] ?: [['avg_actual' => 1, 'avg_planned' => 1]])); ?>
        <?php foreach ($a['stats'] as $s): ?>
          <tr<?= $s['avg_diff'] > 0 ? ' class="row-warning"' : '' ?>>
            <td class="small"><?= e($s['code'] . ' ' . $s['name']) ?></td>
            <td class="small"><?= $s['part_type'] ? t('part_type.' . $s['part_type']) : t('project.project_level') ?></td>
            <td class="right small"><?= (int) $s['count'] ?></td><td class="right small"><?= $num($s['avg_actual']) ?></td><td class="right small"><?= $num($s['avg_planned']) ?></td>
            <td class="right small"><?= $signed($s['avg_diff']) ?></td>
            <td class="dual-bar-cell" aria-hidden="true"><span class="dual-bar"><span class="bar-fill bar-actual" style="--v: <?= round(100 * $s['avg_actual'] / $maxDur, 1) ?>%"></span><span class="bar-fill bar-planned" style="--v: <?= round(100 * $s['avg_planned'] / $maxDur, 1) ?>%"></span></span></td>
            <td class="right small"><?= $num($s['on_time_rate'], '%') ?></td><td class="right small"><?= (int) $s['rejections'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body"><p class="small muted"><span class="legend-swatch bar-actual"></span> <?= t('report.legend_actual') ?> <span class="legend-swatch bar-planned"></span> <?= t('report.legend_planned') ?> · <?= t('report.stats_note') ?></p></div>
</section>

<?php else: ?>
<?php $k = $kpi; $t = $k['totals']; ?>
<p class="small muted"><?= t('kpi.definition') ?></p>
<section class="stat-grid" aria-label="<?= t('kpi.overall') ?>">
  <div class="card stat"><div class="stat-label"><?= t('kpi.completed') ?></div><div class="stat-value"><?= (int) $t['completed'] ?></div></div>
  <div class="card stat"><div class="stat-label"><?= t('kpi.on_time_rate') ?></div><div class="stat-value"><?= $num($t['on_time_rate'], '%') ?></div><div class="stat-sub"><?= t('kpi.on_time_n', ['n' => (int) $t['on_time']]) ?></div></div>
  <div class="card stat"><div class="stat-label"><?= t('kpi.avg_actual_vs_planned') ?></div><div class="stat-value"><?= $num($t['avg_actual']) ?> / <?= $num($t['avg_planned']) ?></div><div class="stat-sub"><?= t('kpi.ratio') ?>: <?= $num($t['ratio'], '%') ?></div></div>
  <div class="card stat<?= $t['overdue'] > 0 ? ' stat-danger' : '' ?>"><div class="stat-label"><?= t('kpi.overdue') ?></div><div class="stat-value"><?= (int) $t['overdue'] ?></div></div>
</section>

<section class="card section" aria-labelledby="kpi-rank">
  <div class="card-header"><h2 id="kpi-rank"><?= t('kpi.ranking') ?></h2></div>
  <div class="table-wrap">
    <table class="table" data-kpi-table>
      <thead><tr><th scope="col">#</th><th scope="col">PIC</th><th scope="col"><?= t('kpi.role') ?></th><th scope="col" class="right"><?= t('kpi.completed') ?></th>
        <th scope="col" class="right"><?= t('kpi.on_time_rate') ?></th><th scope="col" class="right"><?= t('kpi.avg_actual') ?></th><th scope="col" class="right"><?= t('kpi.avg_planned') ?></th>
        <th scope="col" class="right"><?= t('kpi.avg_diff') ?></th><th scope="col" class="right"><?= t('kpi.ratio') ?></th><th scope="col" class="right"><?= t('kpi.overdue') ?></th></tr></thead>
      <tbody>
        <?php if (!$k['rows']): ?><tr><td colspan="10" class="table-empty"><?= t('report.no_samples') ?></td></tr><?php endif; ?>
        <?php foreach ($k['rows'] as $i => $row): ?>
          <tr<?= $f['pic'] === $row['pic_id'] ? ' class="row-selected"' : '' ?>>
            <td class="small"><?= $i + 1 ?></td>
            <td><a href="<?= e($selfUrl(['pic' => $row['pic_id']])) ?>#kpi-drill"><?= e($row['name']) ?></a></td>
            <td class="small"><?= role_label((string) $row['role']) ?></td>
            <td class="right"><?= (int) $row['completed'] ?></td>
            <td class="right"><?php if ($row['on_time_rate'] !== null): ?><span class="badge <?= $row['on_time_rate'] >= 80 ? 'badge-success' : ($row['on_time_rate'] >= 60 ? 'badge-warning' : 'badge-danger') ?>"><?= $num($row['on_time_rate'], '%') ?></span><?php else: ?>–<?php endif; ?></td>
            <td class="right small"><?= $num($row['avg_actual']) ?></td><td class="right small"><?= $num($row['avg_planned']) ?></td>
            <td class="right small<?= ($row['avg_diff'] ?? 0) > 0 ? ' text-danger' : '' ?>"><?= $signed($row['avg_diff']) ?></td>
            <td class="right small"><?= $num($row['ratio'], '%') ?></td>
            <td class="right"><?= $row['overdue'] > 0 ? '<span class="badge badge-danger">' . (int) $row['overdue'] . '</span>' : '0' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card section" aria-labelledby="kpi-trend">
  <div class="card-header"><h2 id="kpi-trend"><?= t('kpi.trend') ?><?= $k['detail'] ? ' — ' . e($k['detail']['pic']['name']) : '' ?></h2></div>
  <div class="card-body">
    <ul class="bars trend-bars">
      <?php foreach ($k['trend'] as $tr): ?>
        <li>
          <span class="bar-label"><?= t('report.month.' . (int) substr($tr['month'], 5)) ?> <?= substr($tr['month'], 0, 4) ?></span>
          <span class="bar-track" aria-hidden="true"><span class="bar-fill" style="--v: <?= $tr['rate'] ?? 0 ?>%"></span></span>
          <span class="bar-value"><?= $tr['rate'] === null ? '–' : $num($tr['rate'], '%') ?> <span class="muted">(<?= (int) $tr['completed'] ?>)</span></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<?php if ($k['detail']): ?>
  <?php $d = $k['detail']; ?>
  <section class="card section" id="kpi-drill" aria-labelledby="kpi-drill-title">
    <div class="card-header">
      <h2 id="kpi-drill-title"><?= t('kpi.drilldown_for', ['name' => $d['pic']['name']]) ?></h2>
      <a class="btn btn-sm" href="<?= e($selfUrl()) ?>"><?= icon('x') ?> <?= t('common.close') ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col"><?= t('project.project') ?></th><th scope="col"><?= t('process.process') ?></th><th scope="col" class="right"><?= t('report.iteration') ?></th>
          <th scope="col" class="nowrap"><?= t('kpi.planned_finish_activation') ?></th><th scope="col" class="nowrap"><?= t('process.actual_finish') ?></th>
          <th scope="col" class="right"><?= t('kpi.actual_days') ?></th><th scope="col" class="right"><?= t('kpi.planned_days') ?></th><th scope="col" class="right"><?= t('kpi.hold_days') ?></th><th scope="col"><?= t('kpi.on_time') ?></th></tr></thead>
        <tbody>
          <?php if (!$d['runs']): ?><tr><td colspan="9" class="table-empty"><?= t('report.no_samples') ?></td></tr><?php endif; ?>
          <?php foreach ($d['runs'] as $run): ?>
            <tr<?= $run['on_time'] === false ? ' class="row-danger"' : '' ?>>
              <td class="mono small nowrap"><a href="<?= e(url('project.php', ['id' => $run['project_id']])) ?>"><?= e($run['project_code']) ?></a></td>
              <td class="small"><a href="<?= e(url('process.php', ['id' => $run['process_id']])) ?>"><?= e($run['label']) ?></a></td>
              <td class="right small"><?= (int) $run['iteration'] ?></td>
              <td class="small nowrap"><?= fmt_date($run['planned_finish_at_activation']) ?></td><td class="small nowrap"><?= fmt_date($run['actual_finish']) ?></td>
              <td class="right small"><?= (int) $run['actual_days'] ?></td><td class="right small"><?= (int) $run['planned_days'] ?></td><td class="right small"><?= (int) $run['hold_working_days'] ?></td>
              <td><?= $run['on_time'] === null ? '–' : ($run['on_time'] ? '<span class="badge badge-success">' . t('common.yes') . '</span>' : '<span class="badge badge-danger">' . t('common.no') . '</span>') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($d['overdue']): ?>
      <div class="card-body">
        <p class="small"><strong><?= t('kpi.overdue_runs') ?></strong></p>
        <ul class="plain-list small">
          <?php foreach ($d['overdue'] as $o): ?>
            <li><span class="mono"><?= e($o['project_code']) ?></span> · <a href="<?= e(url('process.php', ['id' => $o['process_id']])) ?>"><?= e($o['label']) ?></a>
              <span class="muted">· <?= !empty($o['current']) ? t('kpi.overdue_now', ['days' => (int) ($o['overdue_days'] ?? 0)]) : t('kpi.overdue_since', ['date' => I18n::date($o['overdue_since'])]) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
