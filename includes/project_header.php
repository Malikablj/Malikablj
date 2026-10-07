<?php
declare(strict_types=1);

/**
 * Kepala halaman project (breadcrumb, judul, lencana, tab) — dipakai project.php & timeline.php.
 * @var array<string,mixed> $project
 * @var int $id
 * @var string $tab overview|processes|timeline|history
 * @var string|null $crumbPart nama part (timeline Level 2)
 * @var string|null $headerActions HTML tombol tambahan (sudah di-escape) di kanan judul
 */
?>
<nav class="breadcrumbs" aria-label="<?= t('common.breadcrumbs') ?>">
  <a href="<?= e(url('projects.php')) ?>"><?= t('project.list_title') ?></a><span class="breadcrumbs-sep">/</span>
  <?php if (!empty($crumbPart)): ?>
    <a href="<?= e(url('timeline.php', ['project' => $id])) ?>" class="mono"><?= e($project['code']) ?></a><span class="breadcrumbs-sep">/</span><span aria-current="page"><?= e($crumbPart) ?></span>
  <?php else: ?>
    <span class="mono" aria-current="page"><?= e($project['code']) ?></span>
  <?php endif; ?>
</nav>
<div class="page-header">
  <div>
    <p class="eyebrow mono"><?= e($project['code']) ?> · <?= e($project['npr_number']) ?></p>
    <h1><?= e($project['name']) ?></h1>
    <p>
      <?= status_badge((string) $project['status']) ?>
      <?php if ($project['at_risk']): ?><span class="badge badge-warning"><?= icon('flag', 'icon icon-sm') ?> <?= t('project.at_risk') ?></span><?php endif; ?>
      <?php if ((int) $project['is_archived'] === 1): ?><span class="badge badge-neutral"><?= t('project.archived_badge') ?></span><?php endif; ?>
      <span class="badge badge-neutral"><?= t('project.priority.' . $project['priority']) ?></span>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('npr-edit.php', ['id' => $project['npr_id']])) ?>"><?= icon('file-text') ?> <?= t('project.open_npr') ?></a>
    <?= $headerActions ?? '' ?>
  </div>
</div>

<?php if ((int) $project['is_archived'] === 1): ?>
  <?php $__arch = \App\Core\Db::value('SELECT name FROM users WHERE id = ?', [(int) $project['archived_by']]); ?>
  <div class="flash flash-info" role="status"><?= icon('archive') ?><span><?= t('lifecycle.archived_banner', ['date' => \App\Core\I18n::dateTime($project['archived_at']), 'user' => (string) ($__arch ?: '–'), 'reason' => (string) $project['archive_reason']]) ?></span></div>
<?php endif; ?>
<?php if ($project['cancelled_at'] !== null): ?>
  <div class="flash flash-info" role="status"><?= icon('x') ?><span><?= t('lifecycle.cancelled_banner', ['date' => \App\Core\I18n::dateTime($project['cancelled_at']), 'reason' => (string) $project['cancel_reason']]) ?></span></div>
<?php endif; ?>
<?php foreach ((new \App\Project\HoldService())->openHolds($id) as $__h): ?>
  <?php $__days = (int) floor((\App\Core\Clock::now()->getTimestamp() - strtotime((string) $__h['held_at'])) / 86400); ?>
  <div class="flash flash-warning hold-banner" role="status"><?= icon('pause') ?>
    <span><?= $__h['part_id'] === null
        ? t('hold.banner_project', ['date' => \App\Core\I18n::date(substr((string) $__h['held_at'], 0, 10)), 'days' => $__days, 'reason' => (string) $__h['reason']])
        : t('hold.banner_part', ['part' => (string) $__h['part_name'], 'date' => \App\Core\I18n::date(substr((string) $__h['held_at'], 0, 10)), 'reason' => (string) $__h['reason']]) ?>
      <?php if ($__h['expected_resume_date']): ?><span class="muted"> · <?= t('hold.expected', ['date' => \App\Core\I18n::date($__h['expected_resume_date'])]) ?></span><?php endif; ?></span>
  </div>
<?php endforeach; ?>
<?php $__overdue = (int) $project['is_on_hold'] === 1 ? [] : (new \App\Notification\OverdueService())->overdueProcesses(['project_id' => $id]); ?>
<?php if ($__overdue): ?>
  <div class="flash flash-error overdue-banner" role="alert">
    <?= icon('alert') ?>
    <div>
      <?php foreach (array_slice($__overdue, 0, 5) as $__o): ?>
        <div><strong><?= t('status.overdue') ?>:</strong> <a href="<?= e(url('process.php', ['id' => $__o['id']])) ?>"><?= e(($__o['part_name'] ? $__o['part_name'] . ' › ' : '') . \App\Project\ProjectQuery::processName($__o)) ?></a>
          · PIC: <?= e($__o['pic_name'] ?? t('project.no_pic')) ?> · <?= t('notif.days_late', ['days' => $__o['overdue_days']]) ?></div>
      <?php endforeach; ?>
      <?php if (count($__overdue) > 5): ?><div class="small"><?= t('project.more_n', ['count' => count($__overdue) - 5]) ?></div><?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if ($project['at_risk'] && !$__overdue): ?>
  <div class="flash flash-warning" role="status"><?= icon('flag') ?><span><?= t('project.risk_banner', ['forecast' => \App\Core\I18n::date($project['forecast_finish']), 'target' => \App\Core\I18n::date($project['target_finish'])]) ?></span></div>
<?php endif; ?>

<nav class="tabs" aria-label="<?= t('project.tabs') ?>">
  <?php foreach (['overview' => 'project.tab.overview', 'processes' => 'project.tab.processes', 'timeline' => 'project.tab.timeline', 'approvals' => 'project.tab.approvals',
                  'documents' => 'project.tab.documents', 'records' => 'project.tab.records', 'history' => 'project.tab.history', 'activity' => 'project.tab.activity'] as $k => $label): ?>
    <?php $__href = $k === 'timeline' ? url('timeline.php', ['project' => $id]) : url('project.php', ['id' => $id, 'tab' => $k]); ?>
    <a href="<?= e($__href) ?>"<?= $tab === $k ? ' class="active" aria-current="page"' : '' ?>><?= t($label) ?></a>
  <?php endforeach; ?>
</nav>

