CREATE TABLE IF NOT EXISTS disciplinary_point_reduction_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_user_id INT NOT NULL,
    related_case_id BIGINT UNSIGNED DEFAULT NULL,
    reduction_type VARCHAR(100) NOT NULL,
    reduction_points INT NOT NULL DEFAULT 0,
    activity_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    submitted_by INT NOT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dpr_requests_subject_user_id (subject_user_id),
    KEY idx_dpr_requests_related_case_id (related_case_id),
    KEY idx_dpr_requests_status (status),
    KEY idx_dpr_requests_submitted_by (submitted_by),
    KEY idx_dpr_requests_reviewed_by (reviewed_by),
    CONSTRAINT fk_dpr_requests_subject_user
        FOREIGN KEY (subject_user_id) REFERENCES user_rh (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dpr_requests_related_case
        FOREIGN KEY (related_case_id) REFERENCES disciplinary_cases (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_dpr_requests_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES user_rh (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_dpr_requests_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES user_rh (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
