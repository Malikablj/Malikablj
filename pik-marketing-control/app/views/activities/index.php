<?php

use App\Helpers\Form;
use App\Models\Activity;

/** @var App\Helpers\Paginator $activities @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['type'] !== '' || $filters['pic'] > 0 || $filters['customer_id'] > 0 || $filters['from'] !== '' || $filters['to'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Customer &amp; CRM</div>
        <h1 class="page-title">Activities</h1>
        <p class="page-subtitle">Riwayat interaksi dengan customer dan prospek.</p>
    </div>
    <?php if (can('activities.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/activities/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Log Aktivitas</a></div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/activities')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari judul, deskripsi, customer, lead…" aria-label="Cari aktivitas"></div>
        <select class="form-select" name="type" aria-label="Jenis" data-autosubmit><option value="">Semua jenis</option><?= Form::options(Form::list(Activity::TYPES), $filters['type']) ?></select>
        <select class="form-select" name="pic" aria-label="PIC" data-autosubmit><option value="">Semua PIC</option><?= Form::options($pics, (string) $filters['pic']) ?></select>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/activities')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($activities->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-chat-square-text"></i><div class="empty-title">Belum ada aktivitas</div><p>Catat interaksi pertama dengan customer.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Tanggal</th><th>Aktivitas</th><th class="d-none d-md-table-cell">Customer / Lead</th><th class="d-none d-lg-table-cell">PIC</th><th class="d-none d-xl-table-cell">Tindak lanjut</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($activities->items as $a): ?>
                    <tr>
                        <td class="nowrap"><div class="fw-semibold"><?= e(fmt_date($a['activity_date'])) ?></div><div class="cell-sub"><?= e(substr((string) $a['activity_date'], 11, 5)) ?></div></td>
                        <td><div class="d-flex gap-2 align-items-start"><span class="kpi-icon flex-shrink-0"><i class="bi <?= e(activity_icon($a['activity_type'])) ?>"></i></span>
                            <div><div class="cell-title"><?= e($a['subject']) ?></div><div class="cell-sub"><?= e($a['activity_type']) ?><?= $a['description'] ? ' · ' . e(excerpt($a['description'], 70)) : '' ?></div></div></div></td>
                        <td class="d-none d-md-table-cell small">
                            <?php if ($a['customer_id']): ?><a href="<?= e(url('/customers/' . $a['customer_id'])) ?>"><?= e($a['customer_name']) ?></a><?php endif; ?>
                            <?php if ($a['lead_id']): ?><div><a class="text-secondary" href="<?= e(url('/leads/' . $a['lead_id'])) ?>"><i class="bi bi-kanban"></i> <?= e($a['lead_name']) ?></a></div><?php endif; ?>
                        </td>
                        <td class="d-none d-lg-table-cell"><?= e($a['pic_name'] ?? '—') ?></td>
                        <td class="d-none d-xl-table-cell small text-secondary"><?= e($a['next_action'] ?? '') ?><?= $a['next_follow_up'] ? '<div>' . e(fmt_date($a['next_follow_up'])) . '</div>' : '' ?></td>
                        <td class="col-actions"><?php if (can('activities.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/activities/' . $a['id'] . '/edit', ['return' => '/activities'])) ?>">Edit</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $activities->footer('aktivitas') ?>
    <?php endif; ?>
</div>
