-- Secretary module
-- Create division visit agendas, internal coordinations, and confidential letter register

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `secretary_visit_agendas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agenda_code` VARCHAR(100) NOT NULL,
  `visitor_name` VARCHAR(160) NOT NULL,
  `origin_name` VARCHAR(160) DEFAULT NULL,
  `visit_purpose` TEXT NOT NULL,
  `visit_date` DATE NOT NULL,
  `visit_time` TIME NOT NULL,
  `location` VARCHAR(180) NOT NULL,
  `pic_user_id` INT NOT NULL,
  `status` ENUM('scheduled', 'ongoing', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_secretary_visit_agendas_code` (`agenda_code`),
  KEY `idx_secretary_visit_agendas_date` (`visit_date`),
  KEY `idx_secretary_visit_agendas_status` (`status`),
  KEY `idx_secretary_visit_agendas_pic` (`pic_user_id`),
  CONSTRAINT `fk_secretary_visit_agendas_pic`
    FOREIGN KEY (`pic_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_secretary_visit_agendas_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_secretary_visit_agendas_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `secretary_internal_coordinations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coordination_code` VARCHAR(100) NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `division_scope` VARCHAR(120) NOT NULL,
  `host_user_id` INT NOT NULL,
  `coordination_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `status` ENUM('draft', 'scheduled', 'done', 'cancelled') NOT NULL DEFAULT 'draft',
  `summary_notes` TEXT DEFAULT NULL,
  `follow_up_notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_secretary_internal_coordinations_code` (`coordination_code`),
  KEY `idx_secretary_internal_coordinations_date` (`coordination_date`),
  KEY `idx_secretary_internal_coordinations_status` (`status`),
  KEY `idx_secretary_internal_coordinations_host` (`host_user_id`),
  CONSTRAINT `fk_secretary_internal_coordinations_host`
    FOREIGN KEY (`host_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_secretary_internal_coordinations_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_secretary_internal_coordinations_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `secretary_confidential_letters` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `register_code` VARCHAR(100) NOT NULL,
  `reference_number` VARCHAR(120) NOT NULL,
  `letter_direction` ENUM('incoming', 'outgoing') NOT NULL DEFAULT 'incoming',
  `subject` VARCHAR(200) NOT NULL,
  `counterparty_name` VARCHAR(180) NOT NULL,
  `confidentiality_level` ENUM('confidential', 'secret', 'top_secret') NOT NULL DEFAULT 'confidential',
  `letter_date` DATE NOT NULL,
  `status` ENUM('logged', 'sealed', 'distributed', 'archived') NOT NULL DEFAULT 'logged',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_secretary_confidential_letters_code` (`register_code`),
  KEY `idx_secretary_confidential_letters_date` (`letter_date`),
  KEY `idx_secretary_confidential_letters_status` (`status`),
  KEY `idx_secretary_confidential_letters_level` (`confidentiality_level`),
  CONSTRAINT `fk_secretary_confidential_letters_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_secretary_confidential_letters_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
