-- Add temporary recruitment role for Human Resource interview/training access.
-- Required before saving user_rh.role = 'INTERVIEWER & TRAINER' from Manajemen User.

SET @schema_name = DATABASE();

SET @current_role_column = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'role'
    LIMIT 1
);

SET @sql_alter_user_role = IF(
    @current_role_column LIKE '%INTERVIEWER & TRAINER%',
    'SELECT 1',
    "ALTER TABLE `user_rh`
        MODIFY COLUMN `role` ENUM('Staff','INTERVIEWER & TRAINER','Probation Manager','Staff Manager','Manager','Assisten Manager','Lead Manager','Head Manager','Vice Director','Director') NOT NULL DEFAULT 'Staff'"
);
PREPARE stmt FROM @sql_alter_user_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
