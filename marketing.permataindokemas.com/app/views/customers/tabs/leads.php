<?php /** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */ ?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Leads</h2><p class="surface-subtitle">Peluang penjualan untuk customer ini</p></div>
        <?php if (can('leads.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/leads/create', ['customer_id' => $customer['id']])) ?>"><i class="bi bi-plus-lg"></i> Buat lead</a>
        <?php endif; ?>
    </div>
    <?php if (!$data['leads']): ?>
        <div class="empty-state"><i class="bi bi-kanban"></i><div class="empty-title">Belum ada lead</div><p>Catat peluang order baru agar pipeline terpantau.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Lead</th><th>Status</th><th class="d-none d-md-table-cell">Prioritas</th><th class="num">Potensi</th><th class="d-none d-md-table-cell">Target closing</th><th class="d-none d-lg-table-cell">PIC</th></tr></thead>
                <tbody>
                <?php foreach ($data['leads'] as $l): ?>
                    <tr>
                        <td><a class="cell-title" href="<?= e(url('/leads/' . $l['id'])) ?>"><?= e($l['lead_name']) ?></a>
                            <div class="cell-sub"><?= e($l['product_interest'] ?: $l['code']) ?></div></td>
                        <td><?= status_badge($l['status']) ?></td>
                        <td class="d-none d-md-table-cell"><?= status_badge($l['priority']) ?></td>
                        <td class="num"><?= e(fmt_money($l['potential_value'])) ?></td>
                        <td class="d-none d-md-table-cell nowrap"><?= e(fmt_date($l['expected_close_date'])) ?></td>
                        <td class="d-none d-lg-table-cell"><?= e($l['pic_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
