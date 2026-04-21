<?php
function initialsFromName(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return mb_strtoupper(
            mb_substr($parts[0], 0, 1) .
                mb_substr($parts[1], 0, 1)
        );
    }
    return mb_strtoupper(mb_substr($parts[0], 0, 2));
}

function avatarColorFromName(string $name): string
{
    $hash = crc32(mb_strtolower(trim($name)));
    $hue  = $hash % 360;
    return "hsl($hue, 70%, 45%)";
}

function ems_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = strtolower(trim($table));

    if ($key === '') {
        return false;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$key] = (bool) $stmt->fetchColumn();

    return $cache[$key];
}

function ems_ensure_medical_record_assistants_table(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS medical_record_assistants (
            id INT(11) NOT NULL AUTO_INCREMENT,
            medical_record_id INT(11) NOT NULL,
            assistant_user_id INT(11) NOT NULL,
            sort_order INT(11) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mra_record_id (medical_record_id),
            KEY idx_mra_assistant_user_id (assistant_user_id),
            KEY idx_mra_sort_order (sort_order),
            CONSTRAINT fk_mra_record FOREIGN KEY (medical_record_id) REFERENCES medical_records(id) ON DELETE CASCADE,
            CONSTRAINT fk_mra_assistant FOREIGN KEY (assistant_user_id) REFERENCES user_rh(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

function ems_normalize_assistant_ids(array $assistantIds): array
{
    $normalized = [];

    foreach ($assistantIds as $assistantId) {
        $assistantId = (int) $assistantId;
        if ($assistantId <= 0) {
            continue;
        }

        if (in_array($assistantId, $normalized, true)) {
            continue;
        }

        $normalized[] = $assistantId;
    }

    return $normalized;
}

function ems_save_medical_record_assistants(PDO $pdo, int $recordId, array $assistantIds): void
{
    if ($recordId <= 0) {
        return;
    }

    ems_ensure_medical_record_assistants_table($pdo);

    $assistantIds = ems_normalize_assistant_ids($assistantIds);

    $deleteStmt = $pdo->prepare('DELETE FROM medical_record_assistants WHERE medical_record_id = ?');
    $deleteStmt->execute([$recordId]);

    if ($assistantIds !== []) {
        $insertStmt = $pdo->prepare("
            INSERT INTO medical_record_assistants (medical_record_id, assistant_user_id, sort_order)
            VALUES (?, ?, ?)
        ");

        foreach ($assistantIds as $index => $assistantId) {
            $insertStmt->execute([$recordId, $assistantId, $index + 1]);
        }
    }

    $primaryAssistantId = $assistantIds[0] ?? null;
    $updateStmt = $pdo->prepare('UPDATE medical_records SET assistant_id = ? WHERE id = ?');
    $updateStmt->execute([$primaryAssistantId, $recordId]);
}

function ems_get_medical_record_assistants(PDO $pdo, int $recordId, ?int $fallbackAssistantId = null): array
{
    $assistants = [];

    if ($recordId > 0 && ems_table_exists($pdo, 'medical_record_assistants')) {
        $stmt = $pdo->prepare("
            SELECT
                mra.assistant_user_id AS id,
                u.full_name,
                u.position,
                mra.sort_order
            FROM medical_record_assistants mra
            INNER JOIN user_rh u ON u.id = mra.assistant_user_id
            WHERE mra.medical_record_id = ?
            ORDER BY mra.sort_order ASC, u.full_name ASC
        ");
        $stmt->execute([$recordId]);
        $assistants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($assistants === [] && (int) $fallbackAssistantId > 0) {
        $stmt = $pdo->prepare("
            SELECT
                id,
                full_name,
                position,
                1 AS sort_order
            FROM user_rh
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $fallbackAssistantId]);
        $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fallback) {
            $assistants[] = $fallback;
        }
    }

    foreach ($assistants as &$assistant) {
        $assistant['id'] = (int) ($assistant['id'] ?? 0);
        $assistant['position'] = ems_position_label($assistant['position'] ?? '');
        $assistant['full_name'] = (string) ($assistant['full_name'] ?? '');
    }
    unset($assistant);

    return $assistants;
}

function formatTanggalID($datetime)
{
    if (!$datetime) return '-';

    $bulan = [
        1 => 'Jan',
        'Feb',
        'Mar',
        'Apr',
        'Mei',
        'Jun',
        'Jul',
        'Agu',
        'Sep',
        'Okt',
        'Nov',
        'Des'
    ];

    $dt = new DateTime($datetime);

    $hari  = (int)$dt->format('j');
    $bulanTxt = $bulan[(int)$dt->format('n')];
    $tahun = $dt->format('Y');
    $jam   = $dt->format('H:i');

    return "{$hari} {$bulanTxt} {$tahun} {$jam}";
}

function safeRegulation(PDO $pdo, string $code): int
{
    $stmt = $pdo->prepare("
        SELECT price_type, price_min, price_max
        FROM medical_regulations
        WHERE code = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        throw new Exception("Regulasi tidak ditemukan: {$code}");
    }

    if ($r['price_type'] === 'RANGE') {
        return random_int((int)$r['price_min'], (int)$r['price_max']);
    }

    return (int)$r['price_min'];
}

function formatTanggalIndo($date)
{
    if (!$date) return '-';

    // Jika sudah DateTime, pakai langsung
    if ($date instanceof DateTime) {
        $d = $date;
    } else {
        $d = new DateTime($date);
    }

    $bulan = [
        1 => 'Jan',
        'Feb',
        'Mar',
        'Apr',
        'Mei',
        'Jun',
        'Jul',
        'Agu',
        'Sep',
        'Okt',
        'Nov',
        'Des'
    ];

    $hari  = $d->format('d');                  // 05
    $bulanNama = $bulan[(int)$d->format('n')]; // Jan
    $tahun = substr($d->format('Y'), 2);       // 26

    return "{$hari} {$bulanNama} {$tahun}";
}

function dollar($amount)
{
    return '$' . number_format((float)$amount, 0, ',', '.');
}

function ems_normalize_position(?string $position): string
{
    $raw = strtolower(trim((string)$position));
    $raw = preg_replace('/\s+/', ' ', $raw);

    return match ($raw) {
        'trainee' => 'trainee',
        'paramedic' => 'paramedic',
        '(co.ast)', '(co. ast)', 'co.ast', 'co. ast', 'co asst', 'co. asst', 'co-ass', 'co_asst', 'coasst' => 'co_asst',
        'dokter umum', 'dr umum', 'general practitioner', 'gp' => 'general_practitioner',
        'dokter spesialis', 'dr spesialis', 'specialist', 'specialist doctor' => 'specialist',
        '' => '',
        default => str_replace(' ', '_', $raw),
    };
}

function ems_position_label(?string $position): string
{
    $pos = ems_normalize_position($position);
    return match ($pos) {
        'trainee' => 'Trainee',
        'paramedic' => 'Paramedic',
        'co_asst' => 'Co. Asst',
        'general_practitioner' => 'Dokter Umum',
        'specialist' => 'Dokter Spesialis',
        '' => '-',
        default => (string)$position,
    };
}

function ems_position_options(): array
{
    return [
        ['value' => 'trainee', 'label' => 'Trainee'],
        ['value' => 'paramedic', 'label' => 'Paramedic'],
        ['value' => 'co_asst', 'label' => 'Co. Asst'],
        ['value' => 'general_practitioner', 'label' => 'Dokter Umum'],
        ['value' => 'specialist', 'label' => 'Dokter Spesialis'],
    ];
}

function ems_is_valid_position(string $position): bool
{
    return in_array($position, array_column(ems_position_options(), 'value'), true);
}

function ems_next_position(?string $position): string
{
    $pos = ems_normalize_position($position);
    return match ($pos) {
        'trainee' => 'paramedic',
        'paramedic' => 'co_asst',
        'co_asst' => 'general_practitioner',
        default => '',
    };
}

function ems_normalize_role(?string $role): string
{
    $raw = strtolower(trim((string)$role));
    $raw = preg_replace('/\s+/', ' ', $raw) ?: '';

    return match ($raw) {
        'staff' => 'staff',
        'probation manager', 'probation_manager' => 'probation manager',
        'staff manager', 'assistant manager', 'assisten manager' => 'assisten manager',
        'lead manager' => 'lead manager',
        'manager', 'head manager' => 'head manager',
        'vice director' => 'vice director',
        'director' => 'director',
        default => $raw,
    };
}

function ems_role_label(?string $role): string
{
    return match (ems_normalize_role($role)) {
        'staff' => 'Staff',
        'probation manager' => 'Probation Manager',
        'assisten manager' => 'Assisten Manager',
        'lead manager' => 'Lead Manager',
        'head manager' => 'Head Manager',
        'vice director' => 'Vice Director',
        'director' => 'Director',
        '' => '-',
        default => ucwords((string)$role),
    };
}

function ems_role_options(): array
{
    return [
        ['value' => 'Staff', 'label' => 'Staff'],
        ['value' => 'Probation Manager', 'label' => 'Probation Manager'],
        ['value' => 'Assisten Manager', 'label' => 'Assisten Manager'],
        ['value' => 'Lead Manager', 'label' => 'Lead Manager'],
        ['value' => 'Head Manager', 'label' => 'Head Manager'],
        ['value' => 'Vice Director', 'label' => 'Vice Director'],
        ['value' => 'Director', 'label' => 'Director'],
    ];
}

function ems_is_valid_role(?string $role): bool
{
    return in_array(
        ems_role_label($role),
        array_column(ems_role_options(), 'value'),
        true
    );
}

function ems_is_staff_role(?string $role): bool
{
    return ems_normalize_role($role) === 'staff';
}

function ems_is_manager_plus_role(?string $role): bool
{
    return in_array(
        ems_normalize_role($role),
        ['probation manager', 'assisten manager', 'lead manager', 'head manager', 'vice director', 'director'],
        true
    );
}

function ems_is_director_role(?string $role): bool
{
    return in_array(ems_normalize_role($role), ['vice director', 'director'], true);
}

function ems_normalize_person_name(?string $name): string
{
    $raw = strtolower(trim((string)$name));
    return preg_replace('/\s+/', ' ', $raw) ?: '';
}

function ems_is_programmer_roxwood_name(?string $name): bool
{
    return ems_normalize_person_name($name) === 'programmer roxwood';
}

function ems_current_user_is_programmer_roxwood(): bool
{
    $user = $_SESSION['user_rh'] ?? [];
    $name = (string)($user['full_name'] ?? $user['name'] ?? '');
    return ems_is_programmer_roxwood_name($name);
}

function ems_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower(trim($table)) . '.' . strtolower(trim($column));

    if ($key === '.') {
        return false;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);

    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function ems_normalize_unit_code(?string $unitCode): string
{
    $raw = strtolower(trim((string)$unitCode));
    $raw = preg_replace('/\s+/', '_', $raw) ?: '';

    return match ($raw) {
        'alta', 'rs_alta', 'rumah_sakit_alta', 'hospital_alta' => 'alta',
        'roxwood', 'rs_roxwood', 'rumah_sakit_roxwood', 'roxwood_hospital', '' => $raw === '' ? 'roxwood' : 'roxwood',
        default => in_array($raw, ['alta', 'roxwood'], true) ? $raw : 'roxwood',
    };
}

function ems_unit_label(?string $unitCode): string
{
    return match (ems_normalize_unit_code($unitCode)) {
        'alta' => 'Alta',
        default => 'Roxwood',
    };
}

function ems_unit_hospital_name(?string $unitCode): string
{
    return match (ems_normalize_unit_code($unitCode)) {
        'alta' => 'Alta Hospital',
        default => 'Roxwood Hospital',
    };
}

function ems_unit_logo_path(?string $unitCode): string
{
    return match (ems_normalize_unit_code($unitCode)) {
        'alta' => '/assets/motionlife-logo.png',
        default => '/assets/logo.png',
    };
}

function ems_unit_system_name(?string $unitCode = null): string
{
    return 'Emergency Medical System';
}

function ems_unit_options(): array
{
    return [
        ['value' => 'roxwood', 'label' => 'Roxwood'],
        ['value' => 'alta', 'label' => 'Alta'],
    ];
}

function ems_normalize_citizen_id(?string $value): string
{
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?: '';

    return $value;
}

function ems_looks_like_citizen_id(?string $value): bool
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return false;
    }

    $normalized = ems_normalize_citizen_id($raw);
    if ($normalized === '') {
        return false;
    }

    if (preg_match('/^\d+$/', $normalized)) {
        return false;
    }

    return preg_match('/^[A-Z0-9]{6,20}$/', $normalized) === 1;
}

function ems_consumer_identifier_label(): string
{
    return 'Citizen ID Konsumen';
}

function ems_consumer_identifier_value(?string $storedValue, ?string $citizenId = null): string
{
    $normalizedCitizenId = ems_normalize_citizen_id($citizenId);
    if ($normalizedCitizenId !== '') {
        return $normalizedCitizenId;
    }

    if (ems_looks_like_citizen_id($storedValue)) {
        return ems_normalize_citizen_id($storedValue);
    }

    return '';
}

function ems_consumer_legacy_name_value(?string $storedValue, ?string $citizenId = null): string
{
    $storedValue = preg_replace('/\s+/u', ' ', trim((string)$storedValue)) ?: '';
    if ($storedValue === '') {
        return '-';
    }

    $normalizedStored = ems_normalize_citizen_id($storedValue);
    $normalizedCitizenId = ems_normalize_citizen_id($citizenId);

    if ($normalizedCitizenId !== '' && $normalizedStored === $normalizedCitizenId) {
        return '-';
    }

    if (ems_looks_like_citizen_id($storedValue)) {
        return '-';
    }

    return $storedValue;
}

function ems_is_medical_position(?string $position): bool
{
    return in_array(
        ems_normalize_position($position),
        ['trainee', 'paramedic', 'co_asst', 'general_practitioner', 'specialist'],
        true
    );
}

function ems_position_meets_minimum(?string $userPosition, string $minPosition): bool
{
    $userPosition = ems_normalize_position($userPosition);
    $minPosition = ems_normalize_position($minPosition);

    // Define position hierarchy (lowest to highest)
    $hierarchy = [
        'trainee',
        'paramedic',
        'co_asst',
        'general_practitioner',
        'specialist',
    ];

    $userIndex = array_search($userPosition, $hierarchy, true);
    $minIndex = array_search($minPosition, $hierarchy, true);

    // If either position is not in hierarchy, use exact match as fallback
    if ($userIndex === false || $minIndex === false) {
        return $userPosition === $minPosition;
    }

    // User meets minimum if their position is at or above the minimum
    return $userIndex >= $minIndex;
}

function ems_get_user_unit_scope(PDO $pdo, array $sessionUser): array
{
    static $cache = [];

    $userId = (int)($sessionUser['id'] ?? 0);
    if ($userId <= 0) {
        return [
            'unit_code' => 'roxwood',
            'can_view_all_units' => false,
        ];
    }

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $unitCode = ems_normalize_unit_code($sessionUser['unit_code'] ?? 'roxwood');
    $canViewAllUnits = !empty($sessionUser['can_view_all_units']);

    if (ems_column_exists($pdo, 'user_rh', 'unit_code')) {
        $selects = ['unit_code'];
        if (ems_column_exists($pdo, 'user_rh', 'can_view_all_units')) {
            $selects[] = 'can_view_all_units';
        }

        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $selects) . "
            FROM user_rh
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($row) {
            $unitCode = ems_normalize_unit_code($row['unit_code'] ?? $unitCode);
            $canViewAllUnits = isset($row['can_view_all_units'])
                ? (int)$row['can_view_all_units'] === 1
                : $canViewAllUnits;

            $_SESSION['user_rh']['unit_code'] = $unitCode;
            if (array_key_exists('can_view_all_units', $row)) {
                $_SESSION['user_rh']['can_view_all_units'] = $canViewAllUnits ? 1 : 0;
            }
        }
    }

    $cache[$userId] = [
        'unit_code' => $unitCode,
        'can_view_all_units' => $canViewAllUnits,
    ];

    return $cache[$userId];
}

function ems_current_user_unit(PDO $pdo, array $sessionUser): string
{
    $scope = ems_get_user_unit_scope($pdo, $sessionUser);
    return $scope['unit_code'] ?? 'roxwood';
}

function ems_user_can_view_all_units(PDO $pdo, array $sessionUser): bool
{
    $scope = ems_get_user_unit_scope($pdo, $sessionUser);
    return !empty($scope['can_view_all_units']);
}

function ems_effective_unit(PDO $pdo, array $sessionUser): string
{
    $userUnit = ems_current_user_unit($pdo, $sessionUser);
    $canViewAllUnits = ems_user_can_view_all_units($pdo, $sessionUser);

    if (!$canViewAllUnits) {
        $_SESSION['ems_active_unit'] = $userUnit;
        return $userUnit;
    }

    $requestedUnit = $_GET['unit'] ?? '';
    if ($requestedUnit !== '') {
        $normalizedRequested = ems_normalize_unit_code($requestedUnit);
        if (in_array($normalizedRequested, ['roxwood', 'alta'], true)) {
            $_SESSION['ems_active_unit'] = $normalizedRequested;
        }
    }

    $sessionUnit = ems_normalize_unit_code($_SESSION['ems_active_unit'] ?? $userUnit);
    if (!in_array($sessionUnit, ['roxwood', 'alta'], true)) {
        $sessionUnit = $userUnit;
    }

    $_SESSION['ems_active_unit'] = $sessionUnit;

    return $sessionUnit;
}

function ems_division_options(): array
{
    return [
        ['value' => 'Medis', 'label' => 'Medis'],
        ['value' => 'Executive', 'label' => 'Executive'],
        ['value' => 'Secretary', 'label' => 'Secretary'],
        ['value' => 'Human Capital', 'label' => 'Human Capital'],
        ['value' => 'Disciplinary Committee', 'label' => 'Disciplinary Committee'],
        ['value' => 'Human Resource', 'label' => 'Human Resource'],
        ['value' => 'General Affair', 'label' => 'General Affair'],
        ['value' => 'Specialist Medical Authority', 'label' => 'Specialist Medical Authority'],
        ['value' => 'Forensic', 'label' => 'Forensic'],
    ];
}

function ems_all_division_scope_value(): string
{
    return 'All Divisi';
}

function ems_management_division_scope_value(): string
{
    return 'All Divisi Manajemen';
}

function ems_division_scope_options(): array
{
    return array_merge(
        [
            ['value' => ems_all_division_scope_value(), 'label' => 'All Divisi (termasuk Medis)'],
            ['value' => ems_management_division_scope_value(), 'label' => 'All Divisi Manajemen (tanpa Medis)'],
        ],
        ems_division_options()
    );
}

function ems_normalize_division(?string $division): string
{
    $raw = strtolower(trim((string)$division));
    $raw = preg_replace('/\s+/', ' ', $raw) ?: '';

    return match ($raw) {
        'medis', 'medical', 'devisi medis', 'divisi medis', 'division medis' => 'Medis',
        'executive', 'devisi executive', 'divisi executive', 'division executive' => 'Executive',
        'secretary', 'sekertaris', 'sekretaris', 'devisi sekertaris', 'divisi sekertaris', 'division secretary' => 'Secretary',
        'human capital', 'devisi human capital', 'divisi human capital', 'division human capital' => 'Human Capital',
        'human resource', 'devisi human resource', 'divisi human resource', 'division human resource' => 'Human Resource',
        'disciplinary committee', 'discipline committee', 'disiplin committee', 'disiplin committe', 'deivisi disiplin committe', 'devisi disiplin committe', 'divisi disiplin committe', 'division disciplinary committee' => 'Disciplinary Committee',
        'general affair', 'devisi general affair', 'divisi general affair', 'division general affair' => 'General Affair',
        'specialist medical authority', 'devisi specialist medical authority', 'divisi specialist medical authority', 'division specialist medical authority' => 'Specialist Medical Authority',
        'forensic', 'forensik', 'devisi forensic', 'divisi forensic', 'division forensic' => 'Forensic',
        default => $division !== null ? trim($division) : '',
    };
}

function ems_resolve_user_division(?string $division, ?string $position = null): string
{
    $normalizedDivision = ems_normalize_division($division);
    if ($normalizedDivision !== '') {
        return $normalizedDivision;
    }

    // Akun trainee hasil registrasi lama tidak selalu menyimpan division.
    // Secara bisnis trainee diperlakukan sebagai tenaga Medis.
    if (ems_normalize_position($position) === 'trainee') {
        return 'Medis';
    }

    return '';
}

function ems_is_valid_division(?string $division): bool
{
    return in_array(
        ems_normalize_division($division),
        array_column(ems_division_options(), 'value'),
        true
    );
}

function ems_normalize_division_scope(?string $value): string
{
    $raw = trim((string)$value);
    $normalizedRaw = strtolower(preg_replace('/\s+/', ' ', $raw) ?: '');

    if ($normalizedRaw === '' || in_array($normalizedRaw, ['all', 'all division', 'all divisi', 'semua', 'semua divisi'], true)) {
        return ems_all_division_scope_value();
    }

    if (in_array($normalizedRaw, ['all divisi manajemen', 'all division management', 'semua divisi manajemen'], true)) {
        return ems_management_division_scope_value();
    }

    $division = ems_normalize_division($raw);
    if (!ems_is_valid_division($division)) {
        return '';
    }

    return $division;
}

function ems_is_management_division(?string $division): bool
{
    $division = ems_normalize_division($division);
    return $division !== '' && $division !== 'Medis';
}

function ems_division_scope_matches_division(?string $scope, ?string $division): bool
{
    $scope = ems_normalize_division_scope($scope);
    $division = ems_normalize_division($division);

    if ($scope === ems_all_division_scope_value()) {
        return true;
    }

    if ($scope === ems_management_division_scope_value()) {
        return ems_is_management_division($division);
    }

    return $scope !== '' && $scope === $division;
}

function ems_can_access_division_menu(?string $userDivision, ?string $targetDivision): bool
{
    $userDivision = ems_normalize_division($userDivision);
    $targetDivision = ems_normalize_division($targetDivision);

    if ($userDivision === '' || $targetDivision === '') {
        return false;
    }

    if (in_array($userDivision, ['Executive', 'Secretary'], true)) {
        return true;
    }

    if ($userDivision === $targetDivision) {
        return true;
    }

    if ($userDivision === 'Human Capital' && in_array($targetDivision, ['Human Resource', 'Disciplinary Committee'], true)) {
        return true;
    }

    if ($userDivision === 'Specialist Medical Authority' && $targetDivision === 'Forensic') {
        return true;
    }

    return false;
}

function ems_require_division_access(array $targetDivisions, string $redirectTo = '/dashboard/index.php'): void
{
    $userDivision = ems_normalize_division($_SESSION['user_rh']['division'] ?? '');

    foreach ($targetDivisions as $targetDivision) {
        if (ems_can_access_division_menu($userDivision, (string)$targetDivision)) {
            return;
        }
    }

    $_SESSION['flash_errors'][] = 'Akses division ditolak.';
    header('Location: ' . $redirectTo);
    exit;
}

function ems_division_allowed_dashboard_pages(?string $division): ?array
{
    $division = ems_normalize_division($division);
    $sessionUser = $_SESSION['user_rh'] ?? [];
    $unitCode = ems_normalize_unit_code($sessionUser['unit_code'] ?? 'roxwood');
    $canViewAllUnits = !empty($sessionUser['can_view_all_units']);
    $position = ems_normalize_position($sessionUser['position'] ?? '');

    if (!$canViewAllUnits && $unitCode === 'alta' && $division === 'Medis' && ems_is_medical_position($position)) {
        return [
            'index.php',
            'rekap_farmasi.php',
            'konsumen.php',
            'ranking.php',
            'emt_doj.php',
            'emt_doj_action.php',
            'setting_akun.php',
            'setting_akun_action.php',
            'sertifikat_heli_pendaftaran.php',
        ];
    }

    if ($division !== 'Medis') {
        return null;
    }

    return [
        'index.php',
        'events.php',
        'struktur_organisasi.php',
        'event_participants.php',
        'ems_services.php',
        'rekam_medis_list.php',
        'rekam_medis_view.php',
        'rekam_medis.php',
        'rekam_medis_edit.php',
        'rekam_medis_action.php',
        'rekam_medis_edit_action.php',
        'rekam_medis_delete.php',
        'operasi_plastik.php',
        'emt_doj.php',
        'emt_doj_action.php',
        'rekap_farmasi.php',
        'rekap_farmasi_v2.php',
        'konsumen.php',
        'ranking.php',
        'absensi_ems.php',
        'reimbursement.php',
        'restaurant_consumption.php',
        'restaurant_consumption_action.php',
        'gaji.php',
        'rekap_gaji.php',
        'pengajuan_jabatan.php',
        'pengajuan_jabatan_action.php',
        'pengajuan_cuti_resign.php',
        'pengajuan_cuti_resign_action.php',
        'setting_akun.php',
        'setting_akun_action.php',
        'input_dokumen_medis.php',
        'input_dokumen_medis_action.php',
        'sertifikat_heli_pendaftaran.php',
    ];
}

function ems_enforce_dashboard_page_access(?string $division, string $scriptName, string $redirectTo = '/dashboard/index.php'): void
{
    if ($scriptName === 'setting_akun.php' || $scriptName === 'setting_akun_action.php') {
        return;
    }

    // Exception: sertifikat_heli pages allow all logged-in users regardless of division
    if ($scriptName === 'sertifikat_heli_pendaftaran.php' || $scriptName === 'sertifikat_heli_action.php') {
        return;
    }

    $sessionUser = $_SESSION['user_rh'] ?? [];
    $unitCode = ems_normalize_unit_code($sessionUser['unit_code'] ?? 'roxwood');
    $canViewAllUnits = !empty($sessionUser['can_view_all_units']);

    if (!$canViewAllUnits && $unitCode === 'alta') {
        $altaBlockedPages = [
            'sertifikat_heli.php',
            'event_manage.php',
            'restaurant_settings.php',
            'general_affair_visits.php',
            'reimbursement.php',
            'restaurant_consumption.php',
            'restaurant_consumption_action.php',
            'general_affair_visits_action.php',
        ];

        if (in_array($scriptName, $altaBlockedPages, true)) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    $allowedPages = ems_division_allowed_dashboard_pages($division);
    if ($allowedPages === null) {
        return;
    }

    if (!in_array($scriptName, $allowedPages, true)) {
        $_SESSION['flash_errors'][] = 'Akses halaman ditolak untuk division Anda.';
        header('Location: ' . $redirectTo);
        exit;
    }
}

function ems_disciplinary_tolerance_options(): array
{
    return [
        'tolerable' => 'Tolerable',
        'non_tolerable' => 'Non Tolerable',
    ];
}

function ems_disciplinary_recommendation_from_points(int $totalPoints, bool $hasNonTolerable): string
{
    if ($hasNonTolerable) {
        return match (true) {
            $totalPoints >= 80 => 'termination_review',
            $totalPoints >= 60 => 'final_warning',
            $totalPoints >= 35 => 'written_warning_2',
            default => 'written_warning_1',
        };
    }

    return match (true) {
        $totalPoints >= 100 => 'termination_review',
        $totalPoints >= 80 => 'final_warning',
        $totalPoints >= 60 => 'written_warning_2',
        $totalPoints >= 40 => 'written_warning_1',
        $totalPoints >= 20 => 'verbal_warning',
        default => 'coaching',
    };
}

function ems_disciplinary_recommendation_label(string $recommendation): string
{
    return match ($recommendation) {
        'coaching' => 'Coaching',
        'verbal_warning' => 'Verbal Warning',
        'written_warning_1' => 'Written Warning 1',
        'written_warning_2' => 'Written Warning 2',
        'final_warning' => 'Final Warning',
        'termination_review' => 'Termination Review',
        default => ucwords(str_replace('_', ' ', $recommendation)),
    };
}

function ems_is_letter_receiver_role(?string $role): bool
{
    return ems_normalize_role($role) !== 'staff';
}

function ems_asset(string $path): string
{
    $path = (string)$path;
    if ($path === '') return '';

    // Keep existing query string, append v= if possible.
    $parts = explode('?', $path, 2);
    $pathOnly = '/' . ltrim($parts[0], '/');
    $urlPath = ems_url($pathOnly);
    $query = $parts[1] ?? '';
    $disableVersion = ems_env_flag('EMS_ASSET_DISABLE_VERSION');
    $disableVendorVersion = ems_env_flag('EMS_VENDOR_ASSET_DISABLE_VERSION');

    if (
        $disableVersion ||
        ($disableVendorVersion && strpos($urlPath, '/assets/vendor/') === 0)
    ) {
        return $query !== '' ? ($urlPath . '?' . $query) : $urlPath;
    }

    $base = realpath(__DIR__ . '/..'); // repo root (config/..)
    $fs = $base ? ($base . $pathOnly) : null;
    $v = ($fs && is_file($fs)) ? @filemtime($fs) : null;

    if (!$v) {
        return $query !== '' ? ($urlPath . '?' . $query) : $urlPath;
    }

    $ver = 'v=' . rawurlencode((string)$v);
    if ($query === '') return $urlPath . '?' . $ver;
    if (str_contains($query, 'v=')) return $urlPath . '?' . $query;
    return $urlPath . '?' . $query . '&' . $ver;
}

function ems_base_path(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $repoRoot = realpath(__DIR__ . '/..');

    if (!$documentRoot || !$repoRoot) {
        $basePath = '';
        return $basePath;
    }

    $normalizedDocRoot = str_replace('\\', '/', $documentRoot);
    $normalizedRepoRoot = str_replace('\\', '/', $repoRoot);

    if (strpos($normalizedRepoRoot, $normalizedDocRoot) !== 0) {
        $basePath = '';
        return $basePath;
    }

    $relative = substr($normalizedRepoRoot, strlen($normalizedDocRoot));
    $relative = trim((string)$relative, '/');
    $basePath = $relative === '' ? '' : '/' . $relative;
    return $basePath;
}

function ems_url(string $path): string
{
    $normalized = '/' . ltrim((string)$path, '/');
    $basePath = ems_base_path();

    if ($basePath === '') {
        return $normalized;
    }

    if (strpos($normalized, $basePath . '/') === 0 || $normalized === $basePath) {
        return $normalized;
    }

    return $basePath . $normalized;
}

function ems_env_flag(string $key): bool
{
    $value = getenv($key);
    if ($value === false && array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
    }
    if ($value === false || $value === null) {
        return false;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function ems_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ems_json_error(string $message = 'Internal server error', int $statusCode = 500, array $extra = []): void
{
    ems_json_response(array_merge([
        'success' => false,
        'error' => $message,
    ], $extra), $statusCode);
}

// =====================================================
// HELPER FUNCTIONS SISTEM CUTI & RESIGN
// =====================================================

/**
 * Hitung sisa hari cuti
 *
 * @param string|null $startDate Tanggal mulai cuti (Y-m-d)
 * @param string|null $endDate Tanggal selesai cuti (Y-m-d)
 * @return array Array dengan key: total, remaining, used, percentage, status
 *
 * Contoh return:
 * [
 *     'total' => 14,           // Total hari cuti
 *     'remaining' => 7,        // Sisa hari cuti
 *     'used' => 7,             // Hari yang sudah berjalan
 *     'percentage' => 50.0,    // Persentase progress
 *     'status' => 'active'     // 'not_started', 'active', 'completed'
 * ]
 */
function hitung_sisa_cuti(?string $startDate, ?string $endDate): array
{
    $result = [
        'total' => 0,
        'remaining' => 0,
        'used' => 0,
        'percentage' => 0.0,
        'status' => 'not_started'
    ];

    // Validasi input
    if (!$startDate || !$endDate) {
        return $result;
    }

    try {
        $today = new DateTime();
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        // Validasi tanggal
        if ($start > $end) {
            return $result;
        }

        // Hitung total hari cuti
        $totalDays = $start->diff($end)->days + 1;
        $result['total'] = $totalDays;

        // Cek status cuti
        if ($today < $start) {
            // Cuti belum mulai
            $result['status'] = 'not_started';
            $result['remaining'] = $totalDays;
        } elseif ($today > $end) {
            // Cuti sudah selesai
            $result['status'] = 'completed';
            $result['used'] = $totalDays;
            $result['remaining'] = 0;
            $result['percentage'] = 100.0;
        } else {
            // Sedang cuti
            $result['status'] = 'active';
            $usedDays = $start->diff($today)->days + 1;
            $remainingDays = $today->diff($end)->days;

            $result['used'] = $usedDays;
            $result['remaining'] = $remainingDays;
            $result['percentage'] = round(($usedDays / $totalDays) * 100, 1);
        }
    } catch (Exception $e) {
        // Return default result jika error
        return $result;
    }

    return $result;
}

/**
 * Tentukan status periode cuti berdasarkan flag database dan range tanggal.
 *
 * @param string|null $startDate Tanggal mulai cuti
 * @param string|null $endDate Tanggal selesai cuti
 * @param string|null $cutiStatus Flag status cuti dari database
 * @return string none|scheduled|active|completed
 */
function get_cuti_period_status(?string $startDate, ?string $endDate, ?string $cutiStatus = null): string
{
    if ($cutiStatus !== 'active' || !$startDate || !$endDate) {
        return 'none';
    }

    try {
        $today = new DateTime('today');
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        if ($start > $end) {
            return 'none';
        }

        if ($today < $start) {
            return 'scheduled';
        }

        if ($today > $end) {
            return 'completed';
        }

        return 'active';
    } catch (Exception $e) {
        return 'none';
    }
}

/**
 * Generate kode unik untuk request cuti/resign
 *
 * @param string $type Tipe request ('cuti' atau 'resign')
 * @return string Kode request (format: CT-YYYYMMDD-XXXX atau RS-YYYYMMDD-XXXX)
 */
function generate_request_code(string $type): string
{
    $prefix = strtoupper(substr($type, 0, 2)); // CT atau RS
    $date = date('Ymd');
    $random = strtoupper(bin2hex(random_bytes(2))); // 4 karakter hex

    return sprintf('%s-%s-%s', $prefix, $date, $random);
}

/**
 * Cek apakah user sedang dalam masa cuti aktif
 *
 * @param PDO $pdo Database connection
 * @param int $userId ID user
 * @return bool True jika user sedang cuti, false jika tidak
 */
function is_user_on_cuti(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT cuti_start_date, cuti_end_date, cuti_status
            FROM user_rh
            WHERE id = ? AND cuti_status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        return get_cuti_period_status(
            $user['cuti_start_date'] ?? null,
            $user['cuti_end_date'] ?? null,
            $user['cuti_status'] ?? null
        ) === 'active';
    } catch (Throwable $e) {
        // Return false jika error
        return false;
    }
}

/**
 * Cek apakah user sedang cuti berdasarkan session
 * Lebih cepat karena tidak query database
 *
 * @return bool True jika user sedang cuti
 */
function is_user_on_cuti_session(): bool
{
    if (!isset($_SESSION['user_rh'])) {
        return false;
    }
    
    $user = $_SESSION['user_rh'];
    return get_cuti_period_status(
        $user['cuti_start_date'] ?? null,
        $user['cuti_end_date'] ?? null,
        $user['cuti_status'] ?? null
    ) === 'active';
}

/**
 * Check if user can access a restricted page (not on cuti)
 * Jika user sedang cuti, akan redirect dengan pesan error
 *
 * @param string $redirectPage Halaman redirect jika tidak bisa akses
 * @return void
 */
function require_not_on_cuti(string $redirectPage = '/dashboard/pengajuan_cuti_resign.php'): void
{
    if (is_user_on_cuti_session()) {
        $_SESSION['flash_errors'][] = 'Anda tidak dapat mengakses halaman ini karena sedang cuti.';
        header('Location: ' . $redirectPage);
        exit;
    }
}

/**
 * Format tanggal untuk surat (dengan nama bulan Indonesia)
 *
 * @param string|null $date Tanggal dalam format Y-m-d atau Y-m-d H:i:s
 * @return string Tanggal format Indonesia (misal: "07 Maret 2026")
 */
function format_tanggal_surat(?string $date): string
{
    if (!$date) {
        return '-';
    }

    try {
        $dt = new DateTime($date);

        $bulan = [
            1 => 'Januari',
            'Februari',
            'Maret',
            'April',
            'Mei',
            'Juni',
            'Juli',
            'Agustus',
            'September',
            'Oktober',
            'November',
            'Desember'
        ];

        $hari = $dt->format('d');
        $bulanNama = $bulan[(int)$dt->format('n')];
        $tahun = $dt->format('Y');

        return "{$hari} {$bulanNama} {$tahun}";
    } catch (Exception $e) {
        return '-';
    }
}

/**
 * Format surat cuti sesuai template
 *
 * @param array $data Data surat (user, dates, reasons)
 * @return string Konten surat yang sudah diformat
 */
function format_surat_cuti(array $data): string
{
    $tanggal = format_tanggal_surat($data['created_at'] ?? 'now');
    $nama = htmlspecialchars($data['full_name'] ?? '');
    $jabatan = htmlspecialchars(ems_position_label($data['position'] ?? ''));
    $lamaIzin = (int)($data['days_total'] ?? 0);
    $alasanIC = htmlspecialchars($data['reason_ic'] ?? '-');
    $alasanOOC = htmlspecialchars($data['reason_ooc'] ?? '-');

    $surat = "Tanggal RL    : {$tanggal}\n";
    $surat .= "Hal           : Cuti\n\n";
    $surat .= "Direktur ROXWOOD HOSPITAL,\n";
    $surat .= "di Tempat\n\n";
    $surat .= "Dengan hormat,\n";
    $surat .= "Saya yang bertanda tangan dibawah di bawah ini:\n";
    $surat .= "Nama          : {$nama}\n";
    $surat .= "Jabatan       : {$jabatan}\n";
    $surat .= "Lama izin     : {$lamaIzin} hari\n";
    $surat .= "Alasan IC     : {$alasanIC}\n";
    $surat .= "Alasan OOC    : {$alasanOOC}\n\n";
    $surat .= "Dengan ini saya mengajukan permohonan izin cuti ini saya sampaikan.\n";
    $surat .= "Atas perhatian dan kebijaksanaannya saya ucapkan terima kasih\n\n";
    $surat .= "Salam Hormat,\n";
    $surat .= "{$nama}\n";

    return $surat;
}

/**
 * Format surat resign sesuai template
 *
 * @param array $data Data surat (user, reasons)
 * @return string Konten surat yang sudah diformat
 */
function format_surat_resign(array $data): string
{
    $tanggal = format_tanggal_surat($data['created_at'] ?? 'now');
    $nama = htmlspecialchars($data['full_name'] ?? '');
    $jabatan = htmlspecialchars(ems_position_label($data['position'] ?? ''));
    $alasanIC = htmlspecialchars($data['reason_ic'] ?? '-');
    $alasanOOC = htmlspecialchars($data['reason_ooc'] ?? '-');

    $surat = "Tanggal RL    : {$tanggal}\n";
    $surat .= "Hal           : Resign\n\n";
    $surat .= "Direktur ROXWOOD HOSPITAL,\n";
    $surat .= "di Tempat\n\n";
    $surat .= "Dengan hormat,\n";
    $surat .= "Saya yang bertanda tangan dibawah di bawah ini:\n";
    $surat .= "Nama          : {$nama}\n";
    $surat .= "Jabatan       : {$jabatan}\n\n";
    $surat .= "Alasan IC     : {$alasanIC}\n";
    $surat .= "Alasan OOC    : {$alasanOOC}\n\n";
    $surat .= "Dengan ini saya mengajukan permohonan pengunduran diri sebagai tenaga medis di EMS Department Alterlife. ";
    $surat .= "Saya mengucapkan terima kasih atas berbagai ilmu dan kesempatan yang sudah diberikan selama ini. ";
    $surat .= "Saya juga memohon maaf atas segala kesalahan dan kekurangan saya selama bekerja. ";
    $surat .= "Semoga kedepannya EMS akan terus berkembang dan menjadi lebih baik.\n\n";
    $surat .= "Demikian surat pengunduran diri saya ini saya sampaikan. Atas perhatian dan kebijaksanaannya saya ucapkan terima kasih.\n\n";
    $surat .= "Salam Hormat,\n";
    $surat .= "{$nama}\n";

    return $surat;
}

/**
 * Cek apakah user role bisa approve request
 *
 * @param string|null $role Role user
 * @return bool True jika bisa approve, false jika tidak
 */
function can_approve_cuti_resign(?string $role): bool
{
    $normalizedRole = ems_normalize_role($role);
    return in_array($normalizedRole, ['probation manager', 'assisten manager', 'lead manager', 'head manager', 'vice director', 'director'], true);
}

/**
 * Get label status dengan warna badge
 *
 * @param string $status Status (pending, approved, rejected)
 * @return array Array dengan key: label, class (CSS class)
 */
function get_status_badge(string $status): array
{
    return match ($status) {
        'pending' => [
            'label' => 'Menunggu',
            'class' => 'badge-warning'
        ],
        'approved' => [
            'label' => 'Disetujui',
            'class' => 'badge-success'
        ],
        'rejected' => [
            'label' => 'Ditolak',
            'class' => 'badge-error'
        ],
        default => [
            'label' => strtoupper($status),
            'class' => 'badge-secondary'
        ]
    };
}

/**
 * Compress image smart - reduce file size while maintaining quality
 * 
 * @param string $sourcePath Path to source image
 * @param string $targetPath Path to save compressed image
 * @param int $maxWidth Maximum width (default 1200px)
 * @param int $targetSize Target file size in bytes (default 300KB)
 * @param int $minQuality Minimum quality (default 70)
 * @return bool Success or failure
 */
function compressImageSmart(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1200,
    int $targetSize = 300000,
    int $minQuality = 70
): bool {
    $info = getimagesize($sourcePath);
    if (!$info) return false;

    $mime = $info['mime'];
    if ($mime === 'image/jpeg') {
        $src = imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $src = imagecreatefrompng($sourcePath);
    } else {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    // Resize if width exceeds max
    if ($w > $maxWidth) {
        $ratio = $maxWidth / $w;
        $nw = $maxWidth;
        $nh = (int)($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
    } else {
        $dst = $src;
    }

    // Compress
    if ($mime === 'image/png') {
        imagepng($dst, $targetPath, 7);
    } else {
        for ($q = 90; $q >= $minQuality; $q -= 5) {
            imagejpeg($dst, $targetPath, $q);
            if (filesize($targetPath) <= $targetSize) break;
        }
    }

    imagedestroy($dst);
    return true;
}

/**
 * Upload and compress file helper
 * 
 * @param array $file $_FILES array element
 * @param string $folder Folder name under storage/
 * @param int $maxSize Max file size in bytes (default 300KB)
 * @param int $uploadMaxSize Max upload size in bytes (default 5MB)
 * @return string|null File path on success, null on failure
 */
function uploadAndCompressFile(array $file, string $folder, int $maxSize = 300000, int $uploadMaxSize = 5000000): ?string
{
    // Validate error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png'];
    $info = getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], $allowedTypes, true)) {
        return null;
    }

    // Validate file size
    if ($file['size'] > $uploadMaxSize) {
        return null;
    }

    // Create folder path
    $baseDir = __DIR__ . '/../storage/' . $folder;
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }

    // Generate filename
    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $targetPath = $baseDir . '/' . $filename;

    // Compress and save
    if (compressImageSmart($file['tmp_name'], $targetPath, 1200, $maxSize, 70)) {
        return 'storage/' . $folder . '/' . $filename;
    }

    return null;
}

function emsCreateTempDirectory(string $prefix = 'ems_'): ?string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $path = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));

    if (@mkdir($path, 0700, true)) {
        return $path;
    }

    return null;
}

function emsRemoveDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target)) {
            emsRemoveDirectory($target);
            continue;
        }

        @unlink($target);
    }

    @rmdir($path);
}

function emsFindHeadlessBrowserPath(): ?string
{
    static $resolved = false;
    static $browserPath = null;

    if ($resolved) {
        return $browserPath;
    }

    $resolved = true;
    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $browserPath = $candidate;
            return $browserPath;
        }
    }

    foreach (['chrome', 'msedge'] as $command) {
        $whereOutput = [];
        $status = 1;
        @exec('where ' . escapeshellarg($command) . ' 2>NUL', $whereOutput, $status);
        if ($status === 0 && !empty($whereOutput[0]) && is_file(trim((string) $whereOutput[0]))) {
            $browserPath = trim((string) $whereOutput[0]);
            return $browserPath;
        }
    }

    return null;
}

function emsFilePathToBrowserUrl(string $path): string
{
    $realPath = realpath($path) ?: $path;
    $normalized = str_replace('\\', '/', $realPath);

    if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
        [$drive, $rest] = explode(':/', $normalized, 2);
        $segments = array_map('rawurlencode', array_filter(explode('/', $rest), static fn($segment): bool => $segment !== ''));
        return 'file:///' . $drive . ':/' . implode('/', $segments);
    }

    $segments = array_map('rawurlencode', array_filter(explode('/', ltrim($normalized, '/')), static fn($segment): bool => $segment !== ''));
    return 'file:///' . implode('/', $segments);
}

