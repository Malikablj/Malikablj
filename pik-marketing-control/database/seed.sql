-- =============================================================================
-- PIK MARKETING CONTROL — Seed data (baseline)
--
-- Berisi pengaturan awal aplikasi saja. File ini SENGAJA tidak berisi:
--   * data bisnis (customer, PO, invoice, dll.) — diimpor dari
--     PIK_Master_Database_AppSheet.xlsx memakai database/import_workbook.php,
--     karena repository ini public dan data perusahaan tidak boleh di-commit;
--   * akun user/password — admin pertama dibuat lewat halaman /setup atau
--     `php database/create_admin.php` (lihat README).
--
-- Aman dijalankan berulang (INSERT ... ON DUPLICATE KEY UPDATE tidak mengubah
-- nilai yang sudah diubah admin).
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('company_name',              'PT Permata Indo Kemas'),
  ('app_name',                  'PIK Marketing Control'),
  ('invoice_default_due_days',  '30'),
  ('delivery_reminder_days',    '2'),
  ('automation_interval_minutes', '60'),
  ('automation_last_run',       NULL)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
