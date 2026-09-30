<?php
$barClass = static function (string $total, string $max): string {
    $maxUnits = \App\Support\Decimal::toUnits($max);
    if ($maxUnits <= 0) {
        return 'w-0';
    }
    $percent = (int) round(\App\Support\Decimal::toUnits($total) * 100 / $maxUnits / 5) * 5;

    return 'w-' . max($total !== '0.00' ? 5 : 0, min(100, $percent));
};
$breakdowns = [
    'Per Status' => $byStatus,
    'Per Department' => $byDepartment,
    'Per Supplier' => $bySupplier,
    'Per Requester' => $byRequester,
    'Per Bulan' => $byMonth,
];
$hasFilter = array_filter($filters) !== [];
?>
<header class="page-header">
    <div>
        <p class="eyebrow">Analitik</p>
        <h1>Laporan PR</h1>
        <p class="subtitle">Filter berdasarkan tanggal PR, department, supplier, requester, dan status.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/reports/export', $filters)) ?>"><?= icon('download') ?> Export CSV</a>
    </div>
</header>

<section class="card">
    <form method="get" action="<?= e(url('/reports')) ?>" class="toolbar">
        <div class="field">
            <label for="date_from" class="small">Dari tanggal</label>
            <input type="date" id="date_from" name="date_from" value="<?= e($filters['date_from']) ?>">
        </div>
        <div class="field">
            <label for="date_to" class="small">Sampai tanggal</label>
            <input type="date" id="date_to" name="date_to" value="<?= e($filters['date_to']) ?>">
        </div>
        <div class="field">
            <label for="department_id" class="small">Department</label>
            <select id="department_id" name="department_id">
                <option value="">Semua</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= e((string) $d['id']) ?>"<?= selected($filters['department_id'], $d['id']) ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="supplier_id" class="small">Supplier</label>
            <select id="supplier_id" name="supplier_id">
                <option value="">Semua</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= e((string) $s['id']) ?>"<?= selected($filters['supplier_id'], $s['id']) ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="requester_id" class="small">Requester</label>
            <select id="requester_id" name="requester_id">
                <option value="">Semua</option>
                <?php foreach ($requesters as $r): ?>
                    <option value="<?= e((string) $r['id']) ?>"<?= selected($filters['requester_id'], $r['id']) ?>><?= e($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status" class="small">Status</label>
            <select id="status" name="status">
                <option value="">Semua</option>
                <?php foreach (\App\Models\PrStatus::labels() as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($filters['status'], $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-actions">
            <button type="submit" class="btn btn-primary"><?= icon('filter') ?> Terapkan</button>
            <?php if ($hasFilter): ?><a class="btn btn-secondary" href="<?= e(url('/reports')) ?>">Reset</a><?php endif; ?>
        </div>
    </form>
    <div class="card-body">
        <div class="stats">
            <div class="stat">
                <span class="stat-label">Jumlah PR</span>
                <span class="stat-value"><?= e((string) $summary['count']) ?></span>
                <span class="stat-caption"><?= $hasFilter ? 'Sesuai filter' : 'Seluruh PR' ?></span>
            </div>
            <div class="stat stat-accent stat-wide">
                <span class="stat-label"><?= icon('wallet') ?> Total nilai</span>
                <span class="stat-value"><?= e(money($summary['total'])) ?></span>
                <span class="stat-caption">Jumlah grand total PR (termasuk pajak)</span>
            </div>
            <div class="stat">
                <span class="stat-label">Rata-rata per PR</span>
                <span class="stat-value"><?= e(money($summary['count'] > 0 ? \App\Support\Decimal::fromUnits(intdiv(\App\Support\Decimal::toUnits($summary['total']), $summary['count'])) : '0')) ?></span>
                <span class="stat-caption">Dibulatkan ke bawah</span>
            </div>
        </div>
    </div>
</section>

<div class="grid-2">
    <?php foreach ($breakdowns as $heading => $groupRows): ?>
        <?php $max = $groupRows[0]['total'] ?? '0.00'; ?>
        <section class="card">
            <div class="card-header"><h2><?= e($heading) ?></h2></div>
            <div class="card-body flush">
                <?php if ($groupRows === []): ?>
                    <div class="empty"><strong>Tidak ada data</strong></div>
                <?php else: ?>
                    <ul class="list">
                        <?php foreach ($groupRows as $row): ?>
                            <li class="list-item">
                                <span class="grow">
                                    <span class="title"><?= e($row['label']) ?></span>
                                    <span class="bar" aria-hidden="true"><span class="<?= e($barClass($row['total'], $max)) ?>"></span></span>
                                </span>
                                <span class="end">
                                    <span class="title num"><?= e(money($row['total'])) ?></span>
                                    <span class="meta"><?= e((string) $row['count']) ?> PR</span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<section class="card">
    <div class="card-header">
        <div>
            <h2>Daftar PR</h2>
            <p>Maksimal 200 baris ditampilkan. Gunakan Export CSV untuk data lengkap.</p>
        </div>
    </div>
    <div class="card-body flush">
        <?php if ($rows === []): ?>
            <div class="empty"><strong>Tidak ada PR sesuai filter</strong></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-cards">
                    <thead>
                    <tr>
                        <th scope="col">No. PR</th>
                        <th scope="col">Tanggal</th>
                        <th scope="col">Department</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">Pemohon</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="cell-primary" data-label=""><a class="row-link" href="<?= e(url('/pr/' . $row['id'])) ?>"><?= e($row['pr_number'] ?? 'Draft #' . $row['id']) ?></a></td>
                            <td data-label="Tanggal" class="nowrap"><?= e(tanggal($row['pr_date'])) ?></td>
                            <td data-label="Department"><?= e($row['department_name']) ?></td>
                            <td data-label="Supplier"><?= e($row['supplier_name'] ?: '-') ?></td>
                            <td data-label="Pemohon"><?= e($row['requester_name']) ?></td>
                            <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
                            <td data-label="Total" class="text-right num"><?= e(money($row['grand_total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
