-- Disciplinary Committee module
-- Create master indications, cases, case items, and warning letters

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `disciplinary_indications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(100) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `default_points` INT NOT NULL DEFAULT 0,
  `tolerance_type` ENUM('tolerable', 'non_tolerable') NOT NULL DEFAULT 'tolerable',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_disciplinary_indications_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `disciplinary_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_code` VARCHAR(100) NOT NULL,
  `subject_user_id` INT NOT NULL,
  `case_name` VARCHAR(150) NOT NULL,
  `case_date` DATE NOT NULL,
  `summary` TEXT DEFAULT NULL,
  `status` ENUM('open', 'reviewed', 'escalated', 'closed') NOT NULL DEFAULT 'open',
  `total_points` INT NOT NULL DEFAULT 0,
  `tolerable_count` INT NOT NULL DEFAULT 0,
  `non_tolerable_count` INT NOT NULL DEFAULT 0,
  `tolerance_summary` ENUM('tolerable', 'mixed', 'non_tolerable') NOT NULL DEFAULT 'tolerable',
  `recommended_action` VARCHAR(100) NOT NULL DEFAULT 'coaching',
  `letter_status` ENUM('not_needed', 'pending', 'issued') NOT NULL DEFAULT 'pending',
  `created_by` INT NOT NULL,
  `reviewed_by` INT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_disciplinary_cases_code` (`case_code`),
  KEY `idx_disciplinary_cases_subject_user` (`subject_user_id`),
  KEY `idx_disciplinary_cases_created_by` (`created_by`),
  KEY `idx_disciplinary_cases_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_disciplinary_cases_subject_user`
    FOREIGN KEY (`subject_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_disciplinary_cases_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_disciplinary_cases_reviewed_by`
    FOREIGN KEY (`reviewed_by`) REFERENCES `user_rh` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `disciplinary_case_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `indication_id` BIGINT UNSIGNED DEFAULT NULL,
  `indication_name_snapshot` VARCHAR(150) NOT NULL,
  `points_snapshot` INT NOT NULL DEFAULT 0,
  `tolerance_type_snapshot` ENUM('tolerable', 'non_tolerable') NOT NULL DEFAULT 'tolerable',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disciplinary_case_items_case_id` (`case_id`),
  KEY `idx_disciplinary_case_items_indication_id` (`indication_id`),
  CONSTRAINT `fk_disciplinary_case_items_case`
    FOREIGN KEY (`case_id`) REFERENCES `disciplinary_cases` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_disciplinary_case_items_indication`
    FOREIGN KEY (`indication_id`) REFERENCES `disciplinary_indications` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `disciplinary_warning_letters` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `letter_code` VARCHAR(100) NOT NULL,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `subject_user_id` INT NOT NULL,
  `letter_type` VARCHAR(100) NOT NULL,
  `issued_date` DATE NOT NULL,
  `effective_date` DATE DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body_notes` TEXT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_disciplinary_warning_letters_code` (`letter_code`),
  KEY `idx_disciplinary_warning_letters_case_id` (`case_id`),
  KEY `idx_disciplinary_warning_letters_subject_user_id` (`subject_user_id`),
  KEY `idx_disciplinary_warning_letters_created_by` (`created_by`),
  CONSTRAINT `fk_disciplinary_warning_letters_case`
    FOREIGN KEY (`case_id`) REFERENCES `disciplinary_cases` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_disciplinary_warning_letters_subject_user`
    FOREIGN KEY (`subject_user_id`) REFERENCES `user_rh` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_disciplinary_warning_letters_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `disciplinary_indications` (`code`, `name`, `description`, `default_points`, `tolerance_type`, `is_active`)
VALUES
  ('late_reporting', 'Late Reporting', 'Keterlambatan hadir atau terlambat merespon tugas resmi.', 10, 'tolerable', 1),
  ('absence_without_notice', 'Absence Without Notice', 'Tidak hadir tanpa konfirmasi atau pemberitahuan resmi.', 35, 'non_tolerable', 1),
  ('insubordination', 'Insubordination', 'Tidak mengikuti instruksi kerja yang sah dan tercatat.', 30, 'non_tolerable', 1),
  ('misconduct', 'Misconduct', 'Perilaku tidak profesional yang mencederai disiplin kerja.', 25, 'tolerable', 1),
  ('data_breach', 'Data Breach', 'Pelanggaran kerahasiaan data atau penyalahgunaan akses data.', 60, 'non_tolerable', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `default_points` = VALUES(`default_points`),
  `tolerance_type` = VALUES(`tolerance_type`),
  `is_active` = VALUES(`is_active`);

COMMIT;
