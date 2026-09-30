<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Models\Customer;

$miSetup = static function (): array {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $cust = Customer::create(['name' => 'PT Migrasi Uji', 'status' => 'Active']);
    $prod = Database::insert('products', ['code' => 'PRD-MIG0000001', 'name' => 'Botol Migrasi 100ml', 'unit' => 'pcs']);
    $prod2 = Database::insert('products', ['code' => 'PRD-MIG0000002', 'name' => 'Cap Migrasi', 'unit' => 'pcs']);
    $po = Database::insert('purchase_orders', ['code' => 'PO-MIG0000001', 'po_number' => 'PO/MIG/001', 'customer_id' => $cust, 'po_date' => '2025-05-08', 'status' => 'Open']);
    $line = Database::insert('po_lines', ['code' => 'POL-MIG000001', 'po_id' => $po, 'product_id' => $prod, 'order_qty' => 1000]);
    $po2 = Database::insert('purchase_orders', ['code' => 'PO-MIG0000002', 'po_number' => 'PO/MIG/002', 'customer_id' => $cust, 'po_date' => '2025-06-01', 'status' => 'Open']);
    Database::insert('po_lines', ['code' => 'POL-MIG000002', 'po_id' => $po2, 'product_id' => $prod, 'order_qty' => 100]);
    Database::insert('po_lines', ['code' => 'POL-MIG000003', 'po_id' => $po2, 'product_id' => $prod2, 'order_qty' => 100]);
    $d1 = Database::insert('deliveries', ['code' => 'DEL-MIG000001', 'po_id' => $po, 'sj_number' => 'SJ-MIG-001', 'delivery_date' => '2025-06-10', 'delivered_qty' => 400, 'status' => 'Delivered', 'migration_flag' => 'PRODUCT/LINE NOT MATCHED']);
    $d2 = Database::insert('deliveries', ['code' => 'DEL-MIG000002', 'po_id' => $po2, 'sj_number' => 'SJ-MIG-002', 'delivery_date' => '2025-06-11', 'delivered_qty' => 50, 'status' => 'Delivered', 'migration_flag' => 'PRODUCT/LINE NOT MATCHED']);
    $issue = static fn (string $code, array $data) => Database::insert('migration_issues', ['code' => $code, 'description' => 'uji'] + $data);
    return $ids = [
        'po' => $po, 'line' => $line, 'po2' => $po2, 'd1' => $d1, 'd2' => $d2,
        'date' => $issue('ISS-MIG0000001', ['table_name' => 'PURCHASE_ORDERS', 'record_code' => 'PO-MIG0000001', 'record_id' => $po, 'issue_type' => 'DATE DIFFERS FROM LEGACY',
            'field_name' => 'po_date', 'master_value' => '2025-05-08', 'suggested_value' => '2025-08-05']),
        'bad'  => $issue('ISS-MIG0000002', ['table_name' => 'PURCHASE_ORDERS', 'record_code' => 'PO-MIG0000001', 'record_id' => $po, 'issue_type' => 'DATE DIFFERS FROM LEGACY',
            'field_name' => 'po_date', 'master_value' => '2025-05-08', 'suggested_value' => '31/31/2025']),
        'status' => $issue('ISS-MIG0000003', ['table_name' => 'PURCHASE_ORDERS', 'record_code' => 'PO-MIG0000001', 'record_id' => $po, 'issue_type' => 'STATUS MAPPED',
            'field_name' => 'status', 'master_value' => 'Hold', 'suggested_value' => 'Open']),
        'd1issue' => $issue('ISS-MIG0000004', ['table_name' => 'DELIVERIES', 'record_code' => 'DEL-MIG000001', 'record_id' => $d1, 'issue_type' => 'PRODUCT/LINE NOT MATCHED', 'legacy_product' => 'Botol Migrasi 100 ml Bening']),
        'd2issue' => $issue('ISS-MIG0000005', ['table_name' => 'DELIVERIES', 'record_code' => 'DEL-MIG000002', 'record_id' => $d2, 'issue_type' => 'PRODUCT/LINE NOT MATCHED', 'legacy_product' => 'Cap Migrasi Hitam']),
        'dup1' => $issue('ISS-MIG0000006', ['table_name' => 'CUSTOMERS', 'record_code' => 'CUS-X1', 'issue_type' => 'POSSIBLE DUPLICATE CUSTOMER']),
        'dup2' => $issue('ISS-MIG0000007', ['table_name' => 'CUSTOMERS', 'record_code' => 'CUS-X2', 'issue_type' => 'POSSIBLE DUPLICATE CUSTOMER']),
    ];
};

