<?php

use App\Helpers\Form;
use App\Helpers\Number;
use App\Services\ReportService;

/**
 * @var string $type
 * @var array<string,string> $meta
 * @var array{from:string,to:string,customer_id:int,pic:int} $filters
 * @var array{columns:list<array<string,mixed>>,rows:list<array<string,mixed>>,summary:list<array<string,string>>,breakdowns:list<array<string,mixed>>,truncated:bool} $result
 * @var array<string,array{label:string,from:string,to:string}> $presets
 * @var array<int,string> $customers
 * @var array<int,string> $pics
 */
$cols = $result['columns'];
$rows = $result['rows'];
$screenRows = array_slice($rows, 0, ReportService::SCREEN_ROWS);
$query = array_filter(['from' => $filters['from'], 'to' => $filters['to'], 'customer_id' => $filters['customer_id'] ?: null, 'pic' => $filters['pic'] ?: null]);
$hasFilter = $query !== [];
$hide = ['sm' => ' rc-hide-sm', 'md' => ' rc-hide-md', 'lg' => ' rc-hide-lg', 'xl' => ' rc-hide-xl', 'xxl' => ' rc-hide-xxl'];
$period = ($filters['from'] ? fmt_date($filters['from']) : 'Awal data') . ' – ' . ($filters['to'] ? fmt_date($filters['to']) : 'sekarang');

$format = static function (array $col, mixed $value): string {
    return match ($col['type']) {
        'date'     => e(fmt_date($value, '—')),
        'datetime' => e(fmt_datetime($value, '—')),
        'qty'      => e(fmt_qty($value, '0')),
        'money'    => e(fmt_money($value, $col['empty'] ?? 'Rp 0')),
        'status'   => status_badge($value !== null ? (string) $value : null),
        default    => e($value === null || $value === '' ? '—' : (string) $value),
    };
};
$totals = [];
foreach ($cols as $col) {
    if (!empty($col['total'])) {
        if ($col['type'] === 'money') {
            $cents = 0;
            foreach ($rows as $r) {
                $cents += Number::toCents($r[$col['key']] ?? null) ?? 0;
            }
            $totals[$col['key']] = Number::fromCents($cents);
        } else {
            $totals[$col['key']] = array_sum(array_map(static fn ($r) => (int) ($r[$col['key']] ?? 0), $rows));
        }
    }
}
?>
<style media="print">@page { size: A4 landscape; margin: 12mm; }</style>
<div class="breadcrumb-lite no-print"><a href="<?= e(url('/reports')) ?>">Reports</a><i class="bi bi-chevron-right"></i><span><?= e($meta['title']) ?></span></div>

<div class="print-only print-header">
    <div class="fw-semibold"><?= e(App\Models\Setting::get('company_name') ?? '') ?> — Laporan <?= e($meta['title']) ?></div>
    <div class="small">Periode (<?= e(mb_strtolower($meta['date'])) ?>): <?= e($period) ?>
        · Customer: <?= e($filters['customer_id'] ? ($customers[$filters['customer_id']] ?? '#' . $filters['customer_id']) : 'Semua') ?>
        · <?= e($meta['pic']) ?>: <?= e($filters['pic'] ? ($pics[$filters['pic']] ?? '#' . $filters['pic']) : 'Semua') ?>
        · Dicetak <?= e(date('d/m/Y H:i')) ?> oleh <?= e(auth_user()['name'] ?? '') ?></div>
</div>

<div class="page-header">
    <div>
        <div class="page-eyebrow">Reports</div>
        <h1 class="page-title">Laporan <?= e($meta['title']) ?></h1>
        <p class="page-subtitle"><?= e($meta['desc']) ?> Periode dihitung dari <?= e(mb_strtolower($meta['date'])) ?>.</p>
    </div>
    <div class="page-actions">
        <?php if (can('reports.export')): ?>
            <?php if (\App\Helpers\Requirements::missing(['zip']) === []): ?>
                <a class="btn btn-light" href="<?= e(url('/reports/' . $type . '/export', $query + ['format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</a>
            <?php endif; ?>
            <a class="btn btn-light" href="<?= e(url('/reports/' . $type . '/export', $query + ['format' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
        <?php endif; ?>
        <button type="button" class="btn btn-light" data-print><i class="bi bi-printer"></i> Cetak / PDF</button>
    </div>
</div>

