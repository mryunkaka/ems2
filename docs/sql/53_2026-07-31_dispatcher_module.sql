-- EMS2
-- Dispatcher module: koordinasi status & respon lapangan medis
-- Date: 2026-07-31

CREATE TABLE IF NOT EXISTS `dispatcher_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_code` varchar(40) NOT NULL,
  `status_code` varchar(30) NOT NULL,
  `status_label_custom` varchar(100) DEFAULT NULL,
  `coordinate` varchar(100) DEFAULT NULL,
  `location_name` varchar(150) DEFAULT NULL,
  `koordinasi_note` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `status` enum('active','cleared') NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `cleared_at` datetime DEFAULT NULL,
  `cleared_by` int(11) DEFAULT NULL,
  `cleared_by_name_snapshot` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_by_name_snapshot` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dispatcher_assignment_code` (`assignment_code`),
  KEY `idx_dispatcher_assignment_status` (`status`),
  KEY `idx_dispatcher_assignment_unit_status` (`unit_code`, `status`),
  KEY `idx_dispatcher_assignment_status_code` (`status_code`),
  KEY `idx_dispatcher_assignment_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dispatcher_assignment_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_id` int(11) NOT NULL,
  `medic_user_id` int(11) NOT NULL,
  `medic_name_snapshot` varchar(100) DEFAULT NULL,
  `medic_jabatan_snapshot` varchar(60) DEFAULT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dam_assignment_id` (`assignment_id`),
  KEY `idx_dam_medic_user_id` (`medic_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
