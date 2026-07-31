-- EMS2
-- Pisahkan status open/close portal rekrutmen per jalur (medical_candidate vs assistant_manager)
-- Date: 2026-07-31

SET @has_track := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'recruitment_portal_settings'
    AND COLUMN_NAME = 'track'
);
SET @sql := IF(
  @has_track = 0,
  'ALTER TABLE `recruitment_portal_settings` ADD COLUMN `track` VARCHAR(30) NOT NULL DEFAULT ''medical_candidate'' AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_track_key := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'recruitment_portal_settings'
    AND INDEX_NAME = 'uniq_recruitment_portal_track'
);
SET @sql := IF(
  @has_track_key = 0,
  'ALTER TABLE `recruitment_portal_settings` ADD UNIQUE KEY `uniq_recruitment_portal_track` (`track`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `recruitment_portal_settings` SET `track` = 'medical_candidate' WHERE `id` = 1;

INSERT INTO `recruitment_portal_settings` (`id`, `track`, `is_open`, `closed_message`, `updated_by_user_id`)
VALUES (
    2,
    'assistant_manager',
    0,
    'Pendaftaran Calon Asisten Manager (General Affair) saat ini belum dibuka. Silakan menunggu informasi selanjutnya.',
    NULL
)
ON DUPLICATE KEY UPDATE id = id;
