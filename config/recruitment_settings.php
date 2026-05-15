<?php

function ems_recruitment_settings_defaults(): array
{
    return [
        'is_open' => 1,
        'closed_message' => 'Pendaftaran Medis Roxwood saat ini belum dibuka. Silakan menunggu informasi selanjutnya.',
        'updated_by_user_id' => null,
        'updated_at' => null,
    ];
}

function ems_recruitment_settings_ensure_table(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recruitment_portal_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            is_open TINYINT(1) NOT NULL DEFAULT 1,
            closed_message TEXT NULL,
            updated_by_user_id INT(11) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO recruitment_portal_settings (id, is_open, closed_message, updated_by_user_id)
        VALUES (1, 1, ?, NULL)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $defaults = ems_recruitment_settings_defaults();
    $stmt->execute([(string)$defaults['closed_message']]);

    $ensured = true;
}

function ems_recruitment_get_settings(PDO $pdo): array
{
    $defaults = ems_recruitment_settings_defaults();
    ems_recruitment_settings_ensure_table($pdo);

    $stmt = $pdo->query("
        SELECT id, is_open, closed_message, updated_by_user_id, updated_at
        FROM recruitment_portal_settings
        WHERE id = 1
        LIMIT 1
    ");
    $settings = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    return [
        'is_open' => isset($settings['is_open']) ? (int)$settings['is_open'] : (int)$defaults['is_open'],
        'closed_message' => trim((string)($settings['closed_message'] ?? '')) !== ''
            ? trim((string)$settings['closed_message'])
            : (string)$defaults['closed_message'],
        'updated_by_user_id' => isset($settings['updated_by_user_id']) ? (int)$settings['updated_by_user_id'] : null,
        'updated_at' => $settings['updated_at'] ?? null,
    ];
}

function ems_recruitment_save_settings(PDO $pdo, bool $isOpen, string $closedMessage, ?int $updatedByUserId = null): array
{
    ems_recruitment_settings_ensure_table($pdo);
    $defaults = ems_recruitment_settings_defaults();
    $closedMessage = trim($closedMessage);
    if ($closedMessage === '') {
        $closedMessage = (string)$defaults['closed_message'];
    }

    $stmt = $pdo->prepare("
        UPDATE recruitment_portal_settings
        SET is_open = ?,
            closed_message = ?,
            updated_by_user_id = ?
        WHERE id = 1
    ");
    $stmt->execute([
        $isOpen ? 1 : 0,
        $closedMessage,
        $updatedByUserId ?: null,
    ]);

    return ems_recruitment_get_settings($pdo);
}
