-- EMS2
-- Setting Akun: tanggal dikeluarkan sertifikat medis
-- Date: 2026-05-13

START TRANSACTION;

SET @db_name := DATABASE();

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'tanggal_dikeluarkan_sertifikat_heli'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_dikeluarkan_sertifikat_heli DATE NULL AFTER sertifikat_heli"
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
              AND COLUMN_NAME = 'tanggal_dikeluarkan_sertifikat_operasi'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_dikeluarkan_sertifikat_operasi DATE NULL AFTER sertifikat_operasi"
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
              AND COLUMN_NAME = 'tanggal_dikeluarkan_sertifikat_operasi_plastik'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_dikeluarkan_sertifikat_operasi_plastik DATE NULL AFTER sertifikat_operasi_plastik"
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
              AND COLUMN_NAME = 'tanggal_dikeluarkan_sertifikat_operasi_kecil'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_dikeluarkan_sertifikat_operasi_kecil DATE NULL AFTER sertifikat_operasi_kecil"
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
              AND COLUMN_NAME = 'tanggal_dikeluarkan_sertifikat_operasi_besar'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_dikeluarkan_sertifikat_operasi_besar DATE NULL AFTER sertifikat_operasi_besar"
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
              AND COLUMN_NAME = 'tanggal_dikeluarkan_sertifikat_class_co_asst'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_dikeluarkan_sertifikat_class_co_asst DATE NULL AFTER sertifikat_class_co_asst"
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
              AND COLUMN_NAME = 'tanggal_dikeluarkan_sertifikat_class_paramedic'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_dikeluarkan_sertifikat_class_paramedic DATE NULL AFTER sertifikat_class_paramedic"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
