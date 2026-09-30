CREATE TABLE notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    pr_id       BIGINT UNSIGNED NULL,
    type        VARCHAR(40)     NOT NULL,
    title       VARCHAR(150)    NOT NULL,
    message     VARCHAR(500)    NOT NULL,
    read_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read (user_id, read_at),
    KEY idx_notifications_pr (pr_id),
    KEY idx_notifications_created_at (created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_pr FOREIGN KEY (pr_id)
        REFERENCES purchase_requisitions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
