START TRANSACTION;

CREATE TABLE IF NOT EXISTS `training_groups` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
  `batch` INT NOT NULL,
  `group_code` VARCHAR(30) NOT NULL,
  `group_name` VARCHAR(150) NOT NULL,
  `group_philosophy` TEXT DEFAULT NULL,
  `mentor_summary` TEXT DEFAULT NULL,
  `target_member_count` INT NOT NULL DEFAULT 1,
  `generated_by` INT DEFAULT NULL,
  `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_training_groups_unit_batch` (`unit_code`, `batch`, `status`),
  KEY `idx_training_groups_generated_by` (`generated_by`),
  CONSTRAINT `fk_training_groups_generated_by`
    FOREIGN KEY (`generated_by`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `training_group_members` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `group_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `member_role` ENUM('trainee','mentor') NOT NULL,
  `assignment_source` ENUM('generated','auto_online_fill','manual_manager') NOT NULL DEFAULT 'generated',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unassigned_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_training_group_member_active` (`group_id`, `user_id`, `member_role`, `is_active`),
  KEY `idx_training_group_members_user_active` (`user_id`, `is_active`),
  KEY `idx_training_group_members_role` (`member_role`, `is_active`),
  CONSTRAINT `fk_training_group_members_group`
    FOREIGN KEY (`group_id`) REFERENCES `training_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_training_group_members_user`
    FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
