-- EMS2
-- Nomor "Pendaftaran" (periode buka rekrutmen) khusus jalur Calon Asisten Manager GA
-- Date: 2026-07-31

SET @has_ga_batch := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'medical_applicants'
    AND COLUMN_NAME = 'ga_batch'
);
SET @sql := IF(
  @has_ga_batch = 0,
  'ALTER TABLE `medical_applicants` ADD COLUMN `ga_batch` INT(11) NULL AFTER `target_division`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_current_batch := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'recruitment_portal_settings'
    AND COLUMN_NAME = 'current_batch'
);
SET @sql := IF(
  @has_current_batch = 0,
  'ALTER TABLE `recruitment_portal_settings` ADD COLUMN `current_batch` INT(11) NOT NULL DEFAULT 1 AFTER `closed_message`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Semua kandidat asisten manager yang sudah ada sebelum fitur ini dianggap "Pendaftaran 1"
UPDATE `medical_applicants`
SET `ga_batch` = 1
WHERE COALESCE(NULLIF(`recruitment_type`, ''), 'medical_candidate') = 'assistant_manager'
  AND `ga_batch` IS NULL;

UPDATE `recruitment_portal_settings`
SET `current_batch` = 1
WHERE `track` = 'assistant_manager';
