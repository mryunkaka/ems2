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

function ems_birthday_weekday_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return '-';
    }

    $labels = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
    ];

    return $labels[$date->format('l')] ?? $date->format('l');
}

function ems_birthday_format_long(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return $value;
    }

    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    return ems_birthday_weekday_label($value)
        . ', '
        . (int)$date->format('d')
        . ' '
        . ($months[(int)$date->format('n')] ?? $date->format('M'))
        . ' '
        . $date->format('Y');
}

function ems_birthday_current_age(?string $value, ?DateTimeImmutable $today = null): ?int
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
    return $birthDate->diff($today)->y;
}

function ems_birthday_turning_age(?string $value, ?DateTimeImmutable $today = null): ?int
{
    $currentAge = ems_birthday_current_age($value, $today);
    $daysUntil = ems_birthday_days_until($value, $today);

    if ($currentAge === null || $daysUntil === null) {
        return null;
    }

    return $daysUntil === 0 ? $currentAge : $currentAge + 1;
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

    $turningAge = ems_birthday_turning_age($value, $today);

    if ($days === 0) {
        return $turningAge !== null
            ? 'Ulang tahun ke-' . $turningAge . ' hari ini'
            : 'Ulang tahun hari ini';
    }

    return $turningAge !== null
        ? 'Sisa ' . $days . ' hari menuju ulang tahun ke-' . $turningAge
        : 'Sisa ' . $days . ' hari';
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

function ems_birthday_day_character(?string $value): string
{
    return match (ems_birthday_weekday_label($value)) {
        'Senin' => 'rapi memulai, cepat menata arah, dan cocok jadi penggerak awal',
        'Selasa' => 'tegas, berani ambil langkah, dan kuat saat ritme kerja padat',
        'Rabu' => 'adaptif, komunikatif, dan enak diajak menyambung banyak hal',
        'Kamis' => 'tenang, dewasa, dan biasanya kuat menjaga stabilitas tim',
        'Jumat' => 'hangat, reflektif, dan mudah membuat suasana kerja lebih manusiawi',
        'Sabtu' => 'ulet, tahan proses, dan tidak mudah goyah saat target panjang',
        'Minggu' => 'cerah, membawa energi sosial, dan gampang membuat orang nyaman',
        default => 'punya ritme yang unik dan tidak kaku',
    };
}

function ems_birthday_extract_last_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    if (!$parts) {
        return '';
    }

    return (string)end($parts);
}

function ems_birthday_family_reading(string $fullName): string
{
    $lastName = ems_birthday_extract_last_name($fullName);
    if ($lastName === '') {
        return 'Nama kamu memberi kesan sederhana, membumi, dan mudah dipercaya.';
    }

    $lastNameLower = strtolower($lastName);
    $length = function_exists('mb_strlen') ? mb_strlen($lastNameLower) : strlen($lastNameLower);
    $lastChar = function_exists('mb_substr') ? mb_substr($lastNameLower, -1) : substr($lastNameLower, -1);

    if ($length >= 8) {
        return 'Nama belakang ' . $lastName . ' memberi kesan teliti, tahan proses, dan kuat memegang tanggung jawab yang panjang.';
    }

    return match ($lastChar) {
        'a' => 'Nama belakang ' . $lastName . ' memberi nuansa hangat, terbuka, dan mudah mengikat pertemanan.',
        'i' => 'Nama belakang ' . $lastName . ' memberi kesan sigap, praktis, dan cepat menangkap inti masalah.',
        'u' => 'Nama belakang ' . $lastName . ' terasa tenang, setia proses, dan tidak gampang terpancing.',
        'n' => 'Nama belakang ' . $lastName . ' memberi kesan stabil, bisa diandalkan, dan cocok menjaga ritme tim.',
        'r' => 'Nama belakang ' . $lastName . ' terasa tegas, lurus, dan kuat saat harus menjaga standar kerja.',
        default => 'Nama belakang ' . $lastName . ' memberi kesan khas: kalem di luar, tapi kuat saat sudah memegang komitmen.',
    };
}

