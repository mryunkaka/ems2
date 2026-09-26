-- Simpan alasan dan pelaku setiap perubahan status manual dari HR/manager.
SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cuti_requests' AND COLUMN_NAME = 'status_change_reason') = 0,
    'ALTER TABLE cuti_requests ADD COLUMN status_change_reason TEXT NULL AFTER rejection_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cuti_requests' AND COLUMN_NAME = 'status_changed_by') = 0,
    'ALTER TABLE cuti_requests ADD COLUMN status_changed_by INT NULL AFTER status_change_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cuti_requests' AND COLUMN_NAME = 'status_changed_at') = 0,
    'ALTER TABLE cuti_requests ADD COLUMN status_changed_at DATETIME NULL AFTER status_changed_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resign_requests' AND COLUMN_NAME = 'status_change_reason') = 0,
    'ALTER TABLE resign_requests ADD COLUMN status_change_reason TEXT NULL AFTER rejection_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resign_requests' AND COLUMN_NAME = 'status_changed_by') = 0,
    'ALTER TABLE resign_requests ADD COLUMN status_changed_by INT NULL AFTER status_change_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resign_requests' AND COLUMN_NAME = 'status_changed_at') = 0,
    'ALTER TABLE resign_requests ADD COLUMN status_changed_at DATETIME NULL AFTER status_changed_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