function emsRenderUrlToPng(string $url, string $targetPath, int $width = 1400, int $height = 2000): bool
{
    $browser = emsFindHeadlessBrowserPath();
    if ($browser === null) {
        return false;
    }

    $tempProfile = emsCreateTempDirectory('ems_browser_');
    if ($tempProfile === null) {
        return false;
    }

    $command = implode(' ', [
        escapeshellarg($browser),
        '--headless=new',
        '--disable-gpu',
        '--force-color-profile=srgb',
        '--disable-features=AutoDarkMode,WebContentsForceDark',
        '--blink-settings=darkMode=0',
        '--hide-scrollbars',
        '--allow-file-access-from-files',
        '--disable-software-rasterizer',
        '--run-all-compositor-stages-before-draw',
        '--virtual-time-budget=3000',
        '--user-data-dir=' . escapeshellarg($tempProfile),
        '--window-size=' . (int) $width . ',' . (int) $height,
        '--screenshot=' . escapeshellarg($targetPath),
        escapeshellarg($url),
    ]);

    $output = [];
    $status = 1;
    @exec($command . ' 2>&1', $output, $status);
    emsRemoveDirectory($tempProfile);

    return $status === 0 && is_file($targetPath) && filesize($targetPath) > 0;
}

function emsBuildBrowserPreviewHtml(string $title, string $embeddedUrl): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($embeddedUrl, ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="color-scheme" content="light">'
        . '<title>' . $safeTitle . '</title><style>'
        . 'html,body{margin:0;padding:0;background:#ffffff;color:#0f172a;color-scheme:light;width:100%;height:100%;overflow:hidden;}'
        . '.frame{width:1400px;height:2000px;margin:0 auto;background:#ffffff;}'
        . 'embed,iframe{display:block;width:100%;height:100%;border:0;background:#ffffff;}'
        . '</style></head><body><div class="frame"><embed src="' . $safeUrl . '" type="application/pdf"></div></body></html>';
}

