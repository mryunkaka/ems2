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
    return preg_replace('/\s+/', ' ', $raw) ?: '';
}

function ems_role_label(?string $role): string
{
    return match (ems_normalize_role($role)) {
        'staff' => 'Staff',
        'staff manager' => 'Staff Manager',
        'manager' => 'Manager',
        'vice director' => 'Vice Director',
        'director' => 'Director',
        '' => '-',
        default => ucwords((string)$role),
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
