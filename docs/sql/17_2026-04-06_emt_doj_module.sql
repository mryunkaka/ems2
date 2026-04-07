CREATE TABLE IF NOT EXISTS emt_doj (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    cid VARCHAR(20) NOT NULL,
    target_patients INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_emt_doj_cid (cid),
    KEY idx_emt_doj_active (is_active),
    KEY idx_emt_doj_full_name (full_name),
    KEY idx_emt_doj_created_by (created_by),
    CONSTRAINT fk_emt_doj_created_by
        FOREIGN KEY (created_by) REFERENCES user_rh (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT chk_emt_doj_target_patients
        CHECK (target_patients > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emt_doj_deliveries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    emt_id INT UNSIGNED NOT NULL,
    unit_code ENUM('roxwood', 'alta') NOT NULL,
    input_by_user_id INT NOT NULL,
    input_by_name_snapshot VARCHAR(150) NOT NULL,
    delivered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emt_doj_deliveries_emt_id (emt_id),
    KEY idx_emt_doj_deliveries_unit_code (unit_code),
    KEY idx_emt_doj_deliveries_input_by (input_by_user_id),
    KEY idx_emt_doj_deliveries_updated_by (updated_by),
    KEY idx_emt_doj_deliveries_delivered_at (delivered_at),
    CONSTRAINT fk_emt_doj_deliveries_emt
        FOREIGN KEY (emt_id) REFERENCES emt_doj (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_emt_doj_deliveries_input_by
        FOREIGN KEY (input_by_user_id) REFERENCES user_rh (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_emt_doj_deliveries_updated_by
        FOREIGN KEY (updated_by) REFERENCES user_rh (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jika tabel `emt_doj_deliveries` sudah terlanjur ada sebelum versi audit editor,
-- jalankan perintah berikut satu kali secara manual:
--
-- ALTER TABLE emt_doj_deliveries
--     ADD COLUMN updated_by INT NULL DEFAULT NULL AFTER delivered_at,
--     ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_by,
--     ADD KEY idx_emt_doj_deliveries_updated_by (updated_by),
--     ADD CONSTRAINT fk_emt_doj_deliveries_updated_by
--         FOREIGN KEY (updated_by) REFERENCES user_rh (id)
--         ON UPDATE CASCADE
--         ON DELETE SET NULL;
