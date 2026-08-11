-- EMS2
-- AI Diagnosis Assistant & AI Surgery Planner (Roxwood Hospital AI)
-- Menyimpan riwayat hasil generate ke database, plus prompt template Gemini.
-- Date: 2026-08-11

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `ai_diagnosis_reports` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `division_snapshot` VARCHAR(60) NULL,
    `anamnesis` TEXT NOT NULL,
    `result_json` LONGTEXT NULL,
    `status` ENUM('done','error') NOT NULL DEFAULT 'done',
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_diagnosis_user` (`user_id`),
    KEY `idx_ai_diagnosis_unit_created` (`unit_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ai_surgery_plans` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
    `division_snapshot` VARCHAR(60) NULL,
    `jenis_operasi_kategori` ENUM('Minor','Mayor') NOT NULL DEFAULT 'Mayor',
    `jenis_anestesi_input` VARCHAR(100) NOT NULL,
    `kompleksitas` ENUM('Mudah','Sedang','Panjang') NOT NULL DEFAULT 'Sedang',
    `kasus_tindakan` TEXT NOT NULL,
    `result_json` LONGTEXT NULL,
    `status` ENUM('done','error') NOT NULL DEFAULT 'done',
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_surgery_user` (`user_id`),
    KEY `idx_ai_surgery_unit_created` (`unit_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Prompt template dasar (persona + aturan inti) — bisa diedit lewat tabel ini di
-- masa depan tanpa deploy ulang. Referensi SOP/mantra/animasi/kewenangan tetap
-- disisipkan dari config/ai_diagnosis_surgery.php di runtime (data dari dokumen,
-- bukan konten yang perlu sering diedit lewat UI).
INSERT INTO `system_ai_prompt_templates`
(`feature_key`, `title`, `system_prompt`, `user_prompt_template`, `is_active`, `version_label`)
SELECT
    'ai_diagnosis_assistant',
    'AI Diagnosis Assistant (Roxwood Hospital)',
    NULL,
    'ANAMNESIS:\n{{anamnesis}}\n\nTUGAS: Susun laporan medis yang LENGKAP dan DEFINITIF berdasarkan anamnesis ini. Bila anamnesis singkat/tidak lengkap, lengkapi SENDIRI seluruh data yang hilang dengan asumsi klinis yang realistis, masuk akal, dan sesuai standar medis (ikuti instruksi sistem) — JANGAN mengembalikan daftar data yang dibutuhkan atau pertanyaan klarifikasi.',
    1,
    'v1'
WHERE NOT EXISTS (
    SELECT 1 FROM `system_ai_prompt_templates` WHERE `feature_key` = 'ai_diagnosis_assistant'
);

INSERT INTO `system_ai_prompt_templates`
(`feature_key`, `title`, `system_prompt`, `user_prompt_template`, `is_active`, `version_label`)
SELECT
    'ai_surgery_planner',
    'AI Surgery Planner (Roxwood Hospital)',
    NULL,
    'JENIS OPERASI: {{jenis_operasi}}\nJENIS ANESTESI: {{jenis_anestesi}}\nTINGKAT KOMPLEKSITAS: {{kompleksitas}}\nJUMLAH LANGKAH: {{jumlah_langkah}}\nKASUS MEDIS / TINDAKAN YANG DIPERLUKAN:\n{{kasus_tindakan}}\n\nTUGAS: Susun rencana operasi (operative note) yang LENGKAP dan DEFINITIF berdasarkan data di atas, dengan "tahapan_prosedur" berjumlah PERSIS {{jumlah_langkah}} langkah (tidak kurang, tidak lebih). Bila kasusnya singkat/tidak lengkap, lengkapi SENDIRI seluruh detail yang hilang dengan asumsi klinis yang realistis dan sesuai standar bedah (ikuti instruksi sistem) — JANGAN mengembalikan pertanyaan klarifikasi.',
    1,
    'v1'
WHERE NOT EXISTS (
    SELECT 1 FROM `system_ai_prompt_templates` WHERE `feature_key` = 'ai_surgery_planner'
);

COMMIT;