<div class="surface section-gap no-print">
    <nav class="report-presets" aria-label="Periode cepat">
        <?php foreach ($presets as $p): $active = $p['from'] === $filters['from'] && $p['to'] === $filters['to']; ?>
            <a href="<?= e(url('/reports/' . $type, array_filter(['from' => $p['from'], 'to' => $p['to'], 'customer_id' => $filters['customer_id'] ?: null, 'pic' => $filters['pic'] ?: null]))) ?>"<?= $active ? ' class="active" aria-current="true"' : '' ?>><?= e($p['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="filter-bar" method="get" action="<?= e(url('/reports/' . $type)) ?>">
        <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dari tanggal">
        <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Sampai tanggal">
        <select class="form-select" name="customer_id" aria-label="Customer"><option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?></select>
        <select class="form-select" name="pic" aria-label="<?= e($meta['pic']) ?>"><option value="">Semua <?= e(mb_strtolower($meta['pic'])) ?></option><?= Form::options($pics, (string) $filters['pic']) ?></select>
        <button class="btn btn-primary" type="submit">Terapkan</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/reports/' . $type)) ?>">Reset</a><?php endif; ?>
    </form>
</div>

<div class="stat-strip section-gap">
    <?php foreach ($result['summary'] as $s): ?>
        <div><div class="stat-label"><?= e($s['label']) ?></div><div class="stat-value"><?= e($s['value']) ?></div><?php if (!empty($s['meta'])): ?><div class="x-small text-secondary"><?= e($s['meta']) ?></div><?php endif; ?></div>
    <?php endforeach; ?>
</div>

<?php if ($rows): ?>
    <div class="row g-4 section-gap">
        <?php foreach ($result['breakdowns'] as $b): if (!$b['items']) { continue; } $max = max(array_column($b['items'], 'value')); ?>
            <div class="col-lg-6 min-w-0">
                <section class="surface h-100">
                    <div class="surface-header"><h2 class="surface-title"><?= e($b['title']) ?></h2></div>
                    <div class="bar-list">
                        <?php foreach ($b['items'] as $item): $w = $max > 0 ? round($item['value'] * 100 / $max, 1) : 0; ?>
                            <div class="bar-row" title="<?= e($item['label'] . ': ' . $item['display']) ?>">
                                <div class="bar-label"><?= e($item['label']) ?></div>
                                <div class="bar-track"><div class="bar-fill" style="width: <?= e((string) $w) ?>%"></div></div>
                                <div class="bar-value"><?= e($item['display']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="surface">
    <div class="surface-header"><div><h2 class="surface-title">Data <span class="tab-count"><?= e(Number::qty(count($rows))) ?></span></h2>
        <?php if (count($rows) > count($screenRows)): ?><p class="surface-subtitle no-print-table-note">Menampilkan <?= e(Number::qty(count($screenRows))) ?> baris pertama. Export Excel/CSV berisi seluruh <?= e(Number::qty(count($rows))) ?> baris.</p><?php endif; ?>
        <?php if ($result['truncated']): ?><p class="surface-subtitle text-warning-ink">Hasil dibatasi <?= e(Number::qty(ReportService::MAX_ROWS)) ?> baris — persempit periode atau filter.</p><?php endif; ?></div></div>
    <?php if (!$rows): ?>
        <div class="empty-state"><i class="bi bi-bar-chart-line"></i><div class="empty-title">Tidak ada data untuk filter ini</div><p>Ubah periode atau filter customer/PIC.</p></div>
    <?php else: ?>
        <div class="table-wrap report-cq">
            <table class="table-pik table-compact report-table">
                <thead><tr>
                    <?php foreach ($cols as $col): ?>
                        <th class="<?= in_array($col['type'], ['qty', 'money', 'pct'], true) ? 'num' : '' ?><?= $hide[$col['hide'] ?? ''] ?? '' ?>"><?= e($col['label']) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($screenRows as $row): ?>
                    <tr>
                        <?php foreach ($cols as $i => $col): $value = $row[$col['key']] ?? null; $num = in_array($col['type'], ['qty', 'money', 'pct'], true); ?>
                            <td class="<?= $num ? 'num' : '' ?><?= in_array($col['type'], ['date', 'datetime'], true) ? ' nowrap' : '' ?><?= $hide[$col['hide'] ?? ''] ?? '' ?>">
                                <?php if (!empty($col['link']) && (empty($col['perm']) || can($col['perm']))): ?>
                                    <a class="cell-title" href="<?= e(url(preg_replace_callback('/\{(\w+)\}/', static fn ($m) => (string) ($row[$m[1]] ?? ''), $col['link']))) ?>"><?= $format($col, $value) ?></a>
                                <?php else: ?>
                                    <?= $i === 0 ? '<span class="cell-title">' . $format($col, $value) . '</span>' : $format($col, $value) ?>
                                <?php endif; ?>
                                <?php if ($i === 0 && !empty($col['sub'])): $sub = array_filter(array_map(static fn ($k) => $row[$k] ?? null, $col['sub'])); ?>
                                    <?php if ($sub): ?><div class="cell-sub screen-only d-md-none"><?= e(implode(' · ', $sub)) ?></div><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($totals): ?>
                    <tfoot><tr>
                        <?php foreach ($cols as $i => $col): $num = in_array($col['type'], ['qty', 'money', 'pct'], true); ?>
                            <td class="<?= $num ? 'num' : '' ?><?= $hide[$col['hide'] ?? ''] ?? '' ?>"><?= $i === 0 ? 'Total (' . e(Number::qty(count($rows))) . ' baris)' : (array_key_exists($col['key'], $totals) ? $format($col, $totals[$col['key']]) : '') ?></td>
                        <?php endforeach; ?>
                    </tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>
</section>
