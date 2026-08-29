-- Document Library module ("Dokumen" menu) — lihat docs/DOCUMENT_LIBRARY_MODULE.md
-- Tabel juga dibuat defensif lewat ems_document_ensure_tables() di
-- config/document_library.php (pola project ini: migration file untuk
-- histori, ensure_tables() untuk runtime).

CREATE TABLE IF NOT EXISTS `document_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `division` varchar(60) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name_snapshot` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_document_folders_parent` (`parent_id`),
  KEY `idx_document_folders_unit_division` (`unit_code`, `division`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `document_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `folder_id` int(11) NOT NULL,
  `division` varchar(60) NOT NULL,
  `title` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_ext` varchar(10) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size_bytes` int(11) NOT NULL DEFAULT 0,
  `tags` varchar(255) DEFAULT NULL,
  `extracted_text` longtext DEFAULT NULL,
  `extraction_status` enum('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending',
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_by_name_snapshot` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_document_files_folder` (`folder_id`),
  KEY `idx_document_files_unit_division` (`unit_code`, `division`),
  FULLTEXT KEY `ftx_document_files_search` (`title`, `tags`, `extracted_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `document_activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
  `document_id` int(11) DEFAULT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `action` enum('uploaded','edited','replaced','moved','deleted','folder_created','folder_renamed','folder_moved','folder_deleted') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_name_snapshot` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_document_activity_logs_document` (`document_id`),
  KEY `idx_document_activity_logs_folder` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
