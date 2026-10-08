-- Impor data project lama dari Excel (Pengaturan › Impor Data Lama, docs/IMPORT_DATA_LAMA.md).
--   projects.legacy_ref   : Ref project pada file impor (unik → file yang sama tidak terimpor dua kali)
--   projects.imported_at  : waktu impor (NULL = project dibuat lewat NPR di aplikasi)
--   process_runs.is_imported : run dari data lama — dikecualikan dari KPI, Analytics, dan Weekly Report
-- Idempoten (cek information_schema), berlaku di MySQL 8 dan MariaDB 10.6+.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'legacy_ref');
SET @s := IF(@c = 0, 'ALTER TABLE projects ADD COLUMN legacy_ref VARCHAR(60) NULL AFTER last_activity_at', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'imported_at');
SET @s := IF(@c = 0, 'ALTER TABLE projects ADD COLUMN imported_at DATETIME NULL AFTER legacy_ref', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'uq_projects_legacy_ref');
SET @s := IF(@c = 0, 'ALTER TABLE projects ADD UNIQUE KEY uq_projects_legacy_ref (legacy_ref)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'process_runs' AND COLUMN_NAME = 'is_imported');
SET @s := IF(@c = 0, 'ALTER TABLE process_runs ADD COLUMN is_imported TINYINT(1) NOT NULL DEFAULT 0 AFTER status', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
