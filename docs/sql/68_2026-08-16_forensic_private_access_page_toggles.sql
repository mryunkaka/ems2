-- EMS2
-- Perluasan forensic_private_access_grants: sebelumnya hanya mengatur akses
-- ke Rekam Medis Private (5 checkbox CRUD). User minta halaman "Kelola
-- Akses" dipisah jadi halaman sendiri dan bisa mengatur akses ke SELURUH
-- halaman grup Forensic sekaligus (List Medis, Data Pasien Private, Hasil
-- Visum, Arsip Forensic) — kolom baru ini murni toggle lihat/tidak per
-- halaman (bukan CRUD granular seperti Rekam Medis Private, yang tetap
-- pakai 5 kolom lama).
-- Date: 2026-08-16

SET @db_name = DATABASE();

SET @sql = IF (
    EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants'
          AND COLUMN_NAME = 'can_view_forensic_medics'
    ),
    'SELECT 1',
    'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `can_view_forensic_medics` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_delete`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants'
          AND COLUMN_NAME = 'can_view_private_patients'
    ),
    'SELECT 1',
    'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `can_view_private_patients` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_view_forensic_medics`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants'
          AND COLUMN_NAME = 'can_view_visum_results'
    ),
    'SELECT 1',
    'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `can_view_visum_results` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_view_private_patients`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (
    EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants'
          AND COLUMN_NAME = 'can_view_archive'
    ),
    'SELECT 1',
    'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `can_view_archive` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_view_visum_results`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
