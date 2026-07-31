<?php

function ems_recruitment_settings_default_message(string $track): string
{
    return $track === 'assistant_manager'
        ? 'Pendaftaran Calon Asisten Manager (General Affair) saat ini belum dibuka. Silakan menunggu informasi selanjutnya.'
        : 'Pendaftaran Medis Roxwood saat ini belum dibuka. Silakan menunggu informasi selanjutnya.';
}

function ems_recruitment_settings_defaults(string $track = 'medical_candidate'): array
{
    return [
        'track' => $track,
        'is_open' => $track === 'assistant_manager' ? 0 : 1,
        'closed_message' => ems_recruitment_settings_default_message($track),
        'current_batch' => 1,
        'updated_by_user_id' => null,
        'updated_at' => null,
    ];
}

function ems_recruitment_settings_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'recruitment_portal_settings'
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$column]);

    return (bool)$stmt->fetchColumn();
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

    // Migrasi defensif: kolom `track` mungkin belum ada di install lama (sebelum
    // per-jalur open/close dipisah). Tambahkan di runtime kalau migrasi SQL belum dijalankan.
    if (!ems_recruitment_settings_column_exists($pdo, 'track')) {
        $pdo->exec("ALTER TABLE recruitment_portal_settings ADD COLUMN track VARCHAR(30) NOT NULL DEFAULT 'medical_candidate' AFTER id");
        $pdo->exec("UPDATE recruitment_portal_settings SET track = 'medical_candidate' WHERE id = 1");
        try {
            $pdo->exec("ALTER TABLE recruitment_portal_settings ADD UNIQUE KEY uniq_recruitment_portal_track (track)");
        } catch (Throwable $e) {
            // Index mungkin sudah ada / gagal karena duplikat data lama — abaikan, tidak fatal.
        }
    }

    // Migrasi defensif: kolom `current_batch` (nomor "Pendaftaran" GA) mungkin belum ada.
    if (!ems_recruitment_settings_column_exists($pdo, 'current_batch')) {
        $pdo->exec("ALTER TABLE recruitment_portal_settings ADD COLUMN current_batch INT(11) NOT NULL DEFAULT 1 AFTER closed_message");
    }

    $defaultsMedical = ems_recruitment_settings_defaults('medical_candidate');
    $stmt = $pdo->prepare("
        INSERT INTO recruitment_portal_settings (id, track, is_open, closed_message, current_batch, updated_by_user_id)
        VALUES (1, 'medical_candidate', 1, ?, 1, NULL)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt->execute([(string)$defaultsMedical['closed_message']]);

    $defaultsGa = ems_recruitment_settings_defaults('assistant_manager');
    $stmt = $pdo->prepare("
        INSERT INTO recruitment_portal_settings (id, track, is_open, closed_message, current_batch, updated_by_user_id)
        VALUES (2, 'assistant_manager', 0, ?, 1, NULL)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt->execute([(string)$defaultsGa['closed_message']]);

    $ensured = true;
}

function ems_recruitment_get_settings(PDO $pdo, string $track = 'medical_candidate'): array
{
    $track = $track === 'assistant_manager' ? 'assistant_manager' : 'medical_candidate';
    $defaults = ems_recruitment_settings_defaults($track);
    ems_recruitment_settings_ensure_table($pdo);

    $stmt = $pdo->prepare("
        SELECT id, track, is_open, closed_message, current_batch, updated_by_user_id, updated_at
        FROM recruitment_portal_settings
        WHERE track = ?
        LIMIT 1
    ");
    $stmt->execute([$track]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'track' => $track,
        'is_open' => isset($settings['is_open']) ? (int)$settings['is_open'] : (int)$defaults['is_open'],
        'closed_message' => trim((string)($settings['closed_message'] ?? '')) !== ''
            ? trim((string)$settings['closed_message'])
            : (string)$defaults['closed_message'],
        'current_batch' => isset($settings['current_batch']) && (int)$settings['current_batch'] > 0
            ? (int)$settings['current_batch']
            : (int)$defaults['current_batch'],
        'updated_by_user_id' => isset($settings['updated_by_user_id']) ? (int)$settings['updated_by_user_id'] : null,
        'updated_at' => $settings['updated_at'] ?? null,
    ];
}

function ems_recruitment_save_settings(
    PDO $pdo,
    bool $isOpen,
    string $closedMessage,
    ?int $updatedByUserId = null,
    string $track = 'medical_candidate',
    ?int $currentBatch = null
): array {
    $track = $track === 'assistant_manager' ? 'assistant_manager' : 'medical_candidate';
    ems_recruitment_settings_ensure_table($pdo);
    $defaults = ems_recruitment_settings_defaults($track);
    $closedMessage = trim($closedMessage);
    if ($closedMessage === '') {
        $closedMessage = (string)$defaults['closed_message'];
    }

    if ($currentBatch === null || $currentBatch < 1) {
        $currentBatch = (int)ems_recruitment_get_settings($pdo, $track)['current_batch'];
    }

    $stmt = $pdo->prepare("
        UPDATE recruitment_portal_settings
        SET is_open = ?,
            closed_message = ?,
            current_batch = ?,
            updated_by_user_id = ?
        WHERE track = ?
    ");
    $stmt->execute([
        $isOpen ? 1 : 0,
        $closedMessage,
        $currentBatch,
        $updatedByUserId ?: null,
        $track,
    ]);

    return ems_recruitment_get_settings($pdo, $track);
}
