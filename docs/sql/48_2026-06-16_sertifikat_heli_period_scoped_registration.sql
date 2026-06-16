-- EMS2
-- Migration: Sertifikat Heli - Period-scoped Registration
-- Date: 2026-06-16
--
-- Perubahan:
-- 1. Tambah kolom settings_id ke sertifikat_heli_registrations
--    agar setiap pendaftaran terikat ke periode (settings) tertentu.
-- 2. Ubah UNIQUE KEY dari (user_id) menjadi (user_id, settings_id)
--    sehingga user yang sama bisa mendaftar lagi di periode berikutnya.
-- 3. Tambah INDEX pada settings_id untuk query filter per periode.

START TRANSACTION;

SET @db_name := DATABASE();

-- =========================================================
-- Step 1: Tambah kolom settings_id jika belum ada
-- =========================================================

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'sertifikat_heli_registrations'
      AND COLUMN_NAME = 'settings_id'
);

SET @sql_add_col := IF(
    @col_exists = 0,
    'ALTER TABLE sertifikat_heli_registrations ADD COLUMN settings_id INT NULL DEFAULT NULL AFTER user_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql_add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =========================================================
-- Step 2: Drop UNIQUE KEY lama (user_id) jika masih ada
-- =========================================================

SET @old_key_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'sertifikat_heli_registrations'
      AND INDEX_NAME = 'uniq_user_registration'
);

SET @sql_drop_key := IF(
    @old_key_exists > 0,
    'ALTER TABLE sertifikat_heli_registrations DROP INDEX uniq_user_registration',
    'SELECT 1'
);
PREPARE stmt FROM @sql_drop_key;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =========================================================
-- Step 3: Tambah UNIQUE KEY baru (user_id, settings_id)
-- =========================================================

SET @new_key_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'sertifikat_heli_registrations'
      AND INDEX_NAME = 'uniq_user_period_registration'
);

SET @sql_add_key := IF(
    @new_key_exists = 0,
    'ALTER TABLE sertifikat_heli_registrations ADD UNIQUE KEY uniq_user_period_registration (user_id, settings_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_add_key;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =========================================================
-- Step 4: Tambah INDEX pada settings_id jika belum ada
-- =========================================================

SET @idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'sertifikat_heli_registrations'
      AND INDEX_NAME = 'idx_settings_id'
);

SET @sql_add_idx := IF(
    @idx_exists = 0,
    'ALTER TABLE sertifikat_heli_registrations ADD INDEX idx_settings_id (settings_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_add_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
