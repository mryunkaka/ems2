CREATE TABLE IF NOT EXISTS disciplinary_point_reductions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_user_id INT NOT NULL,
    related_case_id BIGINT UNSIGNED DEFAULT NULL,
    reduction_type VARCHAR(100) NOT NULL,
    reduction_points INT NOT NULL DEFAULT 0,
    activity_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_disciplinary_point_reductions_subject_user_id (subject_user_id),
    KEY idx_disciplinary_point_reductions_related_case_id (related_case_id),
    KEY idx_disciplinary_point_reductions_created_by (created_by),
    CONSTRAINT fk_disciplinary_point_reductions_subject_user
        FOREIGN KEY (subject_user_id) REFERENCES user_rh (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_disciplinary_point_reductions_related_case
        FOREIGN KEY (related_case_id) REFERENCES disciplinary_cases (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_disciplinary_point_reductions_created_by
        FOREIGN KEY (created_by) REFERENCES user_rh (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
