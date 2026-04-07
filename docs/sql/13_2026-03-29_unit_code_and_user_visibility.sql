-- EMS2
-- Tahap 1 pemisahan data EMS Roxwood vs Alta
-- Scope aman: user_rh + sales
-- Date: 2026-03-29
--
-- Keputusan implementasi:
-- 1. Existing system dianggap milik Roxwood.
-- 2. Pemisahan awal hanya untuk modul EMS farmasi:
--    - rekap farmasi
--    - konsumen
--    - ranking
--    - gaji
-- 3. Data transaksi EMS mengikuti unit_code user login.
-- 4. Owner dapat diberi akses lintas unit via can_view_all_units.
-- 5. Modul/tabel lain tidak disentuh pada tahap ini agar risiko minimal.
--    Pengecualian kecil: ems_sales ikut disiapkan agar dashboard tidak campur data.

START TRANSACTION;

SET @db_name := DATABASE();

-- =========================================================
-- user_rh: unit user login + flag owner lintas unit
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'user_rh'
              AND COLUMN_NAME = 'unit_code'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN unit_code VARCHAR(20) NULL DEFAULT NULL AFTER division"
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
              AND COLUMN_NAME = 'can_view_all_units'
        ),
        'SELECT 1',
        "ALTER TABLE user_rh ADD COLUMN can_view_all_units TINYINT(1) NULL DEFAULT NULL AFTER unit_code"
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
              AND TABLE_NAME = 'user_rh'
              AND INDEX_NAME = 'idx_user_rh_unit_code'
        ),
        'SELECT 1',
        'ALTER TABLE user_rh ADD KEY idx_user_rh_unit_code (unit_code)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Semua user existing diasumsikan user Roxwood.
UPDATE user_rh
SET unit_code = 'roxwood'
WHERE unit_code IS NULL;

-- Default akses lintas unit dibiarkan NULL.
-- Nanti bisa diisi manual khusus owner:
-- UPDATE user_rh SET can_view_all_units = 1 WHERE id = ...;

-- =========================================================
-- sales: snapshot unit transaksi EMS
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'unit_code'
        ),
        'SELECT 1',
        "ALTER TABLE sales ADD COLUMN unit_code VARCHAR(20) NULL DEFAULT NULL AFTER medic_jabatan"
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
              AND TABLE_NAME = 'sales'
              AND INDEX_NAME = 'idx_sales_unit_code_created_at'
        ),
        'SELECT 1',
        'ALTER TABLE sales ADD KEY idx_sales_unit_code_created_at (unit_code, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE sales
SET unit_code = 'roxwood'
WHERE unit_code IS NULL;

-- =========================================================
-- packages: snapshot unit regulasi paket farmasi
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'packages'
              AND COLUMN_NAME = 'unit_code'
        ),
        'SELECT 1',
        "ALTER TABLE packages ADD COLUMN unit_code VARCHAR(20) NULL DEFAULT NULL AFTER price"
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
              AND TABLE_NAME = 'packages'
              AND INDEX_NAME = 'idx_packages_unit_code_name'
        ),
        'SELECT 1',
        'ALTER TABLE packages ADD KEY idx_packages_unit_code_name (unit_code, name)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE packages
SET unit_code = 'roxwood'
WHERE unit_code IS NULL;

-- =========================================================
-- ems_sales: snapshot unit untuk dashboard EMS lama
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'ems_sales'
        ) AND NOT EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'ems_sales'
              AND COLUMN_NAME = 'unit_code'
        ),
        "ALTER TABLE ems_sales ADD COLUMN unit_code VARCHAR(20) NULL DEFAULT NULL",
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
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'ems_sales'
        ) AND NOT EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'ems_sales'
              AND INDEX_NAME = 'idx_ems_sales_unit_code_created_at'
        ),
        'ALTER TABLE ems_sales ADD KEY idx_ems_sales_unit_code_created_at (unit_code, created_at)',
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
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'ems_sales'
        ),
        "UPDATE ems_sales SET unit_code = 'roxwood' WHERE unit_code IS NULL",
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =========================================================
-- salary: snapshot unit rekap gaji mingguan
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'salary'
              AND COLUMN_NAME = 'unit_code'
        ),
        'SELECT 1',
        "ALTER TABLE salary ADD COLUMN unit_code VARCHAR(20) NULL DEFAULT NULL AFTER medic_jabatan"
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
              AND TABLE_NAME = 'salary'
              AND INDEX_NAME = 'idx_salary_unit_code_period_end'
        ),
        'SELECT 1',
        'ALTER TABLE salary ADD KEY idx_salary_unit_code_period_end (unit_code, period_end)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE salary
SET unit_code = 'roxwood'
WHERE unit_code IS NULL;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'salary'
              AND INDEX_NAME = 'uniq_salary'
        ),
        'ALTER TABLE salary DROP INDEX uniq_salary',
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
              AND TABLE_NAME = 'salary'
              AND INDEX_NAME = 'uniq_salary'
        ),
        'SELECT 1',
        'ALTER TABLE salary ADD UNIQUE KEY uniq_salary (medic_name, period_start, period_end, unit_code)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
