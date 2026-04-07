-- Secretary attachment tables
-- Adds compressed image attachment storage for visit agendas,
-- internal coordinations, and confidential letters.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `secretary_visit_agenda_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agenda_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secretary_visit_agenda_attachments_agenda` (`agenda_id`),
  KEY `idx_secretary_visit_agenda_attachments_sort` (`agenda_id`, `sort_order`, `id`),
  CONSTRAINT `fk_secretary_visit_agenda_attachments_agenda`
    FOREIGN KEY (`agenda_id`) REFERENCES `secretary_visit_agendas` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `secretary_internal_coordination_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coordination_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secretary_internal_coordination_attachments_coordination` (`coordination_id`),
  KEY `idx_secretary_internal_coordination_attachments_sort` (`coordination_id`, `sort_order`, `id`),
  CONSTRAINT `fk_secretary_internal_coordination_attachments_coordination`
    FOREIGN KEY (`coordination_id`) REFERENCES `secretary_internal_coordinations` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `secretary_confidential_letter_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `letter_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secretary_confidential_letter_attachments_letter` (`letter_id`),
  KEY `idx_secretary_confidential_letter_attachments_sort` (`letter_id`, `sort_order`, `id`),
  CONSTRAINT `fk_secretary_confidential_letter_attachments_letter`
    FOREIGN KEY (`letter_id`) REFERENCES `secretary_confidential_letters` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
