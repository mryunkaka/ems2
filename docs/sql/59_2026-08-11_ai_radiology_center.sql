-- EMS2
-- Radiology Center: generate citra pencitraan medis (X-Ray/CT/MRI/USG) simulasi
-- roleplay memakai model Gemini yang mendukung image generation. Memakai API
-- key Gemini pribadi yang sama dengan AI Diagnosis Assistant & AI Surgery
-- Planner (tabel user_ai_settings, lihat migration 58).
-- Date: 2026-08-11

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `ai_radiology_images` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `division_snapshot` VARCHAR(60) NULL,
    `patient_name` VARCHAR(150) NULL,
    `patient_dob` DATE NULL,
    `patient_citizen_id` VARCHAR(50) NULL,
    `doctor_name` VARCHAR(150) NULL,
    `anamnesis` TEXT NULL,
    `modality` VARCHAR(30) NOT NULL,
    `category` VARCHAR(100) NOT NULL,
    `body_region` VARCHAR(100) NOT NULL,
    `projection` VARCHAR(100) NOT NULL,
    `clinical_finding` VARCHAR(100) NOT NULL,
    `prompt_used` TEXT NULL,
    `image_path` VARCHAR(255) NULL,
    `status` ENUM('done','error') NOT NULL DEFAULT 'done',
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_radiology_user` (`user_id`),
    KEY `idx_ai_radiology_unit_created` (`unit_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
