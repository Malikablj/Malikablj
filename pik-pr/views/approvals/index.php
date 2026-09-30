<?php
$actionLabels = ['approved' => 'Disetujui', 'rejected' => 'Ditolak', 'revision_required' => 'Minta revisi'];
$actionBadge = ['approved' => 'badge-approved', 'rejected' => 'badge-rejected', 'revision_required' => 'badge-revision-required'];
?>
<header class="page-header">
    <div>
        <h1>Approval</h1>
        <p class="subtitle">PR yang menjadi tanggung jawab Anda sesuai approval workflow.</p>
    </div>
</header>

<nav class="tabs" aria-label="Tab approval">
    <a class="tab<?= $tab === 'pending' ? ' is-active' : '' ?>" href="<?= e(url('/approvals')) ?>">Menunggu <span class="count"><?= count($pending) ?></span></a>
    <a class="tab<?= $tab === 'history' ? ' is-active' : '' ?>" href="<?= e(url('/approvals', ['tab' => 'history'])) ?>">Riwayat keputusan</a>
</nav>

<?php if ($tab === 'pending'): ?>
    <section class="card">
        <?php if ($pending === []): ?>
            <div class="empty">
                <?= icon('check-circle') ?>
                <strong>Tidak ada PR yang menunggu</strong>
                <span>Anda akan menerima notifikasi saat ada PR baru.</span>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-cards">
                    <thead>
                    <tr>
                        <th scope="col">No. PR</th>
                        <th scope="col">Tahap</th>
                        <th scope="col">Pemohon</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">Diajukan</th>
                        <th scope="col" class="text-right">Total</th>
                        <th scope="col"><span class="visually-hidden">Aksi</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $pr): ?>
                        <tr>
                            <td class="cell-primary" data-label="">
                                <a class="row-link" href="<?= e(url('/pr/' . $pr['id'])) ?>"><?= e(pr_label($pr)) ?></a>
                                <span class="sub"><?= e($pr['department_name']) ?></span>
                            </td>
                            <td data-label="Tahap"><span class="badge badge-submitted"><span class="badge-dot"></span><?= e($pr['current_step_label']) ?></span></td>
                            <td data-label="Pemohon"><?= e($pr['requester_name']) ?></td>
                            <td data-label="Supplier"><?= e($pr['supplier_name'] ?? '-') ?></td>
                            <td data-label="Diajukan" class="nowrap"><?= e(time_ago($pr['submitted_at'])) ?></td>
                            <td data-label="Total" class="text-right num"><strong><?= e(money($pr['grand_total'])) ?></strong></td>
                            <td data-label="">
                                <div class="table-actions">
                                    <a class="btn btn-primary btn-sm" href="<?= e(url('/pr/' . $pr['id'])) ?>">Tinjau <?= icon('arrow-right') ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="card">
        <?php if ($history === []): ?>
            <div class="empty"><?= icon('clock') ?><strong>Belum ada keputusan</strong></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-cards">
                    <thead>
                    <tr>
                        <th scope="col">No. PR</th>
                        <th scope="col">Tahap</th>
                        <th scope="col">Keputusan</th>
                        <th scope="col">Komentar</th>
                        <th scope="col">Status PR kini</th>
                        <th scope="col">Waktu</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td class="cell-primary" data-label="">
                                <a class="row-link" href="<?= e(url('/pr/' . $row['id'])) ?>"><?= e($row['pr_number']) ?></a>
                                <span class="sub"><?= e($row['requester_name']) ?> · <?= e(money($row['grand_total'])) ?></span>
                            </td>
                            <td data-label="Tahap"><?= e($row['step_label']) ?></td>
                            <td data-label="Keputusan"><span class="badge <?= e($actionBadge[$row['action']] ?? '') ?>"><span class="badge-dot"></span><?= e($actionLabels[$row['action']] ?? $row['action']) ?></span></td>
                            <td data-label="Komentar"><?= e($row['comment'] ?? '—') ?></td>
                            <td data-label="Status PR kini"><?= status_badge((string) $row['status']) ?></td>
                            <td data-label="Waktu" class="nowrap"><?= e(tanggal($row['acted_at'], true)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
