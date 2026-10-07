<?php

declare(strict_types=1);

namespace App\Helpers;

use Throwable;

/**
 * Migrasi skema otomatis untuk database yang sudah berjalan.
 *
 * schema.sql memakai CREATE TABLE IF NOT EXISTS sehingga kolom baru tidak
 * otomatis ditambahkan ke tabel lama. Migrator memeriksa INFORMATION_SCHEMA dan
 * hanya menambahkan kolom/tabel/index yang belum ada (aman dijalankan berulang).
 *
 * Dijalankan:
 *   - otomatis saat aplikasi dibuka (sekali, bila versi skema di settings lebih lama)
 *   - manual lewat CLI: php database/migrate.php
 */
final class Migrator
{
    /** Naikkan setiap kali ada migrasi baru. */
    public const VERSION = '2026.10.2';
    private const SETTING_KEY = 'schema_version';

    /** true bila kolom complaint baru saja ditambahkan pada run ini (data lama perlu disesuaikan). */
    private static bool $complaintColumnsAdded = false;

    /** true bila kolom products.qty baru saja ditambahkan pada run ini (diisi dari OEF terakhir). */
    private static bool $productQtyAdded = false;

    /** Struktur tabel inbound_supplier (sama dengan database/schema.sql). */
    private const INBOUND_SUPPLIER_DDL = "CREATE TABLE IF NOT EXISTS inbound_supplier (
          id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          code           VARCHAR(20)   NOT NULL,
          supplier       VARCHAR(150)  NOT NULL,
          inbound_date   DATE          NOT NULL COMMENT 'Tanggal barang diterima di gudang',
          sj_number      VARCHAR(60)   NULL COMMENT 'No. surat jalan supplier',
          sj_date        DATE          NULL,
          po_reference   VARCHAR(80)   NULL COMMENT 'No. PO pembelian ke supplier',
          item_name      VARCHAR(255)  NOT NULL,
          item_code      VARCHAR(60)   NULL,
          category       VARCHAR(80)   NULL COMMENT 'Jenis barang, mis. bahan baku, kemasan',
          unit           VARCHAR(20)   NOT NULL DEFAULT 'pcs',
          quantity       DECIMAL(15,2) NOT NULL COMMENT 'Qty diterima',
          reject_qty     DECIMAL(15,2) NULL,
          total_in       DECIMAL(15,2) NULL COMMENT 'Qty diterima - reject',
          receiver       VARCHAR(120)  NULL COMMENT 'Penerima / petugas gudang',
          location       VARCHAR(120)  NULL COMMENT 'Lokasi simpan di gudang',
          attachment     VARCHAR(500)  NULL,
          notes          TEXT          NULL,
          created_by     INT UNSIGNED  NULL,
          updated_by     INT UNSIGNED  NULL,
          created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at     DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_inbound_supplier_code (code),
          KEY idx_inbound_supplier_date (inbound_date),
          KEY idx_inbound_supplier_supplier (supplier),
          KEY idx_inbound_supplier_item (item_name),
          CONSTRAINT fk_inbound_supplier_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
          CONSTRAINT fk_inbound_supplier_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /** Jalankan bila perlu (dipanggil di setiap request; cepat bila sudah terbaru). */
    public static function ensure(): void
    {
        $current = (string) Database::fetchValue('SELECT setting_value FROM settings WHERE setting_key = :k', ['k' => self::SETTING_KEY]);
        if ($current === self::VERSION) {
            return;
        }
        $lock = (int) Database::fetchValue("SELECT GET_LOCK('pik_schema_migration', 20)");
        if ($lock !== 1) {
            return;
        }
        try {
            $current = (string) Database::fetchValue('SELECT setting_value FROM settings WHERE setting_key = :k', ['k' => self::SETTING_KEY]);
            if ($current !== self::VERSION) {
                self::run();
            }
        } finally {
            Database::fetchValue("SELECT RELEASE_LOCK('pik_schema_migration')");
        }
    }

