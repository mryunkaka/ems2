-- EMS2
-- Kode referensi unik per laporan AI Diagnosis, supaya dokter tinggal salin
-- kode ini dan tempel di AI Surgery Planner / Radiology Center untuk
-- auto-fill data kasus dari diagnosis awal (kasus_tindakan, jenis_operasi,
-- jenis_anestesi, rekomendasi radiologi terstruktur).
-- Date: 2026-08-12

START TRANSACTION;

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ai_diagnosis_reports'
      AND COLUMN_NAME = 'report_code'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `ai_diagnosis_reports` ADD COLUMN `report_code` VARCHAR(40) NULL AFTER `id`, ADD UNIQUE KEY `uniq_ai_diagnosis_report_code` (`report_code`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
