<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\XlsxReader;
use App\Helpers\XlsxWriter;
use App\Services\Migration\LegacyVerifier;
use App\Services\Migration\WorkbookImporter;

/*
 * Test importer memakai workbook SINTETIS (data asli perusahaan tidak boleh
 * masuk repository). Opsional: set PIK_REAL_WORKBOOK=/path/master.xlsx dan
 * PIK_REAL_LEGACY=/path/a.xlsx;/path/b.xlsx untuk menguji workbook asli.
 * File ini dijalankan terakhir karena mengosongkan data bisnis.
 */

group('Import · Unit (verifier legacy)');

test('bulan dari nomor dokumen', function () {
    assert_same(4, LegacyVerifier::documentMonth('020/BDD/IV/2024'));
    assert_same(9, LegacyVerifier::documentMonth('PIK/SEPT/24/INV/0238'));
    assert_same(2, LegacyVerifier::documentMonth('PURC/20260204/1770176834846'));
    assert_same(4, LegacyVerifier::documentMonth('PO-11.04.26.011'));
    assert_same(3, LegacyVerifier::documentMonth('NIC/202603-074'));
    assert_same(4, LegacyVerifier::documentMonth('PO/260409/0017/PP/JTU100'));
    assert_same(6, LegacyVerifier::documentMonth('0001/RPI-PO/06/2026'));
    assert_same(4, LegacyVerifier::documentMonth('001/GNI-EXT/PO/IV/26'));
    assert_same(null, LegacyVerifier::documentMonth('D2600030'));
    assert_same(null, LegacyVerifier::documentMonth(''));
});

test('parse tanggal & angka format Indonesia, swap hari/bulan', function () {
    assert_same('2024-01-24', LegacyVerifier::parseIndonesianDate('24 Januari 2024'));
    assert_same('2024-07-31', LegacyVerifier::parseIndonesianDate('31/7/24'));
    assert_same('2024-09-04', LegacyVerifier::parseIndonesianDate('4/9/2024'));
    assert_same(null, LegacyVerifier::parseIndonesianDate('31/13/24'));
    assert_same('2025-11-06', LegacyVerifier::swapDayMonth('2025-06-11'));
    assert_same(null, LegacyVerifier::swapDayMonth('2025-06-13'), 'hari > 12 tidak bisa hasil tukar');
    assert_same(['value' => '1846.85', 'unambiguous' => true], LegacyVerifier::parseIdNumber(' 1.846,85'));
    assert_same(['value' => '270.27', 'unambiguous' => true], LegacyVerifier::parseIdNumber('270,27'));
    assert_same(false, LegacyVerifier::parseIdNumber('1,846')['unambiguous'], '3 digit setelah koma ambigu');
    assert_same(2497, LegacyVerifier::sjSequence(' PIK-SJ-02497 '));
    assert_same(null, LegacyVerifier::sjSequence('SJ-GNI-0726-012'));
});

