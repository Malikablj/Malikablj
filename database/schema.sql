-- =====================================================================
-- NPD Project Control v3.0 — PT. Permata Indo Kemas
-- Database schema (MySQL 8.0+, InnoDB, utf8mb4 / utf8mb4_unicode_ci)
--
-- Jalankan pada server:
--   mysql -u root -p < database/schema.sql
--   mysql -u root -p npd_project_control < database/seed.sql
--
-- Catatan:
--  * Skema ini TIDAK berisi DROP DATABASE / DROP TABLE agar aman dijalankan
--    di server yang sudah berisi data (semua CREATE memakai IF NOT EXISTS).
--  * Trigger append-only untuk audit_logs ada di database/hardening.sql
--    (butuh hak TRIGGER/SUPER; opsional di shared hosting).
--  * Semua tanggal planned/actual bertipe DATE (zona waktu Asia/Jakarta).
--  * Kolom *_json berisi JSON yang divalidasi di server.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS npd_project_control
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE npd_project_control;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+07:00';

-- ---------------------------------------------------------------------
-- 1. Role, permission, user
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(32)  NOT NULL,
  name_id       VARCHAR(80)  NOT NULL,
  name_en       VARCHAR(80)  NOT NULL,
  is_read_only  TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(64)  NOT NULL,
  module        VARCHAR(32)  NOT NULL,
  description   VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- scope: 'all' = boleh pada seluruh data; 'own' = hanya data miliknya
