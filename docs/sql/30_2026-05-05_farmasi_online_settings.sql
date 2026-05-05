-- =====================================================
-- FARMASI ONLINE SETTINGS
-- Setting untuk batasan medis online, waktu jaga, dan cooldown
-- =====================================================

CREATE TABLE IF NOT EXISTS farmasi_online_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    max_online_medics INT DEFAULT 0 COMMENT 'Maksimal jumlah medis online (0 = tidak ada batasan)',
    max_duty_minutes INT DEFAULT 0 COMMENT 'Maksimal waktu jaga dalam menit (0 = tidak ada batasan)',
    cooldown_minutes INT DEFAULT 0 COMMENT 'Cooldown per user dalam menit (0 = tidak ada cooldown)',
    updated_by INT DEFAULT NULL COMMENT 'User ID yang terakhir mengupdate',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default row (hanya 1 row yang digunakan)
INSERT INTO farmasi_online_settings (max_online_medics, max_duty_minutes, cooldown_minutes)
SELECT 0, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM farmasi_online_settings LIMIT 1);

-- Tambahkan kolom session_number untuk tracking reset timer
-- Kolom ini menyimpan nomor sesi aktif, increment setiap kali online
SET @session_number_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_farmasi_sessions'
      AND COLUMN_NAME = 'session_number'
);
SET @session_number_sql := IF(
    @session_number_exists = 0,
    "ALTER TABLE user_farmasi_sessions ADD COLUMN session_number INT DEFAULT 1 COMMENT 'Nomor sesi untuk tracking reset timer'",
    "SELECT 'Column session_number already exists' AS message"
);
PREPARE stmt_session_number FROM @session_number_sql;
EXECUTE stmt_session_number;
DEALLOCATE PREPARE stmt_session_number;

-- Update trigger atau flag untuk menandai sesi baru
-- Kolom ini digunakan untuk menandai apakah sesi ini adalah sesi baru (reset timer)
SET @current_session_number_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_farmasi_status'
      AND COLUMN_NAME = 'current_session_number'
);
SET @current_session_number_sql := IF(
    @current_session_number_exists = 0,
    "ALTER TABLE user_farmasi_status ADD COLUMN current_session_number INT DEFAULT 0 COMMENT 'Nomor sesi aktif saat ini'",
    "SELECT 'Column current_session_number already exists' AS message"
);
PREPARE stmt_current_session_number FROM @current_session_number_sql;
EXECUTE stmt_current_session_number;
DEALLOCATE PREPARE stmt_current_session_number;
