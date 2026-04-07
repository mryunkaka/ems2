-- Specialist Medical Authority module
-- Create training recap, promotion assessment, and specialist authorization tables

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `specialist_training_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `training_code` VARCHAR(100) NOT NULL,
  `user_id` INT NOT NULL,
  `training_name` VARCHAR(180) NOT NULL,
  `provider_name` VARCHAR(180) DEFAULT NULL,
  `category` VARCHAR(100) NOT NULL,
  `certificate_number` VARCHAR(120) DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL,
  `status` ENUM('planned', 'ongoing', 'completed', 'expired') NOT NULL DEFAULT 'planned',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_specialist_training_records_code` (`training_code`),
  KEY `idx_specialist_training_records_user` (`user_id`),
  KEY `idx_specialist_training_records_status` (`status`),
  KEY `idx_specialist_training_records_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_specialist_training_records_user`
    FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_specialist_training_records_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_specialist_training_records_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `specialist_promotion_assessments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assessment_code` VARCHAR(100) NOT NULL,
  `promotion_request_id` BIGINT UNSIGNED NOT NULL,
  `assessed_user_id` INT NOT NULL,
  `assessor_user_id` INT NOT NULL,
  `clinical_score` INT NOT NULL DEFAULT 0,
  `training_score` INT NOT NULL DEFAULT 0,
  `readiness_score` INT NOT NULL DEFAULT 0,
  `total_score` INT NOT NULL DEFAULT 0,
  `recommendation` ENUM('recommended', 'follow_up_required', 'not_recommended') NOT NULL DEFAULT 'follow_up_required',
  `notes` TEXT DEFAULT NULL,
  `assessed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_specialist_promotion_assessments_code` (`assessment_code`),
  UNIQUE KEY `uniq_specialist_promotion_assessments_request` (`promotion_request_id`),
  KEY `idx_specialist_promotion_assessments_user` (`assessed_user_id`),
  KEY `idx_specialist_promotion_assessments_assessor` (`assessor_user_id`),
  KEY `idx_specialist_promotion_assessments_recommendation` (`recommendation`),
  CONSTRAINT `fk_specialist_promotion_assessments_request`
    FOREIGN KEY (`promotion_request_id`) REFERENCES `position_promotion_requests` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_specialist_promotion_assessments_user`
    FOREIGN KEY (`assessed_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_specialist_promotion_assessments_assessor`
    FOREIGN KEY (`assessor_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `specialist_authorizations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `authorization_code` VARCHAR(100) NOT NULL,
  `user_id` INT NOT NULL,
  `specialty_name` VARCHAR(150) NOT NULL,
  `privilege_scope` TEXT NOT NULL,
  `effective_date` DATE NOT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `status` ENUM('active', 'expired', 'revoked') NOT NULL DEFAULT 'active',
  `assessment_id` BIGINT UNSIGNED DEFAULT NULL,
  `approved_by` INT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_specialist_authorizations_code` (`authorization_code`),
  KEY `idx_specialist_authorizations_user` (`user_id`),
  KEY `idx_specialist_authorizations_status` (`status`),
  KEY `idx_specialist_authorizations_dates` (`effective_date`, `expiry_date`),
  KEY `idx_specialist_authorizations_assessment` (`assessment_id`),
  CONSTRAINT `fk_specialist_authorizations_user`
    FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_specialist_authorizations_assessment`
    FOREIGN KEY (`assessment_id`) REFERENCES `specialist_promotion_assessments` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_specialist_authorizations_approved_by`
    FOREIGN KEY (`approved_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_specialist_authorizations_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_specialist_authorizations_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
