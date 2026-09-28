-- Koreksi Roxy masuk antrean review manager-plus. Hanya koreksi yang
-- disetujui yang dipakai ulang, dan pencocokannya exact per unit.
CREATE TABLE IF NOT EXISTS `bot_answer_corrections` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `conversation_id` INT NOT NULL,
    `original_message_id` INT NOT NULL,
    `question_snapshot` MEDIUMTEXT NOT NULL,
    `wrong_answer_snapshot` MEDIUMTEXT NOT NULL,
    `corrected_answer` MEDIUMTEXT NOT NULL,
    `submitted_by` INT NOT NULL,
    `submitted_by_name` VARCHAR(150) NOT NULL,
    `verification_status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    `verification_note` TEXT DEFAULT NULL,
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_by_name` VARCHAR(150) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roxy_correction_message_user` (`original_message_id`, `submitted_by`),
    KEY `idx_roxy_correction_queue` (`unit_code`, `verification_status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_learned_answers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `question_hash` CHAR(64) NOT NULL,
    `question_text` MEDIUMTEXT NOT NULL,
    `answer_text` MEDIUMTEXT NOT NULL,
    `source_correction_id` INT NOT NULL,
    `times_reused` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roxy_learned_unit_question` (`unit_code`, `question_hash`),
    UNIQUE KEY `uq_roxy_learned_source_correction` (`source_correction_id`),
    KEY `idx_roxy_learned_unit` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
