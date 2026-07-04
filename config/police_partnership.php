<?php

function policePartnershipEnsureTable(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS police_partnership_records (
            id INT(11) NOT NULL AUTO_INCREMENT,
            police_badge_no VARCHAR(50) NOT NULL,
            action_type VARCHAR(100) NOT NULL,
            treatment_detail TEXT NULL,
            service_date DATE NOT NULL,
            service_at DATETIME NULL,
            input_by_user_id INT(11) NULL,
            input_by_name VARCHAR(150) NOT NULL,
            input_by_position VARCHAR(100) NULL,
            unit_code VARCHAR(20) NOT NULL DEFAULT 'roxwood',
            amount INT(11) NOT NULL DEFAULT 1000,
            amount_updated_by VARCHAR(150) NULL,
            amount_updated_at DATETIME NULL,
            pricing_mode VARCHAR(20) NOT NULL DEFAULT 'per_qty',
            payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            paid_at DATETIME NULL,
            paid_by VARCHAR(200) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ppr_service_date (service_date),
            KEY idx_ppr_service_at (service_at),
            KEY idx_ppr_badge (police_badge_no),
            KEY idx_ppr_unit_date (unit_code, service_date),
            KEY idx_ppr_input_by_user_id (input_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    if (!policePartnershipColumnExists($pdo, 'service_at')) {
        $pdo->exec("ALTER TABLE police_partnership_records ADD COLUMN service_at DATETIME NULL AFTER service_date");
        $pdo->exec("ALTER TABLE police_partnership_records ADD KEY idx_ppr_service_at (service_at)");
        $pdo->exec("UPDATE police_partnership_records SET service_at = CONCAT(service_date, ' 00:00:00') WHERE service_at IS NULL");
    }

    $columnAdds = [
        'pricing_mode' => "ALTER TABLE police_partnership_records ADD COLUMN pricing_mode VARCHAR(20) NOT NULL DEFAULT 'per_qty' AFTER amount_updated_at",
        'payment_status' => "ALTER TABLE police_partnership_records ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER pricing_mode",
        'paid_at' => "ALTER TABLE police_partnership_records ADD COLUMN paid_at DATETIME NULL AFTER payment_status",
        'paid_by' => "ALTER TABLE police_partnership_records ADD COLUMN paid_by VARCHAR(200) NULL AFTER paid_at",
    ];

    foreach ($columnAdds as $column => $sql) {
        if (!policePartnershipColumnExists($pdo, $column)) {
            $pdo->exec($sql);
        }
    }

    $ensured = true;
}

function policePartnershipColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'police_partnership_records'
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$column]);

    return (bool)$stmt->fetchColumn();
}

function policePartnershipActionOptions(): array
{
    return [
        'Treatment',
        'Pertolongan Pertama',
    ];
}

function policePartnershipNormalizeBadge(?string $value): string
{
    $value = strtoupper(trim((string)$value));
    return preg_replace('/[^A-Z0-9\-\/]+/', '', $value) ?: '';
}

function policePartnershipDateLabel(?string $value): string
{
    if (!$value) {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d M Y');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function policePartnershipDateTimeLabel(?string $value, ?string $fallbackDate = null): string
{
    $value = trim((string)$value);
    if ($value === '') {
        $value = trim((string)$fallbackDate);
    }

    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d M Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function policePartnershipPricingModeLabel(?string $mode): string
{
    return match ((string)$mode) {
        'per_week' => 'Per Minggu',
        'per_month' => 'Per Bulan',
        default => 'Per Qty',
    };
}
