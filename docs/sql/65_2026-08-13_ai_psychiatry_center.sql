-- EMS2
-- Psychiatry Center: asesmen psikiatri multi-turn (interview dinamis oleh AI)
-- yang berujung ke laporan diagnosis DSM-5/ICD-10 formal + MSE + risk
-- assessment + treatment plan + farmakoterapi. Memakai API key Gemini
-- pribadi yang sama dengan AI Diagnosis/Surgery/Radiology/Laboratory
-- (tabel user_ai_settings, lihat migration 58).
-- Date: 2026-08-13

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `ai_psychiatry_assessments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `report_code` VARCHAR(40) NULL,
    `user_id` INT NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `division_snapshot` VARCHAR(60) NULL,
    `patient_name` VARCHAR(150) NULL,
    `patient_dob` DATE NULL,
    `patient_citizen_id` VARCHAR(50) NULL,
    `doctor_name` VARCHAR(150) NULL,
    `department` VARCHAR(60) NOT NULL,
    `assessment_type` VARCHAR(40) NOT NULL,
    `priority` VARCHAR(20) NOT NULL,
    `chief_complaint` TEXT NULL,
    `anamnesis` TEXT NULL,
    `chat_transcript` LONGTEXT NULL,
    `result_json` LONGTEXT NULL,
    `status` ENUM('done','error') NOT NULL DEFAULT 'done',
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ai_psychiatry_report_code` (`report_code`),
    KEY `idx_ai_psychiatry_user` (`user_id`),
    KEY `idx_ai_psychiatry_unit_created` (`unit_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
