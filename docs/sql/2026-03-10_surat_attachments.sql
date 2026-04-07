CREATE TABLE IF NOT EXISTS `incoming_letter_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incoming_letter_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incoming_letter_attachments_letter` (`incoming_letter_id`),
  CONSTRAINT `fk_incoming_letter_attachments_letter`
    FOREIGN KEY (`incoming_letter_id`) REFERENCES `incoming_letters` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outgoing_letter_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `outgoing_letter_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_outgoing_letter_attachments_letter` (`outgoing_letter_id`),
  CONSTRAINT `fk_outgoing_letter_attachments_letter`
    FOREIGN KEY (`outgoing_letter_id`) REFERENCES `outgoing_letters` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
