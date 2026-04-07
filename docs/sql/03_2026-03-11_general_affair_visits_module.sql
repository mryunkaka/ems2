-- General Affair Visits module
-- Create visit registry for General Affair operational scheduling

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `general_affair_visits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_code` VARCHAR(100) NOT NULL,
  `visitor_name` VARCHAR(150) NOT NULL,
  `institution_name` VARCHAR(150) DEFAULT NULL,
  `visitor_phone` VARCHAR(50) DEFAULT NULL,
  `visit_purpose` TEXT NOT NULL,
  `visit_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME DEFAULT NULL,
  `location` VARCHAR(150) NOT NULL,
  `pic_user_id` INT NOT NULL,
  `status` ENUM('scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_general_affair_visits_code` (`visit_code`),
  KEY `idx_general_affair_visits_date` (`visit_date`),
  KEY `idx_general_affair_visits_status` (`status`),
  KEY `idx_general_affair_visits_pic_user` (`pic_user_id`),
  KEY `idx_general_affair_visits_created_by` (`created_by`),
  KEY `idx_general_affair_visits_updated_by` (`updated_by`),
  CONSTRAINT `fk_general_affair_visits_pic_user`
    FOREIGN KEY (`pic_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_general_affair_visits_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_general_affair_visits_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
