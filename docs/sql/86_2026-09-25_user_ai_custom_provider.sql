-- EMS2: custom OpenAI-compatible provider untuk Setting AI Saya.
-- Endpoint/model bebas, termasuk router lokal atau layanan kompatibel OpenAI.

SET @has_custom_provider = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_ai_settings'
      AND COLUMN_NAME = 'custom_provider'
);
SET @sql = IF(
    @has_custom_provider = 0,
    'ALTER TABLE `user_ai_settings` ADD COLUMN `custom_provider` VARCHAR(100) NULL AFTER `groq_default_model`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_custom_api_key = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_ai_settings'
      AND COLUMN_NAME = 'custom_api_key'
);
SET @sql = IF(
    @has_custom_api_key = 0,
    'ALTER TABLE `user_ai_settings` ADD COLUMN `custom_api_key` VARCHAR(255) NULL AFTER `custom_provider`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_custom_base_url = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_ai_settings'
      AND COLUMN_NAME = 'custom_base_url'
);
SET @sql = IF(
    @has_custom_base_url = 0,
    'ALTER TABLE `user_ai_settings` ADD COLUMN `custom_base_url` VARCHAR(255) NULL AFTER `custom_api_key`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_custom_default_model = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_ai_settings'
      AND COLUMN_NAME = 'custom_default_model'
);
SET @sql = IF(
    @has_custom_default_model = 0,
    'ALTER TABLE `user_ai_settings` ADD COLUMN `custom_default_model` VARCHAR(100) NULL AFTER `custom_base_url`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
