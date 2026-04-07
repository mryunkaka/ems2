START TRANSACTION;

SET @schema_name = DATABASE();

SET @has_recruitment_type = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'medical_applicants'
      AND COLUMN_NAME = 'recruitment_type'
);
SET @sql_add_recruitment_type = IF(
    @has_recruitment_type = 0,
    "ALTER TABLE `medical_applicants`
        ADD COLUMN `recruitment_type` ENUM('medical_candidate','assistant_manager') NOT NULL DEFAULT 'medical_candidate' AFTER `duty_duration`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_recruitment_type;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_target_role = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'medical_applicants'
      AND COLUMN_NAME = 'target_role'
);
SET @sql_add_target_role = IF(
    @has_target_role = 0,
    "ALTER TABLE `medical_applicants`
        ADD COLUMN `target_role` VARCHAR(100) DEFAULT NULL AFTER `recruitment_type`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_target_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_target_division = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'medical_applicants'
      AND COLUMN_NAME = 'target_division'
);
SET @sql_add_target_division = IF(
    @has_target_division = 0,
    "ALTER TABLE `medical_applicants`
        ADD COLUMN `target_division` VARCHAR(150) DEFAULT NULL AFTER `target_role`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_target_division;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_medical_applicant_type_idx = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'medical_applicants'
      AND INDEX_NAME = 'idx_medical_applicants_recruitment_type'
);
SET @sql_add_medical_applicant_type_idx = IF(
    @has_medical_applicant_type_idx = 0,
    "ALTER TABLE `medical_applicants`
        ADD KEY `idx_medical_applicants_recruitment_type` (`recruitment_type`)",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_medical_applicant_type_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_criteria_recruitment_type = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'interview_criteria'
      AND COLUMN_NAME = 'recruitment_type'
);
SET @sql_add_criteria_recruitment_type = IF(
    @has_criteria_recruitment_type = 0,
    "ALTER TABLE `interview_criteria`
        ADD COLUMN `recruitment_type` ENUM('all','medical_candidate','assistant_manager') NOT NULL DEFAULT 'all' AFTER `is_active`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_criteria_recruitment_type;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `interview_criteria`
SET `recruitment_type` = 'medical_candidate'
WHERE `code` IN ('attitude', 'communication', 'responsibility', 'leadership', 'loyalty', 'discipline', 'stress_control', 'teamwork');

INSERT INTO `interview_criteria` (`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT 'sop_compliance', 'Kepatuhan SOP', 'Kemampuan menjaga prosedur tetap berjalan konsisten saat tekanan operasional meningkat', 2, 1, 'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1 FROM `interview_criteria` WHERE `code` = 'sop_compliance'
);

INSERT INTO `interview_criteria` (`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT 'ga_coordination', 'Koordinasi General Affair', 'Kemampuan menjembatani kebutuhan pimpinan, divisi lain, dan tim lapangan', 2, 1, 'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1 FROM `interview_criteria` WHERE `code` = 'ga_coordination'
);

INSERT INTO `interview_criteria` (`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT 'operational_control', 'Kontrol Operasional', 'Kemampuan memantau fasilitas, tindak lanjut, laporan, dan detail operasional secara rapi', 2, 1, 'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1 FROM `interview_criteria` WHERE `code` = 'operational_control'
);

SET @has_decision_recommended_role = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND COLUMN_NAME = 'recommended_role'
);
SET @sql_add_decision_recommended_role = IF(
    @has_decision_recommended_role = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD COLUMN `recommended_role` VARCHAR(100) DEFAULT NULL AFTER `final_result`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_decision_recommended_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_decision_recommended_division = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND COLUMN_NAME = 'recommended_division'
);
SET @sql_add_decision_recommended_division = IF(
    @has_decision_recommended_division = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD COLUMN `recommended_division` VARCHAR(150) DEFAULT NULL AFTER `recommended_role`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_decision_recommended_division;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_decision_recommended_position = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND COLUMN_NAME = 'recommended_position'
);
SET @sql_add_decision_recommended_position = IF(
    @has_decision_recommended_position = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD COLUMN `recommended_position` VARCHAR(100) DEFAULT NULL AFTER `recommended_division`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_decision_recommended_position;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_decision_recommended_batch = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND COLUMN_NAME = 'recommended_batch'
);
SET @sql_add_decision_recommended_batch = IF(
    @has_decision_recommended_batch = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD COLUMN `recommended_batch` INT NULL AFTER `recommended_position`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_decision_recommended_batch;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @current_role_column = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'role'
    LIMIT 1
);
SET @sql_alter_user_role = IF(
    @current_role_column LIKE '%Probation Manager%',
    "SELECT 1",
    "ALTER TABLE `user_rh`
        MODIFY COLUMN `role` ENUM('Staff','Probation Manager','Staff Manager','Manager','Assisten Manager','Lead Manager','Head Manager','Vice Director','Director') NOT NULL DEFAULT 'Staff'"
);
PREPARE stmt FROM @sql_alter_user_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
