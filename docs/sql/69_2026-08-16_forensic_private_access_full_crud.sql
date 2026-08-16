-- EMS2
-- Perluasan lanjutan forensic_private_access_grants: Data Pasien Private,
-- Hasil Visum, dan Arsip Forensic sekarang punya model izin CRUD granular
-- yang sama seperti Rekam Medis Private (lihat semua / lihat punya sendiri /
-- input / edit / hapus) alih-alih cuma toggle buka-halaman tunggal. Kolom
-- toggle lama (can_view_private_patients/can_view_visum_results/
-- can_view_archive) SENGAJA dibiarkan ada (tidak dihapus, sudah tidak
-- dipakai kode) supaya migrasi ini tetap non-destruktif.
--
-- forensic_private_record_logs juga diperluas dengan resource_type supaya
-- satu tabel log yang sama bisa dipakai untuk history Data Pasien Private /
-- Hasil Visum / Arsip Forensic, bukan cuma Rekam Medis Private.
-- Date: 2026-08-16

SET @db_name = DATABASE();

-- ===== Data Pasien Private =====
SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'patients_view_all'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `patients_view_all` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'patients_view_own'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `patients_view_own` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'patients_create'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `patients_create` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'patients_edit'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `patients_edit` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'patients_delete'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `patients_delete` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== Hasil Visum =====
SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'visum_view_all'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `visum_view_all` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'visum_view_own'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `visum_view_own` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'visum_create'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `visum_create` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'visum_edit'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `visum_edit` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'visum_delete'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `visum_delete` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== Arsip Forensic =====
SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'archive_view_all'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `archive_view_all` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'archive_view_own'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `archive_view_own` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'archive_create'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `archive_create` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'archive_edit'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `archive_edit` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_access_grants' AND COLUMN_NAME = 'archive_delete'), 'SELECT 1', 'ALTER TABLE `forensic_private_access_grants` ADD COLUMN `archive_delete` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== Log aktivitas: resource_type supaya 1 tabel dipakai 4 halaman =====
SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_record_logs' AND COLUMN_NAME = 'resource_type'), 'SELECT 1', "ALTER TABLE `forensic_private_record_logs` ADD COLUMN `resource_type` VARCHAR(30) NOT NULL DEFAULT 'rekam_medis_private' AFTER `medical_record_id`");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF (EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forensic_private_record_logs' AND INDEX_NAME = 'idx_forensic_private_logs_resource'), 'SELECT 1', 'ALTER TABLE `forensic_private_record_logs` ADD KEY `idx_forensic_private_logs_resource` (`resource_type`, `medical_record_id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