group('Phase 9 · Migration Issues');

test('daftar & detail issue: hanya Admin, filter, link ke record asal', function () use ($miSetup) {
    $ids = $miSetup();
    foreach (['Marketing', 'Management', 'Viewer'] as $role) {
        assert_status(403, client_as($role)->get('/migration-issues'), $role);
    }
    $c = client_as('Admin');
    $list = $c->get('/migration-issues', ['table' => 'PURCHASE_ORDERS', 'type' => 'DATE DIFFERS FROM LEGACY']);
    assert_status(200, $list);
    assert_contains('PO-MIG0000001', $list->body);
    assert_not_contains('POSSIBLE DUPLICATE CUSTOMER</a>', $list->body, 'filter jenis');
    $detail = $c->get('/migration-issues/' . $ids['date']);
    assert_status(200, $detail);
    assert_contains('/purchase-orders/' . $ids['po'], $detail->body, 'link ke PO');
    assert_contains('Pakai nilai spreadsheet legacy', $detail->body);
    assert_not_contains('Pakai nilai master', $c->get('/migration-issues/' . $ids['dup1'])->body, 'issue tanpa kolom tidak punya pilihan nilai');
});

test('pakai nilai legacy: kolom record diubah, issue selesai, tercatat di audit; nilai tidak valid ditolak', function () use ($miSetup) {
    $ids = $miSetup();
    $c = client_as('Admin');
    assert_redirect($c->post('/migration-issues/' . $ids['date'] . '/apply', ['which' => 'suggested', 'note' => 'cek dokumen PO']), '/migration-issues/' . $ids['date']);
    assert_same('2025-08-05', Database::fetchValue('SELECT po_date FROM purchase_orders WHERE id = :id', ['id' => $ids['po']]));
    $issue = Database::fetch('SELECT * FROM migration_issues WHERE id = :id', ['id' => $ids['date']]);
    assert_same('Resolved', $issue['resolution_status']);
    assert_contains('legacy', (string) $issue['resolution_note']);
    assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'update' AND entity_type = 'purchase_order' AND entity_id = :id AND changes LIKE '%2025-08-05%'", ['id' => $ids['po']]));
    // nilai legacy bukan tanggal → ditolak, data tidak berubah
    $c->post('/migration-issues/' . $ids['bad'] . '/apply', ['which' => 'suggested']);
    assert_contains('bukan tanggal yang valid', $c->get('/migration-issues/' . $ids['bad'])->body);
    assert_same('2025-08-05', Database::fetchValue('SELECT po_date FROM purchase_orders WHERE id = :id', ['id' => $ids['po']]));
    // kolom di luar daftar yang diizinkan (status) tidak bisa diubah lewat issue
    $c->post('/migration-issues/' . $ids['status'] . '/apply', ['which' => 'master']);
    assert_same('Open', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $ids['po']]));
    assert_same('Needs Review', Database::fetchValue('SELECT resolution_status FROM migration_issues WHERE id = :id', ['id' => $ids['status']]));
    assert_status(403, client_as('Viewer')->post('/migration-issues/' . $ids['bad'] . '/apply', ['which' => 'master']));
});