function emsSaveImageResourceAsPng($image, string $targetPath, int $maxWidth = 1400): bool
{
    if (!is_resource($image) && !($image instanceof GdImage)) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $target = $image;

    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int) round(($height / max($width, 1)) * $newWidth);
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $target = $resized;
    }

    imagesavealpha($target, true);
    $saved = imagepng($target, $targetPath, 7);

    if ($target !== $image) {
        imagedestroy($target);
    }

    return $saved;
}

function emsConvertImageToPng(string $sourcePath, string $targetPath): bool
{
    $info = @getimagesize($sourcePath);
    if (!$info) {
        return false;
    }

    $mime = (string) ($info['mime'] ?? '');
    $image = null;

    if ($mime === 'image/jpeg') {
        $image = @imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $image = @imagecreatefrompng($sourcePath);
    } elseif ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
        $image = @imagecreatefromgif($sourcePath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $image = @imagecreatefromwebp($sourcePath);
    } elseif ($mime === 'image/bmp' && function_exists('imagecreatefrombmp')) {
        $image = @imagecreatefrombmp($sourcePath);
    }

    if (!$image) {
        return false;
    }

    $saved = emsSaveImageResourceAsPng($image, $targetPath);
    imagedestroy($image);

    return $saved;
}

function emsExtractDocxText(string $sourcePath): string
{
    $zip = new ZipArchive();
    if ($zip->open($sourcePath) !== true) {
        return '';
    }

    $documentXml = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();

    if ($documentXml === '') {
        return '';
    }

    $documentXml = preg_replace('/<\/w:p>/', "</w:p>\n", $documentXml) ?? $documentXml;
    $text = trim(html_entity_decode(strip_tags($documentXml), ENT_QUOTES | ENT_XML1, 'UTF-8'));

    return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
}

