-- Forensic module
-- Create private patient cases, visum results, and forensic archives

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `forensic_private_patients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_code` VARCHAR(100) NOT NULL,
  `patient_name` VARCHAR(160) NOT NULL,
  `medical_record_no` VARCHAR(100) DEFAULT NULL,
  `identity_number` VARCHAR(100) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `gender` ENUM('male', 'female', 'unknown') NOT NULL DEFAULT 'unknown',
  `case_type` VARCHAR(120) NOT NULL,
  `incident_date` DATE NOT NULL,
  `incident_location` VARCHAR(180) NOT NULL,
  `confidentiality_level` ENUM('restricted', 'confidential', 'sealed') NOT NULL DEFAULT 'confidential',
  `status` ENUM('draft', 'active', 'closed', 'archived') NOT NULL DEFAULT 'draft',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forensic_private_patients_code` (`case_code`),
  KEY `idx_forensic_private_patients_incident_date` (`incident_date`),
  KEY `idx_forensic_private_patients_status` (`status`),
  KEY `idx_forensic_private_patients_confidentiality` (`confidentiality_level`),
  CONSTRAINT `fk_forensic_private_patients_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_forensic_private_patients_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `forensic_visum_results` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visum_code` VARCHAR(100) NOT NULL,
  `private_patient_id` BIGINT UNSIGNED NOT NULL,
  `examination_date` DATE NOT NULL,
  `doctor_user_id` INT NOT NULL,
  `requesting_party` VARCHAR(180) NOT NULL,
  `finding_summary` TEXT NOT NULL,
  `conclusion_text` TEXT DEFAULT NULL,
  `recommendation_text` TEXT DEFAULT NULL,
  `status` ENUM('draft', 'issued', 'revised', 'archived') NOT NULL DEFAULT 'draft',
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forensic_visum_results_code` (`visum_code`),
  KEY `idx_forensic_visum_results_case` (`private_patient_id`),
  KEY `idx_forensic_visum_results_exam_date` (`examination_date`),
  KEY `idx_forensic_visum_results_status` (`status`),
  CONSTRAINT `fk_forensic_visum_results_case`
    FOREIGN KEY (`private_patient_id`) REFERENCES `forensic_private_patients` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_forensic_visum_results_doctor`
    FOREIGN KEY (`doctor_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_forensic_visum_results_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_forensic_visum_results_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `forensic_archives` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `archive_code` VARCHAR(100) NOT NULL,
  `private_patient_id` BIGINT UNSIGNED DEFAULT NULL,
  `visum_result_id` BIGINT UNSIGNED DEFAULT NULL,
  `archive_title` VARCHAR(180) NOT NULL,
  `document_type` VARCHAR(100) NOT NULL,
  `retention_until` DATE DEFAULT NULL,
  `status` ENUM('stored', 'sealed', 'released') NOT NULL DEFAULT 'stored',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forensic_archives_code` (`archive_code`),
  KEY `idx_forensic_archives_case` (`private_patient_id`),
  KEY `idx_forensic_archives_visum` (`visum_result_id`),
  KEY `idx_forensic_archives_status` (`status`),
  CONSTRAINT `fk_forensic_archives_case`
    FOREIGN KEY (`private_patient_id`) REFERENCES `forensic_private_patients` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_forensic_archives_visum`
    FOREIGN KEY (`visum_result_id`) REFERENCES `forensic_visum_results` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_forensic_archives_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_forensic_archives_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
