<?php

declare(strict_types=1);

function secretary_current_division(): string
{
    return ems_normalize_division($_SESSION['user_rh']['division'] ?? '');
}

function secretary_require_division_context(string $redirectTo = '/dashboard/index.php'): string
{
    $division = secretary_current_division();
    if (!ems_is_valid_division($division)) {
        $_SESSION['flash_errors'][] = 'Akun Anda belum memiliki divisi yang valid.';
        header('Location: ' . $redirectTo);
        exit;
    }

    return $division;
}

function secretary_require_owned_record(PDO $pdo, string $table, int $recordId, string $division): void
{
    $allowedTables = [
        'secretary_visit_agendas',
        'secretary_internal_coordinations',
        'secretary_confidential_letters',
        'secretary_file_records',
    ];
    if (!in_array($table, $allowedTables, true) || $recordId <= 0) {
        throw new InvalidArgumentException('Data Secretary tidak valid.');
    }

    $stmt = $pdo->prepare("SELECT owner_division FROM `{$table}` WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $ownerDivision = $stmt->fetchColumn();
    if ($ownerDivision === false || ems_normalize_division((string) $ownerDivision) !== $division) {
        throw new RuntimeException('Data tidak ditemukan atau bukan milik divisi Anda.');
    }
}
