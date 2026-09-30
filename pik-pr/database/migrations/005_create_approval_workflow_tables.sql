-- Workflow approval yang dapat dikonfigurasi per department.
-- department_id NULL = workflow default untuk department yang tidak punya workflow khusus.
-- active_scope menjamin hanya ada SATU workflow aktif per department (dan satu default).
CREATE TABLE approval_workflows (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(100)    NOT NULL,
    department_id  BIGINT UNSIGNED NULL,
    description    VARCHAR(255)    NULL,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    active_scope   BIGINT UNSIGNED GENERATED ALWAYS AS (IF(is_active = 1, IFNULL(department_id, 0), NULL)) STORED,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workflows_active_scope (active_scope),
    KEY idx_workflows_department (department_id),
    CONSTRAINT fk_workflows_department FOREIGN KEY (department_id)
        REFERENCES departments (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tahap approval. Approver ditentukan lewat aturan, bukan nama yang di-hard-code:
--   approver_type = 'user' -> user tertentu (approver_user_id)
--   approver_type = 'role' -> semua user aktif dengan role tsb, opsional dibatasi department PR
-- is_required = 0 berarti tahap kondisional: hanya berlaku bila grand total >= min_amount.
CREATE TABLE approval_steps (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    workflow_id       BIGINT UNSIGNED  NOT NULL,
    step_order        TINYINT UNSIGNED NOT NULL,
    label             VARCHAR(50)      NOT NULL,
    approver_type     ENUM('user', 'role') NOT NULL,
    approver_user_id  BIGINT UNSIGNED  NULL,
    approver_role     ENUM('super_admin', 'admin', 'approver') NULL,
    same_department   TINYINT(1)       NOT NULL DEFAULT 0,
    is_required       TINYINT(1)       NOT NULL DEFAULT 1,
    min_amount        DECIMAL(15,2)    NULL,
    created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_steps_workflow_order (workflow_id, step_order),
    KEY idx_steps_approver_user (approver_user_id),
    CONSTRAINT fk_steps_workflow FOREIGN KEY (workflow_id)
        REFERENCES approval_workflows (id) ON DELETE CASCADE,
    CONSTRAINT fk_steps_user FOREIGN KEY (approver_user_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_steps_approver CHECK (
        (approver_type = 'user' AND approver_user_id IS NOT NULL)
        OR (approver_type = 'role' AND approver_role IS NOT NULL)
    ),
    CONSTRAINT chk_steps_order CHECK (step_order >= 1),
    CONSTRAINT chk_steps_min_amount CHECK (min_amount IS NULL OR min_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
