<?php

declare(strict_types=1);

use App\Helpers\Code;
use App\Helpers\Database;
use App\Helpers\Migrator;
use App\Helpers\XlsxWriter;
use App\Models\MigrationIssue;
use App\Services\Migration\ImportFailed;
use App\Services\Migration\PoDatabaseImporter;

group('Import · Database PO (PIK_PO_DATABASE)');

$poDbTestStart = time();

/**
 * Data "lama" di aplikasi (seperti hasil import AppSheet) + workbook database PO sintetis.
 * Nama sengaja unik (Alvoria, Brontex, Zentrixa) agar tidak bentrok dengan data test lain.
 * @return array<string,int>
 */
function po_db_seed(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $cust = static fn (string $name): int => Database::insert('customers', ['code' => Code::generate('customers'), 'name' => $name, 'company' => $name, 'status' => 'Active']);
    $prod = static fn (string $name, ?string $pc): int => Database::insert('products', ['code' => Code::generate('products'), 'name' => $name, 'product_code' => $pc, 'unit' => 'pcs']);
    $po = static fn (string $number, ?int $c, string $date): int => Database::insert('purchase_orders', ['code' => Code::generate('purchase_orders'), 'po_number' => $number, 'customer_id' => $c, 'po_date' => $date, 'status' => 'Open']);
    $line = static fn (int $poId, int $p, int $qty): int => Database::insert('po_lines', ['code' => Code::generate('po_lines'), 'po_id' => $poId, 'product_id' => $p, 'order_qty' => $qty]);
    $ids = [];
    $ids['custA'] = $cust('PT Alvoria Kemasindo');
    $ids['custB'] = $cust('PT Brontex Dermalab');
    $ids['p1'] = $prod('Botol Alvoria 100 ml', '[ALV-BTL]');
    $ids['p2'] = $prod('Tutup Alvoria 24 mm', '[ALV-CAP]');
    $ids['p3'] = $prod('Tube Brontex 50 ml', null);
    $ids['po1'] = $po('UJI/ALV/001', $ids['custA'], '2026-03-01');
    $line($ids['po1'], $ids['p1'], 1000);
    $ids['po2'] = $po('UJI/ALV/002', $ids['custA'], '2026-03-05');
    $ids['po2_l1'] = $line($ids['po2'], $ids['p2'], 500);
    $ids['po2_l2'] = $line($ids['po2'], $ids['p1'], 500);
    $ids['po3'] = $po('UJI/BRX/101', $ids['custB'], '2026-03-10');
    $line($ids['po3'], $ids['p3'], 1000);
    $ids['po4'] = $po('UJI/BRX/102', $ids['custB'], '2026-03-11');
    $line($ids['po4'], $ids['p3'], 1000);
    $line($ids['po4'], $ids['p3'], 2000);
    $ids['po5'] = $po('77/UJI/ALV/0099-X', $ids['custA'], '2026-03-20');
    $line($ids['po5'], $ids['p1'], 700);
    $ids['po6'] = $po('UJIALV005', $ids['custA'], '2026-03-21');
    $line($ids['po6'], $ids['p1'], 300);
    $ids['shared'] = $po('UJI/SHARED/7', $ids['custB'], '2026-03-22');
    $line($ids['shared'], $ids['p3'], 10);
    // nomor PO sama dipakai 2 PO (versi awal & revisi); qty sama, satu tanpa tanggal
    $ids['dupA'] = Database::insert('purchase_orders', ['code' => Code::generate('purchase_orders'), 'po_number' => 'UJI/DUP/001', 'customer_id' => $ids['custA'], 'po_date' => null, 'status' => 'Cancelled']);
    $line($ids['dupA'], $ids['p1'], 400);
    $ids['dupB'] = $po('UJI/DUP/001', $ids['custA'], '2026-03-25');
    $line($ids['dupB'], $ids['p1'], 400);
    return $ids;
}

