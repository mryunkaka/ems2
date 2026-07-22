-- EMS2
-- Setting Akun: dokumen Sertifikat Pelatihan dan Sertifikat Visum
-- Date: 2026-07-22

START TRANSACTION;

SET @db_name := DATABASE();

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'sertifikat_pelatihan'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN sertifikat_pelatihan VARCHAR(255) NULL AFTER sertifikat_operasi"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'file_visum'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN file_visum VARCHAR(255) NULL AFTER sertifikat_pelatihan"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;