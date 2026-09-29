<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
/** @var App\Helpers\Paginator $list */
$list = $data['followups'];
$ret = $base . '?tab=followups';
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Follow up</h2><p class="surface-subtitle">Otomatis overdue bila tanggal sudah lewat dan belum Done</p></div>
        <?php if (can('followups.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/follow-ups/create', ['customer_id' => $customer['id'], 'return' => $ret])) ?>"><i class="bi bi-plus-lg"></i> Jadwalkan</a>
        <?php endif; ?>
    </div>
    <?php if ($list->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-calendar2-check"></i><div class="empty-title">Belum ada follow up</div><p>Jadwalkan tindak lanjut agar tidak ada customer yang terlewat.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Tanggal</th><th>Tujuan</th><th>Status</th><th class="d-none d-md-table-cell">PIC</th><th class="d-none d-lg-table-cell">Hasil</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($list->items as $f): $overdue = (int) $f['is_overdue'] === 1; ?>
                    <tr>
                        <td class="nowrap"><div class="<?= $overdue ? 'is-negative fw-semibold' : 'fw-semibold' ?>"><?= e(fmt_date($f['follow_up_date'])) ?></div>
                            <div class="cell-sub"><?= e(relative_day($f['follow_up_date'])) ?></div></td>
                        <td><div class="cell-title"><?= e($f['purpose']) ?></div><div class="cell-sub"><i class="bi <?= e(activity_icon($f['follow_up_type'])) ?>"></i> <?= e($f['follow_up_type']) ?><?= $f['lead_name'] ? ' · ' . e($f['lead_name']) : '' ?></div></td>
                        <td><?= $overdue ? status_badge('Overdue') : status_badge($f['status']) ?></td>
                        <td class="d-none d-md-table-cell"><?= e($f['pic_name'] ?? '—') ?></td>
                        <td class="d-none d-lg-table-cell small text-secondary"><?= e(excerpt($f['result'] ?? '', 80) ?: '—') ?></td>
                        <td class="col-actions"><?php if (can('followups.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/follow-ups/' . $f['id'] . '/edit', ['return' => $ret])) ?>">Buka</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $list->footer('follow up') ?>
    <?php endif; ?>
</section>
