START TRANSACTION;

CREATE TABLE IF NOT EXISTS `farmasi_hospital_billing_entries` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
  `billing_date` DATE NOT NULL,
  `service_category` VARCHAR(50) NOT NULL DEFAULT 'other',
  `service_label` VARCHAR(150) NOT NULL,
  `hospital_billing_amount` INT NOT NULL DEFAULT 0,
  `expected_pharmacy_amount` INT DEFAULT NULL,
  `source_reference` VARCHAR(150) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_farmasi_hospital_billing_unit_date` (`unit_code`, `billing_date`),
  KEY `idx_farmasi_hospital_billing_category` (`service_category`),
  KEY `idx_farmasi_hospital_billing_created_by` (`created_by`),
  KEY `idx_farmasi_hospital_billing_updated_by` (`updated_by`),
  CONSTRAINT `fk_farmasi_hospital_billing_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_farmasi_hospital_billing_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `farmasi_audit_reviews` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
  `review_date` DATE NOT NULL,
  `medic_user_id` INT NOT NULL,
  `anomaly_score_snapshot` INT NOT NULL DEFAULT 0,
  `suspicious_tx_count` INT NOT NULL DEFAULT 0,
  `same_second_batch_count` INT NOT NULL DEFAULT 0,
  `estimated_loss_amount` INT NOT NULL DEFAULT 0,
  `status` ENUM('pending','clarified','manual_entry_after_service','system_gap','confirmed_violation','cleared') NOT NULL DEFAULT 'pending',
  `question_prompt` TEXT DEFAULT NULL,
  `medic_statement` TEXT DEFAULT NULL,
  `reviewer_notes` TEXT DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_farmasi_audit_review` (`unit_code`, `review_date`, `medic_user_id`),
  KEY `idx_farmasi_audit_reviews_status` (`status`),
  KEY `idx_farmasi_audit_reviews_medic` (`medic_user_id`),
  KEY `idx_farmasi_audit_reviews_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_farmasi_audit_reviews_medic`
    FOREIGN KEY (`medic_user_id`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_farmasi_audit_reviews_reviewed_by`
    FOREIGN KEY (`reviewed_by`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
