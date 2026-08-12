-- EMS2
-- Batasi kode referensi laporan Diagnosis (report_code) supaya hanya bisa
-- dipakai 1x per halaman tujuan (AI Surgery Planner / Radiology Center /
-- Laboratory AI / Psychiatry Center) — dilacak lewat kolom
-- `source_report_code` di masing-masing tabel hasil, bukan tabel relasi
-- terpisah, supaya "sudah dipakai di halaman X?" cukup query langsung ke
-- tabel X itu sendiri. Kode yang sama TETAP bisa dipakai di halaman
-- LAIN (lintas halaman tidak dibatasi) — hanya dilarang dipakai 2x di
-- halaman YANG SAMA, kecuali lewat tombol "Generate Ulang" di riwayat.
-- Date: 2026-08-13

START TRANSACTION;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_surgery_plans' AND COLUMN_NAME = 'source_report_code'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `ai_surgery_plans` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `kasus_tindakan`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_radiology_images' AND COLUMN_NAME = 'source_report_code'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `ai_radiology_images` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `anamnesis`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_laboratory_results' AND COLUMN_NAME = 'source_report_code'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `ai_laboratory_results` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `clinical_info`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_psychiatry_assessments' AND COLUMN_NAME = 'source_report_code'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `ai_psychiatry_assessments` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `anamnesis`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;
