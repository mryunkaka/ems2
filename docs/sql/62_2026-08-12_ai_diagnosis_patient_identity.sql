-- EMS2
-- Identitas pasien (Nama, Jenis Kelamin, Tanggal Lahir, Citizen ID) di AI
-- Diagnosis Assistant — supaya kode referensi laporan diagnosis bisa
-- auto-fill identitas pasien juga (bukan cuma kasus/radiologi) saat
-- dipakai di AI Surgery Planner / Radiology Center.
-- Date: 2026-08-12

START TRANSACTION;

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ai_diagnosis_reports'
      AND COLUMN_NAME = 'patient_name'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `ai_diagnosis_reports`
        ADD COLUMN `patient_name` VARCHAR(150) NULL AFTER `anamnesis`,
        ADD COLUMN `patient_gender` VARCHAR(20) NULL AFTER `patient_name`,
        ADD COLUMN `patient_dob` DATE NULL AFTER `patient_gender`,
        ADD COLUMN `patient_citizen_id` VARCHAR(50) NULL AFTER `patient_dob`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
