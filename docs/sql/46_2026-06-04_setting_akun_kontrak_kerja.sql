-- EMS2
-- Setting Akun: kontrak kerja wajib untuk semua role dan jabatan
-- Date: 2026-06-04

START TRANSACTION;

SET @db_name := DATABASE();

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'file_kontrak_kerja'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN file_kontrak_kerja VARCHAR(255) NULL AFTER file_skb"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