test('status manual: selesai / abaikan / buka kembali, dan aksi massal', function () use ($miSetup) {
    $ids = $miSetup();
    $c = client_as('Admin');
    assert_redirect($c->post('/migration-issues/' . $ids['status'] . '/status', ['status' => 'Resolved', 'note' => 'Hold memang dipetakan Open']), '/migration-issues/' . $ids['status']);
    assert_same('Resolved', Database::fetchValue('SELECT resolution_status FROM migration_issues WHERE id = :id', ['id' => $ids['status']]));
    $c->post('/migration-issues/' . $ids['status'] . '/status', ['status' => 'Needs Review']);
    $row = Database::fetch('SELECT * FROM migration_issues WHERE id = :id', ['id' => $ids['status']]);
    assert_same('Needs Review', $row['resolution_status']);
    assert_same(null, $row['resolved_at']);
    $c->post('/migration-issues/' . $ids['status'] . '/status', ['status' => 'Deleted']);
    assert_same('Needs Review', Database::fetchValue('SELECT resolution_status FROM migration_issues WHERE id = :id', ['id' => $ids['status']]), 'status tidak dikenal ditolak');
    assert_redirect($c->post('/migration-issues/bulk', ['ids' => [$ids['dup1'], $ids['dup2']], 'status' => 'Ignored', 'note' => 'bukan duplikat', 'return' => '/migration-issues']), '/migration-issues');
    assert_same(2, (int) Database::fetchValue("SELECT COUNT(*) FROM migration_issues WHERE id IN (:a, :b) AND resolution_status = 'Ignored'", ['a' => $ids['dup1'], 'b' => $ids['dup2']]));
    assert_true((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'ignore_issue'") >= 2);
});

test('tinjau delivery legacy: hanya PO satu baris, hanya yang dicentang, outstanding & issue ikut diperbarui', function () use ($miSetup) {
    $ids = $miSetup();
    $c = client_as('Admin');
    $page = $c->get('/migration-issues/deliveries');
    assert_status(200, $page);
    assert_contains('SJ-MIG-001', $page->body);
    assert_contains('Botol Migrasi 100 ml Bening', $page->body, 'nama produk spreadsheet ditampilkan untuk dibandingkan');
    assert_not_contains('SJ-MIG-002', $page->body, 'PO dengan dua baris tidak ditawarkan');
    $none = $c->post('/migration-issues/deliveries', []);
    assert_redirect($none, '/migration-issues/deliveries');
    assert_same(null, Database::fetchValue('SELECT po_line_id FROM deliveries WHERE id = :id', ['id' => $ids['d1']]), 'tanpa centang tidak ada yang dihubungkan');
    assert_redirect($c->post('/migration-issues/deliveries', ['delivery_ids' => [$ids['d1'], $ids['d2']]]), '/migration-issues/deliveries');
    $d1 = Database::fetch('SELECT * FROM deliveries WHERE id = :id', ['id' => $ids['d1']]);
    assert_same($ids['line'], (int) $d1['po_line_id']);
    assert_same(null, $d1['migration_flag']);
    assert_same(null, Database::fetchValue('SELECT po_line_id FROM deliveries WHERE id = :id', ['id' => $ids['d2']]), 'PO dua baris dilewati walau dikirim manual');
    assert_same('Resolved', Database::fetchValue('SELECT resolution_status FROM migration_issues WHERE id = :id', ['id' => $ids['d1issue']]));
    assert_same('Needs Review', Database::fetchValue('SELECT resolution_status FROM migration_issues WHERE id = :id', ['id' => $ids['d2issue']]));
    assert_same(['order_qty' => 1000, 'delivered_qty' => 400, 'return_qty' => 0, 'outstanding_qty' => 600], App\Models\PoLine::totals($ids['line']));
    assert_same('Partial', Database::fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $ids['po']]), 'status PO disinkronkan');
    assert_status(403, client_as('Management')->get('/migration-issues/deliveries'));
});
