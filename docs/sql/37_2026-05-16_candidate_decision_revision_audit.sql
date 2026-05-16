START TRANSACTION;

SET @schema_name = DATABASE();

SET @has_revised_by = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND COLUMN_NAME = 'revised_by'
);
SET @sql_add_revised_by = IF(
    @has_revised_by = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD COLUMN `revised_by` VARCHAR(150) DEFAULT NULL AFTER `decided_by`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_revised_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_linked_user_id = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND COLUMN_NAME = 'linked_user_id'
);
SET @sql_add_linked_user_id = IF(
    @has_linked_user_id = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD COLUMN `linked_user_id` INT NULL DEFAULT NULL AFTER `recommended_batch`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_linked_user_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_revised_at = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND COLUMN_NAME = 'revised_at'
);
SET @sql_add_revised_at = IF(
    @has_revised_at = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD COLUMN `revised_at` DATETIME DEFAULT NULL AFTER `revised_by`",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_revised_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_linked_user_id_idx = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_final_decisions'
      AND INDEX_NAME = 'idx_applicant_final_decisions_linked_user'
);
SET @sql_add_linked_user_id_idx = IF(
    @has_linked_user_id_idx = 0,
    "ALTER TABLE `applicant_final_decisions`
        ADD KEY `idx_applicant_final_decisions_linked_user` (`linked_user_id`)",
    "SELECT 1"
);
PREPARE stmt FROM @sql_add_linked_user_id_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
