-- EMS2
-- Radiology Center: tambah laporan bacaan radiologi formal (Findings/
-- Diagnosis/Recommendations + teks laporan terstruktur TECHNIQUE/FINDINGS/
-- IMPRESSION/RECOMMENDATION) mendampingi citra yang sudah ada — dipisah dari
-- status/error_message citra supaya laporan teks & citra bisa sukses/gagal
-- independen satu sama lain.
-- Date: 2026-08-13

START TRANSACTION;

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ai_radiology_images'
      AND COLUMN_NAME = 'report_findings'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `ai_radiology_images`
        ADD COLUMN `report_findings` TEXT NULL AFTER `image_path`,
        ADD COLUMN `report_diagnosis` TEXT NULL AFTER `report_findings`,
        ADD COLUMN `report_recommendations` TEXT NULL AFTER `report_diagnosis`,
        ADD COLUMN `report_text` LONGTEXT NULL AFTER `report_recommendations`,
        ADD COLUMN `report_status` ENUM(''done'',''error'') NULL AFTER `report_text`,
        ADD COLUMN `report_error_message` TEXT NULL AFTER `report_status`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
