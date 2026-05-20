-- EMS2
-- Setting Akun: tanggal lahir IC sesuai KTP wajib untuk akses jualan farmasi
-- Date: 2026-05-20

START TRANSACTION;

SET @db_name := DATABASE();

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'tanggal_lahir_ic'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_lahir_ic DATE NULL AFTER citizen_id"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
