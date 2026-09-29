-- Persist each AI-authored surgery-plan section independently so slow or
-- interrupted generations can resume without repeating completed sections.
UPDATE `system_ai_prompt_templates`
SET `user_prompt_template` = REPLACE(
    `user_prompt_template`,
    'Kembalikan seluruh rencana dalam satu JSON sesuai schema.',
    'Kembalikan hanya bagian yang diminta pada tahap generasi saat ini sesuai schema respons.'
)
WHERE `feature_key` = 'ai_surgery_planner'
  AND `user_prompt_template` LIKE '%Kembalikan seluruh rencana dalam satu JSON sesuai schema.%';

CREATE TABLE IF NOT EXISTS `ai_surgery_generation_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_token` CHAR(36) NOT NULL,
    `user_id` INT NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `division_snapshot` VARCHAR(60) NULL,
    `jenis_operasi_kategori` ENUM('Minor','Mayor') NOT NULL DEFAULT 'Mayor',
    `jenis_anestesi_input` VARCHAR(100) NOT NULL,
    `kompleksitas` ENUM('Mudah','Sedang','Panjang') NOT NULL DEFAULT 'Sedang',
    `kasus_tindakan` TEXT NOT NULL,
    `source_report_code` VARCHAR(40) NULL,
    `diagnosis_context` LONGTEXT NULL,
    `system_prompt` LONGTEXT NOT NULL,
    `user_prompt` LONGTEXT NOT NULL,
    `outline_json` LONGTEXT NULL,
    `status` ENUM('running','repairing','done','error') NOT NULL DEFAULT 'running',
    `next_stage_no` INT NOT NULL DEFAULT 1,
    `planned_step_count` SMALLINT UNSIGNED NULL,
    `repair_indexes_json` TEXT NULL,
    `lock_expires_at` DATETIME NULL,
    `final_plan_id` INT NULL,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ai_surgery_job_token` (`job_token`),
    KEY `idx_ai_surgery_job_user_status` (`user_id`, `status`, `updated_at`),
    KEY `idx_ai_surgery_job_unit` (`unit_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ai_surgery_generation_stages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` BIGINT UNSIGNED NOT NULL,
    `stage_no` INT NOT NULL,
    `attempt_no` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `stage_type` VARCHAR(20) NOT NULL,
    `status` ENUM('done','error') NOT NULL,
    `result_json` LONGTEXT NULL,
    `validation_errors_json` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ai_surgery_job_stage_attempt` (`job_id`, `stage_no`, `attempt_no`),
    KEY `idx_ai_surgery_stage_job` (`job_id`, `stage_no`),
    CONSTRAINT `fk_ai_surgery_stage_job` FOREIGN KEY (`job_id`) REFERENCES `ai_surgery_generation_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bound simultaneous Surgery Planner provider calls on shared hosting.
CREATE TABLE IF NOT EXISTS `ai_surgery_generation_provider_slots` (
    `slot_id` TINYINT UNSIGNED NOT NULL,
    `job_id` BIGINT UNSIGNED NULL,
    `lock_token` CHAR(36) NULL,
    `expires_at` DATETIME NULL,
    PRIMARY KEY (`slot_id`),
    KEY `idx_ai_surgery_provider_slot_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `ai_surgery_generation_provider_slots` (`slot_id`) VALUES (1), (2);
