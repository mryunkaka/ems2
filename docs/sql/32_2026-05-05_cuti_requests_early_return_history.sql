-- =====================================================
-- CUTI REQUESTS EARLY RETURN HISTORY
-- Simpan jejak kembali kerja langsung pada record pengajuan cuti
-- =====================================================

SET @actual_end_date_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cuti_requests'
      AND COLUMN_NAME = 'actual_end_date'
);
SET @actual_end_date_sql := IF(
    @actual_end_date_exists = 0,
    "ALTER TABLE cuti_requests ADD COLUMN actual_end_date DATE NULL AFTER end_date",
    "SELECT 'Column actual_end_date already exists' AS message"
);
PREPARE stmt_actual_end_date FROM @actual_end_date_sql;
EXECUTE stmt_actual_end_date;
DEALLOCATE PREPARE stmt_actual_end_date;

SET @returned_to_work_at_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cuti_requests'
      AND COLUMN_NAME = 'returned_to_work_at'
);
SET @returned_to_work_at_sql := IF(
    @returned_to_work_at_exists = 0,
    "ALTER TABLE cuti_requests ADD COLUMN returned_to_work_at DATETIME NULL AFTER approved_at",
    "SELECT 'Column returned_to_work_at already exists' AS message"
);
PREPARE stmt_returned_to_work_at FROM @returned_to_work_at_sql;
EXECUTE stmt_returned_to_work_at;
DEALLOCATE PREPARE stmt_returned_to_work_at;

SET @returned_to_work_by_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cuti_requests'
      AND COLUMN_NAME = 'returned_to_work_by'
);
SET @returned_to_work_by_sql := IF(
    @returned_to_work_by_exists = 0,
    "ALTER TABLE cuti_requests ADD COLUMN returned_to_work_by INT NULL AFTER returned_to_work_at",
    "SELECT 'Column returned_to_work_by already exists' AS message"
);
PREPARE stmt_returned_to_work_by FROM @returned_to_work_by_sql;
EXECUTE stmt_returned_to_work_by;
DEALLOCATE PREPARE stmt_returned_to_work_by;

SET @actual_days_used_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cuti_requests'
      AND COLUMN_NAME = 'actual_days_used'
);
SET @actual_days_used_sql := IF(
    @actual_days_used_exists = 0,
    "ALTER TABLE cuti_requests ADD COLUMN actual_days_used INT NULL AFTER days_total",
    "SELECT 'Column actual_days_used already exists' AS message"
);
PREPARE stmt_actual_days_used FROM @actual_days_used_sql;
EXECUTE stmt_actual_days_used;
DEALLOCATE PREPARE stmt_actual_days_used;

SET @idx_cuti_requests_returned_to_work_at_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cuti_requests'
      AND INDEX_NAME = 'idx_cuti_requests_returned_to_work_at'
);
SET @idx_cuti_requests_returned_to_work_at_sql := IF(
    @idx_cuti_requests_returned_to_work_at_exists = 0,
    "CREATE INDEX idx_cuti_requests_returned_to_work_at ON cuti_requests(returned_to_work_at)",
    "SELECT 'Index idx_cuti_requests_returned_to_work_at already exists' AS message"
);
PREPARE stmt_idx_cuti_requests_returned_to_work_at FROM @idx_cuti_requests_returned_to_work_at_sql;
EXECUTE stmt_idx_cuti_requests_returned_to_work_at;
DEALLOCATE PREPARE stmt_idx_cuti_requests_returned_to_work_at;
