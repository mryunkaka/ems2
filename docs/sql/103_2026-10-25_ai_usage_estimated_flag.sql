-- Distinguish token counts reported by providers from local estimates.
SET @has_usage_estimated = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'system_ai_request_logs'
      AND COLUMN_NAME = 'usage_estimated'
);
SET @sql = IF(
    @has_usage_estimated = 0,
    'ALTER TABLE system_ai_request_logs ADD COLUMN usage_estimated TINYINT(1) NOT NULL DEFAULT 0 AFTER total_tokens',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