function ems_birthday_fallback_message(string $fullName, string $zodiac, array $profile): string
{
    $activitySummary = ems_birthday_activity_summary($profile);
    $turningAge = (int)($profile['turning_age'] ?? 0);
    $birthDate = (string)($profile['birth_date'] ?? '');
    $weekday = (string)($profile['weekday'] ?? ems_birthday_weekday_label($birthDate));
    $dayCharacter = ems_birthday_day_character($birthDate);
    $familyReading = ems_birthday_family_reading($fullName);
    $agePhrase = $turningAge > 0 ? 'ke-' . $turningAge : 'tahun ini';
    $lifeLine = $zodiac === 'Tidak diketahui'
        ? 'Semoga hidupmu ke depan makin jernih arahnya, makin tenang menjalaninya, dan tetap kuat menjaga energi baik di tengah tekanan apa pun.'
        : 'Semoga di usia ini langkah hidupmu makin matang, hati tetap tenang, dan nuansa ' . $zodiac . ' yang hangat serta tekun terus membawa kamu ke keputusan-keputusan yang baik.';

    return trim(
        'Selamat ulang tahun ' . $agePhrase . ', ' . $fullName . '. '
        . 'Terima kasih karena selama ini kamu terlihat ' . $activitySummary . ', karena konsistensi yang tenang sering justru jadi fondasi paling kuat dalam tim. '
        . $lifeLine . ' '
        . 'Lahir di hari ' . $weekday . ' biasanya membawa karakter yang ' . $dayCharacter . ', dan itu terasa sejalan dengan caramu hadir untuk pekerjaan dan orang-orang di sekitarmu. '
        . $familyReading
    );
}

function ems_birthday_generate_message(PDO $pdo, array $userRow): string
{
    $fullName = trim((string)($userRow['full_name'] ?? 'Medis'));
    $birthDate = trim((string)($userRow['tanggal_lahir_ic'] ?? ''));
    $zodiac = ems_birthday_zodiac_label($birthDate);
    $weekday = ems_birthday_weekday_label($birthDate);
    $turningAge = ems_birthday_turning_age($birthDate) ?? 0;
    $profile = ems_birthday_activity_profile($pdo, (int)($userRow['id'] ?? 0));
    $profile['birth_date'] = $birthDate;
    $profile['weekday'] = $weekday;
    $profile['turning_age'] = $turningAge;
    $fallback = ems_birthday_fallback_message($fullName, $zodiac, $profile);

    try {
        $settings = ems_ai_get_settings($pdo);
        if (empty($settings['is_enabled']) || trim((string)($settings['gemini_api_key'] ?? '')) === '') {
            return $fallback;
        }

        $activitySummary = ems_birthday_activity_summary($profile);
        $disciplinePoints = (int)($profile['discipline_points'] ?? 0);
        $familyReading = ems_birthday_family_reading($fullName);
        $dayCharacter = ems_birthday_day_character($birthDate);
        $prompt = <<<TEXT
Tulis 1 pesan ulang tahun dalam bahasa Indonesia untuk medis internal rumah sakit.

Aturan:
- Nada hangat, realistis, profesional, tidak lebay, tidak halu.
- Buat 3 sampai 4 kalimat yang mengalir natural.
- Jangan pakai emoji.
- Boleh ada bacaan ringan karakter, tapi jangan mistis, jangan menyeramkan, jangan sok tahu.
- Wajib memuat semangat kerja dan semangat hidup.
- Sertakan apresiasi berdasarkan aktivitas nyata.
- Hindari kalimat klise berlebihan.
- Jangan terlalu pendek, jangan terasa generik, dan jangan terdengar seperti puisi kosong.
- Balas JSON valid dengan format {"message":"..."} saja.

Data:
- Nama: {$fullName}
- Ulang tahun ke: {$turningAge}
- Hari lahir: {$weekday}
- Zodiak: {$zodiac}
- Ringkasan aktivitas: {$activitySummary}
- Total transaksi farmasi 90 hari: {$profile['sales_count_90d']}
- Hari aktif farmasi 90 hari: {$profile['sales_days_90d']}
- Status farmasi saat ini: {$profile['farmasi_status']}
- Poin disiplin tercatat: {$disciplinePoints}
- Kesan hari lahir: {$dayCharacter}
- Bacaan nama belakang: {$familyReading}
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

function ems_birthday_inbox_title(array $userRow): string
{
    $turningAge = ems_birthday_turning_age($userRow['tanggal_lahir_ic'] ?? null);
    if ($turningAge !== null && $turningAge > 0) {
        return 'Ucapan Ulang Tahun ke-' . $turningAge;
    }

    return 'Ucapan Ulang Tahun';
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
        sendInbox($pdo, $userId, ems_birthday_inbox_title($birthdayUser), $message, 'birthday_evening');
    }
}
