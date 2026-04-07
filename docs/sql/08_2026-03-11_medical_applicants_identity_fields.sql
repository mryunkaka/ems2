-- Recruitment applicant identity fields
-- Add citizen_id and jenis_kelamin to medical_applicants for public recruitment and auto-create user_rh

START TRANSACTION;

SET @schema_name = DATABASE();

SET @has_citizen_id = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'medical_applicants'
      AND COLUMN_NAME = 'citizen_id'
);

SET @sql_add_citizen_id = IF(
    @has_citizen_id = 0,
    "ALTER TABLE `medical_applicants` ADD COLUMN `citizen_id` VARCHAR(50) DEFAULT NULL AFTER `ic_name`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_citizen_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_jenis_kelamin = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'medical_applicants'
      AND COLUMN_NAME = 'jenis_kelamin'
);

SET @sql_add_jenis_kelamin = IF(
    @has_jenis_kelamin = 0,
    "ALTER TABLE `medical_applicants` ADD COLUMN `jenis_kelamin` ENUM('Laki-laki','Perempuan') DEFAULT NULL AFTER `citizen_id`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_jenis_kelamin;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_citizen_id = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'medical_applicants'
      AND INDEX_NAME = 'idx_medical_applicants_citizen_id'
);

SET @sql_add_idx_citizen_id = IF(
    @has_idx_citizen_id = 0,
    "ALTER TABLE `medical_applicants` ADD KEY `idx_medical_applicants_citizen_id` (`citizen_id`)",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_idx_citizen_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
