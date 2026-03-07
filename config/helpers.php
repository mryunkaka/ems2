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

        // Cek apakah hari ini berada dalam range cuti
        $today = new DateTime();
        $startDate = new DateTime($user['cuti_start_date']);
        $endDate = new DateTime($user['cuti_end_date']);

        return $today >= $startDate && $today <= $endDate;
    } catch (Throwable $e) {
        // Return false jika error
        return false;
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
    return in_array($normalizedRole, ['staff manager', 'manager', 'vice director', 'director'], true);
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
