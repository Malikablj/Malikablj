<?php

use App\Helpers\Form;
use App\Models\FollowUp;

/** @var string $tab @var array<string,int> $counts @var App\Helpers\Paginator $followups @var array<string,mixed> $filters @var string $today */
$keep = array_filter(['q' => $filters['q'], 'pic' => $filters['pic'] ?: null, 'type' => $filters['type'], 'customer_id' => $filters['customer_id'] ?: null]);
$ret = '/follow-ups?tab=' . $tab;
$emptyText = [
    'today' => ['Tidak ada follow up hari ini', 'Semua jadwal hari ini sudah beres.'],
    'upcoming' => ['Belum ada jadwal mendatang', 'Jadwalkan follow up agar tidak ada customer yang terlewat.'],
    'overdue' => ['Tidak ada yang overdue', 'Bagus — semua follow up tepat waktu.'],
    'completed' => ['Belum ada yang selesai', 'Follow up yang ditandai Done atau Cancelled muncul di sini.'],
    'all' => ['Belum ada follow up', 'Jadwalkan follow up pertama.'],
][$tab];
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Customer &amp; CRM</div>
        <h1 class="page-title">Follow Up</h1>
        <p class="page-subtitle">Overdue otomatis bila tanggal sudah lewat dan status belum Done.</p>
    </div>
    <?php if (can('followups.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/follow-ups/create')) ?>" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> Jadwalkan</a></div>
    <?php endif; ?>
</div>

<nav class="tabs-pik" aria-label="Kelompok follow up">
    <?php foreach (FollowUp::TABS as $key => $label): ?>
        <a href="<?= e(url('/follow-ups', ['tab' => $key] + $keep)) ?>" class="<?= $tab === $key ? 'active' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?> <span class="tab-count<?= $key === 'overdue' && $counts['overdue'] > 0 ? ' text-danger' : '' ?>"><?= (int) $counts[$key] ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/follow-ups')) ?>">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari tujuan, customer, lead…" aria-label="Cari follow up"></div>
        <select class="form-select" name="pic" aria-label="PIC" data-autosubmit>
            <option value="">Semua PIC</option>
            <?= Form::options($pics, (string) $filters['pic']) ?>
        </select>
        <select class="form-select" name="type" aria-label="Jenis" data-autosubmit><option value="">Semua jenis</option><?= Form::options(Form::list(FollowUp::TYPES), $filters['type']) ?></select>
        <button class="btn btn-light" type="submit">Terapkan</button>
        <?php if ($keep): ?><a class="btn btn-link-plain small" href="<?= e(url('/follow-ups', ['tab' => $tab])) ?>">Reset</a><?php endif; ?>
    </form>

    <?php if ($followups->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-calendar2-check"></i><div class="empty-title"><?= e($emptyText[0]) ?></div><p><?= e($emptyText[1]) ?></p></div>
    <?php else: ?>
        <ul class="list-lite">
            <?php foreach ($followups->items as $f): $ov = (int) $f['is_overdue'] === 1; $closed = in_array($f['status'], FollowUp::CLOSED_STATUSES, true); ?>
                <li>
                    <div class="date-block">
                        <div class="fw-bold fs-5 lh-1 <?= $ov ? 'is-negative' : '' ?>"><?= e(date('j', strtotime((string) $f['follow_up_date']))) ?></div>
                        <div class="x-small text-secondary text-uppercase"><?= e(ID_MONTHS[(int) date('n', strtotime((string) $f['follow_up_date']))]) ?> <?= e(date('y', strtotime((string) $f['follow_up_date']))) ?></div>
                    </div>
                    <span class="kpi-icon d-none d-sm-inline-flex"><i class="bi <?= e(activity_icon($f['follow_up_type'])) ?>"></i></span>
                    <div class="li-main">
                        <a class="li-title" href="<?= e(url('/follow-ups/' . $f['id'] . '/edit', ['return' => $ret])) ?>"><?= e($f['purpose']) ?></a>
                        <div class="li-sub">
                            <?= e($f['customer_name'] ?? '') ?><?= $f['lead_name'] ? ($f['customer_name'] ? ' · ' : '') . 'Lead: ' . e($f['lead_name']) : '' ?>
                            <span class="dot-sep"></span><?= e($f['pic_name'] ?? 'Tanpa PIC') ?>
                            <?php if ($f['follow_up_time']): ?><span class="dot-sep"></span><?= e(substr((string) $f['follow_up_time'], 0, 5)) ?><?php endif; ?>
                        </div>
                        <?php if ($closed && $f['result']): ?><div class="li-sub"><i class="bi bi-check2"></i> <?= e(excerpt($f['result'], 100)) ?></div><?php endif; ?>
                    </div>
                    <div class="li-end d-flex align-items-center gap-2">
                        <span class="d-none d-md-inline"><?= $ov ? status_badge('Overdue') : status_badge($f['status']) ?></span>
                        <?php if (!$closed && can('followups.edit')): ?>
                            <form method="post" action="<?= e(url('/follow-ups/' . $f['id'] . '/done')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($ret) ?>">
                                <button class="btn btn-success-soft btn-sm" type="submit" title="Tandai selesai"><i class="bi bi-check2"></i><span class="d-none d-lg-inline"> Selesai</span></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?= $followups->footer('follow up') ?>
    <?php endif; ?>
</div>