/** @param array<string,mixed> $over  ganti nilai PO_MASTER per PO_ID (untuk versi file berikutnya) */
function po_db_workbook(string $tag, array $over = [], bool $extraNew = false): string
{
    $d = static fn (string $s) => new DateTimeImmutable($s, new DateTimeZone('UTC'));
    static $made = [];
    $path = sys_get_temp_dir() . '/pik-po-db-' . $tag . '-' . getmypid() . '.xlsx';
    if (isset($made[$tag])) {
        return $path; // file yang sama (hash sama) untuk dry run & import
    }
    $made[$tag] = true;
    $master = [
        // PO_ID, NUMBER, DATE, CUST, CUST_NAME, CUST_CODE, CUR, SUBTOTAL, DISC, TAX, SHIP, GRAND, TERM, DELIV, ADDR, STATUS, FILE, CONF, VSTATUS, NOTES, PIT, CONTACT, DOC, COUNT, URL
        ['XPO-1', 'UJI/ALV/001', $d('2026-03-01'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 1500000, null, 165000, null, 1665000, 'NET 30', $d('2026-03-15'), 'Gudang Alvoria, Bekasi', 'ISSUED', 'po1.pdf', 'HIGH', 'OK', null, 'N', 'Rina (Purchasing)', 'PO', 'Y', 'https://drive.example.com/po1'],
        ['XPO-2', 'UJI/ALV/002', $d('2026-03-04'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 75000, null, 8250, null, 83250, null, null, null, 'ISSUED', 'po2.pdf', 'HIGH', 'OK', null, 'N', null, 'PO', 'Y', null],
        ['XPO-3', 'UJI/BRX/101', $d('2026-03-10'), 'XC-B', 'BRONTEXIA', 'BRX', 'IDR', 1000000, null, 110000, null, 1110000, null, null, null, 'ISSUED', 'po3.pdf', 'HIGH', 'OK', null, 'Y', null, 'PO', 'Y', null],
        ['XPO-4', 'UJI/BRX/102', $d('2026-03-11'), 'XC-B', 'BRONTEXIA', 'BRX', 'IDR', 3000000, null, 330000, null, 3330000, null, null, null, 'ISSUED', 'po4.pdf', 'MEDIUM', 'OK', null, 'N', null, 'PO', 'Y', null],
        ['XPO-5', '/ ALV / 0099-X', $d('2026-03-18'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 70000, null, 7700, null, 77700, null, null, null, 'ISSUED', 'po5.pdf', 'MEDIUM', 'MISSING DATA', 'Nomor tidak lengkap', 'N', null, 'PO', 'Y', null],
        ['XPO-6', 'UJI/ALV/005', $d('2026-03-21'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 30000, null, 3300, null, 33300, null, null, null, 'ISSUED', 'po6.pdf', 'HIGH', 'OK', null, 'N', null, 'PO', 'Y', null],
        ['XPO-7', 'UJI/ZEN/001', $d('2026-04-01'), 'XC-Z', 'CV ZENTRIXA QORVANE', 'ZQ', 'IDR', 250000, null, 27500, null, 277500, 'CBD', null, null, 'ISSUED', 'po7.pdf', 'HIGH', 'OK', null, 'N', null, 'PO', 'Y', null],
        ['XPO-8', 'UJI/ALV/010', $d('2026-04-02'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 20000, null, 2200, null, 22200, null, null, null, 'ISSUED', 'po8.pdf', 'HIGH', 'OK', 'Versi lama', 'N', null, 'PO', 'N', null],
        ['XPO-9', 'UJI/AVP/001', $d('2026-04-03'), 'XC-R', 'PT ALVORIA PACKAGING', null, 'IDR', 10000, null, 1100, null, 11100, null, null, null, 'ISSUED', 'po9.pdf', 'LOW', 'AMBIGUOUS', null, 'N', null, 'PO', 'Y', null],
        ['XPO-10', 'UJI/ALV/011', $d('2026-04-04'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 40000, null, 4400, null, 44400, null, null, null, 'ISSUED', 'po10.pdf', 'MEDIUM', 'REVIEW REQUIRED', null, 'N', null, 'PO', 'Y', null],
        ['XPO-11', 'UJI/SHARED/7', $d('2026-04-05'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 5000, null, 550, null, 5550, null, null, null, 'ISSUED', 'po11.pdf', 'HIGH', 'OK', null, 'N', null, 'PO', 'Y', null],
        ['XPO-13', 'UJI/DUP/001', $d('2026-03-25'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 40000, null, 4400, null, 44400, null, null, null, 'ISSUED', 'dup-awal.pdf', 'HIGH', 'POSSIBLE DUPLICATE', 'Versi awal', 'N', null, 'PO', 'N', null],
        ['XPO-14', 'UJI/DUP/001', $d('2026-03-25'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 48000, null, 5280, null, 53280, null, null, null, 'REVISED', 'dup-rev01.pdf', 'HIGH', 'POSSIBLE DUPLICATE', 'Rev.01', 'N', null, 'PO', 'Y', null],
    ];
    if ($extraNew) {
        $master[] = ['XPO-12', 'UJI/ALV/012', $d('2026-04-06'), 'XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'IDR', 10000, null, 1100, null, 11100, null, null, null, 'ISSUED', 'po12.pdf', 'HIGH', 'OK', null, 'N', null, 'PO', 'Y', null];
    }
    foreach ($master as &$row) {
        foreach ($over[$row[0]] ?? [] as $col => $value) {
            $row[$col] = $value;
        }
    }
    unset($row);
    $items = [
        // ITEM_ID, PO_ID, NUMBER, CODE, NAME, QTY, UNIT, PRICE, SUBTOTAL, TAX, PRODUCT_ID, LINE_NO
        ['XIT-1', 'XPO-1', 'UJI/ALV/001', null, 'Botol Alvoria 100 ml', 1000, 'pcs', 1500, 1500000, 0.11, 'XP-1', 1],
        ['XIT-2', 'XPO-2', 'UJI/ALV/002', 'ALV-BTL', 'Botol Alvoria 100 ml', 500, 'pcs', 100, 50000, 0.11, 'XP-1', 1],
        ['XIT-3', 'XPO-2', 'UJI/ALV/002', 'ALV-CAP', 'Tutup Alvoria 24 mm', 500, 'pcs', 50, 25000, 0.11, 'XP-2', 2],
        ['XIT-4', 'XPO-3', 'UJI/BRX/101', null, 'Tube Brontex 50 ml', 1000, 'Units', 1110, 1000000, 0.11, 'XP-3', 1],
        ['XIT-5', 'XPO-4', 'UJI/BRX/102', null, 'Tube Brontex 50 ml', 3000, 'Units', 1000, 3000000, 0.11, 'XP-3', 1],
        ['XIT-6', 'XPO-5', '/ ALV / 0099-X', null, 'Botol Alvoria 100 ml', 700, 'pcs', 100, 70000, 0.11, 'XP-1', 1],
        ['XIT-7', 'XPO-6', 'UJI/ALV/005', null, 'Botol Alvoria 100 ml', 300, 'pcs', 100, 30000, 0.11, 'XP-1', 1],
        ['XIT-8', 'XPO-7', 'UJI/ZEN/001', 'ZQ-JAR-30', 'Jar Zentrixa 30 g', 1000, 'pcs', 250, 250000, 0.11, 'XP-4', 1],
        ['XIT-9', 'XPO-8', 'UJI/ALV/010', 'ALV-BTL', 'Botol Alvoria 100 ml', 200, 'pcs', 100, 20000, 0.11, 'XP-1', 1],
        ['XIT-10', 'XPO-9', 'UJI/AVP/001', null, 'Label Alvoria', 100, null, 100, 10000, 0.11, 'XP-5', 1],
        ['XIT-11', 'XPO-10', 'UJI/ALV/011', 'ALV-CAP', 'Tutup Alvoria 24 mm', 800, 'pcs', 50, 40000, 0.11, 'XP-2', 1],
        ['XIT-12', 'XPO-11', 'UJI/SHARED/7', null, 'Botol Alvoria 100 ml', 50, 'pcs', 100, 5000, 0.11, 'XP-1', 1],
        ['XIT-13', 'XPO-10', 'UJI/ALV/011', null, 'Baris rusak', 'abc', 'pcs', 50, 0, 0.11, 'XP-2', 2],
        ['XIT-14', 'XPO-404', 'UJI/TIDAK/ADA', null, 'Yatim', 5, 'pcs', 1, 5, 0.11, 'XP-2', 1],
        ['XIT-16', 'XPO-13', 'UJI/DUP/001', 'ALV-BTL', 'Botol Alvoria 100 ml', 400, 'pcs', 100, 40000, 0.11, 'XP-1', 1],
        ['XIT-17', 'XPO-14', 'UJI/DUP/001', 'ALV-BTL', 'Botol Alvoria 100 ml', 400, 'pcs', 120, 48000, 0.11, 'XP-1', 1],
    ];
    if ($extraNew) {
        $items[] = ['XIT-15', 'XPO-12', 'UJI/ALV/012', 'ALV-BTL', 'Botol Alvoria 100 ml', 100, 'pcs', 100, 10000, 0.11, 'XP-1', 1];
    }
    $w = new XlsxWriter();
    $w->addSheet('PO_MASTER', ['PO_ID', 'PO_NUMBER', 'PO_DATE', 'CUSTOMER_ID', 'CUSTOMER_NAME', 'CUSTOMER_CODE', 'CURRENCY', 'SUBTOTAL', 'DISCOUNT', 'TAX', 'SHIPPING_COST', 'GRAND_TOTAL',
        'PAYMENT_TERM', 'DELIVERY_DATE', 'DELIVERY_ADDRESS', 'PO_STATUS', 'SOURCE_FILE', 'DATA_CONFIDENCE', 'VALIDATION_STATUS', 'NOTES', 'PRICE_INCLUDES_TAX', 'CONTACT_PERSON', 'DOC_TYPE', 'COUNT_IN_SUMMARY', 'SOURCE_URL'], $master);
    $w->addSheet('PO_ITEMS', ['ITEM_ID', 'PO_ID', 'PO_NUMBER', 'ITEM_CODE', 'ITEM_NAME', 'QUANTITY', 'UNIT', 'UNIT_PRICE', 'SUBTOTAL', 'TAX', 'PRODUCT_ID', 'LINE_NO'], $items);
    $w->addSheet('CUSTOMERS', ['CUSTOMER_ID', 'CUSTOMER_NAME', 'CUSTOMER_CODE', 'ADDRESS', 'PHONE', 'EMAIL', 'CONTACT_PERSON', 'NOTES', 'CUSTOMER_NAME_RAW_VARIANTS'], [
        ['XC-A', 'PT ALVORIA KEMASINDO', 'ALV', 'Bekasi', '021-555', 'beli@alvoria.example', 'Rina', null, 'PT. Alvoria Kemasindo'],
        ['XC-B', 'BRONTEXIA', 'BRX', null, null, null, null, null, 'BRONTEXIA'],
        ['XC-Z', 'CV ZENTRIXA QORVANE', 'ZQ', 'Bandung', null, null, null, null, 'CV. Zentrixa Qorvane'],
        ['XC-R', 'PT ALVORIA PACKAGING', null, null, null, null, null, null, 'PT Alvoria Packaging'],
    ]);
    $w->addSheet('PRODUCTS', ['PRODUCT_ID', 'PRODUCT_CODE', 'PRODUCT_NAME', 'UNIT', 'CUSTOMER_ID'], [
        ['XP-1', 'ALV-BTL', 'Botol Alvoria 100 ml', 'pcs', 'XC-A'],
        ['XP-2', 'ALV-CAP', 'Tutup Alvoria 24 mm', 'pcs', 'XC-A'],
        ['XP-3', null, 'Tube Brontex 50 ml', 'Units', 'XC-B'],
        ['XP-4', 'ZQ-JAR-30', 'Jar Zentrixa 30 g', 'pcs', 'XC-Z'],
        ['XP-5', null, 'Label Alvoria Packaging', null, 'XC-R'],
    ]);
    $w->addSheet('VALIDATION', ['VALIDATION_ID', 'PO_NUMBER', 'SOURCE_FILE', 'FIELD', 'ORIGINAL_TEXT', 'EXTRACTED_VALUE', 'PROBLEM', 'CONFIDENCE', 'RECOMMENDED_ACTION', 'STATUS', 'PO_ID', 'CHECK_REF', 'REVIEWER_RESOLUTION'], [
        ['XV-1', 'UJI/ALV/011', 'po10.pdf', 'UNIT', 'pc5', 'pcs', 'Satuan buram', 'MEDIUM', 'Cek dokumen fisik', 'REVIEW REQUIRED', 'XPO-10', 'C5', null],
        ['XV-2', null, null, 'SOURCE_FOLDER', null, null, 'Folder ganda', 'HIGH', 'Arsipkan', 'OK', null, 'C11', null],
    ]);
    $w->save($path);
    return $path;
}

/** @return array<string,int> jumlah baris tabel bisnis */
function po_db_counts(): array
{
    $out = [];
    foreach (['customers', 'products', 'purchase_orders', 'po_lines', 'migration_issues'] as $t) {
        $out[$t] = (int) Database::fetchValue("SELECT COUNT(*) FROM `{$t}`");
    }
    return $out;
}

function po_db_issue(string $type, ?int $poId = null): ?array
{
    return Database::fetch('SELECT * FROM migration_issues WHERE issue_type = :t' . ($poId !== null ? ' AND record_id = :r' : '') . ' ORDER BY id DESC LIMIT 1',
        $poId !== null ? ['t' => $type, 'r' => $poId] : ['t' => $type]);
}

test('migrasi database: aman dijalankan ulang, kolom nilai PO tersedia', function () {
    assert_same([], Migrator::run(), 'tidak ada migrasi tertunda');
    foreach (['subtotal', 'tax_amount', 'grand_total', 'import_ref', 'import_status'] as $col) {
        assert_true(App\Helpers\Schema::hasColumn('purchase_orders', $col), $col);
    }
    assert_true(App\Helpers\Schema::hasColumn('po_lines', 'unit_price'));
    assert_true(App\Helpers\Schema::hasTable('import_logs'));
    assert_same(255, App\Helpers\Schema::columnLength('purchase_orders', 'payment_term'));
});

test('dry run: laporan lengkap, tidak ada data bisnis yang berubah', function () {
    po_db_seed();
    $file = po_db_workbook('v1');
    $before = po_db_counts();
    $r = (new PoDatabaseImporter($file, 'PO_DB_v1.xlsx'))->dryRun(null);
    assert_same($before, po_db_counts(), 'dry run tidak menulis');
    assert_same('DRY_RUN', $r['mode']);
    assert_same(13, $r['purchase_orders']['total']);
    assert_same(5, $r['purchase_orders']['existing'], 'PO 1,2,3,4,6 cocok');
    assert_same(4, $r['purchase_orders']['inserted'], 'PO 7,8,9,10 baru');
    assert_same(4, $r['purchase_orders']['held'], 'PO 5 (mungkin sama), PO 11 (nomor milik customer lain), PO 13 & 14 (nomor ganda, tidak bisa dibedakan) ditahan');
    assert_same(1, $r['customers']['new'], 'Zentrixa baru');
    assert_same(1, $r['customers']['by_po_number'], 'BRONTEXIA dipetakan lewat 2 nomor PO');
    assert_same(1, $r['customers']['needs_review'], 'Alvoria Packaging ambigu (mirip Alvoria Kemasindo)');
    assert_same(2, $r['po_items']['invalid'], 'qty bukan angka & PO_ID yatim');
    $types = $r['issues']['by_type'];
    foreach (['PO DATE DIFFERS FROM DOCUMENT', 'PO ITEMS DIFFER FROM DOCUMENT', 'POSSIBLE EXISTING PO', 'PO NUMBER USED BY OTHER CUSTOMER', 'PO NUMBER FORMAT DIFFERS', 'CUSTOMER NOT MAPPED', 'REVIEW REQUIRED'] as $t) {
        assert_true(isset($types[$t]), 'issue ' . $t);
    }
    $log = Database::fetch('SELECT * FROM import_logs WHERE id = :id', ['id' => $r['log_id']]);
    assert_same('COMPLETED_WITH_WARNING', $log['status']);
    $errors = array_filter($r['findings'], static fn (array $f): bool => $f['level'] === 'error');
    assert_true(count($errors) >= 2, 'baris error dilaporkan dengan sheet & nomor baris');
    $f = array_values(array_filter($errors, static fn (array $f): bool => $f['field'] === 'QUANTITY'))[0];
    assert_same('PO_ITEMS', $f['sheet']);
    assert_same(14, $f['row']);
});

test('import nyata wajib didahului dry run file yang sama', function () {
    po_db_seed();
    $file = po_db_workbook('nodry', ['XPO-1' => [19 => 'catatan beda']]);
    try {
        (new PoDatabaseImporter($file, 'beda.xlsx'))->import(null);
        fail('import tanpa dry run seharusnya ditolak');
    } catch (RuntimeException $e) {
        assert_contains('dry run', $e->getMessage());
    }
});

test('import: PO lama dilengkapi, PO baru dibuat, data ragu ditahan, rekonsiliasi benar', function () {
    $ids = po_db_seed();
    $file = po_db_workbook('v1');
    $imp = new PoDatabaseImporter($file, 'PO_DB_v1.xlsx');
    $imp->dryRun(null);
    $before = po_db_counts();
    $r = $imp->import(null);
    $after = po_db_counts();
    assert_same($before['purchase_orders'] + 4, $after['purchase_orders']);
    assert_same($before['customers'] + 1, $after['customers']);
    assert_true(is_file(APP_ROOT . '/storage/backups/' . $r['backup_file']), 'backup dibuat sebelum import');

    $po1 = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $ids['po1']]);
    assert_same('1665000.00', (string) $po1['grand_total']);
    assert_same('165000.00', (string) $po1['tax_amount']);
    assert_same('XPO-1', $po1['import_ref']);
    assert_same('OK', $po1['import_status']);
    assert_same('NET 30', $po1['payment_term'], 'termin kosong diisi');
    assert_same('https://drive.example.com/po1', $po1['doc_url']);
    $l1 = Database::fetch('SELECT * FROM po_lines WHERE po_id = :id', ['id' => $ids['po1']]);
    assert_same('1500.00', (string) $l1['unit_price']);
    assert_same('1500000.00', (string) $l1['line_subtotal']);

    // tanggal berbeda tidak ditimpa; baris dengan qty sama dipasangkan lewat kode produk
    $po2 = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $ids['po2']]);
    assert_same('2026-03-05', $po2['po_date']);
    assert_same('NEEDS_REVIEW', $po2['import_status']);
    assert_same('50.00', (string) Database::fetchValue('SELECT unit_price FROM po_lines WHERE id = :id', ['id' => $ids['po2_l1']]), 'tutup = ALV-CAP = 50');
    assert_same('100.00', (string) Database::fetchValue('SELECT unit_price FROM po_lines WHERE id = :id', ['id' => $ids['po2_l2']]), 'botol = ALV-BTL = 100');

    // harga termasuk PPN: subtotal baris = DPP
    $l3 = Database::fetch('SELECT * FROM po_lines WHERE po_id = :id', ['id' => $ids['po3']]);
    assert_same('1110.00', (string) $l3['unit_price']);
    assert_same('1000000.00', (string) $l3['line_subtotal']);
    assert_same('Units', $l3['unit']);
    assert_same(1, (int) Database::fetchValue('SELECT price_includes_tax FROM purchase_orders WHERE id = :id', ['id' => $ids['po3']]));

    // item berbeda: header dilengkapi, baris tidak diubah
    assert_same('3330000.00', (string) Database::fetchValue('SELECT grand_total FROM purchase_orders WHERE id = :id', ['id' => $ids['po4']]));
    assert_same(2, (int) Database::fetchValue('SELECT COUNT(*) FROM po_lines WHERE po_id = :id AND unit_price IS NULL', ['id' => $ids['po4']]));
    assert_true(po_db_issue('PO ITEMS DIFFER FROM DOCUMENT', $ids['po4']) !== null);

    // ditahan: tidak dibuat, tidak dihubungkan
    assert_same(null, Database::fetchValue('SELECT import_ref FROM purchase_orders WHERE id = :id', ['id' => $ids['po5']]));
    assert_same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM purchase_orders WHERE po_number = 'UJI/SHARED/7'"), 'nomor PO tidak digandakan');

    // beda format nomor → dihubungkan
    assert_same('XPO-6', Database::fetchValue('SELECT import_ref FROM purchase_orders WHERE id = :id', ['id' => $ids['po6']]));

    // PO baru + customer & produk baru
    $po7 = Database::fetch('SELECT p.*, c.name AS cname, c.customer_code FROM purchase_orders p JOIN customers c ON c.id = p.customer_id WHERE p.import_ref = :r', ['r' => 'XPO-7']);
    assert_same('CV ZENTRIXA QORVANE', $po7['cname']);
    assert_same('ZQ', $po7['customer_code']);
    assert_same('Open', $po7['status']);
    assert_same('ZQ-JAR-30', Database::fetchValue('SELECT pr.product_code FROM po_lines l JOIN products pr ON pr.id = l.product_id WHERE l.po_id = :id', ['id' => $po7['id']]));
    // versi lama → Cancelled; produk dipetakan lewat kode
    $po8 = Database::fetch("SELECT * FROM purchase_orders WHERE import_ref = 'XPO-8'");
    assert_same('Cancelled', $po8['status']);
    assert_same($ids['p1'], (int) Database::fetchValue('SELECT product_id FROM po_lines WHERE po_id = :id', ['id' => $po8['id']]));
    // customer ambigu → PO tanpa customer + issue
    $po9 = Database::fetch("SELECT * FROM purchase_orders WHERE import_ref = 'XPO-9'");
    assert_same(null, $po9['customer_id']);
    assert_same('NEEDS_REVIEW', $po9['import_status']);
    assert_same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM customers WHERE name LIKE '%Alvoria Packaging%'"), 'customer ambigu tidak dibuat (hindari duplikat)');
    // baris VALIDATION → issue terhubung ke PO
    $po10 = Database::fetch("SELECT * FROM purchase_orders WHERE import_ref = 'XPO-10'");
    assert_same('NEEDS_REVIEW', $po10['import_status']);
    assert_true(po_db_issue('REVIEW REQUIRED', (int) $po10['id']) !== null);
    assert_same(1, (int) Database::fetchValue('SELECT COUNT(*) FROM po_lines WHERE po_id = :id', ['id' => $po10['id']]), 'baris qty "abc" tidak diimport');

    // rekonsiliasi dari database
    $rc = $r['reconciliation'];
    assert_same('database', $rc['source']);
    assert_same(13, $rc['excel_po_count']);
    assert_same(9, $rc['app_po_count']);
    $reasons = array_column($rc['differences'], 'reason', 'po_id');
    assert_contains('POSSIBLE EXISTING PO', $reasons['XPO-5']);
    assert_contains('PO NUMBER USED BY OTHER CUSTOMER', $reasons['XPO-11']);
    assert_same('-180930.00', $rc['grand_total_difference'], 'selisih = nilai 4 PO yang ditahan (77.700 + 5.550 + 44.400 + 53.280)');
    assert_contains('PO MATCH AMBIGUOUS', $reasons['XPO-13']);
    assert_same(null, Database::fetchValue('SELECT import_ref FROM purchase_orders WHERE id = :id', ['id' => $ids['dupB']]), 'PO bernomor ganda tidak dihubungkan dengan menebak');
    $gaps = array_column($rc['item_gaps'], 'reason', 'item_id');
    assert_contains('berbeda', $gaps['XIT-5']);
    assert_contains('ditahan', $gaps['XIT-6']);
    assert_same('IMPORT', Database::fetchValue('SELECT mode FROM import_logs WHERE id = :id', ['id' => $r['log_id']]));
});

test('import ulang file yang sama: tidak ada data ganda atau perubahan', function () {
    po_db_seed();
    $file = po_db_workbook('v1');
    $imp = new PoDatabaseImporter($file, 'PO_DB_v1.xlsx');
    $before = po_db_counts();
    $r = $imp->import(null);
    assert_same($before, po_db_counts());
    assert_same(0, $r['purchase_orders']['inserted']);
    assert_same(0, $r['purchase_orders']['updated']);
    assert_same(9, $r['purchase_orders']['unchanged']);
    assert_same(0, $r['issues']['new']);
});

test('file versi baru: nilai hasil import diperbarui; nilai yang diubah user tidak ditimpa', function () {
    $ids = po_db_seed();
    // user mengoreksi PPN PO3 di aplikasi
    Database::update('purchase_orders', ['tax_amount' => '111000.00'], 'id = :id', ['id' => $ids['po3']]);
    $file = po_db_workbook('v2', ['XPO-1' => [11 => 1700000, 9 => 200000], 'XPO-3' => [9 => 120000, 11 => 1120000]]);
    $imp = new PoDatabaseImporter($file, 'PO_DB_v2.xlsx');
    $imp->dryRun(null);
    $imp->import(null);
    assert_same('1700000.00', (string) Database::fetchValue('SELECT grand_total FROM purchase_orders WHERE id = :id', ['id' => $ids['po1']]), 'belum diubah user → diperbarui');
    assert_same('111000.00', (string) Database::fetchValue('SELECT tax_amount FROM purchase_orders WHERE id = :id', ['id' => $ids['po3']]), 'diubah user → dipertahankan');
    $conflict = Database::fetch("SELECT * FROM migration_issues WHERE issue_type = 'VALUE CONFLICT' AND record_id = :id AND field_name = 'tax_amount'", ['id' => $ids['po3']]);
    assert_true($conflict !== null);
    assert_same('111000.00', $conflict['master_value']);
    assert_same('120000.00', $conflict['suggested_value']);
});

test('gagal di tengah import → rollback total, status ROLLED_BACK', function () {
    po_db_seed();
    $file = po_db_workbook('v3', [], true);
    $imp = new PoDatabaseImporter($file, 'PO_DB_v3.xlsx');
    $imp->dryRun(null);
    $before = po_db_counts();
    $imp->failAfterWrites = 1;
    try {
        $imp->import(null);
        fail('seharusnya gagal');
    } catch (ImportFailed $e) {
        assert_same('ROLLED_BACK', $e->status);
        assert_same('ROLLED_BACK', Database::fetchValue('SELECT status FROM import_logs WHERE id = :id', ['id' => $e->logId]));
    }
    assert_same($before, po_db_counts(), 'tidak ada yang tersimpan');
    assert_same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM purchase_orders WHERE import_ref = 'XPO-12'"));
});

test('review: hubungkan PO yang mungkin sama, import ulang melengkapinya; pakai nilai dokumen massal', function () {
    $ids = po_db_seed();
    $admin = client_as('Admin');
    $issue = Database::fetch("SELECT * FROM migration_issues WHERE issue_type = 'POSSIBLE EXISTING PO' AND record_id = :id", ['id' => $ids['po5']]);
    assert_true($issue !== null);
    $page = $admin->get('/migration-issues/' . $issue['id']);
    assert_contains('name="po_code"', $page->body, 'pilihan PO kandidat tampil');
    assert_redirect($admin->post('/migration-issues/' . $issue['id'] . '/link-po', []), '/migration-issues/' . $issue['id']);
    assert_same('XPO-5', Database::fetchValue('SELECT import_ref FROM purchase_orders WHERE id = :id', ['id' => $ids['po5']]));
    assert_same('Resolved', Database::fetchValue('SELECT resolution_status FROM migration_issues WHERE id = :id', ['id' => $issue['id']]));
    $file = po_db_workbook('v2', ['XPO-1' => [11 => 1700000, 9 => 200000], 'XPO-3' => [9 => 120000, 11 => 1120000]]);
    $r = (new PoDatabaseImporter($file, 'PO_DB_v2.xlsx'))->import(null); // dry run v2 sudah ada
    assert_same('77700.00', (string) Database::fetchValue('SELECT grand_total FROM purchase_orders WHERE id = :id', ['id' => $ids['po5']]));
    assert_same(0, $r['purchase_orders']['inserted']);

    // label nilai & pakai nilai dokumen massal untuk issue tanggal
    $date = Database::fetch("SELECT * FROM migration_issues WHERE issue_type = 'PO DATE DIFFERS FROM DOCUMENT' AND record_id = :id", ['id' => $ids['po2']]);
    assert_contains('Nilai di dokumen PO', $admin->get('/migration-issues/' . $date['id'])->body);
    $admin->get('/migration-issues');
    assert_redirect($admin->post('/migration-issues/bulk', ['ids' => [$date['id']], 'status' => 'apply_suggested']), '/migration-issues');
    assert_same('2026-03-04', Database::fetchValue('SELECT po_date FROM purchase_orders WHERE id = :id', ['id' => $ids['po2']]));
    MigrationIssue::refreshPoReviewStatus($ids['po2']);
    assert_same('OK', Database::fetchValue('SELECT import_status FROM purchase_orders WHERE id = :id', ['id' => $ids['po2']]), 'issue terakhir selesai → OK');
});

test('halaman Import database PO: dry run lewat web, lalu import tanpa upload ulang; akses hanya Admin', function () {
    po_db_seed();
    assert_status(403, client_as('Marketing')->get('/import/po'));
    $admin = client_as('Admin');
    assert_status(200, $admin->get('/import/po'));
    $file = po_db_workbook('web', ['XPO-7' => [19 => 'versi web']]);
    $dry = $admin->upload('/import/po', ['mode' => 'dry'], ['file' => $file]);
    assert_status(200, $dry);
    assert_contains('Dry run selesai', $dry->body);
    assert_contains('Rekonsiliasi Excel vs aplikasi', $dry->body);
    preg_match('/name="log_id" value="(\d+)"/', $dry->body, $m);
    assert_true(isset($m[1]), 'tombol Import sekarang tersedia');
    $imp = $admin->post('/import/po', ['mode' => 'import', 'log_id' => $m[1]]);
    assert_status(200, $imp);
    assert_contains('Import selesai', $imp->body);
    preg_match('#/import/logs/(\d+)#', $imp->body, $lm);
    $log = $admin->get('/import/logs/' . $lm[1]);
    assert_status(200, $log);
    assert_contains('Rekonsiliasi', $log->body);
    // log yang bukan dry run tidak bisa dipakai untuk import
    assert_status(422, $admin->post('/import/po', ['mode' => 'import', 'log_id' => $lm[1]]));
    assert_status(403, client_as('Marketing')->post('/import/po', ['mode' => 'dry']));
});

test('PO: nilai tampil di list & detail; filter bulan & perlu review; sort nilai', function () {
    $ids = po_db_seed();
    $c = client_as('Marketing');
    $list = $c->get('/purchase-orders', ['month' => '2026-03', 'q' => 'UJI/ALV']);
    assert_status(200, $list);
    assert_contains('UJI/ALV/001', $list->body);
    assert_not_contains('UJI/ZEN/001', $list->body, 'bulan April tidak tampil');
    $grand = (string) Database::fetchValue('SELECT grand_total FROM purchase_orders WHERE id = :id', ['id' => $ids['po1']]);
    assert_contains(App\Helpers\Number::money($grand), $list->body, 'grand total tampil sesuai database');
    $review = $c->get('/purchase-orders', ['review' => 'needs_review', 'q' => 'UJI/']);
    assert_contains('UJI/ALV/011', $review->body);
    assert_not_contains('UJI/ALV/001<', $review->body);
    assert_status(200, $c->get('/purchase-orders', ['sort' => 'value', 'dir' => 'desc']));
    $show = $c->get('/purchase-orders/' . $ids['po3']);
    assert_contains('Nilai PO', $show->body);
    assert_contains('harga satuan sudah termasuk PPN', $show->body);
    assert_contains('Rp 1.110', $show->body);
    assert_contains('Rp 1.000.000', $show->body);
});

test('PO: buat & edit dengan harga → subtotal, PPN, grand total dihitung benar', function () {
    $ids = po_db_seed();
    $c = client_as('Marketing');
    $c->get('/purchase-orders/create');
    $res = $c->post('/purchase-orders', [
        'po_number' => 'UJI/NILAI/001', 'customer_id' => $ids['custA'], 'po_date' => '2026-05-01', 'status' => 'Open', 'currency' => 'idr',
        'price_includes_tax' => '0', 'tax_amount' => '33.000', 'discount_amount' => '0', 'shipping_cost' => '10.000',
        'lines' => [
            ['product_id' => $ids['p1'], 'order_qty' => '1.000', 'unit' => 'pcs', 'unit_price' => '250'],
            ['product_id' => $ids['p2'], 'order_qty' => '1000', 'unit' => 'pcs', 'unit_price' => '50'],
        ],
    ]);
    $id = (int) Database::fetchValue("SELECT id FROM purchase_orders WHERE po_number = 'UJI/NILAI/001'");
    assert_redirect($res, '/purchase-orders/' . $id);
    $po = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $id]);
    assert_same('IDR', $po['currency']);
    assert_same('300000.00', (string) $po['subtotal'], '1000×250 + 1000×50');
    assert_same('343000.00', (string) $po['grand_total'], '300.000 + 33.000 + 10.000');

    // tambah baris → subtotal & grand total ikut berubah
    $c->get('/purchase-orders/' . $id);
    $c->post('/purchase-orders/' . $id . '/lines', ['product_id' => $ids['p3'], 'order_qty' => '10', 'unit' => 'pcs', 'unit_price' => '1.000']);
    assert_same('310000.00', (string) Database::fetchValue('SELECT subtotal FROM purchase_orders WHERE id = :id', ['id' => $id]));
    assert_same('353000.00', (string) Database::fetchValue('SELECT grand_total FROM purchase_orders WHERE id = :id', ['id' => $id]));

    // harga termasuk PPN → subtotal baris = DPP
    $c->get('/purchase-orders/' . $id . '/edit');
    $res = $c->post('/purchase-orders/' . $id, ['po_number' => 'UJI/NILAI/001', 'customer_id' => $ids['custA'], 'po_date' => '2026-05-01', 'status' => 'Open',
        'currency' => 'IDR', 'price_includes_tax' => '1', 'tax_amount' => '30.720,71', 'discount_amount' => '', 'shipping_cost' => '']);
    assert_redirect($res, '/purchase-orders/' . $id);
    $po = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $id]);
    // DPP per baris dibulatkan: 225.225,23 + 45.045,05 + 9.009,01
    assert_same('279279.29', (string) $po['subtotal'], 'jumlah DPP per baris');
    assert_same('310000.00', (string) $po['grand_total'], 'DPP + PPN = total harga');

    // hapus baris → dihitung ulang
    $line = (int) Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :id AND product_id = :p', ['id' => $id, 'p' => $ids['p3']]);
    $c->get('/po-lines/' . $line . '/edit');
    $c->post('/po-lines/' . $line . '/delete');
    assert_same('270270.28', (string) Database::fetchValue('SELECT subtotal FROM purchase_orders WHERE id = :id', ['id' => $id]), '225.225,23 + 45.045,05');

    // validasi angka & mata uang
    $base = ['po_number' => 'UJI/NILAI/001', 'customer_id' => $ids['custA'], 'po_date' => '2026-05-01', 'status' => 'Open'];
    $c->get('/purchase-orders/' . $id . '/edit');
    $bad = $c->post('/purchase-orders/' . $id, $base + ['currency' => 'RP1']);
    assert_status(422, $bad);
    assert_contains('Mata uang harus kode 3 huruf', $bad->body);
    $bad = $c->post('/purchase-orders/' . $id, $base + ['tax_amount' => 'abc', 'discount_amount' => '-5']);
    assert_status(422, $bad);
    assert_contains('PPN harus berupa angka', $bad->body);
    assert_contains('Diskon minimal 0', $bad->body);
});

test('kode baru, database belum diperbarui: Admin diarahkan ke Pembaruan database, user lain melihat pesan pemeliharaan', function () {
    $name = Migrator::all()[0];
    Database::delete('schema_migrations', 'migration = :m', ['m' => $name]);
    try {
        $admin = client_as('Admin');
        assert_redirect($admin->get('/purchase-orders'), '/settings/database');
        $page = $admin->get('/settings/database');
        assert_status(200, $page);
        assert_contains('belum dijalankan', $page->body);
        $other = client_as('Marketing');
        assert_status(503, $other->get('/customers'));
        assert_redirect($admin->post('/settings/database', []), '/settings/database');
    } finally {
        Migrator::reset();
        Migrator::run();
    }
    assert_same([], Migrator::pending());
    assert_status(200, client_as('Marketing')->get('/customers'));
});

// Hapus file backup yang dibuat selama test (backup development lain tidak disentuh)
test('nomor PO ganda: admin memilih PO yang sesuai, import berikutnya menghubungkan sisanya & menandai status yang berbeda', function () {
    $ids = po_db_seed();
    $admin = client_as('Admin');
    $issue = Database::fetch("SELECT * FROM migration_issues WHERE issue_type = 'PO MATCH AMBIGUOUS' AND suggested_value = 'XPO-14'");
    assert_true($issue !== null, 'issue ambigu untuk revisi');
    $page = $admin->get('/migration-issues/' . $issue['id']);
    $codeA = (string) Database::fetchValue('SELECT code FROM purchase_orders WHERE id = :id', ['id' => $ids['dupA']]);
    $codeB = (string) Database::fetchValue('SELECT code FROM purchase_orders WHERE id = :id', ['id' => $ids['dupB']]);
    assert_contains('value="' . $codeA . '"', $page->body, 'kandidat 1 bisa dipilih');
    assert_contains('value="' . $codeB . '"', $page->body, 'kandidat 2 bisa dipilih');
    assert_redirect($admin->post('/migration-issues/' . $issue['id'] . '/link-po', ['po_code' => $codeB]), '/migration-issues/' . $issue['id']);
    assert_same('XPO-14', Database::fetchValue('SELECT import_ref FROM purchase_orders WHERE id = :id', ['id' => $ids['dupB']]));
    // kode yang bukan kandidat ditolak
    $other = Database::fetch("SELECT * FROM migration_issues WHERE issue_type = 'PO MATCH AMBIGUOUS' AND suggested_value = 'XPO-13'");
    $admin->get('/migration-issues/' . $other['id']);
    $admin->post('/migration-issues/' . $other['id'] . '/link-po', ['po_code' => 'PO-BUKANKANDIDAT']);
    assert_same(null, Database::fetchValue("SELECT id FROM purchase_orders WHERE import_ref = 'XPO-13'"));
    $file = po_db_workbook('v2', ['XPO-1' => [11 => 1700000, 9 => 200000], 'XPO-3' => [9 => 120000, 11 => 1120000]]);
    (new PoDatabaseImporter($file, 'PO_DB_v2.xlsx'))->import(null);
    assert_same('XPO-13', Database::fetchValue('SELECT import_ref FROM purchase_orders WHERE id = :id', ['id' => $ids['dupA']]), 'tinggal satu kandidat → terhubung');
    assert_same('53280.00', (string) Database::fetchValue('SELECT grand_total FROM purchase_orders WHERE id = :id', ['id' => $ids['dupB']]));
    $status = Database::fetch("SELECT * FROM migration_issues WHERE issue_type = 'PO STATUS DIFFERS FROM DOCUMENT' AND record_id = :id", ['id' => $ids['dupA']]);
    assert_true($status === null, 'versi lama (N) & PO Cancelled: konsisten, tidak ada issue');
    assert_true(Database::fetch("SELECT 1 FROM migration_issues WHERE issue_type = 'PO STATUS DIFFERS FROM DOCUMENT' AND record_id = :id", ['id' => $ids['dupB']]) === null, 'revisi berlaku & PO Open: konsisten');
});

foreach (glob(APP_ROOT . '/storage/backups/*.sql') ?: [] as $f) {
    if (filemtime($f) >= $poDbTestStart && preg_match('/before-(po-import|migrate)/', $f)) {
        @unlink($f);
    }
}
