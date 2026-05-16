START TRANSACTION;

CREATE TABLE IF NOT EXISTS `training_user_availability` (
  `user_id` INT NOT NULL,
  `status` ENUM('online','offline') NOT NULL DEFAULT 'offline',
  `last_activity_at` DATETIME DEFAULT NULL,
  `last_confirm_at` DATETIME DEFAULT NULL,
  `current_session_number` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_training_user_availability_user`
    FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `training_user_availability_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `session_number` INT NOT NULL DEFAULT 1,
  `session_start` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `session_end` DATETIME DEFAULT NULL,
  `duration_seconds` INT DEFAULT NULL,
  `end_reason` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_training_availability_sessions_user_open` (`user_id`, `session_end`),
  KEY `idx_training_availability_sessions_user_number` (`user_id`, `session_number`),
  CONSTRAINT `fk_training_user_availability_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
