-- Store an explicit owning division for each Secretary workspace record.
-- Existing records inherit their creator division; blank legacy values stay with Secretary.
SET @db_name = DATABASE();

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_visit_agendas' AND COLUMN_NAME = 'owner_division');
SET @ddl = IF(@has_col = 0, 'ALTER TABLE `secretary_visit_agendas` ADD COLUMN `owner_division` VARCHAR(120) NULL AFTER `id`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `secretary_visit_agendas` r LEFT JOIN user_rh u ON u.id = r.created_by SET r.owner_division = CASE LOWER(TRIM(u.division)) WHEN 'medis' THEN 'Medis' WHEN 'medical' THEN 'Medis' WHEN 'executive' THEN 'Executive' WHEN 'secretary' THEN 'Secretary' WHEN 'sekertaris' THEN 'Secretary' WHEN 'sekretaris' THEN 'Secretary' WHEN 'human capital' THEN 'Human Capital' WHEN 'human resource' THEN 'Human Resource' WHEN 'disciplinary committee' THEN 'Disciplinary Committee' WHEN 'general affair' THEN 'General Affair' WHEN 'medical affair' THEN 'Medical Affair' WHEN 'specialist medical authority' THEN 'Medical Affair' WHEN 'forensic' THEN 'Forensic' WHEN 'forensik' THEN 'Forensic' ELSE COALESCE(NULLIF(TRIM(u.division), ''), 'Secretary') END WHERE r.owner_division IS NULL OR TRIM(r.owner_division) = '';
ALTER TABLE `secretary_visit_agendas` MODIFY `owner_division` VARCHAR(120) NOT NULL;
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_visit_agendas' AND INDEX_NAME = 'idx_secretary_visit_agendas_owner_division');
SET @ddl = IF(@has_idx = 0, 'CREATE INDEX `idx_secretary_visit_agendas_owner_division` ON `secretary_visit_agendas` (`owner_division`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_internal_coordinations' AND COLUMN_NAME = 'owner_division');
SET @ddl = IF(@has_col = 0, 'ALTER TABLE `secretary_internal_coordinations` ADD COLUMN `owner_division` VARCHAR(120) NULL AFTER `id`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `secretary_internal_coordinations` r LEFT JOIN user_rh u ON u.id = r.created_by SET r.owner_division = COALESCE(NULLIF(TRIM(u.division), ''), 'Secretary') WHERE r.owner_division IS NULL OR TRIM(r.owner_division) = '';
ALTER TABLE `secretary_internal_coordinations` MODIFY `owner_division` VARCHAR(120) NOT NULL;
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_internal_coordinations' AND INDEX_NAME = 'idx_secretary_internal_coordinations_owner_division');
SET @ddl = IF(@has_idx = 0, 'CREATE INDEX `idx_secretary_internal_coordinations_owner_division` ON `secretary_internal_coordinations` (`owner_division`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_confidential_letters' AND COLUMN_NAME = 'owner_division');
SET @ddl = IF(@has_col = 0, 'ALTER TABLE `secretary_confidential_letters` ADD COLUMN `owner_division` VARCHAR(120) NULL AFTER `id`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `secretary_confidential_letters` r LEFT JOIN user_rh u ON u.id = r.created_by SET r.owner_division = COALESCE(NULLIF(TRIM(u.division), ''), 'Secretary') WHERE r.owner_division IS NULL OR TRIM(r.owner_division) = '';
ALTER TABLE `secretary_confidential_letters` MODIFY `owner_division` VARCHAR(120) NOT NULL;
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_confidential_letters' AND INDEX_NAME = 'idx_secretary_confidential_letters_owner_division');
SET @ddl = IF(@has_idx = 0, 'CREATE INDEX `idx_secretary_confidential_letters_owner_division` ON `secretary_confidential_letters` (`owner_division`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_file_records' AND COLUMN_NAME = 'owner_division');
SET @ddl = IF(@has_col = 0, 'ALTER TABLE `secretary_file_records` ADD COLUMN `owner_division` VARCHAR(120) NULL AFTER `id`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `secretary_file_records` r LEFT JOIN user_rh u ON u.id = r.created_by SET r.owner_division = COALESCE(NULLIF(TRIM(u.division), ''), 'Secretary') WHERE r.owner_division IS NULL OR TRIM(r.owner_division) = '';
ALTER TABLE `secretary_file_records` MODIFY `owner_division` VARCHAR(120) NOT NULL;
UPDATE `secretary_file_records`
SET `owner_division` = 'General Affair'
WHERE `file_category` = 'cooperation'
  AND COALESCE(`keywords`, '') LIKE '%ga_cooperation_input%';
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'secretary_file_records' AND INDEX_NAME = 'idx_secretary_file_records_owner_division');
SET @ddl = IF(@has_idx = 0, 'CREATE INDEX `idx_secretary_file_records_owner_division` ON `secretary_file_records` (`owner_division`)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
