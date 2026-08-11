-- EMS2
-- Setting AI pribadi untuk AI Diagnosis Assistant & AI Surgery Planner
-- Setiap user mengisi API key Gemini miliknya sendiri, terpisah dari
-- system_ai_settings global yang dipakai fitur AI lain di ems2.
-- Date: 2026-08-11

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `user_ai_settings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `gemini_api_key` VARCHAR(255) NOT NULL,
    `gemini_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://generativelanguage.googleapis.com/v1beta',
    `default_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.5-flash',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_ai_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
