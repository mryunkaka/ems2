-- EMS2
-- Setting Akun: dokumen wajib + sertifikat tambahan + tanggal kenaikan jabatan
-- Date: 2026-04-21

START TRANSACTION;

SET @db_name := DATABASE();

-- =========================================================
-- Dokumen sertifikat tambahan user_rh
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'sertifikat_operasi_plastik'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN sertifikat_operasi_plastik VARCHAR(255) NULL AFTER sertifikat_operasi"
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
              AND COLUMN_NAME = 'sertifikat_operasi_kecil'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN sertifikat_operasi_kecil VARCHAR(255) NULL AFTER sertifikat_operasi_plastik"
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
              AND COLUMN_NAME = 'sertifikat_operasi_besar'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN sertifikat_operasi_besar VARCHAR(255) NULL AFTER sertifikat_operasi_kecil"
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
              AND COLUMN_NAME = 'sertifikat_class_co_asst'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN sertifikat_class_co_asst VARCHAR(255) NULL AFTER sertifikat_operasi_besar"
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
              AND COLUMN_NAME = 'sertifikat_class_paramedic'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN sertifikat_class_paramedic VARCHAR(255) NULL AFTER sertifikat_class_co_asst"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =========================================================
-- Tanggal kenaikan / join manager user_rh
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'tanggal_naik_paramedic'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_naik_paramedic DATE NULL AFTER tanggal_masuk"
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
              AND COLUMN_NAME = 'tanggal_naik_co_asst'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_naik_co_asst DATE NULL AFTER tanggal_naik_paramedic"
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
              AND COLUMN_NAME = 'tanggal_naik_dokter'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_naik_dokter DATE NULL AFTER tanggal_naik_co_asst"
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
              AND COLUMN_NAME = 'tanggal_naik_dokter_spesialis'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_naik_dokter_spesialis DATE NULL AFTER tanggal_naik_dokter"
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
              AND COLUMN_NAME = 'tanggal_join_manager'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN tanggal_join_manager DATE NULL AFTER tanggal_naik_dokter_spesialis"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
