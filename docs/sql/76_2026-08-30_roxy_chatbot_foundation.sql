-- Fondasi modul asisten AI internal "Roxy" — lihat docs/AI_ASSISTANT_MODULE.md
-- untuk PRD/ERD lengkap. Fase 1 (MVP) saja: percakapan, pesan, dan knowledge
-- base manual. bot_answer_corrections/bot_learned_answers (alur koreksi &
-- pelatihan) sengaja BELUM dibuat di sini — itu Fase 2.
--
-- Groq API key SENGAJA per-user (kolom di user_ai_settings, tabel yang sama
-- dipakai Gemini pribadi), BUKAN satu key global — dicek langsung lewat
-- header rate-limit respons Groq sungguhan (2026-08-30): tier gratis Groq
-- cuma 1.000 request/hari + 8.000 token/menit PER AKUN. Dengan 154 staff
-- aktif di database ini (119 di antaranya divisi Medis), satu key global
-- dibagi rata semua orang jelas tidak cukup — satu obrolan panjang saja
-- (desain Roxy mengirim ulang seluruh riwayat percakapan tiap balasan) bisa
-- menghabiskan jatah harian untuk SEMUA orang. Per-user key = tiap orang
-- punya jatah 1.000 request/hari sendiri-sendiri, sama seperti pola Gemini
-- pribadi yang sudah terbukti jalan.

ALTER TABLE `user_ai_settings`
    ADD COLUMN `groq_api_key` VARCHAR(255) NULL AFTER `default_model`,
    ADD COLUMN `groq_default_model` VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-oss-120b' AFTER `groq_api_key`;

CREATE TABLE IF NOT EXISTS `bot_conversations` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `user_id` INT NOT NULL,
    `title` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_message_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bot_conversations_user` (`user_id`, `status`),
    KEY `idx_bot_conversations_unit` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_messages` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `conversation_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `sender` ENUM('user','bot') NOT NULL,
    `content` MEDIUMTEXT NOT NULL,
    `reply_to_message_id` INT DEFAULT NULL,
    `answer_source` ENUM('knowledge_base','learned_correction','free_model','gemini_personal') DEFAULT NULL,
    `expression_tag` VARCHAR(30) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bot_messages_conversation` (`conversation_id`, `created_at`),
    KEY `idx_bot_messages_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_knowledge_base` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `category` VARCHAR(100) DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `content` MEDIUMTEXT NOT NULL,
    `tags` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_by_name_snapshot` VARCHAR(150) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bot_knowledge_base_unit` (`unit_code`),
    FULLTEXT KEY `ftx_bot_knowledge_base_search` (`title`, `tags`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
