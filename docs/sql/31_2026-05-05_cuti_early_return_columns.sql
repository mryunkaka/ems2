-- =====================================================
-- CUTI EARLY RETURN COLUMNS
-- Menyimpan siapa yang mengakhiri cuti lebih cepat dan kapan efektif kembali kerja
-- =====================================================

SET @cuti_ended_at_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'cuti_ended_at'
);
SET @cuti_ended_at_sql := IF(
    @cuti_ended_at_exists = 0,
    "ALTER TABLE user_rh ADD COLUMN cuti_ended_at DATETIME NULL AFTER cuti_approved_at",
    "SELECT 'Column cuti_ended_at already exists' AS message"
);
PREPARE stmt_cuti_ended_at FROM @cuti_ended_at_sql;
EXECUTE stmt_cuti_ended_at;
DEALLOCATE PREPARE stmt_cuti_ended_at;

SET @cuti_ended_by_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'cuti_ended_by'
);
SET @cuti_ended_by_sql := IF(
    @cuti_ended_by_exists = 0,
    "ALTER TABLE user_rh ADD COLUMN cuti_ended_by INT NULL AFTER cuti_ended_at",
    "SELECT 'Column cuti_ended_by already exists' AS message"
);
PREPARE stmt_cuti_ended_by FROM @cuti_ended_by_sql;
EXECUTE stmt_cuti_ended_by;
DEALLOCATE PREPARE stmt_cuti_ended_by;

SET @cuti_original_days_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'cuti_original_days'
);
SET @cuti_original_days_sql := IF(
    @cuti_original_days_exists = 0,
    "ALTER TABLE user_rh ADD COLUMN cuti_original_days INT NULL AFTER cuti_days_total",
    "SELECT 'Column cuti_original_days already exists' AS message"
);
PREPARE stmt_cuti_original_days FROM @cuti_original_days_sql;
EXECUTE stmt_cuti_original_days;
DEALLOCATE PREPARE stmt_cuti_original_days;

SET @idx_cuti_status_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND INDEX_NAME = 'idx_cuti_status'
);
SET @idx_cuti_status_sql := IF(
    @idx_cuti_status_exists = 0,
    "CREATE INDEX idx_cuti_status ON user_rh(cuti_status)",
    "SELECT 'Index idx_cuti_status already exists' AS message"
);
PREPARE stmt_idx_cuti_status FROM @idx_cuti_status_sql;
EXECUTE stmt_idx_cuti_status;
DEALLOCATE PREPARE stmt_idx_cuti_status;

SET @idx_cuti_ended_at_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND INDEX_NAME = 'idx_cuti_ended_at'
);
SET @idx_cuti_ended_at_sql := IF(
    @idx_cuti_ended_at_exists = 0,
    "CREATE INDEX idx_cuti_ended_at ON user_rh(cuti_ended_at)",
    "SELECT 'Index idx_cuti_ended_at already exists' AS message"
);
PREPARE stmt_idx_cuti_ended_at FROM @idx_cuti_ended_at_sql;
EXECUTE stmt_idx_cuti_ended_at;
DEALLOCATE PREPARE stmt_idx_cuti_ended_at;
