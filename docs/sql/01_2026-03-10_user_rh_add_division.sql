-- Simple user_rh update
-- 1. Add division column
-- 2. Rename legacy roles
-- 3. Fill division values

START TRANSACTION;

SET @sql = IF (
  EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'division'
  ),
  'SELECT 1',
  'ALTER TABLE `user_rh` ADD COLUMN `division` VARCHAR(150) NULL AFTER `role`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `user_rh`
MODIFY COLUMN `role` ENUM(
  'Staff',
  'Staff Manager',
  'Manager',
  'Assisten Manager',
  'Lead Manager',
  'Head Manager',
  'Vice Director',
  'Director'
) NOT NULL DEFAULT 'Staff';

UPDATE `user_rh`
SET `role` = 'Assisten Manager'
WHERE LOWER(TRIM(`role`)) IN ('staff manager', 'assistant manager', 'assisten manager');

UPDATE `user_rh`
SET `role` = 'Lead Manager'
WHERE LOWER(TRIM(`role`)) = 'lead manager';

UPDATE `user_rh`
SET `role` = 'Head Manager'
WHERE LOWER(TRIM(`role`)) IN ('manager', 'head manager');

UPDATE `user_rh`
SET `division` = 'Executive'
WHERE LOWER(TRIM(`role`)) IN ('director', 'vice director')
  AND (`division` IS NULL OR TRIM(`division`) = '');

UPDATE `user_rh`
SET `division` = 'Secretary'
WHERE LOWER(TRIM(`role`)) IN ('assisten manager', 'lead manager', 'head manager')
  AND (`division` IS NULL OR TRIM(`division`) = '');

UPDATE `user_rh`
SET `division` = 'Human Resource'
WHERE LOWER(TRIM(`role`)) = 'staff'
  AND (`division` IS NULL OR TRIM(`division`) = '');

COMMIT;