function emsExtractOdtText(string $sourcePath): string
{
    $zip = new ZipArchive();
    if ($zip->open($sourcePath) !== true) {
        return '';
    }

    $contentXml = $zip->getFromName('content.xml') ?: '';
    $zip->close();

    if ($contentXml === '') {
        return '';
    }

    $contentXml = preg_replace('/<\/text:p>/', "</text:p>\n", $contentXml) ?? $contentXml;
    $text = trim(html_entity_decode(strip_tags($contentXml), ENT_QUOTES | ENT_XML1, 'UTF-8'));

    return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
}

function emsExtractLegacyDocText(string $sourcePath): string
{
    $content = @file_get_contents($sourcePath);
    if ($content === false || $content === '') {
        return '';
    }

    $content = preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F]/", ' ', $content) ?? $content;
    $content = preg_replace('/[^[:print:]\r\n\t]/u', ' ', $content) ?? $content;
    $content = preg_replace('/\s{2,}/', ' ', $content) ?? $content;

    return trim($content);
}

function emsBuildTextPreviewHtml(string $title, string $content): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeContent = htmlspecialchars($content !== '' ? $content : 'Dokumen tidak memiliki teks yang dapat ditampilkan.', ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>' . $safeTitle . '</title><style>'
        . 'body{margin:0;background:#e2e8f0;font-family:Segoe UI,Tahoma,Arial,sans-serif;color:#0f172a;}'
        . '.sheet{width:1100px;min-height:1500px;margin:0 auto;padding:56px 64px;background:#fff;box-sizing:border-box;}'
        . 'h1{margin:0 0 24px;font-size:28px;line-height:1.3;}'
        . 'pre{margin:0;white-space:pre-wrap;word-break:break-word;font:16px/1.7 Consolas,"Courier New",monospace;color:#1e293b;}'
        . '</style></head><body><div class="sheet"><h1>' . $safeTitle . '</h1><pre>' . $safeContent . '</pre></div></body></html>';
}

