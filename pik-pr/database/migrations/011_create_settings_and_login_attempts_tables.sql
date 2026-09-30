CREATE TABLE settings (
    setting_key    VARCHAR(100)    NOT NULL,
    setting_value  TEXT            NULL,
    updated_by     BIGINT UNSIGNED NULL,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('company_name', 'PT PERMATA INDO KEMAS'),
    ('company_address', ''),
    ('pr_prefix', 'PR/PIK'),
    ('default_tax_rate', '0.00');

-- Pembatasan percobaan login (brute-force protection).
CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190)    NOT NULL,
    ip_address    VARCHAR(45)     NOT NULL,
    attempted_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email (email, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
