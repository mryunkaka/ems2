START TRANSACTION;

CREATE TABLE IF NOT EXISTS `system_ai_settings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'gemini',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `gemini_api_key` TEXT NULL,
    `gemini_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://generativelanguage.googleapis.com/v1beta',
    `default_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.5-flash',
    `summary_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.5-flash',
    `interview_question_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.5-flash',
    `criteria_scoring_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.5-flash',
    `temperature` DECIMAL(4,2) NOT NULL DEFAULT 0.40,
    `top_p` DECIMAL(4,2) NOT NULL DEFAULT 0.95,
    `top_k` INT NOT NULL DEFAULT 40,
    `max_output_tokens` INT NOT NULL DEFAULT 2048,
    `timeout_seconds` INT NOT NULL DEFAULT 30,
    `daily_request_limit` INT NOT NULL DEFAULT 200,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_system_ai_settings_provider` (`provider`),
    KEY `idx_system_ai_settings_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_ai_request_logs` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `feature_key` VARCHAR(100) NOT NULL,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'gemini',
    `model_name` VARCHAR(100) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `request_payload` MEDIUMTEXT NULL,
    `response_payload` MEDIUMTEXT NULL,
    `prompt_tokens` INT NULL,
    `response_tokens` INT NULL,
    `total_tokens` INT NULL,
    `http_status` INT NULL,
    `latency_ms` INT NULL,
    `success_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `error_message` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_system_ai_request_logs_feature` (`feature_key`),
    KEY `idx_system_ai_request_logs_provider` (`provider`),
    KEY `idx_system_ai_request_logs_success` (`success_flag`),
    KEY `idx_system_ai_request_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_ai_prompt_templates` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `feature_key` VARCHAR(100) NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `system_prompt` MEDIUMTEXT NULL,
    `user_prompt_template` LONGTEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `version_label` VARCHAR(50) NOT NULL DEFAULT 'v1',
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_system_ai_prompt_templates_feature` (`feature_key`),
    KEY `idx_system_ai_prompt_templates_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `system_ai_settings`
(
    `id`,
    `provider`,
    `is_enabled`,
    `gemini_api_key`,
    `gemini_base_url`,
    `default_model`,
    `summary_model`,
    `interview_question_model`,
    `criteria_scoring_model`,
    `temperature`,
    `top_p`,
    `top_k`,
    `max_output_tokens`,
    `timeout_seconds`,
    `daily_request_limit`
)
SELECT
    1,
    'gemini',
    1,
    NULL,
    'https://generativelanguage.googleapis.com/v1beta',
    'gemini-2.5-flash',
    'gemini-2.5-flash',
    'gemini-2.5-flash',
    'gemini-2.5-flash',
    0.40,
    0.95,
    40,
    2048,
    30,
    200
WHERE NOT EXISTS (
    SELECT 1 FROM `system_ai_settings` WHERE `id` = 1
);

INSERT INTO `system_ai_prompt_templates`
(`feature_key`, `title`, `system_prompt`, `user_prompt_template`, `is_active`, `version_label`)
SELECT
    'candidate_summary_assistant_manager',
    'Ringkasan Kandidat Assistant Manager',
    'Anda adalah asisten HR internal EMS. Tugas Anda hanya merangkum profil kandidat secara objektif dan terstruktur. Jangan membuat keputusan final lolos atau tidak lolos.',
    'Buat JSON valid berbahasa Indonesia dengan field summary, strengths, risks, follow_up_points. Field summary harus berupa satu ringkasan utuh tanpa summary_short atau summary_full. Gunakan data berikut: {{candidate_payload}}',
    1,
    'v1'
WHERE NOT EXISTS (
    SELECT 1 FROM `system_ai_prompt_templates` WHERE `feature_key` = 'candidate_summary_assistant_manager'
);

INSERT INTO `system_ai_prompt_templates`
(`feature_key`, `title`, `system_prompt`, `user_prompt_template`, `is_active`, `version_label`)
SELECT
    'candidate_summary_medical_candidate',
    'Ringkasan Kandidat Medis',
    'Anda adalah asisten HR internal EMS. Tugas Anda hanya merangkum profil kandidat secara objektif dan terstruktur. Jangan membuat keputusan final lolos atau tidak lolos.',
    'Buat JSON valid berbahasa Indonesia dengan field summary, strengths, risks, follow_up_points. Field summary harus berupa satu ringkasan utuh tanpa summary_short atau summary_full. Gunakan data berikut: {{candidate_payload}}',
    1,
    'v1'
WHERE NOT EXISTS (
    SELECT 1 FROM `system_ai_prompt_templates` WHERE `feature_key` = 'candidate_summary_medical_candidate'
);

INSERT INTO `system_ai_prompt_templates`
(`feature_key`, `title`, `system_prompt`, `user_prompt_template`, `is_active`, `version_label`)
SELECT
    'interview_question_pack',
    'Generator Pertanyaan Interview',
    'Anda adalah asisten HR internal EMS. Tugas Anda menyusun pertanyaan interview berbasis assessment kandidat tanpa mengambil keputusan final.',
    'Buat JSON valid berbahasa Indonesia dengan field medical_questions dan personal_questions. medical_questions harus berisi tepat 10 pertanyaan seputar medis, SOP, disiplin, sopan santun, leadership, dan verifikasi sikap kerja. personal_questions harus berisi tepat 10 pertanyaan non-medis mencakup kepribadian, sisi baik, sisi buruk, jebakan, kondisi mental saat tertekan, dan verifikasi jawaban assessment yang tampak bertentangan. Setiap item wajib berbentuk object dengan field text, intent, criterion_code, good_answer, bad_answer. Field good_answer dan bad_answer harus spesifik terhadap isi pertanyaan tersebut, bukan template umum, bukan pengulangan, dan harus memberi gambaran jawaban kuat versus jawaban lemah. Gunakan data berikut: {{candidate_payload}}',
    1,
    'v1'
WHERE NOT EXISTS (
    SELECT 1 FROM `system_ai_prompt_templates` WHERE `feature_key` = 'interview_question_pack'
);

INSERT INTO `system_ai_prompt_templates`
(`feature_key`, `title`, `system_prompt`, `user_prompt_template`, `is_active`, `version_label`)
SELECT
    'criteria_scoring_guidance',
    'Panduan Nilai Criteria Interview',
    'Anda adalah asisten HR internal EMS. Tugas Anda memberi panduan area penilaian interview secara terstruktur berdasarkan data kandidat.',
    'Buat JSON valid berbahasa Indonesia dengan field criteria_guidance. Setiap item harus berisi criteria_code, indicators_strong, indicators_weak, probing_points, suggested_score_range. Gunakan data berikut: {{candidate_payload}}',
    1,
    'v1'
WHERE NOT EXISTS (
    SELECT 1 FROM `system_ai_prompt_templates` WHERE `feature_key` = 'criteria_scoring_guidance'
);

COMMIT;