function emsConvertDocumentToPng(string $sourcePath, string $originalName, string $targetPath): bool
{
    $extension = strtolower((string) pathinfo($originalName !== '' ? $originalName : $sourcePath, PATHINFO_EXTENSION));
    $mime = (string) (mime_content_type($sourcePath) ?: '');

    if (str_starts_with($mime, 'image/')) {
        return emsConvertImageToPng($sourcePath, $targetPath);
    }

    if (in_array($extension, ['html', 'htm'], true)) {
        return emsRenderUrlToPng(emsFilePathToBrowserUrl($sourcePath), $targetPath);
    }

    if ($extension === 'pdf') {
        $tempDir = emsCreateTempDirectory('ems_pdf_preview_');
        if ($tempDir === null) {
            return false;
        }

        $htmlPath = $tempDir . DIRECTORY_SEPARATOR . 'preview.html';
        $pdfUrl = emsFilePathToBrowserUrl($sourcePath) . '#page=1&toolbar=0&navpanes=0&scrollbar=0&view=FitH';
        $written = @file_put_contents($htmlPath, emsBuildBrowserPreviewHtml($originalName !== '' ? $originalName : basename($sourcePath), $pdfUrl));
        if ($written === false) {
            emsRemoveDirectory($tempDir);
            return false;
        }

        $rendered = emsRenderUrlToPng(emsFilePathToBrowserUrl($htmlPath), $targetPath);
        emsRemoveDirectory($tempDir);
        return $rendered;
    }

    $textContent = '';

    if (in_array($extension, ['txt', 'log', 'csv', 'json', 'xml', 'md', 'ini'], true) || str_starts_with($mime, 'text/')) {
        $textContent = (string) @file_get_contents($sourcePath);
    } elseif ($extension === 'docx') {
        $textContent = emsExtractDocxText($sourcePath);
    } elseif ($extension === 'odt') {
        $textContent = emsExtractOdtText($sourcePath);
    } elseif ($extension === 'doc') {
        $textContent = emsExtractLegacyDocText($sourcePath);
    }

    if ($textContent === '') {
        return false;
    }

    $tempDir = emsCreateTempDirectory('ems_preview_');
    if ($tempDir === null) {
        return false;
    }

    $htmlPath = $tempDir . DIRECTORY_SEPARATOR . 'preview.html';
    $written = @file_put_contents($htmlPath, emsBuildTextPreviewHtml($originalName !== '' ? $originalName : basename($sourcePath), $textContent));
    if ($written === false) {
        emsRemoveDirectory($tempDir);
        return false;
    }

    $rendered = emsRenderUrlToPng(emsFilePathToBrowserUrl($htmlPath), $targetPath);
    emsRemoveDirectory($tempDir);

    return $rendered;
}

