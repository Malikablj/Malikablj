<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Migrator;

group('Phase 0 · Migrasi skema database yang sudah berjalan');

/*
 * Mensimulasikan database produksi versi sebelumnya (skema 2026.10.1: tanpa role
 * Produksi, No. order VARCHAR(30), tanpa products.qty & tabel inbound_supplier)
 * lalu memastikan Migrator memperbarui strukturnya tanpa menghapus data.
 * Dijalankan paling awal karena mengubah struktur tabel (database test masih kosong).
 */
test('update dari skema 2026.10.1: role baru, No. order manual, qty produk dari OEF, tabel inbound_supplier', function () {
    $pdo = Database::connection();
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('Admin','Marketing','Sales','Management','PPIC','Viewer') NOT NULL DEFAULT 'Viewer'");
    $pdo->exec("ALTER TABLE purchase_orders MODIFY order_number VARCHAR(30) NULL");
    $pdo->exec('ALTER TABLE products DROP COLUMN qty');
    $pdo->exec('DROP TABLE inbound_supplier');
    Database::query("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '2026.10.1') ON DUPLICATE KEY UPDATE setting_value = '2026.10.1'");

    // data lama: produk dengan 2 OEF (qty terakhir = 750) + produk yang hanya ada di PO lama
    $cust = Database::insert('customers', ['code' => 'CUS-MIG0000001', 'name' => 'PT Migrasi Uji', 'status' => 'Active']);
    $p1 = Database::insert('products', ['code' => 'PRD-MIG0000001', 'name' => 'Botol Migrasi', 'unit' => 'pcs', 'capacity_per_day' => 5000]);
    $p2 = Database::insert('products', ['code' => 'PRD-MIG0000002', 'name' => 'Produk PO Lama', 'unit' => 'pcs']);
    $old = Database::insert('purchase_orders', ['code' => 'PO-MIG0000001', 'order_number' => 'OEF-2609-0001', 'customer_id' => $cust, 'po_date' => '2026-09-01', 'status' => 'Open', 'ppic_status' => 'Approved']);
    $new = Database::insert('purchase_orders', ['code' => 'PO-MIG0000002', 'order_number' => 'OEF-2610-0001', 'customer_id' => $cust, 'po_date' => '2026-10-01', 'status' => 'Open', 'ppic_status' => 'Pending']);
    $legacy = Database::insert('purchase_orders', ['code' => 'PO-MIG0000003', 'po_number' => 'PO/MIG/LAMA', 'customer_id' => $cust, 'po_date' => '2026-10-02', 'status' => 'Open']);
    Database::insert('po_lines', ['code' => 'POL-MIG000001', 'po_id' => $old, 'product_id' => $p1, 'order_qty' => 300]);
    Database::insert('po_lines', ['code' => 'POL-MIG000002', 'po_id' => $new, 'product_id' => $p1, 'order_qty' => 750]);
    Database::insert('po_lines', ['code' => 'POL-MIG000003', 'po_id' => $legacy, 'product_id' => $p2, 'order_qty' => 999]);

    $steps = Migrator::run();
    foreach (['users.role: PPIC, Produksi', 'purchase_orders: No. order diisi manual', 'products: kolom qty (arsip OEF)', 'products: qty diisi dari OEF terakhir', 'tabel inbound_supplier'] as $label) {
        assert_true(in_array($label, $steps, true), 'langkah migrasi dijalankan: ' . $label . ' (' . implode('; ', $steps) . ')');
    }
    $role = (string) Database::fetchValue("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
    assert_contains("'Produksi'", $role);
    assert_not_contains("'Gudang'", $role, 'role Gudang tidak dipakai lagi');
    assert_same(60, (int) Database::fetchValue("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'order_number'"));
    assert_same(750, (int) Database::fetchValue('SELECT qty FROM products WHERE id = :id', ['id' => $p1]), 'qty = OEF terakhir');
    assert_same(null, Database::fetchValue('SELECT qty FROM products WHERE id = :id', ['id' => $p2]), 'PO lama (tanpa PPIC) tidak dipakai');
    assert_same(5000, (int) Database::fetchValue('SELECT capacity_per_day FROM products WHERE id = :id', ['id' => $p1]), 'data kapasitas lama tidak dihapus');
    assert_true(Migrator::tableExists('inbound_supplier'));
    assert_same(Migrator::VERSION, Database::fetchValue("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'"));
    // aman dijalankan ulang
    assert_same([], Migrator::run(), 'tidak ada perubahan pada run kedua');
    // nomor order panjang & role baru bisa disimpan
    Database::update('purchase_orders', ['order_number' => str_repeat('A', 60)], 'id = :id', ['id' => $new]);
    $uid = create_user('Produksi', 'produksi.migrasi@pik.test');
    assert_same('Produksi', Database::fetchValue('SELECT role FROM users WHERE id = :id', ['id' => $uid]));

    // bersihkan agar test lain mulai dari data kosong
    Database::query("DELETE FROM po_lines WHERE code LIKE 'POL-MIG%'");
    Database::query("DELETE FROM purchase_orders WHERE code LIKE 'PO-MIG%'");
    Database::query("DELETE FROM products WHERE code LIKE 'PRD-MIG%'");
    Database::query("DELETE FROM customers WHERE code LIKE 'CUS-MIG%'");
    Database::query('DELETE FROM audit_logs');
    Database::query('DELETE FROM users WHERE id = :id', ['id' => $uid]);
});

test('update dari skema 2026.10.2: user role Gudang otomatis menjadi Produksi, role Gudang dihapus', function () {
    Database::query("ALTER TABLE users MODIFY role ENUM('Admin','Marketing','Sales','Management','PPIC','Produksi','Gudang','Viewer') NOT NULL DEFAULT 'Viewer'");
    Database::query("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '2026.10.2') ON DUPLICATE KEY UPDATE setting_value = '2026.10.2'");
    $g1 = create_user('Viewer', 'gudang1.migrasi@pik.test');
    $g2 = create_user('Viewer', 'gudang2.migrasi@pik.test');
    $other = create_user('PPIC', 'ppic.migrasi@pik.test');
    Database::query("UPDATE users SET role = 'Gudang' WHERE id IN ({$g1}, {$g2})");

    $steps = Migrator::run();
    assert_true(in_array('users.role: Gudang digabung ke Produksi', $steps, true), implode('; ', $steps));
    assert_false(in_array('users.role: PPIC, Produksi', $steps, true), 'role lain tidak diubah');
    foreach ([$g1, $g2] as $id) {
        assert_same('Produksi', Database::fetchValue('SELECT role FROM users WHERE id = :id', ['id' => $id]));
        assert_true((bool) Database::fetchValue("SELECT 1 FROM audit_logs WHERE action = 'update' AND entity_type = 'user' AND entity_id = :id AND changes LIKE '%Gudang%'", ['id' => $id]), 'tercatat di audit');
    }
    assert_same('PPIC', Database::fetchValue('SELECT role FROM users WHERE id = :id', ['id' => $other]));
    $role = (string) Database::fetchValue("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
    assert_not_contains("'Gudang'", $role);
    assert_same(Migrator::VERSION, Database::fetchValue("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'"));
    assert_same([], Migrator::run(), 'aman dijalankan ulang');

    Database::query("DELETE FROM users WHERE id IN ({$g1}, {$g2}, {$other})");
    Database::query('DELETE FROM audit_logs');
});

test('update dari skema 2026.10.3: order yang Closed otomatis padahal masih ada outstanding dibuka kembali', function () {
    Database::query('ALTER TABLE purchase_orders DROP COLUMN status_auto');
    Database::query("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '2026.10.3') ON DUPLICATE KEY UPDATE setting_value = '2026.10.3'");
    $cust = Database::insert('customers', ['code' => 'CUS-STA0000001', 'name' => 'PT Status Migrasi', 'status' => 'Active']);
    $prod = Database::insert('products', ['code' => 'PRD-STA0000001', 'name' => 'Botol Status Migrasi', 'unit' => 'pcs']);
    $mk = static function (string $code, string $ppic) use ($cust, $prod): array {
        $po = Database::insert('purchase_orders', ['code' => $code, 'order_number' => $code, 'customer_id' => $cust, 'po_date' => today(), 'status' => 'Closed', 'ppic_status' => $ppic]);
        $line = Database::insert('po_lines', ['code' => 'POL-' . substr($code, 3), 'po_id' => $po, 'product_id' => $prod, 'order_qty' => 1000]);
        Database::insert('deliveries', ['code' => 'DLV-' . substr($code, 3), 'po_id' => $po, 'po_line_id' => $line, 'delivery_date' => today(), 'delivered_qty' => 600, 'status' => 'Partial']);
        return [$po, $line];
    };
    $audit = static fn (int $po, string $action, string $old, string $new) => Database::insert('audit_logs', ['action' => $action, 'entity_type' => 'purchase_order', 'entity_id' => $po,
        'changes' => json_encode(['status' => ['old' => $old, 'new' => $new]])]);
    [$auto] = $mk('PO-STA0000001', 'Approved');      // Closed oleh sistem (delivery sempat Delivered penuh, lalu dikoreksi)
    $audit($auto, 'auto_status', 'On Process', 'Partial');
    $audit($auto, 'auto_status', 'Partial', 'Closed');
    [$manual] = $mk('PO-STA0000002', 'Approved');    // ditutup manual oleh user
    $audit($manual, 'auto_status', 'On Process', 'Partial');
    $audit($manual, 'update', 'Partial', 'Closed');
    [$legacy] = $mk('PO-STA0000003', 'Approved');    // data lama tanpa riwayat → dianggap manual

    $steps = Migrator::run();
    foreach (['purchase_orders: kolom status_auto', 'purchase_orders: order Closed otomatis dihitung ulang'] as $label) {
        assert_true(in_array($label, $steps, true), $label . ' (' . implode('; ', $steps) . ')');
    }
    $row = static fn (int $id): array => array_values(Database::fetch('SELECT status, status_auto FROM purchase_orders WHERE id = :id', ['id' => $id]));
    assert_same(['Partial', 1], $row($auto), 'Closed otomatis + outstanding 400 → Partial');
    assert_same(['Closed', 0], $row($manual), 'Closed manual tetap');
    assert_same(['Closed', 0], $row($legacy), 'tanpa riwayat → tetap');
    assert_same(Migrator::VERSION, Database::fetchValue("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'"));
    assert_same([], Migrator::run(), 'aman dijalankan ulang');

    Database::query("DELETE FROM deliveries WHERE code LIKE 'DLV-STA%'");
    Database::query("DELETE FROM po_lines WHERE code LIKE 'POL-STA%'");
    Database::query("DELETE FROM purchase_orders WHERE code LIKE 'PO-STA%'");
    Database::delete('products', 'id = :id', ['id' => $prod]);
    Database::delete('customers', 'id = :id', ['id' => $cust]);
    Database::query('DELETE FROM audit_logs');
});
