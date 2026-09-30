CREATE TABLE items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code           VARCHAR(30)     NOT NULL,
    name           VARCHAR(150)    NOT NULL,
    description    TEXT            NULL,
    unit           VARCHAR(20)     NOT NULL DEFAULT 'pcs',
    default_price  DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_items_code (code),
    KEY idx_items_name (name),
    KEY idx_items_active (is_active),
    CONSTRAINT chk_items_default_price CHECK (default_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