/** Buat workbook master & legacy sintetis. @return array{master:string,legacy:string} */
function build_import_fixture(): array
{
    $dir = sys_get_temp_dir();
    $master = $dir . '/pik-fixture-master-' . getmypid() . '.xlsx';
    $legacy = $dir . '/pik-fixture-Legacy_A-' . getmypid() . '.xlsx';
    $d = static fn (string $s) => new DateTimeImmutable($s, new DateTimeZone('UTC'));
    $src = 'Legacy A.xlsx';
    $w = new XlsxWriter();
    $w->addSheet('CUSTOMERS', ['CustomerID', 'CustomerName', 'Company', 'PIC', 'Phone', 'Email', 'Industry', 'CustomerStatus', 'Source', 'MarketingPIC', 'Notes', 'CreatedAt'], [
        ['CUS-A000000001', 'PT Alpha Kosmetik', 'PT Alpha Kosmetik', null, null, null, null, 'Active', 'Imported', null, 'Imported from legacy data', null],
        ['CUS-A000000002', 'PT. ALPHA KOSMETIK', null, null, null, 'bukan-email', null, 'Active', 'Imported', 'Budi', null, null],
        ['CUS-A000000003', 'CV Beta', null, null, null, null, null, 'Weird', 'Imported', null, null, null],
    ]);
    $w->addSheet('CONTACTS', ['ContactID', 'CustomerID', 'ContactName'], []);
    $w->addSheet('PRODUCTS', ['ProductID', 'ProductName', 'ProductCode', 'Variant', 'Category', 'ProductionCapacityPerDay', 'Unit', 'Active', 'Source'], [
        ['PRD-A000000001', 'Botol A', '[BTA]', null, null, null, 'pcs', true, 'Imported'],
        ['PRD-A000000002', 'Pot B', '[PTB]', 'Pink', null, null, 'pcs', true, 'Imported'],
        ['PRD-A000000003', 'Botol A', '[BTA]', null, null, null, 'pcs', true, 'Imported'],
    ]);
    $w->addSheet('PURCHASE_ORDERS', ['POID', 'PONumber', 'CustomerID', 'PODate', 'PaymentTerm', 'Status', 'Remark', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['PO-A000000001', '020/BDD/IV/2024', 'CUS-A000000001', $d('2024-01-04'), 'DP 50%', 'On Proses', null, $src, 'PO Masuk', 2],
        ['PO-A000000002', 'X/1', 'CUS-A000000001', $d('2024-05-05'), null, 'Hold', null, $src, 'PO Masuk', 3],
        ['PO-A000000003', 'X/1', 'CUS-A000000002', $d('2024-06-06'), null, 'cancel', null, $src, 'PO Masuk', 4],
        ['PO-A000000004', null, null, null, null, 'Closed', null, $src, 'PO Masuk', 5],
    ]);
    $w->addSheet('PO_LINES', ['POLineID', 'POID', 'ProductID', 'ProductNameLegacy', 'VariantLegacy', 'OrderQuantity', 'DeliveredQuantitySource', 'ReturnQuantitySource', 'OutstandingSource', 'StatusSource', 'CapacityPerDay', 'Remark', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['POL-A000000001', 'PO-A000000001', 'PRD-A000000001', 'Botol A', null, 1000, 500, 0, 500, 'Process', null, null, $src, 'PO Masuk', 2],
        ['POL-A000000002', 'PO-A000000001', 'PRD-A000000002', 'Pot B', 'Pink', 200, null, null, 200, 'Process', null, null, $src, 'PO Masuk', 2],
        ['POL-A000000003', 'PO-A000000002', 'PRD-A000000001', 'Botol A', null, 300, null, null, 300, 'Hold', null, null, $src, 'PO Masuk', 3],
    ]);
    $w->addSheet('DELIVERIES', ['DeliveryID', 'POID', 'POLineID', 'ProductID', 'DeliveryDate', 'SJNumber', 'Destination', 'DeliveredQuantity', 'Note', 'Attachment', 'SourceFile', 'SourceSheet', 'LegacyRow', 'MigrationFlag'], [
        ['DEL-A000000001', 'PO-A000000001', 'POL-A000000001', 'PRD-A000000001', $d('2024-05-10'), 'PIK-SJ-00100', 'PT Alpha', 400, null, null, $src, 'Delivery Details', 2, 'OK'],
        ['DEL-A000000002', 'PO-A000000001', null, null, $d('2024-05-11'), 'PIK-SJ-00101', 'PT Alpha', 50, null, null, $src, 'Delivery Details', 3, 'PRODUCT/LINE NOT MATCHED'],
        ['DEL-A000000005', 'PO-A000000001', 'POL-A000000001', 'PRD-A000000001', $d('2024-12-05'), 'PIK-SJ-00102', 'PT Alpha', 100, null, null, $src, 'Delivery Details', 4, 'OK'],
        ['DEL-A000000006', 'PO-A000000001', 'POL-A000000002', 'PRD-A000000002', $d('2024-05-14'), 'PIK-SJ-00103', 'PT Alpha', 20, null, null, $src, 'Delivery Details', 5, 'OK'],
        ['DEL-A000000003', null, null, null, $d('2024-05-20'), 'PIK-SJ-00104', 'Tidak diketahui', 70, null, null, $src, 'Delivery Details', 6, 'PO NOT FOUND'],
        ['DEL-A000000004', 'PO-A000000001', 'POL-A000000001', 'PRD-A000000001', $d('2024-05-21'), 'PIK-SJ-00105', 'PT Alpha', -20, null, null, $src, 'Delivery Details', 7, 'OK'],
    ]);
    $w->addSheet('RETURNS', ['ReturnID', 'POID', 'ProductID', 'PONumberLegacy', 'ProductLegacy', 'ReturnDate', 'SJNumber', 'Destination', 'ReturnQuantity', 'Attachment', 'Note', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['RET-A000000001', 'PO-A000000001', 'PRD-A000000001', '020/BDD/IV/2024', 'Botol A', $d('2024-06-01'), null, 'PT Alpha', 30, null, null, $src, 'RETURN', 2],
        ['RET-A000000002', 'PO-A000000001', null, '020/BDD/IV/2024', 'Botol ?', null, null, 'PT Alpha', 10, null, null, $src, 'RETURN', 3],
    ]);
    $w->addSheet('STOCK', ['StockID', 'ProductID', 'ProductLegacy', 'StockType', 'Quantity', 'Box', 'QtyPerBox', 'Status', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['STK-A000000001', 'PRD-A000000001', 'Botol A', 'FG', 1000, 2, 500, 'Ready', $src, 'STOCK', 3],
        ['STK-A000000002', null, 'Nama Barang', 'FG', null, null, null, 'Ready', $src, 'STOCK', 2],
        ['STK-A000000003', null, 'Barang X', 'WIP', 50, null, null, null, $src, 'STOCK', 4],
    ]);
    $w->addSheet('LEADTIME', ['LeadTimeID', 'POID', 'ProductID', 'PONumberLegacy', 'ProductLegacy', 'Quantity', 'DeliveryDate', 'Status', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['LT-A000000001', null, null, 'X/1', 'Botol A', 100, $d('2024-07-01'), 'Terkirim', $src, 'LT', 2],
        ['LT-A000000002', null, null, 'Estimasi Total Delivery', null, 900, null, null, $src, 'LT', 3],
    ]);
    $w->addSheet('INBOUND_MAKLON', ['InboundID', 'Vendor', 'Receiver', 'ActualInboundDate', 'SJDate', 'SJNumber', 'PONumberLegacy', 'InternalComponentCode', 'Type', 'ComponentName', 'FactoryComponentCode', 'Quantity', 'RejectQuantity', 'TotalIn', 'Attachment', 'Notes', 'OdooChecklist', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['INB-A000000001', 'PIK', 'ALAMANDA', $d('2024-05-01'), null, 'PIK-SJ-00090', '020/BDD/IV/2024', null, null, null, '[PTB]', 500, 0, 500, null, null, null, $src, 'INB', 2],
    ]);
    $w->addSheet('INVOICES_PAYMENTS', ['InvoicePaymentID', 'POID', 'PONumberLegacy', 'InvoiceDate', 'InvoiceNumber', 'InvoiceAmount', 'PaymentAmount', 'PaymentDate', 'PaymentReceiptNumber', 'InvoiceOutstanding', 'CumulativeOutstanding', 'InvoiceAttachment', 'PaymentAttachment', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['PAY-A000000001', 'PO-A000000001', '020/BDD/IV/2024', $d('2024-10-02'), 'PIK/OKT/24/INV/0001', 1000000, 1000000, $d('2024-10-09'), '123.0', 0, 0, 'https://drive.google.com/file/d/x/view', null, $src, 'INVOICE', 2],
        ['PAY-A000000002', null, '020/BDD/IV/2024', $d('2024-10-05'), 'PIK/OKT/24/INV/0002', 500000, 100000, null, null, 400000, 400000, null, null, $src, 'INVOICE', 3],
    ]);
    $w->addSheet('PO_FINANCIALS', ['POFinancialID', 'POID', 'PONumberLegacy', 'Brand', 'PODate', 'ProductLegacy', 'ProductCodeLegacy', 'OrderQuantity', 'UnitPrice', 'TotalOrderAmount', 'PPN', 'TotalInclPPN', 'DeliveredQuantity', 'UndeliveredQuantity', 'Outstanding', 'Status', 'POAttachment', 'SourceFile', 'SourceSheet', 'LegacyRow'], [
        ['POF-A000000001', 'PO-A000000001', '020/BDD/IV/2024', 'Alpha', $d('2024-04-01'), 'Botol A', null, 1000, 1.5, 1500000, 165000, 1665000, 1000, 0, 0, 'BELUM LUNAS', null, $src, 'SUMMARY', 2],
    ]);
    foreach (['LEADS' => ['LeadID'], 'ACTIVITIES' => ['ActivityID'], 'FOLLOW_UP' => ['FollowUpID'], 'USERS' => ['UserID']] as $sheet => $h) {
        $w->addSheet($sheet, $h, []);
    }
    $w->addSheet('MIGRATION_ISSUES', ['IssueID', 'TableName', 'RecordID', 'IssueType', 'LegacyPO', 'LegacyProduct', 'Description', 'SourceFile', 'SourceSheet', 'LegacyRow', 'ResolutionStatus'], [
        ['ISS-A000000001', 'DELIVERIES', 'DEL-A000000002', 'PRODUCT/LINE NOT MATCHED', null, null, 'Delivery cannot yet be linked.', $src, 'Delivery Details', 3, 'Needs Review'],
        ['ISS-A000000002', 'DELIVERIES', 'DEL-A000000003', 'PO NOT FOUND', null, null, 'Delivery cannot yet be linked.', $src, 'Delivery Details', 6, 'Needs Review'],
    ]);
    $w->save($master);

    // File legacy: tanggal legacy bertipe date. Delivery 00102 tertukar di master.
    $l = new XlsxWriter();
    $l->addSheet('PO Masuk', ['No', 'No. PO', 'Nama Konsumen', '  Tanggal'], [
        [1, '020/BDD/IV/2024', 'PT Alpha', $d('2024-04-01')],
        [2, 'X/1', 'PT Alpha', $d('2024-05-05')],
        [3, 'X/1', 'PT Alpha', $d('2024-06-06')],
        [4, '-', '-', '-'],
    ]);
    $l->addSheet('Delivery Details', ['No.', 'PO NUMBER', 'Product', 'Delivery Date', 'No. Surat Jalan', 'Delivery Destination', 'Delivered Quantity'], [
        [1, '020/BDD/IV/2024', 'Botol A', $d('2024-05-10'), 'PIK-SJ-00100', 'PT Alpha', 400],
        [2, '020/BDD/IV/2024', 'Botol Alpha Kuning', $d('2024-05-11'), 'PIK-SJ-00101 ', 'PT Alpha', 50],
        [3, '020/BDD/IV/2024', 'Botol A', $d('2024-05-12'), 'PIK-SJ-00102', 'PT Alpha', 100],
        [4, '020/BDD/IV/2024', 'Pot B', $d('2024-05-14'), 'PIK-SJ-00103', 'PT Alpha', 20],
        [5, '999/NOPE', 'Barang ?', $d('2024-05-20'), 'PIK-SJ-00104', 'Tidak diketahui', 70],
        [6, '020/BDD/IV/2024', 'Botol A', $d('2024-05-21'), 'PIK-SJ-00105', 'PT Alpha', -20],
    ]);
    // Excel ber-locale US: pembayaran "9/10/24" (9 Okt) tersimpan sebagai 10 Sep → master yang benar
    $l->addSheet('INVOICE', ['No.', 'PO Number', 'Invoice Date', 'Invoice Number', 'Invoice Amount', 'Payment Amount', 'Payment Date'], [
        [1, '020/BDD/IV/2024', $d('2024-10-02'), 'PIK/OKT/24/INV/0001', 1000000, 1000000, $d('2024-09-10')],
        [2, '020/BDD/IV/2024', $d('2024-10-05'), 'PIK/OKT/24/INV/0002', 500000, 100000, null],
    ]);
    $l->save($legacy);
    return ['master' => $master, 'legacy' => $legacy];
}

