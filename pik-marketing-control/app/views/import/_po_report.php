<?php
/**
 * Laporan import database PO (dry run / import) — dipakai halaman Import PO & detail log.
 * @var array<string,mixed> $report
 */
$c = $report['customers'] ?? [];
$p = $report['products'] ?? [];
$po = $report['purchase_orders'] ?? [];
$it = $report['po_items'] ?? [];
$rc = $report['reconciliation'] ?? null;
$iss = $report['issues'] ?? [];
$num = static fn ($v): string => e(fmt_qty($v ?? 0, '0'));
$diffBadge = static function ($v, bool $money = false): string {
    $zero = $money ? ((float) $v === 0.0) : ((int) $v === 0);
    $text = $money ? fmt_money($v) : fmt_qty($v, '0');
    return $zero ? '<span class="badge-soft badge-soft-success">0</span>' : '<span class="badge-soft badge-soft-warning">' . e($text) . '</span>';
};
?>
<section class="surface section-gap">
    <div class="surface-header"><div><h2 class="surface-title">Ringkasan</h2>
        <p class="surface-subtitle">Baris = data di file. "Sudah ada" = cocok dengan data aplikasi (via nomor PO / kode / nama persis); data itu dilengkapi, tidak dibuat ganda.</p></div></div>
    <div class="table-wrap">
        <table class="table-pik table-compact">
            <thead><tr><th>Data</th><th class="num">Di file</th><th class="num">Sudah ada</th><th class="num">Baru</th><th class="num d-none d-sm-table-cell">Dilengkapi</th><th class="num">Perlu review</th><th class="num d-none d-sm-table-cell">Gagal</th></tr></thead>
            <tbody>
                <tr><td>Customer<div class="cell-sub">via nomor PO <?= $num($c['by_po_number'] ?? 0) ?> · duplikat di aplikasi <?= $num($c['merged'] ?? 0) ?></div></td>
                    <td class="num"><?= $num($c['total'] ?? 0) ?></td><td class="num"><?= $num($c['existing'] ?? 0) ?></td><td class="num"><?= $num($c['new'] ?? 0) ?></td>
                    <td class="num d-none d-sm-table-cell"><?= $num($c['updated'] ?? 0) ?></td><td class="num"><?= $num($c['needs_review'] ?? 0) ?></td><td class="num d-none d-sm-table-cell">0</td></tr>
                <tr><td>Produk<div class="cell-sub">via baris PO <?= $num($p['via_po_line'] ?? 0) ?> · tidak diperlukan <?= $num($p['not_needed'] ?? 0) ?></div></td>
                    <td class="num"><?= $num($p['total'] ?? 0) ?></td><td class="num"><?= $num($p['existing'] ?? 0) ?></td><td class="num"><?= $num($p['new'] ?? 0) ?></td>
                    <td class="num d-none d-sm-table-cell">0</td><td class="num"><?= $num($p['needs_review'] ?? 0) ?></td><td class="num d-none d-sm-table-cell">0</td></tr>
                <tr><td>Purchase order<div class="cell-sub">identik <?= $num($po['unchanged'] ?? 0) ?> · ditahan <?= $num($po['held'] ?? 0) ?></div></td>
                    <td class="num"><?= $num($po['total'] ?? 0) ?></td><td class="num"><?= $num($po['existing'] ?? 0) ?></td><td class="num"><?= $num($po['inserted'] ?? 0) ?></td>
                    <td class="num d-none d-sm-table-cell"><?= $num($po['updated'] ?? 0) ?></td><td class="num"><?= $num($po['needs_review'] ?? 0) ?></td><td class="num d-none d-sm-table-cell"><?= $num($po['failed'] ?? 0) ?></td></tr>
                <tr><td>Item PO<div class="cell-sub">tidak diimport <?= $num($it['not_imported'] ?? 0) ?></div></td>
                    <td class="num"><?= $num($it['total'] ?? 0) ?></td><td class="num"><?= $num($it['matched'] ?? 0) ?></td><td class="num"><?= $num($it['inserted'] ?? 0) ?></td>
                    <td class="num d-none d-sm-table-cell"><?= $num($it['updated'] ?? 0) ?></td><td class="num">—</td><td class="num d-none d-sm-table-cell"><?= $num($it['invalid'] ?? 0) ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<?php if ($rc): ?>
    <section class="surface section-gap">
        <div class="surface-header"><div><h2 class="surface-title">Rekonsiliasi Excel vs aplikasi</h2>
            <p class="surface-subtitle"><?= ($rc['source'] ?? '') === 'database' ? 'Dihitung dari isi database setelah import.' : 'Perkiraan hasil bila diimport (dry run).' ?> Selisih selalu disertai penyebabnya di bawah.</p></div></div>
        <div class="table-wrap">
            <table class="table-pik table-compact">
                <thead><tr><th></th><th class="num">Excel</th><th class="num">Aplikasi</th><th class="num">Selisih</th></tr></thead>
                <tbody>
                    <tr><td>Jumlah PO</td><td class="num"><?= $num($rc['excel_po_count']) ?></td><td class="num"><?= $num($rc['app_po_count']) ?></td><td class="num"><?= $diffBadge($rc['po_difference']) ?></td></tr>
                    <tr><td>Jumlah item</td><td class="num"><?= $num($rc['excel_item_count']) ?></td><td class="num"><?= $num($rc['app_item_count']) ?></td><td class="num"><?= $diffBadge($rc['item_difference']) ?></td></tr>
                    <tr><td>Total grand total</td><td class="num nowrap"><?= e(fmt_money($rc['excel_grand_total'])) ?></td><td class="num nowrap"><?= e(fmt_money($rc['app_grand_total'])) ?></td><td class="num"><?= $diffBadge($rc['grand_total_difference'], true) ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php if (!empty($rc['differences'])): ?>
            <div class="surface-pad border-top small">
                <div class="fw-semibold mb-1">PO yang berbeda / tidak masuk (<?= count($rc['differences']) ?>)</div>
                <div class="table-wrap"><table class="table-pik table-compact">
                    <thead><tr><th>No. PO</th><th class="num d-none d-sm-table-cell">Excel</th><th class="num d-none d-sm-table-cell">Aplikasi</th><th>Penyebab</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($rc['differences'], 0, 100) as $d): ?>
                        <tr><td class="nowrap"><?= e((string) ($d['po_number'] ?? '(tanpa nomor)')) ?><div class="cell-sub"><?= e((string) $d['po_id']) ?></div></td>
                            <td class="num nowrap d-none d-sm-table-cell"><?= e(fmt_money($d['excel'])) ?></td><td class="num nowrap d-none d-sm-table-cell"><?= e(fmt_money($d['app'])) ?></td>
                            <td class="text-secondary"><?= e((string) $d['reason']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($rc['item_gaps'])): ?>
            <div class="surface-pad border-top small">
                <div class="fw-semibold mb-1">Item yang tidak masuk ke baris PO (<?= count($rc['item_gaps']) ?>)</div>
                <div class="table-wrap"><table class="table-pik table-compact">
                    <thead><tr><th>Item</th><th class="d-none d-md-table-cell">No. PO</th><th class="num">Qty</th><th>Penyebab</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($rc['item_gaps'], 0, 100) as $g): ?>
                        <tr><td><?= e((string) ($g['item'] ?? '')) ?><div class="cell-sub"><?= e((string) $g['item_id']) ?><span class="d-md-none"> · <?= e((string) $g['po_number']) ?></span></div></td>
                            <td class="d-none d-md-table-cell nowrap"><?= e((string) $g['po_number']) ?></td><td class="num"><?= e(fmt_qty($g['qty'])) ?></td><td class="text-secondary"><?= e((string) $g['reason']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!empty($iss['by_type'])): ?>
    <section class="surface section-gap">
        <div class="surface-header"><div><h2 class="surface-title">Catatan untuk ditinjau</h2>
            <p class="surface-subtitle"><?= ($report['mode'] ?? '') === 'IMPORT' ? 'Sudah masuk ke Migration Issues.' : 'Akan masuk ke Migration Issues saat import.' ?>
                Baru <?= $num($iss['new'] ?? 0) ?> · sudah ada sebelumnya <?= $num($iss['existing'] ?? 0) ?>.</p></div></div>
        <ul class="list-lite">
            <?php foreach ($iss['by_type'] as $type => $n): ?>
                <li><div class="li-main"><span class="li-title">
                    <?php if (($report['mode'] ?? '') === 'IMPORT' && can('migration.view')): ?><a href="<?= e(url('/migration-issues', ['type' => $type])) ?>"><?= e((string) $type) ?></a><?php else: ?><?= e((string) $type) ?><?php endif; ?>
                </span></div><div class="li-end"><?= $num($n) ?></div></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php $findings = array_values(array_filter($report['findings'] ?? [], static fn (array $f): bool => $f['level'] !== 'info')); ?>