function uploadFileAsPngPreview(array $file, string $folder, int $uploadMaxSize = 10000000): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    if ((int) ($file['size'] ?? 0) > $uploadMaxSize) {
        return null;
    }

    $sourcePath = (string) ($file['tmp_name'] ?? '');
    if ($sourcePath === '' || !is_uploaded_file($sourcePath)) {
        return null;
    }

    $baseDir = __DIR__ . '/../storage/' . $folder;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        return null;
    }

    $filename = uniqid('', true) . '_' . time() . '.png';
    $targetPath = $baseDir . '/' . $filename;

    if (!emsConvertDocumentToPng($sourcePath, (string) ($file['name'] ?? ''), $targetPath)) {
        return null;
    }

    return 'storage/' . $folder . '/' . $filename;
}

function emsUploadedFileExtension(array $file): string
{
    return strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
}

function emsUploadedFileMime(array $file): string
{
    $sourcePath = (string) ($file['tmp_name'] ?? '');
    if ($sourcePath === '' || !is_file($sourcePath)) {
        return '';
    }

    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo) {
        $mime = (string) (@finfo_file($finfo, $sourcePath) ?: '');
        @finfo_close($finfo);
        if ($mime !== '') {
            return strtolower($mime);
        }
    }

    return strtolower((string) (@mime_content_type($sourcePath) ?: ''));
}

