-- Store the manager's human-facing reply when approving a leave request.
SET @db_name = DATABASE();
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'cuti_requests'
    AND COLUMN_NAME = 'manager_approval_reply'
);
SET @ddl = IF(@column_exists = 0,
  'ALTER TABLE `cuti_requests` ADD COLUMN `manager_approval_reply` TEXT NULL AFTER `reason_ooc`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
