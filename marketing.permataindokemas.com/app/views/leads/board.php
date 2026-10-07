<?php

use App\Helpers\Number;
use App\Helpers\View;
use App\Models\Lead;

/** @var array<string,array{items:list<array<string,mixed>>,count:int,value:float}> $board @var array<string,mixed> $filters @var string $today */
$openCount = 0;
$openValue = 0.0;
foreach (Lead::OPEN_STATUSES as $s) {
    $openCount += $board[$s]['count'];
    $openValue += $board[$s]['value'];
}
$canEdit = can('leads.edit');
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Customer &amp; CRM</div>
        <h1 class="page-title">Leads</h1>
        <p class="page-subtitle"><?= e(fmt_qty($openCount, '0')) ?> lead aktif · pipeline <?= e(fmt_money($openValue, 'Rp 0')) ?></p>
    </div>
    <div class="page-actions">
        <div class="tabs-pik mb-0">
            <a href="<?= e(url('/leads', array_filter(['q' => $filters['q'], 'pic' => $filters['pic'] ?: null, 'priority' => $filters['priority']]))) ?>" class="active"><i class="bi bi-kanban"></i> Board</a>
            <a href="<?= e(url('/leads/list', array_filter(['q' => $filters['q'], 'pic' => $filters['pic'] ?: null, 'priority' => $filters['priority']]))) ?>"><i class="bi bi-list-ul"></i> List</a>
        </div>
        <?php if (can('leads.create')): ?>
            <a href="<?= e(url('/leads/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buat Lead</a>
        <?php endif; ?>
    </div>
</div>

<div class="surface section-gap">
    <?= View::partial('leads/_filters', ['filters' => $filters, 'pics' => $pics, 'action' => '/leads', 'showStatus' => false]) ?>
</div>

<?php if ($canEdit): ?>
    <p class="small text-secondary mb-2 d-none d-lg-block"><i class="bi bi-arrows-move"></i> Seret kartu ke kolom lain untuk mengubah status, atau gunakan pilihan status di kartu.</p>
<?php endif; ?>

<div class="kanban" data-kanban>
    <?php foreach ($board as $status => $col): ?>
        <section class="kanban-col" data-status="<?= e($status) ?>" aria-label="Kolom <?= e($status) ?>">
            <header class="kanban-col-header">
                <div class="kanban-col-title"><span class="kanban-dot" style="background: <?= e(Lead::tone($status)) ?>"></span><?= e($status) ?>
                    <span class="tab-count" data-col-count><?= (int) $col['count'] ?></span></div>
                <div class="kanban-col-meta" data-col-value data-value="<?= e((string) $col['value']) ?>"><?= e($col['value'] > 0 ? 'Rp ' . Number::compact($col['value']) : '') ?></div>
            </header>
            <div class="kanban-cards">
                <?php if (!$col['items']): ?>
                    <div class="kanban-empty" data-empty>Belum ada lead</div>
                <?php endif; ?>
                <?php foreach ($col['items'] as $l): $late = $l['expected_close_date'] && $l['expected_close_date'] < $today && in_array($l['status'], Lead::OPEN_STATUSES, true); ?>
                    <article class="kanban-card"<?= $canEdit ? ' draggable="true"' : '' ?> data-id="<?= (int) $l['id'] ?>" data-value="<?= e((string) ($l['potential_value'] ?? 0)) ?>" data-status-url="<?= e(url('/leads/' . $l['id'] . '/status')) ?>">
                        <a class="kanban-card-title" href="<?= e(url('/leads/' . $l['id'])) ?>"><?= e($l['lead_name']) ?></a>
                        <div class="kanban-card-sub"><?= e($l['customer_name'] ?? ($l['company_name'] ? $l['company_name'] . ' (prospek)' : '—')) ?></div>
                        <div class="kanban-card-foot">
                            <span class="fw-semibold tabular"><?= e($l['potential_value'] !== null ? 'Rp ' . Number::compact($l['potential_value']) : '—') ?></span>
                            <?= status_badge($l['priority']) ?>
                        </div>
                        <div class="kanban-card-foot">
                            <span class="<?= $late ? 'is-negative fw-semibold' : 'text-secondary' ?>" title="Target closing">
                                <i class="bi bi-flag"></i> <?= e($l['expected_close_date'] ? fmt_date($l['expected_close_date']) : 'Tanpa target') ?>
                            </span>
                            <?php if ($l['pic_name']): ?><span class="avatar avatar-sm" title="PIC: <?= e($l['pic_name']) ?>"><?= e(initials($l['pic_name'])) ?></span><?php endif; ?>
                        </div>
                        <?php if ($canEdit): ?>
                            <form method="post" action="<?= e(url('/leads/' . $l['id'] . '/status')) ?>" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return" value="/leads">
                                <label class="visually-hidden" for="ls-<?= (int) $l['id'] ?>">Pindahkan status</label>
                                <select class="form-select form-select-sm" id="ls-<?= (int) $l['id'] ?>" name="status" data-autosubmit data-kanban-select>
                                    <?php foreach (Lead::STATUSES as $s): ?><option value="<?= e($s) ?>"<?= selected($s, $l['status']) ?>><?= e($s) ?></option><?php endforeach; ?>
                                </select>
                                <noscript><button type="submit" class="btn btn-light btn-sm w-100 mt-1">Pindah</button></noscript>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($col['count'] > count($col['items'])): ?>
                    <a class="small text-center d-block" href="<?= e(url('/leads/list', ['status' => $status])) ?>">+<?= $col['count'] - count($col['items']) ?> lead lainnya</a>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
