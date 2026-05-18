CREATE TABLE IF NOT EXISTS medical_record_supporting_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    medical_record_id INT(11) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sort_order INT(11) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mrsi_record_id (medical_record_id),
    KEY idx_mrsi_sort_order (sort_order),
    CONSTRAINT fk_mrsi_record
        FOREIGN KEY (medical_record_id) REFERENCES medical_records(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
