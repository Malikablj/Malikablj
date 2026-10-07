-- =====================================================================
-- Hardening opsional (butuh hak TRIGGER; pada MySQL dengan binary log aktif
-- mungkin butuh SUPER atau log_bin_trust_function_creators = 1).
-- Jalankan: php bin/install.php --with-hardening   atau   mysql npd_project_control < database/hardening.sql
-- =====================================================================

-- Audit log append-only di level database: tolak UPDATE/DELETE.
DROP TRIGGER IF EXISTS trg_audit_logs_no_update;
CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

DROP TRIGGER IF EXISTS trg_audit_logs_no_delete;
CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

-- Contoh hak minimal user aplikasi (jalankan sebagai root, sesuaikan nama/host):
--   CREATE USER 'npd_app'@'localhost' IDENTIFIED BY '<password kuat>';
--   GRANT SELECT, INSERT, UPDATE, DELETE ON npd_project_control.* TO 'npd_app'@'localhost';
--   REVOKE UPDATE, DELETE ON npd_project_control.audit_logs FROM 'npd_app'@'localhost';
-- Catatan: REVOKE per tabel setelah GRANT per database tidak berlaku di MySQL; untuk
-- pembatasan per tabel, berikan GRANT per tabel (lihat docs/DEPLOYMENT.md).