group('Import · Workbook sintetis');

test('import penuh: jumlah, relasi, status, issue, koreksi legacy berbasis bukti', function () {
    $fx = build_import_fixture();
    WorkbookImporter::wipeBusinessData();
    $importer = (new WorkbookImporter($fx['master'], [$fx['legacy']]))->build();
    $counts = $importer->execute(null);
    assert_same(3, $counts['customers']);
    assert_same(3, $counts['products']);
    assert_same(4, $counts['purchase_orders']);
    assert_same(3, $counts['po_lines']);
    assert_same(6, $counts['deliveries']);

    $issue = static fn (string $type, string $record) => Database::fetch('SELECT * FROM migration_issues WHERE issue_type = :t AND record_code = :r', ['t' => $type, 'r' => $record]);
    // Status mapping & traceability
    $po1 = Database::fetch("SELECT * FROM purchase_orders WHERE code = 'PO-A000000001'");
    assert_same('On Process', $po1['status']);
    assert_same('On Proses', $po1['legacy_status']);
    assert_same('Legacy A.xlsx', $po1['source_file']);
    assert_same(2, (int) $po1['legacy_row']);
    assert_same('Open', Database::fetchValue("SELECT status FROM purchase_orders WHERE code = 'PO-A000000002'"));
    assert_true($issue('STATUS MAPPED', 'PO-A000000002') !== null, 'Hold dipetakan + issue');
    assert_same('Cancelled', Database::fetchValue("SELECT status FROM purchase_orders WHERE code = 'PO-A000000003'"));
    assert_true($issue('DUPLICATE PO NUMBER', 'PO-A000000003') !== null);
    foreach (['CUSTOMER NOT FOUND', 'PO NUMBER MISSING', 'PO DATE MISSING'] as $t) {
        assert_true($issue($t, 'PO-A000000004') !== null, $t);
    }
    // Duplikat tidak digabung otomatis
    assert_true($issue('POSSIBLE DUPLICATE CUSTOMER', 'CUS-A000000002') !== null);
    assert_true($issue('POSSIBLE DUPLICATE PRODUCT', 'PRD-A000000003') !== null);
    assert_true($issue('INVALID EMAIL', 'CUS-A000000002') !== null);
    assert_true($issue('MARKETING PIC NOT MAPPED', 'CUS-A000000002') !== null);

    // Koreksi tanggal PO: nomor PO bulan IV mendukung legacy 2024-04-01
    assert_same('2024-04-01', $po1['po_date']);
    $corr = $issue('DATE CORRECTED FROM LEGACY', 'PO-A000000001');
    assert_same('Auto-Corrected', $corr['resolution_status']);
    assert_same('2024-01-04', $corr['master_value']);
    assert_same('purchase_orders', 'purchase_orders');
    assert_same((int) $po1['id'], (int) $corr['record_id']);
    // Delivery tertukar: urutan SJ mendukung legacy (00101 = 11 Mei, 00103 = 14 Mei)
    assert_same('2024-05-12', Database::fetchValue("SELECT delivery_date FROM deliveries WHERE code = 'DEL-A000000005'"));
    // Pembayaran: legacy (10 Sep) sebelum invoice (2 Okt) ⇒ master dipertahankan, tanpa issue
    assert_same('2024-10-09', Database::fetchValue("SELECT payment_date FROM invoices_payments WHERE code = 'PAY-A000000001'"));
    assert_true($issue('DATE CORRECTED FROM LEGACY', 'PAY-A000000001') === null);
    assert_same(1, (int) $importer->report['master_confirmed']);

    // Delivery: relasi line, flag, qty negatif, enrichment issue workbook
    $del2 = Database::fetch("SELECT * FROM deliveries WHERE code = 'DEL-A000000002'");
    assert_same(null, $del2['po_line_id']);
    assert_same('PRODUCT/LINE NOT MATCHED', $del2['migration_flag']);
    $wbIssue = Database::fetch("SELECT * FROM migration_issues WHERE code = 'ISS-A000000001'");
    assert_same((int) $del2['id'], (int) $wbIssue['record_id']);
    assert_same('Botol Alpha Kuning', $wbIssue['legacy_product'], 'issue workbook diperkaya nama produk legacy');
    assert_same('020/BDD/IV/2024', $wbIssue['legacy_po']);
    assert_true($issue('NEGATIVE QUANTITY', 'DEL-A000000004') !== null);
    assert_same(null, Database::fetchValue("SELECT migration_flag FROM deliveries WHERE code = 'DEL-A000000001'"));

    // Retur: produk sama & satu-satunya line di PO ⇒ tertaut; tanpa produk ⇒ issue
    assert_true(Database::fetchValue("SELECT po_line_id FROM returns WHERE code = 'RET-A000000001'") !== null);
    assert_true($issue('RETURN NOT LINKED TO PO LINE', 'RET-A000000002') !== null);

    // Outstanding line 1: order 1000 − delivered (400+100−20) + return 30 = 550
    $line = (int) Database::fetchValue("SELECT id FROM po_lines WHERE code = 'POL-A000000001'");
    assert_same(550, App\Models\PoLine::totals($line)['outstanding_qty']);

    // Stock, lead time, inbound, invoice, PO financial
    assert_true($issue('LEGACY HEADER ROW', 'STK-A000000002') !== null);
    assert_true($issue('STOCK PRODUCT NOT MATCHED', 'STK-A000000003') !== null);
    assert_true($issue('LEGACY SUMMARY ROW', 'LT-A000000002') !== null);
    assert_true($issue('PO NOT FOUND', 'LT-A000000001') !== null, 'nomor PO X/1 tidak unik ⇒ tidak ditautkan');
    assert_same('Delivered', Database::fetchValue("SELECT status FROM leadtime WHERE code = 'LT-A000000001'"));
    $inb = Database::fetch("SELECT po_id, product_id FROM inbound_maklon WHERE code = 'INB-A000000001'");
    assert_same((int) $po1['id'], (int) $inb['po_id'], 'nomor PO unik & sama persis ⇒ tertaut');
    assert_same((int) Database::fetchValue("SELECT id FROM products WHERE code = 'PRD-A000000002'"), (int) $inb['product_id']);
    $inv2 = Database::fetch("SELECT * FROM invoices_payments WHERE code = 'PAY-A000000002'");
    assert_same((int) $po1['id'], (int) $inv2['po_id']);
    assert_same((int) $po1['customer_id'], (int) $inv2['customer_id'], 'customer invoice mengikuti PO');
    assert_same('Partial', $inv2['status']);
    assert_true($issue('DUE DATE MISSING', 'PAY-A000000002') !== null);
    assert_same('123', Database::fetchValue("SELECT payment_receipt_number FROM invoices_payments WHERE code = 'PAY-A000000001'"));
    assert_true($issue('AMOUNT INCONSISTENT', 'POF-A000000001') !== null);
    assert_same('Unpaid', Database::fetchValue("SELECT payment_status FROM po_financials WHERE code = 'POF-A000000001'"));

    // Semua issue yang merujuk record berhasil ditautkan
    assert_same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM migration_issues WHERE record_code IS NOT NULL AND record_id IS NULL'));
    @unlink($fx['master']);
    @unlink($fx['legacy']);
});

