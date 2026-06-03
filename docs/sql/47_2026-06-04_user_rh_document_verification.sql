-- EMS2
-- Manajemen User: status verifikasi manual dokumen medis
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
              AND COLUMN_NAME = 'documents_verified_hash'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN documents_verified_hash CHAR(64) NULL AFTER dokumen_lainnya"
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
              AND COLUMN_NAME = 'documents_verified_at'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN documents_verified_at DATETIME NULL AFTER documents_verified_hash"
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
              AND COLUMN_NAME = 'documents_verified_by'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN documents_verified_by INT NULL AFTER documents_verified_at"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
