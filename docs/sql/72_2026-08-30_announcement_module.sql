-- Announcement / push-notification-modal module ("Kelola Pengumuman").
-- Juga dibuat defensif lewat ems_announcement_ensure_tables() di
-- config/announcement.php.

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target_type` enum('scope','user') NOT NULL DEFAULT 'scope',
  `target_scope` varchar(60) DEFAULT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `target_user_name_snapshot` varchar(150) DEFAULT NULL,
  `frequency` enum('once','every_login','every_visit') NOT NULL DEFAULT 'once',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name_snapshot` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_announcements_unit_active` (`unit_code`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `announcement_dismissals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `dismissed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_announcement_dismissal` (`announcement_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