test('import ditolak bila database sudah berisi data; tanpa file legacy tetap jalan', function () {
    $fx = build_import_fixture();
    $importer = (new WorkbookImporter($fx['master']))->build();
    try {
        $importer->execute(null);
        fail('seharusnya ditolak');
    } catch (RuntimeException $e) {
        assert_contains('Database sudah berisi data', $e->getMessage());
    }
    assert_contains('File legacy tidak diberikan', implode(' ', $importer->report['notes']));
    WorkbookImporter::wipeBusinessData();
    $counts = $importer->execute(null);
    assert_same(6, $counts['deliveries']);
    // tanpa legacy: tanggal master tidak diubah
    assert_same('2024-01-04', Database::fetchValue("SELECT po_date FROM purchase_orders WHERE code = 'PO-A000000001'"));
    @unlink($fx['master']);
    @unlink($fx['legacy']);
});

test('mode --sql-out menghasilkan SQL yang dapat dijalankan dan identik', function () {
    $fx = build_import_fixture();
    $sqlFile = sys_get_temp_dir() . '/pik-fixture-' . getmypid() . '.sql';
    (new WorkbookImporter($fx['master'], [$fx['legacy']]))->build()->writeSql($sqlFile);
    $sql = (string) file_get_contents($sqlFile);
    assert_contains("(SELECT id FROM `customers` WHERE code = 'CUS-A000000001')", $sql);
    WorkbookImporter::wipeBusinessData();
    App\Helpers\SqlFile::run($sqlFile);
    assert_same(6, (int) Database::fetchValue('SELECT COUNT(*) FROM deliveries'));
    assert_same('2024-04-01', Database::fetchValue("SELECT po_date FROM purchase_orders WHERE code = 'PO-A000000001'"));
    assert_same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM migration_issues WHERE record_code IS NOT NULL AND record_id IS NULL'));
    @unlink($sqlFile);
    @unlink($fx['master']);
    @unlink($fx['legacy']);
});

