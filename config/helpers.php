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
        ['assisten manager', 'lead manager', 'head manager', 'vice director', 'director'],
        true
    );
}

function ems_is_director_role(?string $role): bool
{
    return in_array(ems_normalize_role($role), ['vice director', 'director'], true);
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

function ems_is_valid_division(?string $division): bool
{
    return in_array(
        ems_normalize_division($division),
        array_column(ems_division_options(), 'value'),
        true
    );
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
        'rekap_farmasi.php',
        'rekap_farmasi_v2.php',
        'konsumen.php',
        'ranking.php',
        'absensi_ems.php',
        'reimbursement.php',
        'restaurant_consumption.php',
        'gaji.php',
        'rekap_gaji.php',
        'pengajuan_jabatan.php',
        'pengajuan_jabatan_action.php',
        'pengajuan_cuti_resign.php',
        'pengajuan_cuti_resign_action.php',
        'setting_akun.php',
        'setting_akun_action.php',
    ];
}

function ems_enforce_dashboard_page_access(?string $division, string $scriptName, string $redirectTo = '/dashboard/index.php'): void
{
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
    return in_array($normalizedRole, ['assisten manager', 'lead manager', 'head manager', 'vice director', 'director'], true);
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
