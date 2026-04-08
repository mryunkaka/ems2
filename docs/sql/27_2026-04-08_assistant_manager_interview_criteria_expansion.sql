START TRANSACTION;

INSERT INTO `interview_criteria`
(`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT
    'logical_thinking',
    'Logical Thinking',
    'Kemampuan berpikir runtut dan logis saat menganalisis situasi kerja.',
    1.00,
    1,
    'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1
    FROM `interview_criteria`
    WHERE `code` = 'logical_thinking' AND `recruitment_type` = 'assistant_manager'
);

INSERT INTO `interview_criteria`
(`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT
    'decision_making',
    'Decision Making',
    'Kemampuan mengambil keputusan yang tepat, cepat, dan bertanggung jawab.',
    1.00,
    1,
    'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1
    FROM `interview_criteria`
    WHERE `code` = 'decision_making' AND `recruitment_type` = 'assistant_manager'
);

INSERT INTO `interview_criteria`
(`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT
    'attention_to_detail',
    'Attention to Detail',
    'Ketelitian dalam melihat detail, data, prosedur, dan potensi kesalahan kecil.',
    1.00,
    1,
    'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1
    FROM `interview_criteria`
    WHERE `code` = 'attention_to_detail' AND `recruitment_type` = 'assistant_manager'
);

INSERT INTO `interview_criteria`
(`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT
    'integrity',
    'Integrity',
    'Kejujuran, konsistensi nilai, dan kemampuan menjaga amanah dalam bekerja.',
    1.00,
    1,
    'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1
    FROM `interview_criteria`
    WHERE `code` = 'integrity' AND `recruitment_type` = 'assistant_manager'
);

INSERT INTO `interview_criteria`
(`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT
    'initiative',
    'Initiative',
    'Kemauan bergerak, mencari solusi, dan mengambil langkah kerja tanpa harus selalu menunggu perintah.',
    1.00,
    1,
    'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1
    FROM `interview_criteria`
    WHERE `code` = 'initiative' AND `recruitment_type` = 'assistant_manager'
);

INSERT INTO `interview_criteria`
(`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT
    'adaptability',
    'Adaptability',
    'Kemampuan menyesuaikan diri dengan perubahan situasi, ritme kerja, dan kebutuhan tim.',
    1.00,
    1,
    'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1
    FROM `interview_criteria`
    WHERE `code` = 'adaptability' AND `recruitment_type` = 'assistant_manager'
);

INSERT INTO `interview_criteria`
(`code`, `label`, `description`, `weight`, `is_active`, `recruitment_type`)
SELECT
    'time_management',
    'Time Management',
    'Kemampuan mengatur waktu, prioritas kerja, dan penyelesaian tugas sesuai tenggat.',
    1.00,
    1,
    'assistant_manager'
WHERE NOT EXISTS (
    SELECT 1
    FROM `interview_criteria`
    WHERE `code` = 'time_management' AND `recruitment_type` = 'assistant_manager'
);

COMMIT;
