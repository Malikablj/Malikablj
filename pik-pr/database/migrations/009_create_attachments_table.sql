-- Lampiran PR. File fisik disimpan di storage/ (di luar public) dengan nama acak (stored_name).
CREATE TABLE attachments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pr_id          BIGINT UNSIGNED NOT NULL,
    original_name  VARCHAR(255)    NOT NULL,
    stored_name    VARCHAR(100)    NOT NULL,
    mime_type      VARCHAR(100)    NOT NULL,
    size_bytes     INT UNSIGNED    NOT NULL,
    path           VARCHAR(255)    NOT NULL,
    uploaded_by    BIGINT UNSIGNED NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachments_stored_name (stored_name),
    KEY idx_attachments_pr (pr_id),
    KEY idx_attachments_uploaded_by (uploaded_by),
    CONSTRAINT fk_attachments_pr FOREIGN KEY (pr_id)
        REFERENCES purchase_requisitions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_attachments_user FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_attachments_size CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
