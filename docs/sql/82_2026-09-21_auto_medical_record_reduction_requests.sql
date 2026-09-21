SET @has_medical_record_id = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'disciplinary_point_reduction_requests'
      AND COLUMN_NAME = 'medical_record_id'
);
SET @sql = IF(
    @has_medical_record_id = 0,
    'ALTER TABLE disciplinary_point_reduction_requests ADD COLUMN medical_record_id INT NULL AFTER subject_user_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_source_key = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'disciplinary_point_reduction_requests'
      AND INDEX_NAME = 'uniq_dpr_requests_medical_source'
);
SET @sql = IF(
    @has_source_key = 0,
    'ALTER TABLE disciplinary_point_reduction_requests ADD UNIQUE KEY uniq_dpr_requests_medical_source (medical_record_id, subject_user_id, reduction_type)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_source_fk = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'disciplinary_point_reduction_requests'
      AND CONSTRAINT_NAME = 'fk_dpr_requests_medical_record'
);
SET @sql = IF(
    @has_source_fk = 0,
    'ALTER TABLE disciplinary_point_reduction_requests ADD CONSTRAINT fk_dpr_requests_medical_record FOREIGN KEY (medical_record_id) REFERENCES medical_records(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
