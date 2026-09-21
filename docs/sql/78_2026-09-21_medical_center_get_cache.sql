-- Medical Center GET-only read cache.
-- No POST, PUT, PATCH, DELETE, outbox, or Idempotency-Key path exists.

CREATE TABLE IF NOT EXISTS `medical_record_integrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(50) NOT NULL DEFAULT 'medical_center',
  `hospital_code` VARCHAR(50) NOT NULL,
  `remote_record_id` VARCHAR(100) NOT NULL,
  `remote_event_at` DATETIME NULL,
  `remote_created_at` DATETIME NULL,
  `remote_updated_at` DATETIME NULL,
  `patient_name` VARCHAR(255) NULL,
  `record_payload_json` LONGTEXT NOT NULL,
  `payload_hash` CHAR(64) NOT NULL,
  `sync_state` ENUM('synced','needs_review','stale') NOT NULL DEFAULT 'synced',
  `last_pulled_at` DATETIME NOT NULL,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mri_provider_hospital_remote` (`provider`, `hospital_code`, `remote_record_id`),
  KEY `idx_mri_remote_event_at` (`remote_event_at`),
  KEY `idx_mri_sync_state` (`sync_state`),
  KEY `idx_mri_last_pulled_at` (`last_pulled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `medical_record_api_sync_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` VARCHAR(50) NOT NULL DEFAULT 'medical_center',
  `hospital_code` VARCHAR(50) NOT NULL,
  `status` ENUM('synced','failed','needs_review') NOT NULL,
  `pages_fetched` INT UNSIGNED NOT NULL DEFAULT 0,
  `records_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `records_stored` INT UNSIGNED NOT NULL DEFAULT 0,
  `records_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` TEXT NULL,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mrasr_lookup` (`provider`, `hospital_code`, `finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
