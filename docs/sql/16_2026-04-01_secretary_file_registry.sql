-- Secretary file registry
-- Stores searchable secretary file data such as proposals,
-- cooperation files, contracts, reports, and other archives.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `secretary_file_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_code` VARCHAR(100) NOT NULL,
  `file_category` ENUM('proposal', 'cooperation', 'contract', 'report', 'other') NOT NULL DEFAULT 'other',
  `reference_number` VARCHAR(120) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `counterparty_name` VARCHAR(180) NOT NULL,
  `document_date` DATE NOT NULL,
  `status` ENUM('draft', 'review', 'active', 'archived') NOT NULL DEFAULT 'draft',
  `keywords` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_secretary_file_records_code` (`file_code`),
  KEY `idx_secretary_file_records_category` (`file_category`),
  KEY `idx_secretary_file_records_date` (`document_date`),
  KEY `idx_secretary_file_records_status` (`status`),
  KEY `idx_secretary_file_records_reference` (`reference_number`),
  KEY `idx_secretary_file_records_counterparty` (`counterparty_name`),
  CONSTRAINT `fk_secretary_file_records_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_secretary_file_records_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `secretary_file_record_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_secretary_file_record_attachments_record` (`record_id`),
  KEY `idx_secretary_file_record_attachments_sort` (`record_id`, `sort_order`, `id`),
  CONSTRAINT `fk_secretary_file_record_attachments_record`
    FOREIGN KEY (`record_id`) REFERENCES `secretary_file_records` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
