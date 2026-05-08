CREATE TABLE IF NOT EXISTS `general_affair_cooperations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `institution_name` VARCHAR(150) NOT NULL,
    `period_type` ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily',
    `notes` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ga_coop_unit_active` (`unit_code`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `general_affair_cooperation_members` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cooperation_id` INT UNSIGNED NOT NULL,
    `citizen_id` VARCHAR(30) NOT NULL,
    `member_name` VARCHAR(150) NULL,
    `identity_id` INT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ga_coop_member` (`cooperation_id`, `citizen_id`),
    KEY `idx_ga_coop_member_lookup` (`citizen_id`, `is_active`),
    CONSTRAINT `fk_ga_coop_member_cooperation`
        FOREIGN KEY (`cooperation_id`) REFERENCES `general_affair_cooperations` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `general_affair_cooperation_packages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cooperation_id` INT UNSIGNED NOT NULL,
    `package_id` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ga_coop_package` (`cooperation_id`, `package_id`),
    KEY `idx_ga_coop_package_lookup` (`package_id`),
    CONSTRAINT `fk_ga_coop_package_cooperation`
        FOREIGN KEY (`cooperation_id`) REFERENCES `general_affair_cooperations` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'original_price'
        ),
        'SELECT 1',
        'ALTER TABLE `sales` ADD COLUMN `original_price` INT NULL AFTER `price`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'cooperation_discount_amount'
        ),
        'SELECT 1',
        'ALTER TABLE `sales` ADD COLUMN `cooperation_discount_amount` INT NOT NULL DEFAULT 0 AFTER `original_price`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'cooperation_id'
        ),
        'SELECT 1',
        'ALTER TABLE `sales` ADD COLUMN `cooperation_id` INT NULL AFTER `identity_id`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'cooperation_member_id'
        ),
        'SELECT 1',
        'ALTER TABLE `sales` ADD COLUMN `cooperation_member_id` INT NULL AFTER `cooperation_id`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'cooperation_period_type'
        ),
        'SELECT 1',
        'ALTER TABLE `sales` ADD COLUMN `cooperation_period_type` VARCHAR(20) NULL AFTER `cooperation_member_id`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'cooperation_period_key'
        ),
        'SELECT 1',
        'ALTER TABLE `sales` ADD COLUMN `cooperation_period_key` VARCHAR(20) NULL AFTER `cooperation_period_type`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'cooperation_claimed_free'
        ),
        'SELECT 1',
        'ALTER TABLE `sales` ADD COLUMN `cooperation_claimed_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cooperation_period_key`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `sales`
SET
    `original_price` = `price`,
    `cooperation_discount_amount` = COALESCE(`cooperation_discount_amount`, 0)
WHERE `original_price` IS NULL;
