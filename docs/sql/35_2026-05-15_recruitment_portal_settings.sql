CREATE TABLE IF NOT EXISTS recruitment_portal_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    closed_message TEXT NULL,
    updated_by_user_id INT(11) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO recruitment_portal_settings (id, is_open, closed_message, updated_by_user_id)
VALUES (
    1,
    1,
    'Pendaftaran Medis Roxwood saat ini belum dibuka. Silakan menunggu informasi selanjutnya.',
    NULL
)
ON DUPLICATE KEY UPDATE id = id;
