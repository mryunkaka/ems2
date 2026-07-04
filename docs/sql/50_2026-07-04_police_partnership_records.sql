CREATE TABLE IF NOT EXISTS `police_partnership_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `police_badge_no` varchar(50) NOT NULL,
  `badge_file_path` varchar(255) DEFAULT NULL,
  `action_type` varchar(100) NOT NULL,
  `treatment_detail` text DEFAULT NULL,
  `service_date` date NOT NULL,
  `service_at` datetime DEFAULT NULL,
  `input_by_user_id` int(11) DEFAULT NULL,
  `input_by_name` varchar(150) NOT NULL,
  `input_by_position` varchar(100) DEFAULT NULL,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `amount` int(11) NOT NULL DEFAULT 1000,
  `amount_updated_by` varchar(150) DEFAULT NULL,
  `amount_updated_at` datetime DEFAULT NULL,
  `pricing_mode` varchar(20) NOT NULL DEFAULT 'per_qty',
  `payment_status` varchar(20) NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `paid_by` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ppr_service_date` (`service_date`),
  KEY `idx_ppr_service_at` (`service_at`),
  KEY `idx_ppr_badge` (`police_badge_no`),
  KEY `idx_ppr_unit_date` (`unit_code`, `service_date`),
  KEY `idx_ppr_input_by_user_id` (`input_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @has_service_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'police_partnership_records'
    AND COLUMN_NAME = 'service_at'
);
SET @sql := IF(
  @has_service_at = 0,
  'ALTER TABLE `police_partnership_records` ADD COLUMN `service_at` datetime DEFAULT NULL AFTER `service_date`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_service_at_index := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'police_partnership_records'
    AND INDEX_NAME = 'idx_ppr_service_at'
);
SET @sql := IF(
  @has_service_at_index = 0,
  'ALTER TABLE `police_partnership_records` ADD KEY `idx_ppr_service_at` (`service_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `police_partnership_records`
SET `service_at` = CONCAT(`service_date`, ' 00:00:00')
WHERE `service_at` IS NULL;

SET @has_pricing_mode := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'police_partnership_records'
    AND COLUMN_NAME = 'pricing_mode'
);
SET @sql := IF(@has_pricing_mode = 0, 'ALTER TABLE `police_partnership_records` ADD COLUMN `pricing_mode` varchar(20) NOT NULL DEFAULT ''per_qty'' AFTER `amount_updated_at`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_badge_file_path := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'police_partnership_records'
    AND COLUMN_NAME = 'badge_file_path'
);
SET @sql := IF(@has_badge_file_path = 0, 'ALTER TABLE `police_partnership_records` ADD COLUMN `badge_file_path` varchar(255) DEFAULT NULL AFTER `police_badge_no`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_payment_status := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'police_partnership_records'
    AND COLUMN_NAME = 'payment_status'
);
SET @sql := IF(@has_payment_status = 0, 'ALTER TABLE `police_partnership_records` ADD COLUMN `payment_status` varchar(20) NOT NULL DEFAULT ''pending'' AFTER `pricing_mode`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_paid_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'police_partnership_records'
    AND COLUMN_NAME = 'paid_at'
);
SET @sql := IF(@has_paid_at = 0, 'ALTER TABLE `police_partnership_records` ADD COLUMN `paid_at` datetime DEFAULT NULL AFTER `payment_status`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_paid_by := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'police_partnership_records'
    AND COLUMN_NAME = 'paid_by'
);
SET @sql := IF(@has_paid_by = 0, 'ALTER TABLE `police_partnership_records` ADD COLUMN `paid_by` varchar(200) DEFAULT NULL AFTER `paid_at`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