-- (Sales PIC / PIC proses / pembuat), dievaluasi oleh App\Core\Gate.
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id        TINYINT UNSIGNED  NOT NULL,
  permission_id  SMALLINT UNSIGNED NOT NULL,
  scope          ENUM('all','own') NOT NULL DEFAULT 'all',
  PRIMARY KEY (role_id, permission_id),
  KEY idx_rp_permission (permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id               TINYINT UNSIGNED NOT NULL,
  name                  VARCHAR(120) NOT NULL,
  email                 VARCHAR(190) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  job_title             VARCHAR(120) NULL,
  phone                 VARCHAR(40)  NULL,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  language              ENUM('id','en') NOT NULL DEFAULT 'id',
  theme                 ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  must_change_password  TINYINT(1)   NOT NULL DEFAULT 0,
  password_changed_at   DATETIME     NULL,
  last_login_at         DATETIME     NULL,
  last_login_ip         VARCHAR(45)  NULL,
  deactivated_at        DATETIME     NULL,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id, is_active),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  ip_address    VARCHAR(45)  NOT NULL,
  success       TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_email_time (email, attempted_at),
  KEY idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Pengaturan & master data
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS application_settings (
  setting_key    VARCHAR(100) NOT NULL,
  setting_value  TEXT         NULL,
  value_type     ENUM('string','int','bool','json','secret') NOT NULL DEFAULT 'string',
  description    VARCHAR(255) NULL,
  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seluruh daftar pilihan form NPR & tipe dokumen. Menghapus = menonaktifkan.
-- category: part_name, request_type, development_type, product_application,
--   product_content, resin, color, surface, neck_preform, printing_method,
--   varnish, labelling_side, mould_method, packaging, test_method,
--   document_type, priority
CREATE TABLE IF NOT EXISTS master_options (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category     VARCHAR(40)  NOT NULL,
  code         VARCHAR(60)  NOT NULL,
  label_id     VARCHAR(160) NOT NULL,
  label_en     VARCHAR(160) NOT NULL,
  meta_json    JSON         NULL,
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  is_builtin   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_master_cat_code (category, code),
  KEY idx_master_cat_active (category, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(30)  NOT NULL,
  name              VARCHAR(190) NOT NULL,
  invoice_address   TEXT         NULL,
  shipping_address  TEXT         NULL,
  phone             VARCHAR(60)  NULL,
  email             VARCHAR(190) NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_code (code),
  KEY idx_customers_name (name),
  CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_contacts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  position     VARCHAR(120) NULL,
  phone        VARCHAR(60)  NULL,
  email        VARCHAR(190) NULL,
  is_primary   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cc_customer (customer_id),
  CONSTRAINT fk_cc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Penomoran aman konkuren (NPR, kode project, approval):
--   INSERT ... ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)
CREATE TABLE IF NOT EXISTS number_sequences (
  seq_key     VARCHAR(50)  NOT NULL,
  current_value INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (seq_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Kalender kerja
-- ---------------------------------------------------------------------
-- weekday: ISO-8601 (1 = Senin ... 7 = Minggu)
CREATE TABLE IF NOT EXISTS working_calendar (
  weekday     TINYINT UNSIGNED NOT NULL,
  is_working  TINYINT(1)       NOT NULL DEFAULT 1,
  updated_by  INT UNSIGNED     NULL,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (weekday),
  CONSTRAINT chk_wc_weekday CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT fk_wc_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_recurring = 1: berulang setiap tahun pada bulan-tanggal yang sama
CREATE TABLE IF NOT EXISTS holidays (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  holiday_date  DATE         NOT NULL,
  name          VARCHAR(160) NOT NULL,
  is_recurring  TINYINT(1)   NOT NULL DEFAULT 0,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_holidays_date (holiday_date),
  CONSTRAINT fk_holidays_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Template workflow (berversi)
-- ---------------------------------------------------------------------
-- code: project (proses level project), new_mold, subcont
CREATE TABLE IF NOT EXISTS workflow_templates (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                VARCHAR(30)  NOT NULL,
  name                VARCHAR(120) NOT NULL,
  scope               ENUM('project','part') NOT NULL,
  part_type           ENUM('new_mold','subcont') NULL,
  current_version_id  INT UNSIGNED NULL,
  gate_enabled        TINYINT(1)   NOT NULL DEFAULT 1,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wt_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_template_versions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id   INT UNSIGNED NOT NULL,
  version_no    SMALLINT UNSIGNED NOT NULL,
  status        ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  notes         VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  published_by  INT UNSIGNED NULL,
  published_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wtv_template_version (template_id, version_no),
  CONSTRAINT fk_wtv_template FOREIGN KEY (template_id) REFERENCES workflow_templates(id),
  CONSTRAINT fk_wtv_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_wtv_published_by FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK melingkar ditambahkan secara idempoten (aman bila skema dijalankan ulang)
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_wt_current_version');
SET @ddl := IF(@fk_exists = 0,
  'ALTER TABLE workflow_templates ADD CONSTRAINT fk_wt_current_version FOREIGN KEY (current_version_id) REFERENCES workflow_template_versions(id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- step_type: task | approval | decision | finish | gate | request | feedback
-- activation: auto (aktif saat dependency terpenuhi) | loop_only (hanya via loop, mis. Mold Correction)
-- decision_options_json: [{"code":"not_approved","label_id":"..","label_en":"..",
--                          "effect":"loop","loop_to":"S1","comment_required":true}, ...]
CREATE TABLE IF NOT EXISTS workflow_steps (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_version_id      INT UNSIGNED NOT NULL,
  code                     VARCHAR(20)  NOT NULL,
  name                     VARCHAR(160) NOT NULL,
  name_en                  VARCHAR(160) NULL,
  short_name               VARCHAR(40)  NULL,
  description              VARCHAR(500) NULL,
  step_type                VARCHAR(20)  NOT NULL DEFAULT 'task',
  pic_role_id              TINYINT UNSIGNED NOT NULL,
  executor_roles_json      JSON         NULL,
  uploader_roles_json      JSON         NULL,
  default_duration         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_mandatory             TINYINT(1)   NOT NULL DEFAULT 1,
  is_skippable             TINYINT(1)   NOT NULL DEFAULT 0,
  skip_group               VARCHAR(30)  NULL,
  is_external              TINYINT(1)   NOT NULL DEFAULT 0,
  is_customer_approval     TINYINT(1)   NOT NULL DEFAULT 0,
  approval_type            VARCHAR(30)  NULL,
  approval_giver           ENUM('customer','internal') NULL,
  activation               ENUM('auto','loop_only') NOT NULL DEFAULT 'auto',
  on_complete_reopen_code  VARCHAR(20)  NULL,
  decision_options_json    JSON         NULL,
  required_doc_types_json  JSON         NULL,
  suggested_doc_types_json JSON         NULL,
  record_type              VARCHAR(30)  NULL,
  form_fields_json         JSON         NULL,
  calendar_category        VARCHAR(30)  NULL,
  is_gate_milestone        TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order               SMALLINT     NOT NULL DEFAULT 0,
  is_active                TINYINT(1)   NOT NULL DEFAULT 1,
  is_builtin               TINYINT(1)   NOT NULL DEFAULT 1,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ws_version_code (template_version_id, code),
  KEY idx_ws_version_sort (template_version_id, sort_order),
  CONSTRAINT fk_ws_version FOREIGN KEY (template_version_id) REFERENCES workflow_template_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ws_pic_role FOREIGN KEY (pic_role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- predecessor_code merujuk kode step lain (dalam part yang sama) atau kode
-- proses level project (P2, G1). only_when_gate = 1: hanya berlaku bila gate aktif.
CREATE TABLE IF NOT EXISTS workflow_step_dependencies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  step_id           INT UNSIGNED NOT NULL,
  predecessor_code  VARCHAR(20)  NOT NULL,
  dep_type          ENUM('FS','SS','FF','PARALLEL') NOT NULL DEFAULT 'FS',
  lag_days          SMALLINT     NOT NULL DEFAULT 0,
  only_when_gate    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wsd_step_pred (step_id, predecessor_code),
  CONSTRAINT fk_wsd_step FOREIGN KEY (step_id) REFERENCES workflow_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. NPR (PIK-FORM-NPD-01 rev 00)
-- ---------------------------------------------------------------------
-- Kolom biru (Sales) ada di npr & npr_parts; kolom pink (Admin NPD) ada di npr_feedback.
CREATE TABLE IF NOT EXISTS npr (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  npr_number                 VARCHAR(40)  NULL,
  seq_year                   SMALLINT UNSIGNED NULL,
  seq_no                     INT UNSIGNED NULL,
  status                     ENUM('draft','submitted','returned','feedback_completed','discarded') NOT NULL DEFAULT 'draft',
  -- Header
  product_name               VARCHAR(190) NOT NULL DEFAULT '',
  -- Jenis Permintaan (form: STATUS PROJECT)
  request_types_json         JSON         NULL,
  request_type_other         VARCHAR(255) NULL,
  -- Data customer
  customer_id                INT UNSIGNED NULL,
  invoice_address            TEXT         NULL,
  shipping_address           TEXT         NULL,
  phone                      VARCHAR(60)  NULL,
  -- Aplikasi & isi produk
  product_applications_json  JSON         NULL,
  product_contents_json      JSON         NULL,
  product_content_other      VARCHAR(255) NULL,
  -- Berat bersih / netto
  net_volume_ml              DECIMAL(12,2) NULL,
  net_weight_gr              DECIMAL(12,2) NULL,
  -- Decoration
  deco_printing              TINYINT(1)   NOT NULL DEFAULT 0,
  deco_printing_method       VARCHAR(60)  NULL,
  deco_printing_components   VARCHAR(255) NULL,
  deco_varnish               VARCHAR(60)  NULL,
  deco_varnish_components    VARCHAR(255) NULL,
  deco_labelling             TINYINT(1)   NOT NULL DEFAULT 0,
  deco_labelling_side        VARCHAR(60)  NULL,
  deco_labelling_components  VARCHAR(255) NULL,
  deco_shrink                TINYINT(1)   NOT NULL DEFAULT 0,
  deco_shrink_components     VARCHAR(255) NULL,
  -- Kebutuhan
  qty_per_month              INT UNSIGNED NULL,
  qty_per_year               INT UNSIGNED NULL,
  -- Kemasan
  packaging_json             JSON         NULL,
  packaging_other            VARCHAR(255) NULL,
  -- Metode test: [{"code":"leaking_test","value":"2","unit":"kg_cm2"}, ...]
  test_methods_json          JSON         NULL,
  test_refer_spec_doc        TINYINT(1)   NOT NULL DEFAULT 0,
  test_other                 VARCHAR(255) NULL,
  -- Regulasi
  regulation_compliance      ENUM('no','yes') NULL,
  regulation_note            TEXT         NULL,
  -- Lampiran dari customer
  attach_sample              ENUM('ada','tidak_ada') NULL,
  attach_technical_drawing   ENUM('ada','tidak_ada') NULL,
  attach_mockup              ENUM('ada','tidak_ada') NULL,
  -- Penutup
  note                       TEXT         NULL,
  launching_target           DATE         NULL,
  -- Pemilik
  created_by                 INT UNSIGNED NOT NULL,
  sales_pic_id               INT UNSIGNED NOT NULL,
  -- Requested by (otomatis saat dikirim)
  requested_by_id            INT UNSIGNED NULL,
  requested_by_name          VARCHAR(120) NULL,
  requested_by_title         VARCHAR(120) NULL,
  requested_at               DATETIME     NULL,
  first_submitted_at         DATETIME     NULL,
  submit_count               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Received by (otomatis saat Selesaikan Feedback)
  received_by_id             INT UNSIGNED NULL,
  received_by_name           VARCHAR(120) NULL,
  received_by_title          VARCHAR(120) NULL,
  received_at                DATETIME     NULL,
  -- Pengembalian terakhir
  return_reason              TEXT         NULL,
  returned_at                DATETIME     NULL,
  returned_by                INT UNSIGNED NULL,
  lock_version               INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_npr_number (npr_number),
  UNIQUE KEY uq_npr_seq (seq_year, seq_no),
  KEY idx_npr_status (status, updated_at),
  KEY idx_npr_sales (sales_pic_id, status),
  KEY idx_npr_customer (customer_id),
  CONSTRAINT fk_npr_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_npr_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_npr_sales FOREIGN KEY (sales_pic_id) REFERENCES users(id),
  CONSTRAINT fk_npr_requested_by FOREIGN KEY (requested_by_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_npr_received_by FOREIGN KEY (received_by_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_npr_returned_by FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris per part / komponen pada Tabel Komponen Produk (kolom biru)
CREATE TABLE IF NOT EXISTS npr_parts (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  npr_id                 INT UNSIGNED NOT NULL,
  sort_order             SMALLINT     NOT NULL DEFAULT 0,
  part_name_code         VARCHAR(60)  NULL,
  part_name_custom       VARCHAR(120) NULL,
  part_name              VARCHAR(120) NOT NULL,
  part_type              ENUM('new_mold','subcont') NULL,
  development_type       VARCHAR(60)  NULL,
  mold_supplier          VARCHAR(190) NULL,
  is_external_component  TINYINT(1)   NOT NULL DEFAULT 0,
  external_note          VARCHAR(255) NULL,
  resin_code             VARCHAR(60)  NULL,
  color_code             VARCHAR(60)  NULL,
  pantone                VARCHAR(60)  NULL,
  surface_code           VARCHAR(60)  NULL,
  neck_preform_code      VARCHAR(60)  NULL,
  needs_review           TINYINT(1)   NOT NULL DEFAULT 0,
  status                 ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  cancel_reason          TEXT         NULL,
  cancelled_by           INT UNSIGNED NULL,
  cancelled_at           DATETIME     NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nprp_npr (npr_id, sort_order),
  CONSTRAINT fk_nprp_npr FOREIGN KEY (npr_id) REFERENCES npr(id) ON DELETE CASCADE,
  CONSTRAINT fk_nprp_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kolom pink per part: berat, kebutuhan mould, feedback, keputusan.
-- Draft (published_at NULL) tidak terlihat Sales sampai Selesaikan Feedback.
CREATE TABLE IF NOT EXISTS npr_feedback (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  npr_part_id            INT UNSIGNED NOT NULL,
  weight_gr              DECIMAL(10,2) NULL,
  mould_method_code      VARCHAR(60)  NULL,
  mould_method_other     VARCHAR(120) NULL,
  cavity                 SMALLINT UNSIGNED NULL,
  mould_price_pik_pct    DECIMAL(5,2) NULL,
  mould_price_cust_pct   DECIMAL(5,2) NULL,
  mould_lead_time_days   SMALLINT UNSIGNED NULL,
  mould_lead_time_note   VARCHAR(120) NULL,
  feedback_text          TEXT         NULL,
  decision               ENUM('feasible','feasible_with_notes','needs_revision','not_feasible') NULL,
  decision_reason        TEXT         NULL,
  needs_new_masterbatch  TINYINT(1)   NULL,
  published_at           DATETIME     NULL,
  published_by           INT UNSIGNED NULL,
  updated_by             INT UNSIGNED NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nprf_part (npr_part_id),
  CONSTRAINT fk_nprf_part FOREIGN KEY (npr_part_id) REFERENCES npr_parts(id) ON DELETE CASCADE,
  CONSTRAINT fk_nprf_published_by FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_nprf_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_nprf_pct CHECK (
    (mould_price_pik_pct IS NULL OR (mould_price_pik_pct BETWEEN 0 AND 100)) AND
    (mould_price_cust_pct IS NULL OR (mould_price_cust_pct BETWEEN 0 AND 100)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Project → Part
-- ---------------------------------------------------------------------
-- status (turunan, disimpan untuk query cepat):
--   not_started | on_progress | waiting | hold | ready_to_finish | completed | cancelled
CREATE TABLE IF NOT EXISTS projects (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code               VARCHAR(20)  NOT NULL,
  npr_id             INT UNSIGNED NOT NULL,
  customer_id        INT UNSIGNED NOT NULL,
  name               VARCHAR(190) NOT NULL,
  priority           ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  sales_pic_id       INT UNSIGNED NOT NULL,
  npd_pic_id         INT UNSIGNED NULL,
  start_date         DATE         NOT NULL,
  target_finish      DATE         NULL,
  forecast_finish    DATE         NULL,
  status             VARCHAR(30)  NOT NULL DEFAULT 'not_started',
  is_on_hold         TINYINT(1)   NOT NULL DEFAULT 0,
  gate_enabled       TINYINT(1)   NOT NULL DEFAULT 0,
  finished_at        DATETIME     NULL,
  finished_by        INT UNSIGNED NULL,
  cancelled_at       DATETIME     NULL,
  cancelled_by       INT UNSIGNED NULL,
  cancel_reason      TEXT         NULL,
  is_archived        TINYINT(1)   NOT NULL DEFAULT 0,
  archived_at        DATETIME     NULL,
  archived_by        INT UNSIGNED NULL,
  archive_reason     TEXT         NULL,
  last_activity_at   DATETIME     NULL,
  lock_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_code (code),
  UNIQUE KEY uq_projects_npr (npr_id),
  KEY idx_projects_status (is_archived, status),
  KEY idx_projects_customer (customer_id),
  KEY idx_projects_npd_pic (npd_pic_id),
  KEY idx_projects_sales_pic (sales_pic_id),
  CONSTRAINT fk_projects_npr FOREIGN KEY (npr_id) REFERENCES npr(id),
  CONSTRAINT fk_projects_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_projects_sales FOREIGN KEY (sales_pic_id) REFERENCES users(id),
  CONSTRAINT fk_projects_npd FOREIGN KEY (npd_pic_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_finished_by FOREIGN KEY (finished_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: not_started | on_progress | waiting_approval | waiting_external | hold | completed | cancelled
CREATE TABLE IF NOT EXISTS project_parts (
  id                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id                    INT UNSIGNED NOT NULL,
  npr_part_id                   INT UNSIGNED NOT NULL,
  name                          VARCHAR(120) NOT NULL,
  sort_order                    SMALLINT     NOT NULL DEFAULT 0,
  part_type                     ENUM('new_mold','subcont') NOT NULL,
  workflow_template_version_id  INT UNSIGNED NULL,
  status                        VARCHAR(30)  NOT NULL DEFAULT 'not_started',
  start_date                    DATE         NULL,
  forecast_finish               DATE         NULL,
  is_on_hold                    TINYINT(1)   NOT NULL DEFAULT 0,
  needs_new_masterbatch         TINYINT(1)   NULL,
  mold_supplier                 VARCHAR(190) NULL,
  drafter_pic_id                INT UNSIGNED NULL,
  purchasing_pic_id             INT UNSIGNED NULL,
  production_pic_id             INT UNSIGNED NULL,
  quality_pic_id                INT UNSIGNED NULL,
  accepted_at                   DATETIME     NULL,
  completed_at                  DATETIME     NULL,
  cancelled_at                  DATETIME     NULL,
  cancelled_by                  INT UNSIGNED NULL,
  cancel_reason                 TEXT         NULL,
  last_activity_at              DATETIME     NULL,
  lock_version                  INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pp_npr_part (npr_part_id),
  KEY idx_pp_project (project_id, sort_order),
  KEY idx_pp_status (status),
  CONSTRAINT fk_pp_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_pp_npr_part FOREIGN KEY (npr_part_id) REFERENCES npr_parts(id),
  CONSTRAINT fk_pp_template_version FOREIGN KEY (workflow_template_version_id) REFERENCES workflow_template_versions(id),
  CONSTRAINT fk_pp_drafter FOREIGN KEY (drafter_pic_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_purchasing FOREIGN KEY (purchasing_pic_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_production FOREIGN KEY (production_pic_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_quality FOREIGN KEY (quality_pic_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Proses (instance), dependency, run (untuk KPI)
-- ---------------------------------------------------------------------
-- part_id NULL = proses level project (P1, P2, G1, PF).
-- status: not_started | current | completed | revision | problem | skipped
-- Atribut step disalin (snapshot) agar perubahan template tidak mengubah project berjalan.
CREATE TABLE IF NOT EXISTS processes (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id               INT UNSIGNED NOT NULL,
  part_id                  INT UNSIGNED NULL,
  workflow_step_id         INT UNSIGNED NULL,
  code                     VARCHAR(20)  NOT NULL,
  name                     VARCHAR(160) NOT NULL,
  name_en                  VARCHAR(160) NULL,
  step_type                VARCHAR(20)  NOT NULL DEFAULT 'task',
  sort_order               SMALLINT     NOT NULL DEFAULT 0,
  pic_role_id              TINYINT UNSIGNED NOT NULL,
  pic_user_id              INT UNSIGNED NULL,
  status                   VARCHAR(20)  NOT NULL DEFAULT 'not_started',
  activation               ENUM('auto','loop_only') NOT NULL DEFAULT 'auto',
  is_mandatory             TINYINT(1)   NOT NULL DEFAULT 1,
  is_skippable             TINYINT(1)   NOT NULL DEFAULT 0,
  skip_group               VARCHAR(30)  NULL,
  is_external              TINYINT(1)   NOT NULL DEFAULT 0,
  is_customer_approval     TINYINT(1)   NOT NULL DEFAULT 0,
  approval_type            VARCHAR(30)  NULL,
  approval_giver           ENUM('customer','internal') NULL,
  on_complete_reopen_code  VARCHAR(20)  NULL,
  decision_options_json    JSON         NULL,
  required_doc_types_json  JSON         NULL,
  uploader_roles_json      JSON         NULL,
  record_type              VARCHAR(30)  NULL,
  calendar_category        VARCHAR(30)  NULL,
  is_gate_milestone        TINYINT(1)   NOT NULL DEFAULT 0,
  -- planning
  duration                 SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  duration_is_manual       TINYINT(1)   NOT NULL DEFAULT 0,
  manual_start             DATE         NULL,
  manual_finish            DATE         NULL,
  planned_start            DATE         NULL,
  planned_finish           DATE         NULL,
  forecast_start           DATE         NULL,
  forecast_finish          DATE         NULL,
  actual_start             DATE         NULL,
  actual_finish            DATE         NULL,
  schedule_warning         VARCHAR(500) NULL,
  -- eksekusi
  activated_at             DATETIME     NULL,
  completed_at             DATETIME     NULL,
  completed_by             INT UNSIGNED NULL,
  iteration                SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  loop_count               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  outcome                  VARCHAR(30)  NULL,
  outcome_comment          TEXT         NULL,
  skip_reason              TEXT         NULL,
  skipped_by               INT UNSIGNED NULL,
  skipped_at               DATETIME     NULL,
  form_data_json           JSON         NULL,
  lock_version             INT UNSIGNED NOT NULL DEFAULT 1,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proc_scope_code (project_id, part_id, code),
  KEY idx_proc_part (part_id, sort_order),
  KEY idx_proc_status_finish (status, planned_finish),
  KEY idx_proc_pic (pic_user_id, status),
  CONSTRAINT fk_proc_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_proc_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_proc_step FOREIGN KEY (workflow_step_id) REFERENCES workflow_steps(id) ON DELETE SET NULL,
  CONSTRAINT fk_proc_pic_role FOREIGN KEY (pic_role_id) REFERENCES roles(id),
  CONSTRAINT fk_proc_pic_user FOREIGN KEY (pic_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_proc_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_proc_skipped_by FOREIGN KEY (skipped_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS process_dependencies (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  process_id      INT UNSIGNED NOT NULL,
  predecessor_id  INT UNSIGNED NOT NULL,
  dep_type        ENUM('FS','SS','FF','PARALLEL') NOT NULL DEFAULT 'FS',
  lag_days        SMALLINT     NOT NULL DEFAULT 0,
  source          ENUM('template','override') NOT NULL DEFAULT 'template',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pd_pair (process_id, predecessor_id),
  KEY idx_pd_predecessor (predecessor_id),
  CONSTRAINT fk_pd_process FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_predecessor FOREIGN KEY (predecessor_id) REFERENCES processes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_pd_not_self CHECK (process_id <> predecessor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris per aktivasi (iterasi) proses: dasar KPI per PIC
-- (Planned Finish saat aktivasi, PIC saat selesai, masa Hold dikecualikan).
CREATE TABLE IF NOT EXISTS process_runs (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  process_id                  INT UNSIGNED NOT NULL,
  iteration                   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  activated_at                DATETIME     NOT NULL,
  planned_start_at_activation DATE         NULL,
  planned_finish_at_activation DATE        NULL,
  planned_duration_at_activation SMALLINT UNSIGNED NULL,
  actual_start                DATE         NULL,
  actual_finish               DATE         NULL,
  outcome                     VARCHAR(30)  NULL,
  pic_user_id                 INT UNSIGNED NULL,
  completed_by                INT UNSIGNED NULL,
  hold_working_days           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  overdue_since               DATE         NULL,
  status                      ENUM('open','completed','reset','skipped') NOT NULL DEFAULT 'open',
  created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_process_iter (process_id, iteration),
  KEY idx_pr_pic_finish (pic_user_id, actual_finish),
  KEY idx_pr_status (status),
  CONSTRAINT fk_pr_process FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_pic FOREIGN KEY (pic_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gate level project (Assembly / Fit Test). Gate juga merupakan proses (process_id).
CREATE TABLE IF NOT EXISTS project_gates (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id       INT UNSIGNED NOT NULL,
  process_id       INT UNSIGNED NOT NULL,
  name             VARCHAR(160) NOT NULL,
  result           ENUM('pass','fail') NULL,
  result_note      TEXT         NULL,
  failed_part_ids_json JSON     NULL,
  decided_by       INT UNSIGNED NULL,
  decided_at       DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pg_process (process_id),
  KEY idx_pg_project (project_id),
  CONSTRAINT fk_pg_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_pg_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_pg_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Baseline & perubahan jadwal
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedule_baselines (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   INT UNSIGNED NOT NULL,
  part_id      INT UNSIGNED NULL,
  version_no   SMALLINT UNSIGNED NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  target_finish DATE        NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sb_scope (project_id, part_id, version_no),
  CONSTRAINT fk_sb_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_sb_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_sb_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_baseline_items (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  baseline_id     INT UNSIGNED NOT NULL,
  process_id      INT UNSIGNED NOT NULL,
  planned_start   DATE         NULL,
  planned_finish  DATE         NULL,
  duration        SMALLINT UNSIGNED NULL,
  status          VARCHAR(20)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sbi (baseline_id, process_id),
  KEY idx_sbi_process (process_id),
  CONSTRAINT fk_sbi_baseline FOREIGN KEY (baseline_id) REFERENCES schedule_baselines(id) ON DELETE CASCADE,
  CONSTRAINT fk_sbi_process FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris per proses yang bergeser dalam satu perhitungan ulang (batch_id).
-- change_type: auto_shift | manual_plan | dependency_change | resume | skip | unskip
--              | loop | holiday | target_change | baseline | initial
CREATE TABLE IF NOT EXISTS schedule_changes (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id           VARCHAR(40)  NOT NULL,
  project_id         INT UNSIGNED NOT NULL,
  part_id            INT UNSIGNED NULL,
  process_id         INT UNSIGNED NULL,
  cause_process_id   INT UNSIGNED NULL,
  change_type        VARCHAR(30)  NOT NULL,
  old_start          DATE         NULL,
  old_finish         DATE         NULL,
  new_start          DATE         NULL,
  new_finish         DATE         NULL,
  shift_working_days SMALLINT     NULL,
  reason             VARCHAR(500) NULL,
  user_id            INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sc_project (project_id, created_at),
  KEY idx_sc_batch (batch_id),
  KEY idx_sc_process (process_id),
  CONSTRAINT fk_sc_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_sc_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_sc_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_sc_cause FOREIGN KEY (cause_process_id) REFERENCES processes(id),
  CONSTRAINT fk_sc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. Dokumen & approval
-- ---------------------------------------------------------------------
-- Dokumen milik NPR (lampiran sebelum project ada) atau milik project › part › proses.
-- npr_category: product_shape (Contoh Bentuk Produk) | spec_reference (Referensi Spek)
CREATE TABLE IF NOT EXISTS documents (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  npr_id              INT UNSIGNED NULL,
  npr_category        VARCHAR(30)  NULL,
  project_id          INT UNSIGNED NULL,
  part_id             INT UNSIGNED NULL,
  process_id          INT UNSIGNED NULL,
  doc_type_code       VARCHAR(60)  NOT NULL,
  title               VARCHAR(190) NOT NULL,
  current_version_id  INT UNSIGNED NULL,
  version_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc_npr (npr_id),
  KEY idx_doc_project (project_id, part_id, process_id),
  KEY idx_doc_type (doc_type_code),
  CONSTRAINT fk_doc_npr FOREIGN KEY (npr_id) REFERENCES npr(id),
  CONSTRAINT fk_doc_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_doc_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_doc_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_doc_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_doc_owner CHECK (npr_id IS NOT NULL OR project_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File fisik disimpan di storage/documents/ (di luar webroot) dengan nama acak.
-- status: current | superseded | rejected | approved
CREATE TABLE IF NOT EXISTS document_versions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id    INT UNSIGNED NOT NULL,
  version_no     SMALLINT UNSIGNED NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  stored_path    VARCHAR(255) NOT NULL,
  mime_type      VARCHAR(120) NOT NULL,
  extension      VARCHAR(16)  NOT NULL,
  size_bytes     BIGINT UNSIGNED NOT NULL,
  sha256         CHAR(64)     NOT NULL,
  status         ENUM('current','superseded','rejected','approved') NOT NULL DEFAULT 'current',
  notes          VARCHAR(500) NULL,
  uploaded_by    INT UNSIGNED NULL,
  uploaded_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dv_doc_version (document_id, version_no),
  UNIQUE KEY uq_dv_path (stored_path),
  CONSTRAINT fk_dv_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_dv_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_doc_current_version');
SET @ddl := IF(@fk_exists = 0,
  'ALTER TABLE documents ADD CONSTRAINT fk_doc_current_version FOREIGN KEY (current_version_id) REFERENCES document_versions(id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- approval_type: npr | artwork | masterbatch | 3d | layout_decoration | mold_drawing
--                | t0 | trial | commissioning | validation
CREATE TABLE IF NOT EXISTS approvals (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                 VARCHAR(30)  NOT NULL,
  project_id           INT UNSIGNED NOT NULL,
  part_id              INT UNSIGNED NULL,
  process_id           INT UNSIGNED NULL,
  approval_type        VARCHAR(30)  NOT NULL,
  giver                ENUM('customer','internal') NOT NULL,
  iteration            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  document_version_id  INT UNSIGNED NULL,
  status               ENUM('pending','approved','rejected','revision_required') NOT NULL DEFAULT 'pending',
  requested_by         INT UNSIGNED NULL,
  requested_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by           INT UNSIGNED NULL,
  decided_at           DATETIME     NULL,
  decision_maker_name  VARCHAR(160) NULL,
  comment              TEXT         NULL,
  evidence_document_id INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_approvals_code (code),
  KEY idx_appr_project (project_id, status),
  KEY idx_appr_process (process_id, iteration),
  KEY idx_appr_status (status, requested_at),
  CONSTRAINT fk_appr_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_appr_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_appr_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_appr_doc_version FOREIGN KEY (document_version_id) REFERENCES document_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_appr_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_appr_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_appr_evidence FOREIGN KEY (evidence_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  approval_id  INT UNSIGNED NOT NULL,
  action       VARCHAR(30)  NOT NULL,
  status_from  VARCHAR(30)  NULL,
  status_to    VARCHAR(30)  NOT NULL,
  comment      TEXT         NULL,
  user_id      INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ah_approval (approval_id, created_at),
  CONSTRAINT fk_ah_approval FOREIGN KEY (approval_id) REFERENCES approvals(id),
  CONSTRAINT fk_ah_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. Catatan proses (record): trial, material, validasi
-- ---------------------------------------------------------------------
-- trial_type: trial | t0 | commissioning
CREATE TABLE IF NOT EXISTS trial_records (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      INT UNSIGNED NOT NULL,
  part_id         INT UNSIGNED NOT NULL,
  process_id      INT UNSIGNED NOT NULL,
  iteration       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  trial_type      ENUM('trial','t0','commissioning') NOT NULL,
  trial_date      DATE         NULL,
  machine         VARCHAR(120) NULL,
  mold            VARCHAR(120) NULL,
  cavity          VARCHAR(40)  NULL,
  material        VARCHAR(190) NULL,
  parameters      TEXT         NULL,
  quantity        VARCHAR(60)  NULL,
  tests           TEXT         NULL,
  problems        TEXT         NULL,
  evaluation      TEXT         NULL,
  recommendation  TEXT         NULL,
  result          VARCHAR(30)  NULL,
  created_by      INT UNSIGNED NULL,
  updated_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tr_part (part_id, process_id),
  KEY idx_tr_project (project_id),
  CONSTRAINT fk_tr_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_tr_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_tr_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_tr_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tr_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- request_type: trial (Trial Material Preparation / Material Request) | bulk | preparation
CREATE TABLE IF NOT EXISTS material_requests (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id         INT UNSIGNED NOT NULL,
  part_id            INT UNSIGNED NOT NULL,
  process_id         INT UNSIGNED NULL,
  request_type       ENUM('trial','bulk','preparation') NOT NULL,
  material           VARCHAR(190) NULL,
  material_received  VARCHAR(190) NULL,
  batch_no           VARCHAR(80)  NULL,
  quantity_kg        DECIMAL(12,3) NULL,
  requested_date     DATE         NULL,
  received_date      DATE         NULL,
  supplier           VARCHAR(190) NULL,
  preparation_date   DATE         NULL,
  pic_user_id        INT UNSIGNED NULL,
  notes              TEXT         NULL,
  created_by         INT UNSIGNED NULL,
  updated_by         INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mr_part (part_id),
  KEY idx_mr_project (project_id),
  CONSTRAINT fk_mr_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_mr_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_mr_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_mr_pic FOREIGN KEY (pic_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mr_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mr_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS validation_records (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id         INT UNSIGNED NOT NULL,
  part_id            INT UNSIGNED NOT NULL,
  process_id         INT UNSIGNED NOT NULL,
  iteration          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  validation_date    DATE         NULL,
  machine            VARCHAR(120) NULL,
  mold               VARCHAR(120) NULL,
  cavity             VARCHAR(40)  NULL,
  material           VARCHAR(190) NULL,
  production_qty     VARCHAR(60)  NULL,
  parameters         TEXT         NULL,
  result             ENUM('pass','pass_with_condition','fail') NULL,
  problems           TEXT         NULL,
  corrective_action  TEXT         NULL,
  comment            TEXT         NULL,
  created_by         INT UNSIGNED NULL,
  updated_by         INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vr_part (part_id, process_id),
  KEY idx_vr_project (project_id),
  CONSTRAINT fk_vr_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_vr_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_vr_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_vr_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_vr_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. Next action, Hold, riwayat revisi, komentar, agenda
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS next_actions (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id        INT UNSIGNED NOT NULL,
  part_id           INT UNSIGNED NULL,
  description       VARCHAR(500) NOT NULL,
  due_date          DATE         NULL,
  owner_user_id     INT UNSIGNED NULL,
  waiting_for       ENUM('internal','external','customer') NOT NULL DEFAULT 'internal',
  waiting_for_note  VARCHAR(255) NULL,
  status            ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  completed_at      DATETIME     NULL,
  completed_by      INT UNSIGNED NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_na_project (project_id, status),
  KEY idx_na_owner (owner_user_id, status, due_date),
  CONSTRAINT fk_na_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_na_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_na_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_na_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_na_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- scope project: part_id NULL; scope part: part_id terisi.
CREATE TABLE IF NOT EXISTS hold_history (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id            INT UNSIGNED NOT NULL,
  part_id               INT UNSIGNED NULL,
  scope                 ENUM('project','part') NOT NULL,
  reason                TEXT         NOT NULL,
  expected_resume_date  DATE         NULL,
  held_at               DATETIME     NOT NULL,
  held_by               INT UNSIGNED NULL,
  resumed_at            DATETIME     NULL,
  resumed_by            INT UNSIGNED NULL,
  resume_note           TEXT         NULL,
  new_target_finish     DATE         NULL,
  baseline_id           INT UNSIGNED NULL,
  hold_working_days     SMALLINT UNSIGNED NULL,
  last_reminder_at      DATETIME     NULL,
  reminder_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hh_project (project_id, resumed_at),
  KEY idx_hh_part (part_id, resumed_at),
  CONSTRAINT fk_hh_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_hh_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_hh_held_by FOREIGN KEY (held_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_hh_resumed_by FOREIGN KEY (resumed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_hh_baseline FOREIGN KEY (baseline_id) REFERENCES schedule_baselines(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- revision_type: npr_change | npr_return | npr_resubmit | feedback_change | loop
--   | document_revision | hold | resume | baseline | target_change | skip | unskip
--   | reopen | manual_move | cancel | reopen_cancelled
CREATE TABLE IF NOT EXISTS revision_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  npr_id         INT UNSIGNED NULL,
  project_id     INT UNSIGNED NULL,
  part_id        INT UNSIGNED NULL,
  process_id     INT UNSIGNED NULL,
  revision_type  VARCHAR(30)  NOT NULL,
  summary        VARCHAR(500) NOT NULL,
  details_json   JSON         NULL,
  user_id        INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rh_npr (npr_id, created_at),
  KEY idx_rh_project (project_id, created_at),
  CONSTRAINT fk_rh_npr FOREIGN KEY (npr_id) REFERENCES npr(id),
  CONSTRAINT fk_rh_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_rh_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_rh_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_rh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NOT NULL,
  part_id     INT UNSIGNED NULL,
  process_id  INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  body        TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm_project (project_id, created_at),
  KEY idx_cm_process (process_id),
  CONSTRAINT fk_cm_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_cm_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_cm_process FOREIGN KEY (process_id) REFERENCES processes(id),
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agenda manual pada Kalender (meeting, follow-up)
CREATE TABLE IF NOT EXISTS calendar_events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NULL,
  part_id     INT UNSIGNED NULL,
  title       VARCHAR(190) NOT NULL,
  event_type  ENUM('meeting','follow_up','other') NOT NULL DEFAULT 'meeting',
  event_date  DATE         NOT NULL,
  start_time  TIME         NULL,
  end_time    TIME         NULL,
  location    VARCHAR(190) NULL,
  notes       TEXT         NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ce_date (event_date),
  KEY idx_ce_project (project_id),
  CONSTRAINT fk_ce_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_ce_part FOREIGN KEY (part_id) REFERENCES project_parts(id),
  CONSTRAINT fk_ce_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. Notifikasi
-- ---------------------------------------------------------------------
-- type: lihat Lampiran C (project_assigned, approval_requested, ... hold_reminder)
-- dedupe_key mencegah notifikasi sama terkirim berulang (UNIQUE per user).
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  type        VARCHAR(40)  NOT NULL,
  title       VARCHAR(255) NOT NULL,
  body        TEXT         NULL,
  link        VARCHAR(255) NULL,
  project_id  INT UNSIGNED NULL,
  process_id  INT UNSIGNED NULL,
  data_json   JSON         NULL,
  dedupe_key  VARCHAR(191) NULL,
  is_read     TINYINT(1)   NOT NULL DEFAULT 0,
  read_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_dedupe (user_id, dedupe_key),
  KEY idx_notif_user (user_id, is_read, created_at),
  KEY idx_notif_project (project_id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_notif_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT fk_notif_process FOREIGN KEY (process_id) REFERENCES processes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Antrean email dengan retry. Kegagalan tidak menggagalkan transaksi bisnis.
CREATE TABLE IF NOT EXISTS notification_deliveries (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_id  BIGINT UNSIGNED NULL,
  user_id          INT UNSIGNED NULL,
  channel          ENUM('email') NOT NULL DEFAULT 'email',
  to_email         VARCHAR(190) NOT NULL,
  subject          VARCHAR(255) NOT NULL,
  body_html        MEDIUMTEXT   NOT NULL,
  body_text        MEDIUMTEXT   NULL,
  status           ENUM('pending','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 5,
  last_error       TEXT         NULL,
  next_attempt_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at          DATETIME     NULL,
  dedupe_key       VARCHAR(191) NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nd_dedupe (dedupe_key),
  KEY idx_nd_queue (status, next_attempt_at),
  KEY idx_nd_notification (notification_id),
  CONSTRAINT fk_nd_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL,
  CONSTRAINT fk_nd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. Audit log (append-only) & job runs
-- ---------------------------------------------------------------------
-- Tidak ada FK ke users agar log tetap utuh; user_name disimpan sebagai snapshot.
CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  user_name    VARCHAR(120) NULL,
  ip_address   VARCHAR(45)  NULL,
  user_agent   VARCHAR(255) NULL,
  action       VARCHAR(60)  NOT NULL,
  entity_type  VARCHAR(40)  NOT NULL,
  entity_id    VARCHAR(40)  NULL,
  project_id   INT UNSIGNED NULL,
  old_value    JSON         NULL,
  new_value    JSON         NULL,
  reason       TEXT         NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_al_entity (entity_type, entity_id),
  KEY idx_al_project (project_id, created_at),
  KEY idx_al_user (user_id, created_at),
  KEY idx_al_action (action, created_at),
  KEY idx_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_runs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job          VARCHAR(60)  NOT NULL,
  started_at   DATETIME     NOT NULL,
  finished_at  DATETIME     NULL,
  status       ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  message      TEXT         NULL,
  PRIMARY KEY (id),
  KEY idx_jr_job (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrasi yang sudah dijalankan (bin/migrate.php)
CREATE TABLE IF NOT EXISTS schema_migrations (
  migration    VARCHAR(190) NOT NULL,
  applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
