-- EMS2
-- Laboratory AI: generate hasil pemeriksaan laboratorium (nilai parameter,
-- rentang rujukan, flag H/L/Normal) + interpretasi klinis simulasi roleplay,
-- memakai API key Gemini pribadi yang sama dengan AI Diagnosis/Surgery
-- (tabel user_ai_settings, lihat migration 58).
-- Date: 2026-08-12

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `ai_laboratory_results` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `report_code` VARCHAR(40) NULL,
    `user_id` INT NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `division_snapshot` VARCHAR(60) NULL,
    `patient_name` VARCHAR(150) NULL,
    `patient_dob` DATE NULL,
    `patient_citizen_id` VARCHAR(50) NULL,
    `doctor_name` VARCHAR(150) NULL,
    `department` VARCHAR(100) NOT NULL,
    `category` VARCHAR(100) NOT NULL,
    `level3_option` VARCHAR(100) NULL,
    `custom_parameters` TEXT NULL,
    `specimen_type` VARCHAR(150) NOT NULL,
    `clinical_info` TEXT NOT NULL,
    `result_json` LONGTEXT NULL,
    `status` ENUM('done','error') NOT NULL DEFAULT 'done',
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ai_laboratory_report_code` (`report_code`),
    KEY `idx_ai_laboratory_user` (`user_id`),
    KEY `idx_ai_laboratory_unit_created` (`unit_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