function emsIsAllowedSecretaryAttachment(array $file): bool
{
    $extension = emsUploadedFileExtension($file);
    $mime = emsUploadedFileMime($file);

    if (in_array($extension, ['jpg', 'jpeg'], true)) {
        return $mime === 'image/jpeg';
    }

    if ($extension === 'png') {
        return $mime === 'image/png';
    }

    if ($extension === 'pdf') {
        return in_array($mime, ['application/pdf', 'application/x-pdf'], true);
    }

    if ($extension === 'doc') {
        return in_array($mime, [
            'application/msword',
            'application/octet-stream',
            'application/x-ole-storage',
            'application/ole',
            'application/vnd.ms-office',
        ], true);
    }

    if ($extension === 'docx') {
        return in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ], true);
    }

    return false;
}

function emsStoreUploadedFileOriginal(array $file, string $folder, int $uploadMaxSize = 10000000): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    if ((int) ($file['size'] ?? 0) > $uploadMaxSize) {
        return null;
    }

    $sourcePath = (string) ($file['tmp_name'] ?? '');
    if ($sourcePath === '' || !is_uploaded_file($sourcePath)) {
        return null;
    }

    $extension = emsUploadedFileExtension($file);
    if ($extension === '') {
        return null;
    }

    $baseDir = __DIR__ . '/../storage/' . $folder;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        return null;
    }

    $filename = uniqid('', true) . '_' . time() . '.' . $extension;
    $targetPath = $baseDir . '/' . $filename;

    if (!@move_uploaded_file($sourcePath, $targetPath)) {
        return null;
    }

    return 'storage/' . $folder . '/' . $filename;
}

function uploadSecretaryAttachmentFile(array $file, string $folder, int $imageMaxSize = 400000, int $uploadMaxSize = 10000000): ?string
{
    if (!emsIsAllowedSecretaryAttachment($file)) {
        return null;
    }

    $extension = emsUploadedFileExtension($file);

    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        return uploadAndCompressFile($file, $folder, $imageMaxSize, $uploadMaxSize);
    }

    return emsStoreUploadedFileOriginal($file, $folder, $uploadMaxSize);
}
