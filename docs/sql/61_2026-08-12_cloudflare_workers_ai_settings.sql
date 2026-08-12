-- EMS2
-- Cloudflare Workers AI (image generation) sebagai provider alternatif/gratis
-- untuk Radiology Center, karena API key Gemini personal yang ada belum
-- punya kuota image-generation (perlu billing aktif di Google).
-- Global, dikelola Programmer Roxwood saja (sama seperti system_ai_settings
-- untuk Gemini rekrutmen) — bukan per-user seperti user_ai_settings, karena
-- setup akun Cloudflare + API token lebih ribet dibanding tempel API key.
-- Date: 2026-08-12

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `system_cloudflare_settings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `account_id` VARCHAR(64) NOT NULL DEFAULT '',
    `api_token` VARCHAR(255) NOT NULL DEFAULT '',
    `default_model` VARCHAR(100) NOT NULL DEFAULT '@cf/black-forest-labs/flux-1-schnell',
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
