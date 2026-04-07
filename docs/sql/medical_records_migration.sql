-- ============================================
-- MEDICAL RECORDS TABLE MIGRATION
-- ============================================
-- Author: EMS Development Team
-- Date: 2026-03-08
-- Description: Create medical_records table for patient medical records management
-- Version: 1.0

-- ============================================
-- STEP 1: CREATE TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `medical_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_name` varchar(100) NOT NULL COMMENT 'Nama pasien',
  `patient_occupation` varchar(50) DEFAULT 'Civilian' COMMENT 'Pekerjaan pasien',
  `patient_dob` date NOT NULL COMMENT 'Tanggal lahir pasien',
  `patient_phone` varchar(20) DEFAULT NULL COMMENT 'Nomor HP pasien',
  `patient_gender` enum('Laki-laki','Perempuan') NOT NULL COMMENT 'Jenis kelamin pasien',
  `patient_address` varchar(255) DEFAULT 'INDONESIA' COMMENT 'Alamat pasien',
  `patient_status` varchar(50) DEFAULT NULL COMMENT 'Status pasien',
  `ktp_file_path` varchar(255) NOT NULL COMMENT 'Path file KTP (wajib)',
  `mri_file_path` varchar(255) DEFAULT NULL COMMENT 'Path file foto MRI (opsional)',
  `medical_result_html` text NOT NULL COMMENT 'HTML rich-text hasil rekam medis',
  `doctor_id` int(11) NOT NULL COMMENT 'DPJP dari user_rh',
  `assistant_id` int(11) DEFAULT NULL COMMENT 'Asisten dari user_rh (opsional)',
  `operasi_type` enum('major','minor') NOT NULL COMMENT 'Jenis operasi: mayor/minor',
  `created_by` int(11) NOT NULL COMMENT 'User yang input data (dari user_rh)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu pembuatan record',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Waktu update terakhir',
  PRIMARY KEY (`id`),
  KEY `idx_patient_name` (`patient_name`),
  KEY `idx_patient_dob` (`patient_dob`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_doctor_id` (`doctor_id`),
  KEY `idx_assistant_id` (`assistant_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_mr_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `user_rh` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_mr_assistant` FOREIGN KEY (`assistant_id`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Rekam Medis Pasien';

-- ============================================
-- STEP 2: CREATE STORAGE DIRECTORIES
-- ============================================
-- Note: Run this command manually on server:
-- mkdir -p storage/medical_records/ktp
-- mkdir -p storage/medical_records/mri
-- chmod 755 storage/medical_records
-- chmod 755 storage/medical_records/ktp
-- chmod 755 storage/medical_records/mri

-- ============================================
-- STEP 3: SAMPLE DATA (OPTIONAL - FOR TESTING)
-- ============================================
-- Uncomment below for testing purposes only

-- INSERT INTO `medical_records` (
--   `patient_name`, `patient_occupation`, `patient_dob`, `patient_phone`,
--   `patient_gender`, `patient_address`, `patient_status`, `ktp_file_path`,
--   `mri_file_path`, `medical_result_html`, `doctor_id`, `assistant_id`,
--   `operasi_type`, `created_by`
-- ) VALUES (
--   'Emysyu Takahashi',
--   'Civilian',
--   '2004-11-23',
--   '-',
--   'Perempuan',
--   'INDONESIA',
--   '-',
--   'storage/medical_records/ktp/sample_ktp.jpg',
--   NULL,
--   '<h1>Pemeriksaan Awal</h1><p>Pasien datang dengan keluhan...</p><ul><li>Poin 1</li><li>Poin 2</li></ul>',
--   1,  -- doctor_id (Michael Moore)
--   2,  -- assistant_id (AHMAD MILLER)
--   'minor',
--   1   -- created_by
-- );

-- ============================================
-- STEP 4: VERIFICATION QUERY
-- ============================================
-- Run this to verify table creation:
-- DESCRIBE medical_records;
-- SELECT * FROM medical_records LIMIT 5;

-- ============================================
-- STEP 5: ROLLBACK (IF NEEDED)
-- ============================================
-- Run this to drop table (CAREFUL - DELETES ALL DATA):
-- DROP TABLE IF EXISTS medical_records;

-- ============================================
-- END OF MIGRATION
-- ============================================
