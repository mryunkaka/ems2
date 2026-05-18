CREATE TABLE IF NOT EXISTS disciplinary_case_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_disciplinary_case_attachments_case_id (case_id),
    CONSTRAINT fk_disciplinary_case_attachments_case
        FOREIGN KEY (case_id) REFERENCES disciplinary_cases (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS disciplinary_warning_letter_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    warning_letter_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_disciplinary_warning_letter_attachments_letter_id (warning_letter_id),
    CONSTRAINT fk_disciplinary_warning_letter_attachments_letter
        FOREIGN KEY (warning_letter_id) REFERENCES disciplinary_warning_letters (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
