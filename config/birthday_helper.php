<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';
require_once __DIR__ . '/inbox_helper.php';

function ems_birthday_has_column(PDO $pdo, string $column = 'tanggal_lahir_ic'): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $cache[$column] = ems_column_exists($pdo, 'user_rh', $column);
    return $cache[$column];
}

function ems_birthday_format(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d M Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function ems_birthday_days_until(?string $value, ?DateTimeImmutable $today = null): ?int
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        $birthDate = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return null;
    }

    $today = $today ?: new DateTimeImmutable('today');
    $year = (int)$today->format('Y');
    $monthDay = $birthDate->format('m-d');
    $target = DateTimeImmutable::createFromFormat('Y-m-d', $year . '-' . $monthDay);
    if (!$target) {
        return null;
    }

    if ($target < $today) {
        $target = $target->modify('+1 year');
    }

    return (int)$today->diff($target)->days;
}

function ems_birthday_countdown_label(?string $value, ?DateTimeImmutable $today = null): string
{
    $days = ems_birthday_days_until($value, $today);
    if ($days === null) {
        return '-';
    }

    if ($days === 0) {
        return 'Ulang tahun hari ini';
    }

    return 'Sisa ' . $days . ' hari';
}

function ems_birthday_zodiac_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Tidak diketahui';
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return 'Tidak diketahui';
    }

    $monthDay = (int)$date->format('md');
    return match (true) {
        $monthDay >= 321 && $monthDay <= 419 => 'Aries',
        $monthDay >= 420 && $monthDay <= 520 => 'Taurus',
        $monthDay >= 521 && $monthDay <= 620 => 'Gemini',
        $monthDay >= 621 && $monthDay <= 722 => 'Cancer',
        $monthDay >= 723 && $monthDay <= 822 => 'Leo',
        $monthDay >= 823 && $monthDay <= 922 => 'Virgo',
        $monthDay >= 923 && $monthDay <= 1022 => 'Libra',
        $monthDay >= 1023 && $monthDay <= 1121 => 'Scorpio',
        $monthDay >= 1122 && $monthDay <= 1221 => 'Sagittarius',
        $monthDay >= 1222 || $monthDay <= 119 => 'Capricorn',
        $monthDay >= 120 && $monthDay <= 218 => 'Aquarius',
        $monthDay >= 219 && $monthDay <= 320 => 'Pisces',
        default => 'Tidak diketahui',
    };
}

function ems_birthday_activity_profile(PDO $pdo, int $userId): array
{
    $profile = [
        'sales_count_90d' => 0,
        'sales_days_90d' => 0,
        'farmasi_status' => 'unknown',
        'last_activity_at' => null,
        'discipline_points' => 0,
    ];

    if ($userId <= 0) {
        return $profile;
    }

    if (ems_table_exists($pdo, 'sales') && ems_column_exists($pdo, 'sales', 'medic_user_id')) {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_sales,
                COUNT(DISTINCT DATE(created_at)) AS active_days
            FROM sales
            WHERE medic_user_id = ?
              AND created_at >= (NOW() - INTERVAL 90 DAY)
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $profile['sales_count_90d'] = (int)($row['total_sales'] ?? 0);
        $profile['sales_days_90d'] = (int)($row['active_days'] ?? 0);
    }

    if (ems_table_exists($pdo, 'user_farmasi_status')) {
        $stmt = $pdo->prepare("
            SELECT status, last_activity_at
            FROM user_farmasi_status
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $profile['farmasi_status'] = strtolower(trim((string)($row['status'] ?? 'unknown')));
        $profile['last_activity_at'] = $row['last_activity_at'] ?? null;
    }

    if (ems_table_exists($pdo, 'disciplinary_cases')) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(points), 0)
            FROM disciplinary_cases
            WHERE subject_user_id = ?
        ");
        try {
            $stmt->execute([$userId]);
            $profile['discipline_points'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $profile['discipline_points'] = 0;
        }
    }

    return $profile;
}

function ems_birthday_activity_summary(array $profile): string
{
    $salesCount = (int)($profile['sales_count_90d'] ?? 0);
    $salesDays = (int)($profile['sales_days_90d'] ?? 0);
    $status = strtolower(trim((string)($profile['farmasi_status'] ?? 'unknown')));

    if ($salesCount >= 120 || $salesDays >= 35) {
        return 'sangat aktif dan konsisten di aktivitas web farmasi';
    }
    if ($salesCount >= 45 || $salesDays >= 18) {
        return 'cukup aktif dan rutin berkontribusi di web farmasi';
    }
    if ($salesCount > 0 || $salesDays > 0) {
        return 'tetap terlibat dan sesekali aktif di web farmasi';
    }
    if ($status === 'online') {
        return 'sedang aktif memantau web farmasi';
    }

    return 'aktivitas web farmasinya cenderung tenang namun tetap terhubung dengan tim';
}

