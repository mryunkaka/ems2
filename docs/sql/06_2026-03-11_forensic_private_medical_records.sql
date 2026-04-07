-- Forensic private medical records
-- Extend medical_records for forensic-private scope and link forensic cases to medical records
-- Compatible with MySQL versions that do not support ALTER TABLE ... ADD COLUMN IF NOT EXISTS

START TRANSACTION;

SET @db_name = DATABASE();

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'medical_records'
          AND COLUMN_NAME = 'record_code'
    ),
    'SELECT 1',
    'ALTER TABLE `medical_records` ADD COLUMN `record_code` VARCHAR(100) DEFAULT NULL AFTER `id`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'medical_records'
          AND COLUMN_NAME = 'patient_citizen_id'
    ),
    'SELECT 1',
    'ALTER TABLE `medical_records` ADD COLUMN `patient_citizen_id` VARCHAR(50) DEFAULT NULL AFTER `patient_name`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'medical_records'
          AND COLUMN_NAME = 'visibility_scope'
    ),
    'SELECT 1',
    'ALTER TABLE `medical_records` ADD COLUMN `visibility_scope` ENUM(''standard'', ''forensic_private'') NOT NULL DEFAULT ''standard'' AFTER `operasi_type`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `medical_records`
SET `record_code` = CONCAT('MR-', LPAD(`id`, 6, '0'))
WHERE (`record_code` IS NULL OR `record_code` = '');

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'medical_records'
          AND INDEX_NAME = 'uniq_medical_records_record_code'
    ),
    'SELECT 1',
    'ALTER TABLE `medical_records` ADD UNIQUE KEY `uniq_medical_records_record_code` (`record_code`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'medical_records'
          AND INDEX_NAME = 'idx_medical_records_visibility_scope'
    ),
    'SELECT 1',
    'ALTER TABLE `medical_records` ADD KEY `idx_medical_records_visibility_scope` (`visibility_scope`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'medical_records'
          AND INDEX_NAME = 'idx_medical_records_patient_citizen_id'
    ),
    'SELECT 1',
    'ALTER TABLE `medical_records` ADD KEY `idx_medical_records_patient_citizen_id` (`patient_citizen_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'forensic_private_patients'
          AND COLUMN_NAME = 'medical_record_id'
    ),
    'SELECT 1',
    'ALTER TABLE `forensic_private_patients` ADD COLUMN `medical_record_id` INT DEFAULT NULL AFTER `medical_record_no`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'forensic_private_patients'
          AND INDEX_NAME = 'idx_forensic_private_patients_medical_record'
    ),
    'SELECT 1',
    'ALTER TABLE `forensic_private_patients` ADD KEY `idx_forensic_private_patients_medical_record` (`medical_record_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
