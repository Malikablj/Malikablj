<?php

declare(strict_types=1);

use App\Helpers\Database;
use App\Helpers\Migrator;

group('Phase 0 · Migrasi skema database yang sudah berjalan');

/*
 * Mensimulasikan database produksi versi sebelumnya (skema 2026.10.1: tanpa role
 * Produksi/Gudang, No. order VARCHAR(30), tanpa products.qty & tabel inbound_supplier)
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
    foreach (['users.role: PPIC, Produksi, Gudang', 'purchase_orders: No. order diisi manual', 'products: kolom qty (arsip OEF)', 'products: qty diisi dari OEF terakhir', 'tabel inbound_supplier'] as $label) {
        assert_true(in_array($label, $steps, true), 'langkah migrasi dijalankan: ' . $label . ' (' . implode('; ', $steps) . ')');
    }
    $role = (string) Database::fetchValue("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
    assert_contains("'Produksi'", $role);
    assert_contains("'Gudang'", $role);
    assert_same(60, (int) Database::fetchValue("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'order_number'"));
    assert_same(750, (int) Database::fetchValue('SELECT qty FROM products WHERE id = :id', ['id' => $p1]), 'qty = OEF terakhir');
    assert_same(null, Database::fetchValue('SELECT qty FROM products WHERE id = :id', ['id' => $p2]), 'PO lama (tanpa PPIC) tidak dipakai');
    assert_same(5000, (int) Database::fetchValue('SELECT capacity_per_day FROM products WHERE id = :id', ['id' => $p1]), 'data kapasitas lama tidak dihapus');
    assert_true(Migrator::tableExists('inbound_supplier'));
    assert_same('2026.10.2', Database::fetchValue("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'"));
    // aman dijalankan ulang
    assert_same([], Migrator::run(), 'tidak ada perubahan pada run kedua');
    // nomor order panjang & role baru bisa disimpan
    Database::update('purchase_orders', ['order_number' => str_repeat('A', 60)], 'id = :id', ['id' => $new]);
    $uid = create_user('Gudang', 'gudang.migrasi@pik.test');
    assert_same('Gudang', Database::fetchValue('SELECT role FROM users WHERE id = :id', ['id' => $uid]));

    // bersihkan agar test lain mulai dari data kosong
    Database::query("DELETE FROM po_lines WHERE code LIKE 'POL-MIG%'");
    Database::query("DELETE FROM purchase_orders WHERE code LIKE 'PO-MIG%'");
    Database::query("DELETE FROM products WHERE code LIKE 'PRD-MIG%'");
    Database::query("DELETE FROM customers WHERE code LIKE 'CUS-MIG%'");
    Database::query('DELETE FROM audit_logs');
    Database::query('DELETE FROM users WHERE id = :id', ['id' => $uid]);
});
