<?php
/**
 * Template PDF PR (dirender oleh Dompdf). Semua data berasal dari database.
 *
 * @var array $pr @var list<array> $items @var list<array> $signatures @var array $settings @var string $generatedAt
 */
$columns = max(3, count($signatures));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title><?= e($pr['pr_number']) ?></title>
    <style>
        @page { margin: 28px 36px 40px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #1d1d1f; line-height: 1.45; }
        .top { width: 100%; border-collapse: collapse; }
        .top td { vertical-align: top; padding: 0; }
        .company { font-size: 15px; font-weight: bold; letter-spacing: 0.5px; color: #0a4b8f; }
        .company-address { color: #6e6e73; font-size: 9.5px; margin-top: 2px; }
        .date { text-align: right; color: #3a3a3c; }
        .rule { border: 0; border-top: 2px solid #0a4b8f; margin: 10px 0 16px; }
        h1 { text-align: center; font-size: 16px; letter-spacing: 3px; margin: 0 0 16px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .label { width: 110px; color: #6e6e73; }
        .meta .colon { width: 10px; color: #6e6e73; }
        .meta .value { font-weight: bold; }
        .items { width: 100%; border-collapse: collapse; }
        .items th { background: #eef3f9; color: #0a3a6b; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.4px; padding: 7px 8px; border-top: 1px solid #0a4b8f; border-bottom: 1px solid #0a4b8f; text-align: left; }
        .items td { padding: 7px 8px; border-bottom: 1px solid #e4e4e9; vertical-align: top; }
        .items .num, .items th.num { text-align: right; white-space: nowrap; }
        .items .no { width: 26px; text-align: center; }
        .items .desc { color: #6e6e73; font-size: 9.5px; }
        .totals { width: 44%; margin-left: 56%; border-collapse: collapse; margin-top: 10px; }
        .totals td { padding: 4px 8px; }
        .totals .num { text-align: right; }
        .totals .grand td { border-top: 1.5px solid #1d1d1f; font-weight: bold; font-size: 12px; padding-top: 7px; }
        .notes { margin-top: 14px; padding: 8px 10px; background: #f5f5f7; border-radius: 4px; font-size: 9.5px; }
        .signatures { width: 100%; border-collapse: collapse; margin-top: 30px; table-layout: fixed; }
        .signatures td { text-align: center; vertical-align: top; padding: 0 6px; }
        .sig-label { color: #3a3a3c; margin-bottom: 8px; }
        .sig-stamp { height: 52px; color: #1a7443; font-size: 9px; padding-top: 16px; }
        .sig-name { font-weight: bold; border-top: 1px solid #1d1d1f; padding-top: 4px; margin: 0 8px; }
        .sig-title { color: #6e6e73; font-size: 9px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8.5px; color: #8e8e93; text-align: center; }
    </style>
</head>
<body>
<table class="top">
    <tr>
        <td>
            <div class="company"><?= e($settings['company_name']) ?></div>
            <?php if ($settings['company_address'] !== ''): ?><div class="company-address"><?= e($settings['company_address']) ?></div><?php endif; ?>
        </td>
        <td class="date">Tanggal: <strong><?= e(tanggal_panjang($pr['pr_date'])) ?></strong></td>
    </tr>
</table>
<hr class="rule">

<h1>PURCHASE REQUISITION</h1>

<table class="meta">
    <tr>
        <td class="label">Department</td><td class="colon">:</td><td class="value"><?= e($pr['department_name']) ?></td>
        <td class="label">PR Number</td><td class="colon">:</td><td class="value"><?= e($pr['pr_number']) ?></td>
    </tr>
    <tr>
        <td class="label">Nama Pemohon</td><td class="colon">:</td><td class="value"><?= e($pr['requester_name']) ?></td>
        <td class="label">Supplier</td><td class="colon">:</td><td class="value"><?= e($pr['supplier_name'] ?? '-') ?></td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr>
        <th class="no">No</th>
        <th>Item</th>
        <th class="num">Qty</th>
        <th class="num">Harga</th>
        <th class="num">Jumlah</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td class="no"><?= e((string) $item['line_no']) ?></td>
            <td>
                <?= e($item['item_name_snapshot']) ?>
                <?php if ($item['description']): ?><div class="desc"><?= e($item['description']) ?></div><?php endif; ?>
            </td>
            <td class="num"><?= e(number_id($item['quantity'])) ?> <?= e($item['unit']) ?></td>
            <td class="num"><?= e(money($item['unit_price'])) ?></td>
            <td class="num"><?= e(money($item['line_total'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="num"><?= e(money($pr['subtotal'])) ?></td></tr>
    <tr><td>Pajak (<?= e(number_id($pr['tax_rate'])) ?>%)</td><td class="num"><?= e(money($pr['tax_amount'])) ?></td></tr>
    <tr class="grand"><td>TOTAL</td><td class="num"><?= e(money($pr['grand_total'])) ?></td></tr>
</table>

<?php if ($pr['notes']): ?>
    <div class="notes"><strong>Catatan:</strong> <?= nl2br(e($pr['notes'])) ?></div>
<?php endif; ?>

<table class="signatures">
    <tr>
        <?php foreach ($signatures as $signature): ?>
            <td>
                <div class="sig-label"><?= e($signature['label']) ?></div>
                <div class="sig-stamp"><?= $signature['date'] ? 'Disahkan elektronik<br>' . e(tanggal($signature['date'], true)) : '' ?></div>
                <div class="sig-name"><?= e($signature['name']) ?></div>
                <div class="sig-title"><?= e($signature['title']) ?></div>
            </td>
        <?php endforeach; ?>
        <?php for ($i = count($signatures); $i < $columns; $i++): ?><td></td><?php endfor; ?>
    </tr>
</table>

<div class="footer">
    Dokumen ini dibuat oleh sistem PR PIK dari data yang tersimpan pada <?= e(tanggal($generatedAt, true)) ?> · <?= e($pr['pr_number']) ?> · Status: <?= e(status_label((string) $pr['status'])) ?>
</div>
</body>
</html>
