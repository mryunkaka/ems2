-- EMS2
-- State inbox virtual per user untuk surat masuk dan notulen per divisi
-- Date: 2026-03-17

CREATE TABLE IF NOT EXISTS user_inbox_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(32) NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_inbox_state (user_id, item_type, item_id),
    KEY idx_user_inbox_state_user (user_id, is_deleted, is_read),
    KEY idx_user_inbox_state_item (item_type, item_id)
);
