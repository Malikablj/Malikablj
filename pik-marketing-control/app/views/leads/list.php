<?php

use App\Helpers\View;
use App\Models\Lead;

/** @var App\Helpers\Paginator $leads @var array<string,mixed> $filters @var string $today */
$keep = array_filter(['q' => $filters['q'], 'pic' => $filters['pic'] ?: null, 'priority' => $filters['priority']]);
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Customer &amp; CRM</div>
        <h1 class="page-title">Leads</h1>
        <p class="page-subtitle"><?= e(fmt_qty($leads->total, '0')) ?> lead</p>
    </div>
    <div class="page-actions">
        <div class="tabs-pik mb-0">
            <a href="<?= e(url('/leads', $keep)) ?>"><i class="bi bi-kanban"></i> Board</a>
            <a href="<?= e(url('/leads/list', $keep)) ?>" class="active"><i class="bi bi-list-ul"></i> List</a>
        </div>
        <?php if (can('leads.create')): ?><a href="<?= e(url('/leads/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buat Lead</a><?php endif; ?>
    </div>
</div>

<div class="surface">
    <?= View::partial('leads/_filters', ['filters' => $filters, 'pics' => $pics, 'action' => '/leads/list', 'showStatus' => true]) ?>
    <?php if ($leads->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-kanban"></i><div class="empty-title">Tidak ada lead</div><p>Buat lead untuk mulai memantau peluang penjualan.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr>
                    <th><?= sort_link('name', 'Lead', $sort, $dir) ?></th>
                    <th><?= sort_link('status', 'Status', $sort, $dir) ?></th>
                    <th class="d-none d-md-table-cell">Prioritas</th>
                    <th class="num"><?= sort_link('value', 'Potensi', $sort, $dir) ?></th>
                    <th class="d-none d-md-table-cell"><?= sort_link('close', 'Target closing', $sort, $dir) ?></th>
                    <th class="d-none d-lg-table-cell">PIC</th>
                    <th class="d-none d-xl-table-cell"><?= sort_link('created', 'Dibuat', $sort, $dir) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($leads->items as $l): $late = $l['expected_close_date'] && $l['expected_close_date'] < $today && in_array($l['status'], Lead::OPEN_STATUSES, true); ?>
                    <tr>
                        <td><a class="cell-title" href="<?= e(url('/leads/' . $l['id'])) ?>"><?= e($l['lead_name']) ?></a>
                            <div class="cell-sub"><?= e($l['customer_name'] ?? ($l['company_name'] ? $l['company_name'] . ' (prospek)' : '')) ?></div></td>
                        <td><?= status_badge($l['status']) ?></td>
                        <td class="d-none d-md-table-cell"><?= status_badge($l['priority']) ?></td>
                        <td class="num"><?= e(fmt_money($l['potential_value'])) ?></td>
                        <td class="d-none d-md-table-cell nowrap<?= $late ? ' is-negative fw-semibold' : '' ?>"><?= e(fmt_date($l['expected_close_date'])) ?></td>
                        <td class="d-none d-lg-table-cell"><?= e($l['pic_name'] ?? '—') ?></td>
                        <td class="d-none d-xl-table-cell text-secondary nowrap"><?= e(fmt_date($l['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $leads->footer('lead') ?>
    <?php endif; ?>
</div>
