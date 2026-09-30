-- Audit trail. Aplikasi hanya melakukan INSERT dan SELECT pada tabel ini.
-- Untuk proteksi di level database, lihat database/hardening/audit_logs_protection.sql.
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NULL,
    action       VARCHAR(60)     NOT NULL,
    entity_type  VARCHAR(60)     NOT NULL,
    entity_id    BIGINT UNSIGNED NULL,
    old_values   JSON            NULL,
    new_values   JSON            NULL,
    ip_address   VARCHAR(45)     NULL,
    user_agent   VARCHAR(255)    NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_created_at (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
