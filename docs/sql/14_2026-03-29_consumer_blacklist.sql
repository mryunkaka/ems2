-- EMS2
-- Blacklist nama konsumen farmasi per unit
-- Date: 2026-03-29
--
-- Tujuan:
-- 1. Menyimpan nama konsumen dan Citizen ID yang diblokir secara global
-- 2. Menampilkan warning di rekap farmasi
-- 3. Mencegah transaksi farmasi disimpan untuk nama atau Citizen ID yang diblacklist

START TRANSACTION;

SET @db_name := DATABASE();

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'consumer_blacklist'
        ),
        'SELECT 1',
        "CREATE TABLE consumer_blacklist (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_code VARCHAR(20) NOT NULL,
            consumer_name VARCHAR(255) NOT NULL,
            consumer_name_key VARCHAR(255) NOT NULL,
            citizen_id VARCHAR(100) NULL,
            citizen_id_key VARCHAR(100) NULL,
            note TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_consumer_blacklist_name (consumer_name_key),
            UNIQUE KEY uniq_consumer_blacklist_citizen_id (citizen_id_key),
            KEY idx_consumer_blacklist_active (is_active),
            KEY idx_consumer_blacklist_unit_active (unit_code, is_active),
            KEY idx_consumer_blacklist_created_by (created_by),
            KEY idx_consumer_blacklist_updated_by (updated_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'consumer_blacklist'
        ),
        "DELETE cb_old
         FROM consumer_blacklist cb_old
         INNER JOIN consumer_blacklist cb_new
            ON cb_old.consumer_name_key = cb_new.consumer_name_key
           AND cb_old.id < cb_new.id",
        'SELECT 1'
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
              AND TABLE_NAME = 'consumer_blacklist'
              AND COLUMN_NAME = 'citizen_id'
        ),
        'SELECT 1',
        'ALTER TABLE consumer_blacklist ADD COLUMN citizen_id VARCHAR(100) NULL AFTER consumer_name_key'
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
              AND TABLE_NAME = 'consumer_blacklist'
              AND COLUMN_NAME = 'citizen_id_key'
        ),
        'SELECT 1',
        'ALTER TABLE consumer_blacklist ADD COLUMN citizen_id_key VARCHAR(100) NULL AFTER citizen_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'consumer_blacklist'
              AND INDEX_NAME = 'uniq_consumer_blacklist_unit_name'
        ),
        'ALTER TABLE consumer_blacklist DROP INDEX uniq_consumer_blacklist_unit_name',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'consumer_blacklist'
              AND INDEX_NAME = 'uniq_consumer_blacklist_citizen_id'
        ),
        'SELECT 1',
        'ALTER TABLE consumer_blacklist ADD UNIQUE KEY uniq_consumer_blacklist_citizen_id (citizen_id_key)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'consumer_blacklist'
              AND INDEX_NAME = 'uniq_consumer_blacklist_name'
        ),
        'SELECT 1',
        'ALTER TABLE consumer_blacklist ADD UNIQUE KEY uniq_consumer_blacklist_name (consumer_name_key)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'consumer_blacklist'
              AND INDEX_NAME = 'idx_consumer_blacklist_active'
        ),
        'SELECT 1',
        'ALTER TABLE consumer_blacklist ADD KEY idx_consumer_blacklist_active (is_active)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