<?php if ($findings !== []): ?>
    <section class="surface section-gap">
        <div class="surface-header"><div><h2 class="surface-title">Temuan per baris (<?= count($findings) ?>)</h2>
            <p class="surface-subtitle">Sheet & nomor baris di file Excel, kolom, masalah, dan saran tindakan.</p></div></div>
        <div class="table-wrap"><table class="table-pik table-compact">
            <thead><tr><th>Lokasi</th><th class="d-none d-md-table-cell">No. PO</th><th>Masalah</th><th class="d-none d-lg-table-cell">Saran</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($findings, 0, 150) as $f): ?>
                <tr><td class="nowrap"><?= e($f['sheet']) ?> baris <?= e((string) ($f['row'] ?? '—')) ?><div class="cell-sub"><span class="code-chip"><?= e($f['field']) ?></span></div></td>
                    <td class="d-none d-md-table-cell nowrap"><?= e((string) ($f['po_number'] ?? '—')) ?></td>
                    <td><?= $f['level'] === 'error' ? '<span class="badge-soft badge-soft-danger no-dot">Error</span> ' : '' ?><?= e($f['problem']) ?><div class="cell-sub d-lg-none"><?= e($f['action']) ?></div></td>
                    <td class="d-none d-lg-table-cell text-secondary"><?= e($f['action']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php endif; ?>
