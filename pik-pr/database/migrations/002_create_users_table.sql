-- User aplikasi. Password selalu disimpan sebagai hash (password_hash), tidak pernah plaintext.
CREATE TABLE users (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(100)    NOT NULL,
    email          VARCHAR(190)    NOT NULL,
    password_hash  VARCHAR(255)    NOT NULL,
    role           ENUM('super_admin', 'admin', 'approver', 'requester') NOT NULL DEFAULT 'requester',
    department_id  BIGINT UNSIGNED NULL,
    job_title      VARCHAR(100)    NULL,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at  DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_department (department_id),
    KEY idx_users_active (is_active),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id)
        REFERENCES departments (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
