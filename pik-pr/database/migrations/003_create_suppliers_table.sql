CREATE TABLE suppliers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(20)     NOT NULL,
    name        VARCHAR(150)    NOT NULL,
    contact     VARCHAR(150)    NULL,
    address     TEXT            NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_code (code),
    KEY idx_suppliers_name (name),
    KEY idx_suppliers_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
