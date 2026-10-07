<?php
declare(strict_types=1);

/**
 * Renderer Gantt (PRD §6.6, §6.8, §11): HTML/CSS di server — posisi batang memakai variabel CSS
 * (--s = offset hari, --d = jumlah hari; lebar per hari --day ditentukan zoom). JavaScript (gantt.js)
 * hanya untuk zoom, gulir ke hari ini, toggle baseline/jalur kritis, dan menggambar panah dependency.
 */

use App\Core\I18n;
use App\Scheduling\WorkingCalendar;

/** Selisih hari kalender (b - a). */
function gantt_days(string $a, string $b): int
{
    return (int) round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400);
}

/** Atribut posisi batang. */
function gantt_pos(string $from, ?string $start, ?string $end): string
{
    if ($start === null || $end === null) {
        return '';
    }
    return '--s:' . gantt_days($from, $start) . ';--d:' . (gantt_days($start, $end) + 1);
}

/**
 * @param array<string,mixed> $data hasil TimelineService::project/part atau GanttQuery
 * @param array{id?:string,label?:string,link?:callable,deps?:bool,baseline?:bool,critical?:bool,zoom?:string,sub?:callable} $opt
 */
function render_gantt(array $data, array $opt = []): string
{
    $from = (string) $data['from'];
    $to = (string) $data['to'];
    $days = gantt_days($from, $to) + 1;
    $id = $opt['id'] ?? 'gantt';
    $link = $opt['link'] ?? null;
    $sub = $opt['sub'] ?? null;
    $zoom = $opt['zoom'] ?? ($days > 420 ? 'month' : 'week');
    $showDeps = !empty($opt['deps']);
    $hasBaseline = (bool) array_filter($data['rows'], static fn ($r) => !empty($r['baseline_start']));
    $hasCritical = !empty($opt['critical']) && (bool) array_filter($data['rows'], static fn ($r) => !empty($r['critical']));

    ob_start();
    ?>
<div class="gantt" id="<?= e($id) ?>" data-gantt data-zoom="<?= e($zoom) ?>" style="--days: <?= $days ?>" data-today-offset="<?= gantt_days($from, (string) $data['today']) ?>">
  <div class="gantt-toolbar">
    <div class="segmented" role="group" aria-label="<?= t('gantt.zoom') ?>">
      <?php foreach (['day', 'week', 'month'] as $z): ?>
        <button type="button" class="segmented-item<?= $z === $zoom ? ' is-active' : '' ?>" data-gantt-zoom="<?= $z ?>" aria-pressed="<?= $z === $zoom ? 'true' : 'false' ?>"><?= t('gantt.zoom_' . $z) ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($hasBaseline): ?><label class="check"><input type="checkbox" data-gantt-toggle="baseline" checked> <span><?= t('gantt.show_baseline') ?></span></label><?php endif; ?>
    <?php if ($hasCritical): ?><label class="check"><input type="checkbox" data-gantt-toggle="critical"> <span><?= t('gantt.show_critical') ?></span></label><?php endif; ?>
    <?php if ($showDeps): ?><label class="check"><input type="checkbox" data-gantt-toggle="deps" checked> <span><?= t('gantt.show_deps') ?></span></label><?php endif; ?>
    <button type="button" class="btn btn-sm" data-gantt-today><?= icon('calendar', 'icon icon-sm') ?> <?= t('gantt.today') ?></button>
  </div>
  <div class="gantt-scroll" tabindex="0" role="region" aria-label="<?= e($opt['label'] ?? I18n::t('gantt.chart')) ?>">
    <div class="gantt-inner<?= $hasBaseline ? ' show-baseline' : '' ?><?= $showDeps ? ' show-deps' : '' ?>">
      <div class="gantt-head">
        <div class="gantt-label gantt-label-head"><?= e($opt['label_head'] ?? I18n::t('process.process')) ?></div>
        <div class="gantt-scale" aria-hidden="true">
          <div class="gantt-scale-row gantt-months">
            <?php
            $d = $from;
            while ($d <= $to) {
                $monthEnd = date('Y-m-t', strtotime($d . ' 12:00:00'));
                $end = min($monthEnd, $to);
                echo '<span style="' . gantt_pos($from, $d, $end) . '">' . e(I18n::monthName((int) substr($d, 5, 2), null, false) . ' ' . substr($d, 0, 4)) . '</span>';
                $d = WorkingCalendar::shift($monthEnd, 1);
            }
            ?>
          </div>
          <div class="gantt-scale-row gantt-weeks">
            <?php for ($d = $from; $d <= $to; $d = WorkingCalendar::shift($d, 7)): ?><span style="<?= gantt_pos($from, $d, min(WorkingCalendar::shift($d, 6), $to)) ?>"><?= e(I18n::dateShort($d)) ?></span><?php endfor; ?>
          </div>
          <div class="gantt-scale-row gantt-days">
            <?php for ($d = $from, $i = 0; $d <= $to; $d = WorkingCalendar::shift($d, 1), $i++): ?><span style="--s:<?= $i ?>;--d:1"<?= (int) date('N', strtotime($d . ' 12:00:00')) >= 6 ? ' class="we"' : '' ?>><?= (int) substr($d, 8, 2) ?></span><?php endfor; ?>
          </div>
        </div>
      </div>
      <div class="gantt-body">
        <div class="gantt-overlay" aria-hidden="true">
          <?php foreach ($data['holidays'] as $h): ?><span class="gantt-holiday" style="<?= gantt_pos($from, $h['date'], $h['date']) ?>" title="<?= e($h['name']) ?>"></span><?php endforeach; ?>
          <span class="gantt-today" style="--s:<?= gantt_days($from, (string) $data['today']) ?>" title="<?= t('gantt.today') ?>"></span>
          <?php if (!empty($data['target'])): ?><span class="gantt-target" style="--s:<?= gantt_days($from, (string) $data['target']) + 1 ?>" title="<?= t('project.target_finish') ?> <?= e(I18n::date((string) $data['target'])) ?>"></span><?php endif; ?>
        </div>
        <?php foreach ($data['rows'] as $r): ?>
          <?php
          $cls = ['gantt-row', 'kind-' . $r['kind']];
          if ($r['overdue_days'] > 0) { $cls[] = 'is-overdue'; }
          if (!empty($r['skipped'])) { $cls[] = 'is-skipped'; }
          if (!empty($r['critical'])) { $cls[] = 'is-critical'; }
          if (!empty($r['cancelled'])) { $cls[] = 'is-cancelled'; }
          $href = $link ? $link($r) : null;
          $title = trim(($r['code'] !== '' ? $r['code'] . ' ' : '') . $r['name']);
          $tip = $title . ' — ' . (I18n::has('status.' . $r['status']) ? I18n::t('status.' . $r['status']) : $r['status'])
              . ($r['bar_start'] ? ' · ' . I18n::date($r['bar_start']) . ' – ' . I18n::date($r['bar_end']) : '')
              . ($r['overdue_days'] > 0 ? ' · ' . I18n::t('project.overdue_n', ['days' => $r['overdue_days']]) : '')
              . (!empty($r['pic']) ? ' · ' . $r['pic'] : '');
          $statusCls = 'st-' . preg_replace('/[^a-z_]/', '', (string) $r['status']);
          ?>
          <div class="<?= e(implode(' ', $cls)) ?>" data-row-id="<?= (int) $r['id'] ?>">
            <div class="gantt-label">
              <?php if ($href): ?><a href="<?= e($href) ?>"><?php endif; ?>
                <?php if ($r['code'] !== ''): ?><span class="mono small"><?= e($r['code']) ?></span> <?php endif; ?><span class="gantt-name"><?= e($r['name']) ?></span>
              <?php if ($href): ?></a><?php endif; ?>
              <span class="gantt-meta">
                <?php if ($r['overdue_days'] > 0): ?><span class="gantt-flag" title="<?= e(I18n::t('project.overdue_n', ['days' => $r['overdue_days']])) ?>"><?= icon('alert', 'icon icon-sm') ?> <?= (int) ($r['overdue_count'] ?? 0) > 1 ? (int) $r['overdue_count'] . '× · ' : '' ?><?= t('project.overdue_n', ['days' => $r['overdue_days']]) ?></span><?php endif; ?>
                <?php if (!empty($r['manual'])): ?><span title="<?= t('gantt.manual_date') ?>"><?= icon('lock', 'icon icon-sm') ?></span><?php endif; ?>
                <?= e($sub ? (string) $sub($r) : (string) ($r['pic'] ?? '')) ?>
              </span>
            </div>
            <div class="gantt-track">
              <?php if (!empty($r['target'])): ?><span class="gantt-marker" style="--s:<?= gantt_days($from, (string) $r['target']) + 1 ?>" title="<?= e(I18n::t('project.target_finish') . ' ' . I18n::date((string) $r['target'])) ?>"></span><?php endif; ?>
              <?php if (!empty($r['baseline_start'])): ?><span class="gantt-baseline" style="<?= gantt_pos($from, $r['baseline_start'], $r['baseline_finish']) ?>" title="<?= e(I18n::t('gantt.baseline') . ': ' . I18n::date($r['baseline_start']) . ' – ' . I18n::date($r['baseline_finish'])) ?>"></span><?php endif; ?>
              <?php if (!empty($r['bar_start'])): ?>
                <span class="gantt-bar <?= e($statusCls) ?>" style="<?= gantt_pos($from, $r['bar_start'], $r['bar_end']) ?>" data-bar="<?= (int) $r['id'] ?>"
                  <?php if (!empty($r['deps'])): ?> data-deps="<?= e(implode(',', array_map(static fn ($d) => $d['id'] . ':' . $d['type'], $r['deps']))) ?>"<?php endif; ?>
                  title="<?= e($tip) ?>" role="img" aria-label="<?= e($tip) ?>">
                  <?php if (isset($r['progress']) && $r['kind'] === 'part'): ?><span class="gantt-progress" style="width: <?= (int) $r['progress'] ?>%"></span><?php endif; ?>
                </span>
                <?php if ($r['overdue_days'] > 0 && !empty($r['planned_finish']) && $r['kind'] !== 'part' && $r['bar_end'] > $r['planned_finish']): ?>
                  <span class="gantt-bar-late" style="<?= gantt_pos($from, WorkingCalendar::shift((string) $r['planned_finish'], 1), (string) $r['bar_end']) ?>" aria-hidden="true"></span>
                <?php endif; ?>
              <?php elseif (!empty($r['skipped'])): ?>
                <span class="gantt-skipped-note small muted"><?= t('status.skipped') ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($showDeps): ?><svg class="gantt-arrows" aria-hidden="true" focusable="false"></svg><?php endif; ?>
    </div>
  </div>
  <div class="gantt-legend small">
    <span class="legend-item"><span class="swatch st-not_started"></span><?= t('status.not_started') ?></span>
    <span class="legend-item"><span class="swatch st-current"></span><?= t('gantt.legend_running') ?></span>
    <span class="legend-item"><span class="swatch st-completed"></span><?= t('status.completed') ?></span>
    <span class="legend-item"><span class="swatch late"></span><?= t('status.overdue') ?></span>
    <?php if ($hasBaseline): ?><span class="legend-item"><span class="swatch baseline"></span><?= t('gantt.baseline') ?></span><?php endif; ?>
    <span class="legend-item"><span class="swatch today"></span><?= t('gantt.today') ?></span>
    <?php if (!empty($data['target']) || array_filter($data['rows'], static fn ($r) => !empty($r['target']))): ?><span class="legend-item"><span class="swatch target"></span><?= t('project.target_finish') ?></span><?php endif; ?>
    <span class="legend-item"><span class="swatch weekend"></span><?= t('gantt.non_working') ?></span>
  </div>
</div>
    <?php
    return (string) ob_get_clean();
}
