-- EMS2
-- Dispatcher module: Penanggung Jawab (PIC) + Roster medis yang tampil di Papan Status
-- Date: 2026-08-05

CREATE TABLE IF NOT EXISTS `dispatcher_supervisors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `user_name_snapshot` varchar(100) DEFAULT NULL,
  `activated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dispatcher_supervisor_user_unit` (`user_id`, `unit_code`),
  KEY `idx_dispatcher_supervisor_unit` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dispatcher_roster` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medic_user_id` int(11) NOT NULL,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `medic_name_snapshot` varchar(100) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_by_name_snapshot` varchar(100) DEFAULT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dispatcher_roster_medic_unit` (`medic_user_id`, `unit_code`),
  KEY `idx_dispatcher_roster_unit` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
