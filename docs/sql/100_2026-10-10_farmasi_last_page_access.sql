-- Track the most recent visit to the Pharmacy web page independently from duty sessions.
SET @has_farmasi_web_login_at = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'last_farmasi_web_login_at'
);
SET @sql = IF(@has_farmasi_web_login_at = 0,
    'ALTER TABLE user_rh ADD COLUMN last_farmasi_web_login_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_farmasi_sessions = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_farmasi_sessions'
);
SET @has_farmasi_session_start = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_farmasi_sessions'
      AND COLUMN_NAME = 'session_start'
);
SET @sql = IF(@has_farmasi_sessions > 0 AND @has_farmasi_session_start > 0,
    'UPDATE user_rh u SET u.last_farmasi_web_login_at = (SELECT MAX(fs.session_start) FROM user_farmasi_sessions fs WHERE fs.user_id = u.id) WHERE u.last_farmasi_web_login_at IS NULL AND EXISTS (SELECT 1 FROM user_farmasi_sessions fs WHERE fs.user_id = u.id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_farmasi_web_login_idx = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND INDEX_NAME = 'idx_user_rh_farmasi_web_login'
);
SET @sql = IF(@has_farmasi_web_login_idx = 0,
    'CREATE INDEX idx_user_rh_farmasi_web_login ON user_rh (last_farmasi_web_login_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
