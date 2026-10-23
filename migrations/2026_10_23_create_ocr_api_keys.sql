CREATE TABLE IF NOT EXISTS ocr_api_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(100) NOT NULL,
    encrypted_key TEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_tested_at DATETIME NULL,
    last_test_status VARCHAR(24) NULL,
    last_error_category VARCHAR(32) NULL,
    last_error_at DATETIME NULL,
    last_success_at DATETIME NULL,
    count_engine1 INT UNSIGNED NULL,
    count_engine2 INT UNSIGNED NULL,
    count_engine3 INT UNSIGNED NULL,
    usage_checked_at DATETIME NULL,
    last_used_at DATETIME(6) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ocr_api_keys_enabled (enabled, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ocr_api_key_rotation (
    id TINYINT UNSIGNED NOT NULL,
    next_key_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ocr_api_key_rotation (id, next_key_id) VALUES (1, NULL);

CREATE TABLE IF NOT EXISTS ocr_api_key_bootstrap (
    id TINYINT UNSIGNED NOT NULL,
    legacy_imported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ocr_api_key_bootstrap (id, legacy_imported) VALUES (1, 0);
