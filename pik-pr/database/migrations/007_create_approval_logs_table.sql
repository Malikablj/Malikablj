-- Riwayat keputusan approval. Tidak pernah dihapus, termasuk saat PR direvisi:
-- setiap pengajuan ulang menaikkan submission_round sehingga riwayat lama tetap ada.
CREATE TABLE approval_logs (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    pr_id             BIGINT UNSIGNED  NOT NULL,
    step_id           BIGINT UNSIGNED  NOT NULL,
    submission_round  INT UNSIGNED     NOT NULL,
    step_order        TINYINT UNSIGNED NOT NULL,
    step_label        VARCHAR(50)      NOT NULL,
    approver_id       BIGINT UNSIGNED  NOT NULL,
    action            ENUM('approved', 'rejected', 'revision_required') NOT NULL,
    comment           TEXT             NULL,
    status_after      ENUM('draft', 'submitted', 'in_review', 'revision_required',
                           'rejected', 'approved', 'completed', 'cancelled') NOT NULL,
    acted_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_approval_logs_round_step (pr_id, submission_round, step_order),
    KEY idx_approval_logs_approver (approver_id),
    KEY idx_approval_logs_step (step_id),
    KEY idx_approval_logs_acted_at (acted_at),
    CONSTRAINT fk_approval_logs_pr FOREIGN KEY (pr_id)
        REFERENCES purchase_requisitions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_logs_step FOREIGN KEY (step_id)
        REFERENCES approval_steps (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_logs_approver FOREIGN KEY (approver_id)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
