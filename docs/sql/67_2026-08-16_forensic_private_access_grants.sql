-- EMS2
-- Forensic private access grants: Head Manager Forensic (atau Director/Vice
-- Director/Executive) bisa memberi izin granular ke medis di luar division
-- Forensic untuk melihat/menginput/mengedit/menghapus rekam medis private
-- tertentu, plus log aktivitas (dilihat/diedit/dst) dan lampiran surat
-- permohonan visum dari DOJ/instansi lain pada rekam medis private.
-- Date: 2026-08-16

CREATE TABLE IF NOT EXISTS `forensic_private_access_grants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medic_user_id` int(11) NOT NULL,
  `medic_name_snapshot` varchar(150) DEFAULT NULL,
  `can_view_all` tinyint(1) NOT NULL DEFAULT 0,
  `can_view_own` tinyint(1) NOT NULL DEFAULT 0,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by` int(11) DEFAULT NULL,
  `granted_by_name_snapshot` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forensic_private_access_medic` (`medic_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `forensic_private_record_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medical_record_id` int(11) NOT NULL,
  `action` enum('created','viewed','edited','deleted') NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_name_snapshot` varchar(150) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_forensic_private_logs_record` (`medical_record_id`),
  KEY `idx_forensic_private_logs_record_created` (`medical_record_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @db_name = DATABASE();

SET @sql = IF (
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'medical_records'
          AND COLUMN_NAME = 'visum_letter_file_path'
    ),
    'SELECT 1',
    'ALTER TABLE `medical_records` ADD COLUMN `visum_letter_file_path` VARCHAR(255) DEFAULT NULL AFTER `mri_file_path`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