    /** @return list<string> langkah yang dijalankan */
    public static function run(): array
    {
        $done = [];
        foreach (self::steps() as $label => $step) {
            try {
                if ($step()) {
                    $done[] = $label;
                }
            } catch (Throwable $e) {
                Logger::error('Migrasi gagal pada langkah "' . $label . '": ' . $e->getMessage());
                throw $e;
            }
        }
        Database::query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['k' => self::SETTING_KEY, 'v' => self::VERSION]
        );
        if ($done !== []) {
            Logger::info('Migrasi skema ' . self::VERSION . ': ' . implode('; ', $done));
        }
        return $done;
    }

    /** @return array<string,callable():bool> label => langkah (true bila ada perubahan) */
    private static function steps(): array
    {
        return [
            // ---------------------------------------------------------- role PPIC, Produksi, Gudang
            'users.role: PPIC, Produksi, Gudang' => static function (): bool {
                $type = (string) Database::fetchValue(
                    "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
                );
                $missing = array_filter(Permission::ROLES, static fn (string $role): bool => !str_contains($type, "'" . $role . "'"));
                if ($missing === []) {
                    return false;
                }
                Database::query("ALTER TABLE users MODIFY role ENUM('" . implode("','", Permission::ROLES) . "') NOT NULL DEFAULT 'Viewer'");
                return true;
            },

            // ---------------------------------------------------------- Order Entry Form
            'purchase_orders: kolom OEF' => static function (): bool {
                return self::addColumns('purchase_orders', [
                    'order_number'         => "VARCHAR(30) NULL COMMENT 'No. Order Entry Form (OEF-YYMM-NNNN)' AFTER code",
                    'sales_name'           => 'VARCHAR(120) NULL AFTER customer_id',
                    'sales_user_id'        => 'INT UNSIGNED NULL AFTER sales_name',
                    'product_spec'         => 'TEXT NULL AFTER payment_term',
                    'is_subcont'           => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER product_spec',
                    'supplier'             => 'VARCHAR(190) NULL AFTER is_subcont',
                    'requested_date'       => "DATE NULL COMMENT 'Permintaan selesai / kirim' AFTER supplier",
                    'ship_to'              => "VARCHAR(255) NULL COMMENT 'Tujuan kirim' AFTER requested_date",
                    'ppic_status'          => "ENUM('Pending','Approved','Rejected') NULL COMMENT 'NULL = data lama (tanpa konfirmasi PPIC)' AFTER status",
                    'ppic_note'            => 'TEXT NULL AFTER ppic_status',
                    'ppic_by'              => 'INT UNSIGNED NULL AFTER ppic_note',
                    'ppic_at'              => 'DATETIME NULL AFTER ppic_by',
                    'schedule_delivery_id' => "INT UNSIGNED NULL COMMENT 'Jadwal delivery otomatis dari OEF' AFTER ppic_at",
                ]);
            },
            'purchase_orders: index & relasi OEF' => static function (): bool {
                $changed = self::addIndex('purchase_orders', 'uq_po_order_number', 'UNIQUE KEY uq_po_order_number (order_number)');
                $changed = self::addIndex('purchase_orders', 'idx_po_ppic', 'KEY idx_po_ppic (ppic_status)') || $changed;
                $changed = self::addForeignKey('purchase_orders', 'fk_po_sales', 'FOREIGN KEY (sales_user_id) REFERENCES users (id) ON DELETE SET NULL') || $changed;
                $changed = self::addForeignKey('purchase_orders', 'fk_po_ppic_by', 'FOREIGN KEY (ppic_by) REFERENCES users (id) ON DELETE SET NULL') || $changed;
                $changed = self::addForeignKey('purchase_orders', 'fk_po_schedule', 'FOREIGN KEY (schedule_delivery_id) REFERENCES deliveries (id) ON DELETE SET NULL') || $changed;
                return $changed;
            },
            'products: penanda sumber OEF' => static function (): bool {
                return self::addColumns('products', [
                    'spec' => "TEXT NULL COMMENT 'Spesifikasi terakhir dari OEF' AFTER variant",
                ]);
            },
            'purchase_orders: No. order diisi manual' => static function (): bool {
                $length = (int) Database::fetchValue(
                    "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'order_number'"
                );
                if ($length >= 60) {
                    return false;
                }
                Database::query("ALTER TABLE purchase_orders MODIFY order_number VARCHAR(60) NULL COMMENT 'No. order Order Entry Form (diisi manual, unik)'");
                return true;
            },
            'products: kolom qty (arsip OEF)' => static function (): bool {
                self::$productQtyAdded = !self::columnExists('products', 'qty');
                return self::addColumns('products', [
                    'qty' => "INT UNSIGNED NULL COMMENT 'Qty dari Order Entry Form terakhir (arsip produk)' AFTER category",
                ]);
            },
            'products: qty diisi dari OEF terakhir' => static function (): bool {
                if (!self::$productQtyAdded) {
                    return false;
                }
                // Kolom baru: isi dengan qty Order Entry Form terakhir untuk setiap produk (data PO lama tidak dipakai).
                return Database::query(
                    'UPDATE products pr
                     JOIN (SELECT pl.product_id, pl.order_qty FROM po_lines pl
                           WHERE pl.id = (SELECT pl2.id FROM po_lines pl2 JOIN purchase_orders p2 ON p2.id = pl2.po_id
                                          WHERE pl2.product_id = pl.product_id AND p2.ppic_status IS NOT NULL
                                          ORDER BY p2.po_date DESC, pl2.id DESC LIMIT 1)) x ON x.product_id = pr.id
                     SET pr.qty = GREATEST(x.order_qty, 0)
                     WHERE pr.qty IS NULL'
                )->rowCount() > 0;
            },

            // ---------------------------------------------------------- Complaint & Return
            'returns: kolom complaint' => static function (): bool {
                self::$complaintColumnsAdded = !self::columnExists('returns', 'complaint_status');
                return self::addColumns('returns', [
                    'record_type'      => "ENUM('Complaint','Return') NOT NULL DEFAULT 'Return' COMMENT 'Complaint = tanpa barang kembali' AFTER code",
                    'customer_id'      => 'INT UNSIGNED NULL AFTER record_type',
                    'complaint_detail' => 'TEXT NULL AFTER reason',
                    'complaint_status' => "ENUM('Open','Resolved','Unresolved') NOT NULL DEFAULT 'Open' AFTER complaint_detail",
                    'resolution_note'  => "TEXT NULL COMMENT 'Hasil penyelesaian / alasan tidak selesai' AFTER complaint_status",
                    'resolved_by'      => 'INT UNSIGNED NULL AFTER resolution_note',
                    'resolved_at'      => 'DATETIME NULL AFTER resolved_by',
                    'qc_email'         => "VARCHAR(500) NULL COMMENT 'Email QC penerima notifikasi (pisahkan dengan koma)' AFTER resolved_at",
                    'email_status'     => 'VARCHAR(20) NULL AFTER qc_email',
                    'email_sent_at'    => 'DATETIME NULL AFTER email_status',
                    'email_error'      => 'VARCHAR(500) NULL AFTER email_sent_at',
                ]);
            },
            'returns: data lama' => static function (): bool {
                $n = Database::query(
                    'UPDATE returns r JOIN purchase_orders p ON p.id = r.po_id SET r.customer_id = p.customer_id WHERE r.customer_id IS NULL AND p.customer_id IS NOT NULL'
                )->rowCount();
                // Retur lama (sebelum modul complaint) dianggap sudah selesai agar tidak muncul sebagai complaint terbuka.
                $m = 0;
                if (self::$complaintColumnsAdded) {
                    $m = Database::query(
                        "UPDATE returns SET complaint_status = 'Resolved', resolution_note = 'Data retur lama (sebelum modul Complaint & Return).'
                         WHERE complaint_status = 'Open' AND complaint_detail IS NULL"
                    )->rowCount();
                }
                return ($n + $m) > 0;
            },
            'returns: index & relasi complaint' => static function (): bool {
                $changed = self::addIndex('returns', 'idx_returns_customer', 'KEY idx_returns_customer (customer_id)');
                $changed = self::addIndex('returns', 'idx_returns_complaint', 'KEY idx_returns_complaint (complaint_status, return_date)') || $changed;
                $changed = self::addForeignKey('returns', 'fk_returns_customer', 'FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT') || $changed;
                $changed = self::addForeignKey('returns', 'fk_returns_resolved', 'FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL') || $changed;
                return $changed;
            },
            'tabel complaint_attachments' => static function (): bool {
                if (self::tableExists('complaint_attachments')) {
                    return false;
                }
                Database::query(
                    "CREATE TABLE IF NOT EXISTS complaint_attachments (
                      id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                      return_id      INT UNSIGNED NOT NULL,
                      original_name  VARCHAR(190) NOT NULL,
                      stored_name    VARCHAR(120) NOT NULL,
                      mime_type      VARCHAR(80)  NOT NULL,
                      file_size      INT UNSIGNED NOT NULL,
                      uploaded_by    INT UNSIGNED NULL,
                      created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (id),
                      KEY idx_complaint_att_return (return_id),
                      CONSTRAINT fk_complaint_att_return FOREIGN KEY (return_id) REFERENCES returns (id) ON DELETE CASCADE,
                      CONSTRAINT fk_complaint_att_user   FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
                return true;
            },

            // ---------------------------------------------------------- Inbound Supplier (Gudang)
            'tabel inbound_supplier' => static function (): bool {
                if (self::tableExists('inbound_supplier')) {
                    return false;
                }
                Database::query(self::INBOUND_SUPPLIER_DDL);
                return true;
            },
        ];
    }

    // ------------------------------------------------------------------ helpers

    public static function tableExists(string $table): bool
    {
        return (bool) Database::fetchValue(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            ['t' => $table]
        );
    }

    public static function columnExists(string $table, string $column): bool
    {
        return (bool) Database::fetchValue(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
    }

    /**
     * Tambah kolom yang belum ada (berurutan, agar klausa AFTER valid).
     * @param array<string,string> $columns nama => definisi
     */
    private static function addColumns(string $table, array $columns): bool
    {
        Database::assertIdentifier($table);
        $changed = false;
        foreach ($columns as $name => $definition) {
            Database::assertIdentifier($name);
            if (self::columnExists($table, $name)) {
                continue;
            }
            // Bila kolom acuan AFTER belum ada (skema lama berbeda), tambahkan di akhir tabel.
            if (preg_match('/\sAFTER\s+([a-z_0-9]+)\s*$/i', $definition, $m) && !self::columnExists($table, $m[1])) {
                $definition = (string) preg_replace('/\sAFTER\s+[a-z_0-9]+\s*$/i', '', $definition);
            }
            Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
            $changed = true;
        }
        return $changed;
    }

    private static function addIndex(string $table, string $name, string $definition): bool
    {
        $exists = (bool) Database::fetchValue(
            'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
            ['t' => $table, 'i' => $name]
        );
        if ($exists) {
            return false;
        }
        Database::assertIdentifier($table);
        Database::query("ALTER TABLE `{$table}` ADD {$definition}");
        return true;
    }

    private static function addForeignKey(string $table, string $name, string $definition): bool
    {
        $exists = (bool) Database::fetchValue(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            ['t' => $table, 'n' => $name]
        );
        if ($exists) {
            return false;
        }
        Database::assertIdentifier($table);
        Database::assertIdentifier($name);
        Database::query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` {$definition}");
        return true;
    }
}