test('workbook yang salah ditolak dengan pesan jelas', function () {
    $bad = sys_get_temp_dir() . '/pik-bad-' . getmypid() . '.xlsx';
    (new XlsxWriter())->addSheet('Sheet1', ['A'], [['x']])->save($bad);
    try {
        (new WorkbookImporter($bad))->build();
        fail('seharusnya gagal');
    } catch (RuntimeException $e) {
        assert_contains("Sheet 'CUSTOMERS' tidak ditemukan", $e->getMessage());
    }
    @unlink($bad);
    try {
        new XlsxReader(__FILE__);
        fail('file bukan xlsx seharusnya ditolak');
    } catch (RuntimeException $e) {
        assert_contains('bukan .xlsx', $e->getMessage());
    }
});

$real = getenv('PIK_REAL_WORKBOOK');
if (is_string($real) && is_file($real)) {
    group('Import · Workbook asli (PIK_REAL_WORKBOOK)');
    test('jumlah baris sama dengan workbook master', function () use ($real) {
        $legacy = array_values(array_filter(explode(';', (string) getenv('PIK_REAL_LEGACY'))));
        WorkbookImporter::wipeBusinessData();
        $counts = (new WorkbookImporter($real, $legacy))->build()->execute(null);
        $expected = ['customers' => 44, 'products' => 262, 'purchase_orders' => 365, 'po_lines' => 407, 'deliveries' => 1526,
            'returns' => 6, 'stock' => 54, 'leadtime' => 9, 'inbound_maklon' => 44, 'invoices_payments' => 92, 'po_financials' => 29];
        foreach ($expected as $table => $n) {
            assert_same($n, $counts[$table], $table);
        }
        assert_true($counts['migration_issues'] >= 614, 'issue workbook (614) ikut terimpor');
        assert_same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM migration_issues WHERE record_code IS NOT NULL AND record_id IS NULL'));
    });
}
