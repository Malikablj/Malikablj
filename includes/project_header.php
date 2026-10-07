<?php
declare(strict_types=1);

/**
 * Kepala halaman project (breadcrumb, judul, lencana, tab) — dipakai project.php & timeline.php.
 * @var array<string,mixed> $project
 * @var int $id
 * @var string $tab overview|processes|timeline|history
 * @var string|null $crumbPart nama part (timeline Level 2)
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
  </div>
</div>

<nav class="tabs" aria-label="<?= t('project.tabs') ?>">
  <?php foreach (['overview' => 'project.tab.overview', 'processes' => 'project.tab.processes', 'timeline' => 'project.tab.timeline', 'history' => 'project.tab.history'] as $k => $label): ?>
    <?php $__href = $k === 'timeline' ? url('timeline.php', ['project' => $id]) : url('project.php', ['id' => $id, 'tab' => $k]); ?>
    <a href="<?= e($__href) ?>"<?= $tab === $k ? ' class="active" aria-current="page"' : '' ?>><?= t($label) ?></a>
  <?php endforeach; ?>
</nav>

