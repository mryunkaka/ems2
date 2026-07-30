<?php

function ems_dispatcher_ensure_tables(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatcher_assignments (
            id INT(11) NOT NULL AUTO_INCREMENT,
            assignment_code VARCHAR(40) NOT NULL,
            status_code VARCHAR(30) NOT NULL,
            status_label_custom VARCHAR(100) NULL,
            coordinate VARCHAR(100) NULL,
            location_name VARCHAR(150) NULL,
            koordinasi_note TEXT NULL,
            note TEXT NULL,
            unit_code VARCHAR(20) NOT NULL DEFAULT 'roxwood',
            status ENUM('active','cleared') NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cleared_at DATETIME NULL,
            cleared_by INT(11) NULL,
            cleared_by_name_snapshot VARCHAR(100) NULL,
            created_by INT(11) NOT NULL,
            created_by_name_snapshot VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dispatcher_assignment_code (assignment_code),
            KEY idx_dispatcher_assignment_status (status),
            KEY idx_dispatcher_assignment_unit_status (unit_code, status),
            KEY idx_dispatcher_assignment_status_code (status_code),
            KEY idx_dispatcher_assignment_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatcher_assignment_members (
            id INT(11) NOT NULL AUTO_INCREMENT,
            assignment_id INT(11) NOT NULL,
            medic_user_id INT(11) NOT NULL,
            medic_name_snapshot VARCHAR(100) NULL,
            medic_jabatan_snapshot VARCHAR(60) NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_dam_assignment_id (assignment_id),
            KEY idx_dam_medic_user_id (medic_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

/**
 * Katalog status dispatcher. 'requires_location' = wajib isi koordinat.
 * 'requires_custom_label' = wajib isi label bebas (status "Lainnya").
 */
function ems_dispatcher_status_options(): array
{
    return [
        'off_duty' => [
            'label' => '10-7 / Istirahat',
            'badge' => 'badge-secondary',
            'icon' => 'pause-circle',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'rapat' => [
            'label' => 'Rapat',
            'badge' => 'badge-info',
            'icon' => 'user-group',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'kunjungan' => [
            'label' => 'Kunjungan',
            'badge' => 'badge-info',
            'icon' => 'building-office-2',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'standby_resepsionis' => [
            'label' => 'Standby Resepsionis',
            'badge' => 'badge-info',
            'icon' => 'identification',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'bantu_igd' => [
            'label' => 'Bantu IGD',
            'badge' => 'badge-warning',
            'icon' => 'shield-exclamation',
            'requires_location' => false,
            'requires_custom_label' => false,
        ],
        'respon_lapangan' => [
            'label' => 'Respon Lapangan',
            'badge' => 'badge-danger',
            'icon' => 'signal',
            'requires_location' => true,
            'requires_custom_label' => false,
        ],
        'lainnya' => [
            'label' => 'Lainnya',
            'badge' => 'badge-muted',
            'icon' => 'information-circle',
            'requires_location' => false,
            'requires_custom_label' => true,
        ],
    ];
}

function ems_dispatcher_status_label(string $statusCode, ?string $customLabel = null): string
{
    $options = ems_dispatcher_status_options();

    if ($statusCode === 'lainnya' && trim((string)$customLabel) !== '') {
        return trim((string)$customLabel);
    }

    return $options[$statusCode]['label'] ?? ucfirst(str_replace('_', ' ', $statusCode));
}

function ems_dispatcher_status_badge_class(string $statusCode): string
{
    $options = ems_dispatcher_status_options();

    return $options[$statusCode]['badge'] ?? 'badge-muted';
}

function ems_dispatcher_status_icon(string $statusCode): string
{
    $options = ems_dispatcher_status_options();

    return $options[$statusCode]['icon'] ?? 'signal';
}

function ems_dispatcher_generate_code(): string
{
    return 'DSP-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function ems_dispatcher_duration_label(int $seconds): string
{
    $seconds = max(0, $seconds);

    if ($seconds < 60) {
        return $seconds . ' detik';
    }

    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . ' menit';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    return $remainingMinutes > 0
        ? ($hours . ' jam ' . $remainingMinutes . ' menit')
        : ($hours . ' jam');
}

function ems_dispatcher_datetime_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d M Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}
