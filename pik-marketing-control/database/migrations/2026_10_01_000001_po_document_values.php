<?php

declare(strict_types=1);

use App\Helpers\Schema;

/*
 * Nilai dokumen PO + log import (untuk PIK_PO_DATABASE_*.xlsx).
 *
 * HANYA MENAMBAH: tabel import_logs, kolom baru di purchase_orders, po_lines,
 * customers dan migration_issues, serta index. Satu-satunya MODIFY adalah
 * memperlebar purchase_orders.payment_term (60 -> 255 karakter); tidak ada data
 * yang dihapus atau diubah. Setiap langkah dicek dulu sehingga aman diulang.
 */
return static function (): void {
    Schema::createTable("CREATE TABLE IF NOT EXISTS import_logs (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        import_type    VARCHAR(40)  NOT NULL COMMENT 'mis. PO_DATABASE',
        mode           ENUM('DRY_RUN','IMPORT') NOT NULL,
        filename       VARCHAR(255) NOT NULL,
        file_sha256    CHAR(64)     NOT NULL,
        status         ENUM('RUNNING','COMPLETED','COMPLETED_WITH_WARNING','FAILED','ROLLED_BACK') NOT NULL DEFAULT 'RUNNING',
        started_at     DATETIME     NOT NULL,
        completed_at   DATETIME     NULL,
        total_rows     INT UNSIGNED NOT NULL DEFAULT 0,
        inserted_rows  INT UNSIGNED NOT NULL DEFAULT 0,
        updated_rows   INT UNSIGNED NOT NULL DEFAULT 0,
        skipped_rows   INT UNSIGNED NOT NULL DEFAULT 0,
        failed_rows    INT UNSIGNED NOT NULL DEFAULT 0,
        warning_count  INT UNSIGNED NOT NULL DEFAULT 0,
        error_message  TEXT         NULL,
        summary        LONGTEXT     NULL COMMENT 'JSON: ringkasan, rekonsiliasi, daftar temuan',
        backup_file    VARCHAR(255) NULL,
        created_by     INT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY idx_import_logs_type (import_type, started_at),
        KEY idx_import_logs_file (file_sha256, mode, status),
        CONSTRAINT fk_import_logs_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Termin pembayaran di dokumen bisa lebih dari 60 karakter
    if ((Schema::columnLength('purchase_orders', 'payment_term') ?? 0) < 255) {
        Schema::modifyColumn('purchase_orders', 'payment_term', 'VARCHAR(255) NULL');
    }

    $po = [
        'payment_term_code'       => "VARCHAR(60) NULL COMMENT 'Termin standar, mis. NET_30, CBD' AFTER payment_term",
        'currency'                => "CHAR(3) NULL COMMENT 'Mata uang dokumen PO' AFTER payment_term_code",
        'price_includes_tax'      => "TINYINT(1) NULL COMMENT '1 = harga satuan sudah termasuk PPN' AFTER currency",
        'subtotal'                => "DECIMAL(18,2) NULL COMMENT 'DPP (sebelum PPN)' AFTER price_includes_tax",
        'discount_amount'         => 'DECIMAL(18,2) NULL AFTER subtotal',
        'tax_amount'              => "DECIMAL(18,2) NULL COMMENT 'PPN' AFTER discount_amount",
        'shipping_cost'           => 'DECIMAL(18,2) NULL AFTER tax_amount',
        'grand_total'             => "DECIMAL(18,2) NULL COMMENT 'subtotal - diskon + PPN + ongkir' AFTER shipping_cost",
        'requested_delivery_date' => "DATE NULL COMMENT 'Tanggal kirim yang diminta di dokumen PO' AFTER grand_total",
        'delivery_address'        => 'TEXT NULL AFTER requested_delivery_date',
        'contact_person'          => 'VARCHAR(150) NULL AFTER delivery_address',
        'doc_type'                => "VARCHAR(40) NULL COMMENT 'PO / PURCHASE INVOICE' AFTER contact_person",
        'doc_url'                 => "VARCHAR(500) NULL COMMENT 'Link dokumen PO asli' AFTER doc_type",
        'data_confidence'         => "VARCHAR(10) NULL COMMENT 'HIGH/MEDIUM/LOW hasil digitalisasi' AFTER doc_url",
        'import_status'           => "ENUM('OK','NEEDS_REVIEW') NULL AFTER data_confidence",
        'import_ref'              => "VARCHAR(40) NULL COMMENT 'ID record di file import, mis. PO-000001' AFTER import_status",
        'import_log_id'           => 'INT UNSIGNED NULL AFTER import_ref',
        'import_snapshot'         => "TEXT NULL COMMENT 'JSON nilai yang ditulis import terakhir (deteksi perubahan manual)' AFTER import_log_id",
    ];
    foreach ($po as $column => $definition) {
        Schema::addColumn('purchase_orders', $column, $definition);
    }
    Schema::addIndex('purchase_orders', 'idx_po_import_ref', ['import_ref']);
    Schema::addIndex('purchase_orders', 'idx_po_import_status', ['import_status']);
    Schema::addForeignKey('purchase_orders', 'fk_po_import_log', 'import_log_id', 'import_logs');

    $lines = [
        'line_no'                 => 'SMALLINT UNSIGNED NULL AFTER product_id',
        'item_code'               => "VARCHAR(80) NULL COMMENT 'Kode item di dokumen PO' AFTER line_no",
        'item_description'        => 'TEXT NULL AFTER item_code',
        'unit'                    => "VARCHAR(20) NULL COMMENT 'Satuan seperti tertulis di PO' AFTER order_qty",
        'unit_price'              => 'DECIMAL(18,2) NULL AFTER unit',
        'line_subtotal'           => "DECIMAL(18,2) NULL COMMENT 'DPP baris (sebelum PPN)' AFTER unit_price",
        'tax_rate'                => "DECIMAL(6,4) NULL COMMENT '0.11 = 11%' AFTER line_subtotal",
        'requested_delivery_date' => 'DATE NULL AFTER tax_rate',
        'data_confidence'         => 'VARCHAR(10) NULL AFTER requested_delivery_date',
        'import_ref'              => "VARCHAR(40) NULL COMMENT 'ID item di file import, mis. ITEM-000001' AFTER data_confidence",
        'import_snapshot'         => 'TEXT NULL AFTER import_ref',
    ];
    foreach ($lines as $column => $definition) {
        Schema::addColumn('po_lines', $column, $definition);
    }
    Schema::addIndex('po_lines', 'idx_po_lines_import_ref', ['import_ref']);

    Schema::addColumn('customers', 'customer_code', "VARCHAR(20) NULL COMMENT 'Kode singkat di nomor PO, mis. BDD, SIT' AFTER code");
    Schema::addIndex('customers', 'idx_customers_customer_code', ['customer_code']);

    Schema::addColumn('migration_issues', 'import_log_id', 'INT UNSIGNED NULL AFTER legacy_row');
    Schema::addIndex('migration_issues', 'idx_mi_import_log', ['import_log_id']);
    Schema::addForeignKey('migration_issues', 'fk_mi_import_log', 'import_log_id', 'import_logs');
};
