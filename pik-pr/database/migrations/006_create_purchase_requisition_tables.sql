-- Header PR. Nilai uang memakai DECIMAL(15,2); CHECK memastikan total selalu konsisten.
-- pr_number NULL selama draft; diterbitkan (unik) saat PR pertama kali disubmit.
CREATE TABLE purchase_requisitions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pr_number         VARCHAR(60)     NULL,
    requester_id      BIGINT UNSIGNED NOT NULL,
    department_id     BIGINT UNSIGNED NOT NULL,
    supplier_id       BIGINT UNSIGNED NULL,
    pr_date           DATE            NOT NULL,
    status            ENUM('draft', 'submitted', 'in_review', 'revision_required',
                           'rejected', 'approved', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    subtotal          DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    tax_rate          DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
    tax_amount        DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    grand_total       DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    notes             TEXT            NULL,
    workflow_id       BIGINT UNSIGNED NULL,
    current_step_id   BIGINT UNSIGNED NULL,
    submission_round  INT UNSIGNED    NOT NULL DEFAULT 0,
    submitted_at      DATETIME        NULL,
    approved_at       DATETIME        NULL,
    completed_at      DATETIME        NULL,
    cancelled_at      DATETIME        NULL,
    cancel_reason     TEXT            NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_number (pr_number),
    KEY idx_pr_status (status),
    KEY idx_pr_department (department_id),
    KEY idx_pr_requester (requester_id),
    KEY idx_pr_supplier (supplier_id),
    KEY idx_pr_created_at (created_at),
    KEY idx_pr_date (pr_date),
    KEY idx_pr_current_step (current_step_id),
    KEY idx_pr_workflow (workflow_id),
    CONSTRAINT fk_pr_requester FOREIGN KEY (requester_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_department FOREIGN KEY (department_id)
        REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_workflow FOREIGN KEY (workflow_id)
        REFERENCES approval_workflows (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_current_step FOREIGN KEY (current_step_id)
        REFERENCES approval_steps (id) ON DELETE RESTRICT,
    CONSTRAINT chk_pr_amounts CHECK (
        subtotal >= 0 AND tax_amount >= 0 AND grand_total = subtotal + tax_amount
    ),
    CONSTRAINT chk_pr_tax_rate CHECK (tax_rate >= 0 AND tax_rate <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Baris item PR. item_name_snapshot menyimpan nama saat PR dibuat sehingga
-- perubahan master item tidak mengubah dokumen lama.
CREATE TABLE purchase_requisition_items (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    pr_id               BIGINT UNSIGNED   NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    item_id             BIGINT UNSIGNED   NULL,
    item_name_snapshot  VARCHAR(150)      NOT NULL,
    description         VARCHAR(500)      NULL,
    quantity            DECIMAL(12,2)     NOT NULL,
    unit                VARCHAR(20)       NOT NULL DEFAULT 'pcs',
    unit_price          DECIMAL(15,2)     NOT NULL,
    line_total          DECIMAL(15,2)     NOT NULL,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_items_line (pr_id, line_no),
    KEY idx_pr_items_item (item_id),
    CONSTRAINT fk_pr_items_pr FOREIGN KEY (pr_id)
        REFERENCES purchase_requisitions (id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_items_item FOREIGN KEY (item_id)
        REFERENCES items (id) ON DELETE RESTRICT,
    CONSTRAINT chk_pr_items_values CHECK (quantity > 0 AND unit_price >= 0 AND line_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counter nomor PR per department per tahun. Di-update dengan
-- INSERT ... ON DUPLICATE KEY UPDATE di dalam transaksi submit (row lock InnoDB),
-- sehingga aman terhadap request bersamaan.
CREATE TABLE pr_number_sequences (
    scope        VARCHAR(40)  NOT NULL,
    last_number  INT UNSIGNED NOT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
