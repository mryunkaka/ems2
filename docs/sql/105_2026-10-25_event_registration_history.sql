CREATE TABLE IF NOT EXISTS `event_registration_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `event_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `action` ENUM('registered', 'cancelled') NOT NULL,
    `reason` VARCHAR(1000) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_registration_history_event_user` (`event_id`, `user_id`, `id`),
    KEY `idx_event_registration_history_event_action` (`event_id`, `action`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
