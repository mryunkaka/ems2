CREATE TABLE IF NOT EXISTS `meeting_minutes_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `meeting_minutes_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_meeting_minutes_attachments_minutes` (`meeting_minutes_id`),
  CONSTRAINT `fk_meeting_minutes_attachments_minutes`
    FOREIGN KEY (`meeting_minutes_id`) REFERENCES `meeting_minutes` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
