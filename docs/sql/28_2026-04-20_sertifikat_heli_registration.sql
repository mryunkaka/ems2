-- EMS2
-- Modul Sertifikat Heli Registration
-- Date: 2026-04-20
--
-- Fitur:
-- 1. Settings untuk pendaftaran sertifikat heli (waktu mulai, selesai, max slot, min jabatan)
-- 2. Tabel registrasi untuk tracking pendaftar
-- 3. Batasan slot dan validasi waktu

START TRANSACTION;

SET @db_name := DATABASE();

-- =========================================================
-- sertifikat_heli_settings: Konfigurasi pendaftaran sertifikat heli
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'sertifikat_heli_settings'
        ),
        'SELECT 1',
        'CREATE TABLE sertifikat_heli_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            start_datetime DATETIME NOT NULL COMMENT "Tanggal dan jam mulai pendaftaran",
            end_datetime DATETIME NOT NULL COMMENT "Tanggal dan jam selesai pendaftaran",
            max_slots INT NOT NULL DEFAULT 10 COMMENT "Maksimal jumlah pendaftar",
            min_jabatan VARCHAR(100) NULL COMMENT "Jabatan minimal yang bisa daftar (NULL = semua jabatan)",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_start_datetime (start_datetime),
            INDEX idx_end_datetime (end_datetime)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =========================================================
-- sertifikat_heli_registrations: Data pendaftar sertifikat heli
-- =========================================================

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'sertifikat_heli_registrations'
        ),
        'SELECT 1',
        'CREATE TABLE sertifikat_heli_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT "ID user dari tabel user_rh",
            user_name VARCHAR(255) NOT NULL COMMENT "Nama user",
            user_jabatan VARCHAR(100) NOT NULL COMMENT "Jabatan user",
            user_division VARCHAR(100) NULL COMMENT "Divisi user",
            registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT "Waktu pendaftaran",
            status ENUM("registered", "approved", "rejected") DEFAULT "registered" COMMENT "Status pendaftaran",
            UNIQUE KEY uniq_user_registration (user_id),
            INDEX idx_registered_at (registered_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
