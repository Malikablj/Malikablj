<?php

declare(strict_types=1);

use App\Helpers\Schema;

/*
 * Order Entry Form (OEF) + review PPIC, role baru (PPIC, Produksi, Gudang,
 * Purchasing), Retur & Komplain (bukti, email QC, penyelesaian), dan
 * Inbound Supplier.
 *
 * HANYA MENAMBAH: nilai ENUM role baru, kolom baru, tabel baru, dan index.
 * Tidak ada tabel, kolom, atau data yang dihapus. Tabel keuangan
 * (invoices_payments, po_financials) tetap ada beserta isinya walaupun
 * menunya sudah tidak ditampilkan. Setiap langkah dicek dulu sehingga aman
 * dijalankan ulang.
 */
return static function (): void {
    // 1. Role baru (nilai ENUM ditambahkan di belakang; role lama tidak berubah)
    if (!str_contains((string) Schema::columnType('users', 'role'), "'Purchasing'")) {
        Schema::modifyColumn('users', 'role', "ENUM('Admin','Marketing','Sales','Management','Viewer','PPIC','Produksi','Gudang','Purchasing') NOT NULL DEFAULT 'Viewer'");
    }

    // 2. OEF memakai tabel purchase_orders (No PO customer tetap di po_number)
    Schema::addColumn('purchase_orders', 'order_number', "VARCHAR(60) NULL COMMENT 'No order OEF (diisi manual, unik)' AFTER code");
    Schema::addColumn('purchase_orders', 'sales_name', "VARCHAR(120) NULL COMMENT 'Nama sales' AFTER customer_id");
    // Default Approved: PO yang sudah ada & PO dari import sudah berjalan sebelum review PPIC
    // diberlakukan. OEF baru dari form disimpan eksplisit sebagai Pending (menunggu PPIC).
    Schema::addColumn('purchase_orders', 'review_status', "ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Approved' COMMENT 'Review PPIC; OEF baru = Pending' AFTER status");
    Schema::addColumn('purchase_orders', 'reviewed_by', 'INT UNSIGNED NULL AFTER review_status');
    Schema::addColumn('purchase_orders', 'reviewed_at', 'DATETIME NULL AFTER reviewed_by');
    Schema::addColumn('purchase_orders', 'review_note', "TEXT NULL COMMENT 'Alasan tidak bisa diproses / catatan PPIC' AFTER reviewed_at");
    Schema::addIndex('purchase_orders', 'uq_po_order_number', ['order_number'], true);
    Schema::addIndex('purchase_orders', 'idx_po_review', ['review_status']);
    Schema::addForeignKey('purchase_orders', 'fk_po_reviewed_by', 'reviewed_by', 'users');

    Schema::addColumn('po_lines', 'subcont_supplier', "VARCHAR(150) NULL COMMENT 'Supplier bila produk dikerjakan subcont' AFTER item_description");

    // 3. Jadwal delivery yang dibuat otomatis saat OEF disetujui PPIC
    Schema::addColumn('deliveries', 'schedule_source', "VARCHAR(20) NULL COMMENT 'OEF = dijadwalkan otomatis dari OEF' AFTER status");

    // 4. Retur & komplain
    $returns = [
        'case_type'         => "ENUM('Retur','Komplain') NOT NULL DEFAULT 'Retur' COMMENT 'Retur = barang kembali (menambah outstanding); Komplain = tanpa barang kembali' AFTER code",
        'affected_qty'      => "INT NULL COMMENT 'Qty bermasalah untuk komplain (tidak mengubah outstanding)' AFTER return_qty",
        'qc_email'          => "VARCHAR(500) NULL COMMENT 'Email QC penerima hasil komplain (dipisah koma)' AFTER note",
        'resolution_status' => "ENUM('Open','Selesai','Tidak selesai') NOT NULL DEFAULT 'Open' AFTER qc_email",
        'resolution_note'   => "TEXT NULL COMMENT 'Catatan penyelesaian / alasan tidak selesai' AFTER resolution_status",
        'resolved_by'       => 'INT UNSIGNED NULL AFTER resolution_note',
        'resolved_at'       => 'DATETIME NULL AFTER resolved_by',
    ];
    foreach ($returns as $column => $definition) {
        Schema::addColumn('returns', $column, $definition);
    }
    Schema::addIndex('returns', 'idx_returns_resolution', ['resolution_status']);
    Schema::addForeignKey('returns', 'fk_returns_resolved_by', 'resolved_by', 'users');

    Schema::createTable("CREATE TABLE IF NOT EXISTS return_attachments (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        return_id      INT UNSIGNED NOT NULL,
        original_name  VARCHAR(255) NOT NULL,
        stored_name    VARCHAR(120) NOT NULL COMMENT 'Path relatif di storage/uploads (di luar folder public)',
        mime_type      VARCHAR(60)  NOT NULL,
        file_size      INT UNSIGNED NOT NULL,
        uploaded_by    INT UNSIGNED NULL,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_return_att_file (stored_name),
        KEY idx_return_att_return (return_id),
        CONSTRAINT fk_return_att_return FOREIGN KEY (return_id) REFERENCES returns (id) ON DELETE CASCADE,
        CONSTRAINT fk_return_att_user   FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    Schema::createTable("CREATE TABLE IF NOT EXISTS email_logs (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        entity_type    VARCHAR(40)  NULL,
        entity_id      INT UNSIGNED NULL,
        recipients     VARCHAR(500) NOT NULL,
        subject        VARCHAR(255) NOT NULL,
        driver         VARCHAR(20)  NOT NULL COMMENT 'smtp / mail / log',
        status         ENUM('SENT','LOGGED','FAILED') NOT NULL COMMENT 'LOGGED = hanya ditulis ke log (MAIL_DRIVER=log)',
        error_message  VARCHAR(500) NULL,
        sent_by        INT UNSIGNED NULL,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_email_logs_entity (entity_type, entity_id),
        CONSTRAINT fk_email_logs_user FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5. Inbound Supplier (diinput Purchasing)
    Schema::createTable("CREATE TABLE IF NOT EXISTS inbound_supplier (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code             VARCHAR(20)  NOT NULL,
        supplier         VARCHAR(150) NOT NULL,
        receive_date     DATE         NOT NULL,
        purchase_number  VARCHAR(80)  NULL COMMENT 'No PO pembelian dari Purchasing',
        sj_number        VARCHAR(80)  NULL COMMENT 'No surat jalan supplier',
        item_name        VARCHAR(255) NOT NULL,
        specification    TEXT         NULL,
        quantity         INT          NOT NULL,
        unit             VARCHAR(20)  NOT NULL DEFAULT 'pcs',
        reject_qty       INT          NOT NULL DEFAULT 0,
        receiver         VARCHAR(120) NULL,
        notes            TEXT         NULL,
        created_by       INT UNSIGNED NULL,
        updated_by       INT UNSIGNED NULL,
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_inbound_supplier_code (code),
        KEY idx_inbound_supplier_date (receive_date),
        KEY idx_inbound_supplier_supplier (supplier),
        CONSTRAINT fk_inbound_supplier_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_inbound_supplier_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
