-- OPSIONAL (dijalankan oleh DBA dengan hak SUPER / log_bin_trust_function_creators=1).
-- Mencegah UPDATE dan DELETE pada audit_logs langsung di level MySQL,
-- sehingga audit trail tidak dapat diubah bahkan lewat query manual.
--
--   mysql -u root -p pik_pr < database/hardening/audit_logs_protection.sql

DROP TRIGGER IF EXISTS trg_audit_logs_no_update;
DROP TRIGGER IF EXISTS trg_audit_logs_no_delete;

DELIMITER $$
CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs bersifat read-only';
END$$

CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs bersifat read-only';
END$$
DELIMITER ;