function ems_birthday_fallback_message(string $fullName, string $zodiac, array $profile): string
{
    $activitySummary = ems_birthday_activity_summary($profile);
    $disciplinePoints = (int)($profile['discipline_points'] ?? 0);
    $disciplineLine = $disciplinePoints > 0
        ? ' Semoga tahun ini juga makin ringan langkahnya, makin rapi ritmenya, dan makin bijak menjaga diri dalam kerja.'
        : ' Semoga ritme kerja, tenaga, dan fokusnya tetap terjaga dengan baik di tahun yang baru ini.';

    return trim(
        'Selamat ulang tahun, ' . $fullName . '. Aura ' . $zodiac . ' kamu hari ini terasa cocok dengan cara kamu bekerja yang ' . $activitySummary . '.'
        . ' Terima kasih karena sudah tetap hadir, jalan pelan tapi pasti, dan menjaga kualitas di momen-momen penting.' . $disciplineLine
    );
}

function ems_birthday_generate_message(PDO $pdo, array $userRow): string
{
    $fullName = trim((string)($userRow['full_name'] ?? 'Medis'));
    $zodiac = ems_birthday_zodiac_label($userRow['tanggal_lahir_ic'] ?? null);
    $profile = ems_birthday_activity_profile($pdo, (int)($userRow['id'] ?? 0));
    $fallback = ems_birthday_fallback_message($fullName, $zodiac, $profile);

    try {
        $settings = ems_ai_get_settings($pdo);
        if (empty($settings['is_enabled']) || trim((string)($settings['gemini_api_key'] ?? '')) === '') {
            return $fallback;
        }

        $activitySummary = ems_birthday_activity_summary($profile);
        $disciplinePoints = (int)($profile['discipline_points'] ?? 0);
        $prompt = <<<TEXT
Tulis 1 pesan ulang tahun dalam bahasa Indonesia untuk medis internal rumah sakit.

Aturan:
- Nada hangat, realistis, profesional, tidak lebay, tidak halu.
- Maksimal 90 kata.
- Jangan pakai emoji.
- Jangan terdengar seperti ramalan.
- Zodiak hanya dipakai sebagai nuansa karakter ringan, bukan mistis.
- Sertakan apresiasi berdasarkan aktivitas nyata.
- Hindari kalimat klise berlebihan.
- Balas JSON valid dengan format {"message":"..."} saja.

Data:
- Nama: {$fullName}
- Zodiak: {$zodiac}
- Ringkasan aktivitas: {$activitySummary}
- Total transaksi farmasi 90 hari: {$profile['sales_count_90d']}
- Hari aktif farmasi 90 hari: {$profile['sales_days_90d']}
- Status farmasi saat ini: {$profile['farmasi_status']}
- Poin disiplin tercatat: {$disciplinePoints}
TEXT;

        $response = ems_gemini_generate_content(
            $pdo,
            $settings,
            [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            (string)($settings['default_model'] ?? 'gemini-2.5-flash'),
            'birthday_evening_message',
            (int)($userRow['id'] ?? 0)
        );

        $text = trim((string)($response['text'] ?? ''));
        if ($text === '') {
            return $fallback;
        }

        $decoded = json_decode($text, true);
        $message = trim((string)($decoded['message'] ?? ''));
        return $message !== '' ? $message : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function ems_birthday_viewer_is_celebrating_today(PDO $pdo, int $userId): bool
{
    if ($userId <= 0 || !ems_birthday_has_column($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM user_rh
        WHERE id = ?
          AND is_active = 1
          AND tanggal_lahir_ic IS NOT NULL
          AND DATE_FORMAT(tanggal_lahir_ic, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function ems_birthday_fetch_today_celebrants(PDO $pdo, int $viewerUserId = 0): array
{
    if (!ems_birthday_has_column($pdo)) {
        return [];
    }

    $sql = "
        SELECT id, full_name, position, role, division, tanggal_lahir_ic
        FROM user_rh
        WHERE is_active = 1
          AND tanggal_lahir_ic IS NOT NULL
          AND DATE_FORMAT(tanggal_lahir_ic, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
    ";
    $params = [];

    if ($viewerUserId > 0) {
        $sql .= " AND id != ?";
        $params[] = $viewerUserId;
    }

    $sql .= " ORDER BY full_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_birthday_evening_inbox_exists(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM user_inbox
        WHERE user_id = ?
          AND type = 'birthday_evening'
          AND DATE(created_at) = CURDATE()
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function ems_birthday_ensure_evening_inbox(PDO $pdo): void
{
    if (!ems_birthday_has_column($pdo) || !ems_table_exists($pdo, 'user_inbox')) {
        return;
    }

    $now = new DateTimeImmutable('now');
    if ((int)$now->format('G') < 19) {
        return;
    }

    $stmt = $pdo->query("
        SELECT id, full_name, tanggal_lahir_ic
        FROM user_rh
        WHERE is_active = 1
          AND tanggal_lahir_ic IS NOT NULL
          AND DATE_FORMAT(tanggal_lahir_ic, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
    ");
    $birthdayUsers = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($birthdayUsers as $birthdayUser) {
        $userId = (int)($birthdayUser['id'] ?? 0);
        if ($userId <= 0 || ems_birthday_evening_inbox_exists($pdo, $userId)) {
            continue;
        }

        $message = ems_birthday_generate_message($pdo, $birthdayUser);
        sendInbox($pdo, $userId, 'Ucapan Ulang Tahun', $message, 'birthday_evening');
    }
}
