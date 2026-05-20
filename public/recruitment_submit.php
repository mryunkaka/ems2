<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/recruitment_gate.php';

ems_public_recruitment_require_portal_open();

const EMS_ASSISTANT_MANAGER_DOC_BYPASS_CITIZEN_ID = 'RH39IQLC';

/* ===============================
   FUNGSI
   =============================== */
function slugName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    return preg_replace('/\s+/', '_', $name);
}

function recruitmentResolveExistingUserDoc(PDO $pdo, string $citizenId, string $documentType, string $candidatePath): ?string
{
    $citizenId = trim($citizenId);
    $candidatePath = trim($candidatePath);
    if ($citizenId === '' || $candidatePath === '') {
        return null;
    }

    $columnMap = [
        'ktp_ic' => 'file_ktp',
        'skb' => 'file_skb',
        'sim' => 'file_sim',
    ];

    $column = $columnMap[$documentType] ?? null;
    if ($column === null) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT {$column} AS file_path FROM user_rh WHERE citizen_id = ? LIMIT 1");
    $stmt->execute([$citizenId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $existingPath = trim((string)($row['file_path'] ?? ''));

    if ($existingPath === '' || $existingPath !== $candidatePath) {
        return null;
    }

    $absolutePath = realpath(__DIR__ . '/../' . ltrim($existingPath, '/'));
    if ($absolutePath === false || !is_file($absolutePath)) {
        return null;
    }

    return $existingPath;
}

function recruitmentAllowedApplicantDocumentTypes(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM applicant_documents LIKE 'document_type'");
    $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    $type = strtolower((string)($column['Type'] ?? ''));

    if (preg_match("/^enum\\((.+)\\)$/", $type, $matches)) {
        $items = str_getcsv($matches[1], ',', "'");
        $cache = array_values(array_filter(array_map('trim', $items)));
        return $cache;
    }

    $cache = ['ktp_ic', 'skb', 'sim', 'kta', 'surat_keterangan_sehat', 'surat_keterangan_psikolog'];
    return $cache;
}

function recruitmentStoreApplicantImage(array $file, string $uploadDir, string $documentType): string
{
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new Exception("Upload {$documentType} gagal");
    }

    if (emsUploadedFileExceedsLimit($file)) {
        throw new Exception("Ukuran file {$documentType} maksimal " . emsUploadLimitLabel());
    }

    $imgInfo = @getimagesize($tmp);
    $allowedMimes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
    ];

    if ($imgInfo === false || !in_array((string)($imgInfo['mime'] ?? ''), $allowedMimes, true)) {
        throw new Exception("File {$documentType} harus berupa gambar yang valid");
    }

    $mime = (string)($imgInfo['mime'] ?? '');
    $sourceImage = null;
    if ($mime === 'image/jpeg') {
        $sourceImage = @imagecreatefromjpeg($tmp);
    } elseif ($mime === 'image/png') {
        $sourceImage = @imagecreatefrompng($tmp);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $sourceImage = @imagecreatefromwebp($tmp);
    } elseif ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
        $sourceImage = @imagecreatefromgif($tmp);
    } elseif ($mime === 'image/bmp' && function_exists('imagecreatefrombmp')) {
        $sourceImage = @imagecreatefrombmp($tmp);
    }

    if (!$sourceImage) {
        throw new Exception("File {$documentType} tidak dapat dibaca server");
    }

    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);
    $maxLongEdge = 2400;
    $longestEdge = max($sourceWidth, $sourceHeight, 1);
    $scale = $longestEdge > $maxLongEdge ? ($maxLongEdge / $longestEdge) : 1;
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));

    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$canvas) {
        imagedestroy($sourceImage);
        throw new Exception("Server gagal menyiapkan kompresi {$documentType}");
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopyresampled($canvas, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    imagedestroy($sourceImage);

    $finalPath = $uploadDir . '/' . $documentType . '.jpg';
    $qualitySteps = [90, 86, 82, 78, 74, 70, 66];
    $targetMaxBytes = 500 * 1024;
    $saved = false;

    foreach ($qualitySteps as $quality) {
        $saved = imagejpeg($canvas, $finalPath, $quality);
        if (!$saved) {
            continue;
        }

        clearstatcache(true, $finalPath);
        $finalSize = is_file($finalPath) ? (int)filesize($finalPath) : 0;
        if ($finalSize > 0 && $finalSize <= $targetMaxBytes) {
            break;
        }
    }

    imagedestroy($canvas);

    if (!$saved || !is_file($finalPath)) {
        throw new Exception("Gagal memproses {$documentType}");
    }

    return $finalPath;
}

function recruitmentUploadErrorMessage(int $errorCode, string $documentType): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Ukuran file {$documentType} terlalu besar. Batas upload adalah " . emsUploadLimitLabel() . '.',
        UPLOAD_ERR_PARTIAL => "Upload {$documentType} terputus sebelum selesai. Silakan coba lagi.",
        UPLOAD_ERR_NO_FILE => "File {$documentType} belum dipilih.",
        UPLOAD_ERR_NO_TMP_DIR => "Server upload sementara tidak siap.",
        UPLOAD_ERR_CANT_WRITE => "Server gagal menyimpan file {$documentType}.",
        UPLOAD_ERR_EXTENSION => "Upload {$documentType} dihentikan oleh konfigurasi server.",
        default => "Upload {$documentType} gagal.",
    };
}

function recruitmentRequirePostedFields(array $fields): void
{
    foreach ($fields as $field => $label) {
        $value = $_POST[$field] ?? null;
        if (is_array($value)) {
            if ($value === []) {
                http_response_code(400);
                exit("Field {$label} wajib diisi.");
            }
            continue;
        }

        if (trim((string)$value) === '') {
            http_response_code(400);
            exit("Field {$label} wajib diisi.");
        }
    }
}

function recruitmentInsertApplicantDocument(PDO $pdo, int $applicantId, string $documentType, string $filePath): void
{
    $allowedTypes = recruitmentAllowedApplicantDocumentTypes($pdo);
    if (!in_array($documentType, $allowedTypes, true)) {
        return;
    }

    $pdo->prepare("
        INSERT INTO applicant_documents
        (applicant_id, document_type, file_path)
        VALUES (?, ?, ?)
    ")->execute([
        $applicantId,
        $documentType,
        $filePath,
    ]);
}

function recruitmentFormatJoinDurationForStorage(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) {
        return '-';
    }

    try {
        $start = new DateTime((string)$tanggalMasuk);
        $now = new DateTime();
        if ($start > $now) {
            return '-';
        }

        $diff = $start->diff($now);
        if ($diff->y > 0) {
            return $diff->y . ' tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' bulan' : '');
        }

        if ($diff->m > 0) {
            return $diff->m . ' bulan' . ($diff->d > 0 ? ' ' . $diff->d . ' hari' : '');
        }

        $days = (int)$diff->days;
        if ($days >= 7) {
            $weeks = intdiv($days, 7);
            $remainingDays = $days % 7;
            return $weeks . ' minggu' . ($remainingDays > 0 ? ' ' . $remainingDays . ' hari' : '');
        }

        if ($days >= 1) {
            return $days . ' hari';
        }

        return max(1, (int)$diff->h) . ' jam';
    } catch (Throwable $e) {
        return '-';
    }
}

function recruitmentFormatAverageScheduleForStorage(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT
            session_start,
            COALESCE(session_end, DATE_ADD(session_start, INTERVAL duration_seconds SECOND)) AS effective_end,
            CASE
                WHEN session_end IS NULL THEN TIMESTAMPDIFF(SECOND, session_start, NOW())
                ELSE duration_seconds
            END AS effective_duration_seconds
        FROM user_farmasi_sessions
        WHERE user_id = ?
        ORDER BY session_start DESC
        LIMIT 30
    ");
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($sessions === []) {
        return [
            'online_schedule' => '-',
            'duty_duration' => '-',
        ];
    }

    $dailyTotals = [];
    $dailyBuckets = [];
    $bucketRanges = [];
    $buckets = [
        'siang' => ['label' => 'Siang', 'start' => 11 * 60, 'end' => 15 * 60],
        'sore' => ['label' => 'Sore', 'start' => 15 * 60, 'end' => 18 * 60],
        'malam' => ['label' => 'Malam', 'start' => 18 * 60, 'end' => 24 * 60],
        'subuh' => ['label' => 'Subuh', 'start' => 0, 'end' => 5 * 60],
        'pagi' => ['label' => 'Pagi', 'start' => 5 * 60, 'end' => 11 * 60],
    ];
    $formatClock = static function (int $minute): string {
        $minute = (($minute % 1440) + 1440) % 1440;
        return str_pad((string)intdiv($minute, 60), 2, '0', STR_PAD_LEFT)
            . ':'
            . str_pad((string)($minute % 60), 2, '0', STR_PAD_LEFT);
    };

    foreach ($sessions as $session) {
        $startRaw = (string)($session['session_start'] ?? '');
        $endRaw = (string)($session['effective_end'] ?? '');
        $duration = (int)($session['effective_duration_seconds'] ?? 0);
        if ($startRaw === '' || $endRaw === '' || $duration <= 0) {
            continue;
        }

        try {
            $start = new DateTime($startRaw);
            $end = new DateTime($endRaw);
        } catch (Throwable $e) {
            continue;
        }

        if ($end <= $start) {
            continue;
        }

        $cursor = clone $start;
        while ($cursor < $end) {
            $dayKey = $cursor->format('Y-m-d');
            $nextDay = (clone $cursor)->setTime(0, 0, 0)->modify('+1 day');
            $segmentEnd = $end < $nextDay ? clone $end : $nextDay;
            $segmentSeconds = max(0, $segmentEnd->getTimestamp() - $cursor->getTimestamp());
            if ($segmentSeconds > 0) {
                $dailyTotals[$dayKey] = ($dailyTotals[$dayKey] ?? 0) + $segmentSeconds;
            }

            foreach ($buckets as $bucketKey => $bucket) {
                $bucketStart = (clone $cursor)->setTime(0, 0, 0)->modify('+' . $bucket['start'] . ' minutes');
                $bucketEnd = (clone $cursor)->setTime(0, 0, 0)->modify('+' . $bucket['end'] . ' minutes');
                $overlapStart = max($cursor->getTimestamp(), $bucketStart->getTimestamp());
                $overlapEnd = min($segmentEnd->getTimestamp(), $bucketEnd->getTimestamp());
                if ($overlapEnd > $overlapStart) {
                    $dailyBuckets[$dayKey][$bucketKey] = ($dailyBuckets[$dayKey][$bucketKey] ?? 0) + ($overlapEnd - $overlapStart);
                    $overlapStartMinute = ((int)date('H', $overlapStart) * 60) + (int)date('i', $overlapStart);
                    $overlapEndMinute = ((int)date('H', $overlapEnd) * 60) + (int)date('i', $overlapEnd);
                    if (!isset($bucketRanges[$bucketKey][$dayKey])) {
                        $bucketRanges[$bucketKey][$dayKey] = [
                            'start' => $overlapStartMinute,
                            'end' => $overlapEndMinute,
                        ];
                    } else {
                        $bucketRanges[$bucketKey][$dayKey]['start'] = min($bucketRanges[$bucketKey][$dayKey]['start'], $overlapStartMinute);
                        $bucketRanges[$bucketKey][$dayKey]['end'] = max($bucketRanges[$bucketKey][$dayKey]['end'], $overlapEndMinute);
                    }
                }
            }

            $cursor = $segmentEnd;
        }
    }

    $activeDayCount = count($dailyTotals);
    if ($activeDayCount <= 0) {
        return [
            'online_schedule' => '-',
            'duty_duration' => '-',
        ];
    }

    $bucketLines = [];
    foreach ($buckets as $bucketKey => $bucket) {
        $rangeRows = $bucketRanges[$bucketKey] ?? [];
        if ($rangeRows === []) {
            $bucketLines[] = $bucket['label'] . ' jam -';
            continue;
        }

        $startSum = 0;
        $endSum = 0;
        $rangeCount = 0;
        foreach ($rangeRows as $range) {
            $startSum += (int)$range['start'];
            $endSum += (int)$range['end'];
            $rangeCount++;
        }

        if ($rangeCount <= 0) {
            $bucketLines[] = $bucket['label'] . ' jam -';
            continue;
        }

        $averageStart = (int)round($startSum / $rangeCount);
        $averageEnd = (int)round($endSum / $rangeCount);
        if ($averageEnd <= $averageStart) {
            $bucketLines[] = $bucket['label'] . ' jam -';
            continue;
        }

        $bucketLines[] = $bucket['label'] . ' jam ' . $formatClock($averageStart) . '-' . $formatClock($averageEnd);
    }

    $averageDuration = (int)round(array_sum($dailyTotals) / $activeDayCount);
    $hours = intdiv($averageDuration, 3600);
    $minutes = intdiv($averageDuration % 3600, 60);
    $dutyDuration = $hours > 0
        ? 'Rata-rata ' . $hours . ' jam' . ($minutes > 0 ? ' ' . $minutes . ' menit' : '') . ' per hari'
        : 'Rata-rata ' . max(1, $minutes) . ' menit per hari';

    return [
        'online_schedule' => implode("\n", $bucketLines),
        'duty_duration' => $dutyDuration,
    ];
}

function recruitmentFetchVerifiedUser(PDO $pdo, string $citizenId, int $verifiedUserId): ?array
{
    if ($citizenId === '' || $verifiedUserId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            full_name,
            citizen_id,
            no_hp_ic,
            jenis_kelamin,
            tanggal_masuk,
            file_ktp,
            file_skb,
            file_kta,
            file_sim
        FROM user_rh
        WHERE id = ?
          AND citizen_id = ?
        LIMIT 1
    ");
    $stmt->execute([$verifiedUserId, $citizenId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function recruitmentFindExistingApplicant(PDO $pdo, string $citizenId, string $recruitmentType): ?array
{
    $citizenId = trim($citizenId);
    if ($citizenId === '') {
        return null;
    }

    $hasRecruitmentTypeColumn = ems_column_exists($pdo, 'medical_applicants', 'recruitment_type');
    $sql = "
        SELECT
            m.id,
            m.citizen_id,
            m.status,
            EXISTS(
                SELECT 1
                FROM ai_test_results r
                WHERE r.applicant_id = m.id
            ) AS has_ai_result
        FROM medical_applicants m
        WHERE m.citizen_id = ?
    ";
    $params = [$citizenId];

    if ($hasRecruitmentTypeColumn) {
        $sql .= " AND COALESCE(NULLIF(m.recruitment_type, ''), 'medical_candidate') = ?";
        $params[] = $recruitmentType;
    }

    $sql .= " ORDER BY m.id DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function recruitmentRedirectToExistingApplicant(array $existingApplicant, string $recruitmentType): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $applicantId = (int)($existingApplicant['id'] ?? 0);
    if ($applicantId <= 0) {
        header('Location: ' . ems_url('/public/recruitment_done.php'));
        exit;
    }

    $_SESSION['recruitment_track_map'] = $_SESSION['recruitment_track_map'] ?? [];
    $_SESSION['recruitment_track_map'][(string)$applicantId] = $recruitmentType;

    $status = trim((string)($existingApplicant['status'] ?? ''));
    $hasAiResult = (int)($existingApplicant['has_ai_result'] ?? 0) === 1;

    if ($status === 'ai_test' && !$hasAiResult) {
        ems_public_recruitment_gate_set([
            'citizen_id' => ems_normalize_citizen_id($existingApplicant['citizen_id'] ?? $_POST['citizen_id'] ?? ''),
            'applicant_id' => $applicantId,
            'recruitment_type' => $recruitmentType,
            'stage' => 'ai_test',
            'updated_at' => time(),
        ]);
        header('Location: ' . ems_url('/public/ai_test.php?applicant_id=' . $applicantId . '&track=' . urlencode($recruitmentType)));
        exit;
    }

    ems_public_recruitment_gate_set([
        'citizen_id' => ems_normalize_citizen_id($existingApplicant['citizen_id'] ?? $_POST['citizen_id'] ?? ''),
        'applicant_id' => $applicantId,
        'recruitment_type' => $recruitmentType,
        'stage' => 'done',
        'updated_at' => time(),
    ]);
    header('Location: ' . ems_url('/public/recruitment_done.php'));
    exit;
}

/* ===============================
   VALIDASI REQUEST
   =============================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

$gate = ems_public_recruitment_require_gate_stage('form');

if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    exit('Ukuran request terlalu besar atau upload gagal diproses server. Batas upload adalah ' . emsUploadLimitLabel() . '.');
}

$requiredFields = [
    'ic_name' => 'Nama IC',
    'citizen_id' => 'Citizen ID',
    'ooc_age' => 'Umur OOC',
    'jenis_kelamin' => 'Jenis Kelamin',
    'ic_phone' => 'Nomor Telepon IC',
    'medical_experience' => 'Pengalaman Medis',
    'city_duration' => 'Lama di Kota IME',
    'online_schedule' => 'Jam Biasanya Online',
    'other_city_responsibility' => 'Tanggung Jawab di Kota Lain',
    'academy_ready' => 'Bersedia Mengikuti Medical Academy',
    'rule_commitment' => 'Siap Mengikuti Aturan dan Etika',
    'duty_duration' => 'Perkiraan Waktu Duty',
    'motivation' => 'Alasan Bergabung',
    'work_principle' => 'Prinsip Kerja',
];
recruitmentRequirePostedFields($requiredFields);

/* ===============================
   PREP DATA
   =============================== */
$icName  = trim($_POST['ic_name']);
$icPhone = trim($_POST['ic_phone']);
$citizenId = ems_normalize_citizen_id($_POST['citizen_id'] ?? '');
$jenisKelamin = trim($_POST['jenis_kelamin'] ?? '');

if ($citizenId === '' || $jenisKelamin === '') {
    http_response_code(400);
    exit;
}

if ($citizenId !== (string)($gate['citizen_id'] ?? '')) {
    http_response_code(403);
    exit('Citizen ID tidak sesuai dengan sesi verifikasi.');
}

if (!in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
    http_response_code(400);
    exit;
}

if ((int)($_POST['ooc_age'] ?? 0) <= 0) {
    http_response_code(400);
    exit('Umur OOC tidak valid.');
}

$recruitmentType = ems_normalize_recruitment_type($_POST['recruitment_type'] ?? '');
$targetDivision = ems_normalize_division($_POST['target_division'] ?? '');
$targetRole = $recruitmentType === 'assistant_manager' ? 'Probation Manager' : null;
$verifiedUserId = (int)($_POST['verified_user_id'] ?? 0);

$longTextRules = [
    'medical_experience' => 80,
    'other_city_responsibility' => 30,
    'motivation' => 120,
    'work_principle' => 120,
];

$isAssistantManager = $recruitmentType === 'assistant_manager';
$isTemporaryBypassCitizen = $isAssistantManager && strtoupper($citizenId) === EMS_ASSISTANT_MANAGER_DOC_BYPASS_CITIZEN_ID;
if ($isAssistantManager) {
    if (!$isTemporaryBypassCitizen) {
        foreach ($longTextRules as $field => $minimum) {
            $value = trim((string)($_POST[$field] ?? ''));
            if (mb_strlen($value) < $minimum) {
                http_response_code(400);
                exit("Field {$field} minimal {$minimum} karakter");
            }
        }
    }
}

$verifiedUser = null;
if ($isAssistantManager) {
    $verifiedUser = recruitmentFetchVerifiedUser($pdo, $citizenId, $verifiedUserId);
    if (!$verifiedUser) {
        http_response_code(400);
        exit('Citizen ID belum terverifikasi dari akun EMS');
    }

    if (!$isTemporaryBypassCitizen) {
        foreach (['file_ktp' => 'KTP', 'file_skb' => 'SKB', 'file_kta' => 'KTA', 'file_sim' => 'SIM'] as $column => $label) {
            if (trim((string)($verifiedUser[$column] ?? '')) === '') {
                http_response_code(400);
                exit("Dokumen {$label} belum tersedia di akun EMS");
            }
        }
    } else {
        foreach (['file_ktp', 'file_skb', 'file_kta', 'file_sim'] as $column) {
            if (trim((string)($verifiedUser[$column] ?? '')) === '') {
                $verifiedUser[$column] = 'temporary-bypass://' . strtolower(substr($column, 5));
            }
        }
    }

    $icName = trim((string)($verifiedUser['full_name'] ?? ''));
    $icPhone = trim((string)($verifiedUser['no_hp_ic'] ?? ''));
    $jenisKelamin = trim((string)($verifiedUser['jenis_kelamin'] ?? ''));

    if ($icName === '' || $icPhone === '' || $jenisKelamin === '') {
        http_response_code(400);
        exit('Data identitas akun EMS belum lengkap');
    }
}

$existingApplicant = recruitmentFindExistingApplicant($pdo, $citizenId, $recruitmentType);
if ($existingApplicant) {
    recruitmentRedirectToExistingApplicant($existingApplicant, $recruitmentType);
}

$folderName = slugName($icName) . '_' . $icPhone;

$pdo->beginTransaction();

try {

    /* ===============================
       INSERT PELAMAR
       =============================== */
    $storedCityDuration = $_POST['city_duration'] ?? null;
    $storedOnlineSchedule = $_POST['online_schedule'] ?? null;
    $storedDutyDuration = $_POST['duty_duration'] ?? null;

    if ($isAssistantManager && $verifiedUser) {
        $onlineSummary = recruitmentFormatAverageScheduleForStorage($pdo, (int)$verifiedUser['id']);
        $storedCityDuration = recruitmentFormatJoinDurationForStorage($verifiedUser['tanggal_masuk'] ?? null);
        $storedOnlineSchedule = $onlineSummary['online_schedule'];
        $storedDutyDuration = $onlineSummary['duty_duration'];
    }

    $insertColumns = [
        'ic_name',
        'citizen_id',
        'jenis_kelamin',
        'ooc_age',
        'ic_phone',
        'medical_experience',
        'city_duration',
        'online_schedule',
        'other_city_responsibility',
        'motivation',
        'work_principle',
        'academy_ready',
        'rule_commitment',
        'duty_duration',
        'status',
    ];
    $insertValues = [
        $icName,
        $citizenId,
        $jenisKelamin,
        $_POST['ooc_age'],
        $icPhone,
        $_POST['medical_experience'] ?? null,
        $storedCityDuration,
        $storedOnlineSchedule,
        $_POST['other_city_responsibility'] ?? null,
        $_POST['motivation'] ?? null,
        $_POST['work_principle'] ?? null,
        $_POST['academy_ready'],
        $_POST['rule_commitment'],
        $storedDutyDuration,
        'ai_test',
    ];

    if (ems_column_exists($pdo, 'medical_applicants', 'recruitment_type')) {
        $insertColumns[] = 'recruitment_type';
        $insertValues[] = $recruitmentType;
    }

    if (ems_column_exists($pdo, 'medical_applicants', 'target_role')) {
        $insertColumns[] = 'target_role';
        $insertValues[] = $targetRole;
    }

    if (ems_column_exists($pdo, 'medical_applicants', 'target_division')) {
        $insertColumns[] = 'target_division';
        $insertValues[] = $targetDivision !== '' ? $targetDivision : ($recruitmentType === 'assistant_manager' ? 'General Affair' : null);
    }

    $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));
    $stmt = $pdo->prepare("
        INSERT INTO medical_applicants (" . implode(', ', $insertColumns) . ")
        VALUES ({$placeholders})
    ");

    $stmt->execute($insertValues);

    $applicantId = $pdo->lastInsertId();

    /* ===============================
       DOKUMEN VERIFIKASI DARI AKUN EMS
       =============================== */
    if ($isAssistantManager && $verifiedUser) {
        foreach ([
            'ktp_ic' => (string)$verifiedUser['file_ktp'],
            'skb' => (string)$verifiedUser['file_skb'],
            'kta' => (string)$verifiedUser['file_kta'],
            'sim' => (string)$verifiedUser['file_sim'],
        ] as $doc => $path) {
            recruitmentInsertApplicantDocument($pdo, (int)$applicantId, $doc, $path);
        }
    } else {
        /* ===============================
           UPLOAD FILE
           =============================== */
        $baseDir = __DIR__ . '/../storage/applicants/';
        $uploadDir = $baseDir . $folderName;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new Exception('Gagal membuat folder upload');
        }

        foreach (['ktp_ic', 'skb', 'sim', 'surat_keterangan_sehat', 'surat_keterangan_psikolog'] as $doc) {
            $existingDocPath = recruitmentResolveExistingUserDoc(
                $pdo,
                $citizenId,
                $doc,
                trim((string)($_POST['existing_doc_' . $doc] ?? ''))
            );

            if ($existingDocPath !== null) {
                recruitmentInsertApplicantDocument($pdo, (int)$applicantId, $doc, $existingDocPath);
                continue;
            }

            if ($doc === 'sim' && empty($_FILES[$doc]['tmp_name'])) {
                continue;
            }

            if (
                !isset($_FILES[$doc]) ||
                $_FILES[$doc]['error'] !== UPLOAD_ERR_OK ||
                !is_uploaded_file($_FILES[$doc]['tmp_name'])
            ) {
                throw new Exception(recruitmentUploadErrorMessage((int)($_FILES[$doc]['error'] ?? UPLOAD_ERR_NO_FILE), $doc));
            }

            $finalPath = recruitmentStoreApplicantImage($_FILES[$doc], $uploadDir, $doc);

            recruitmentInsertApplicantDocument(
                $pdo,
                (int)$applicantId,
                $doc,
                'storage/applicants/' . $folderName . '/' . basename($finalPath)
            );
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents(
        __DIR__ . '/../storage/recruitment_error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    http_response_code(500);
    exit($e->getMessage());
}

$_SESSION['recruitment_track_map'] = $_SESSION['recruitment_track_map'] ?? [];
$_SESSION['recruitment_track_map'][(string)$applicantId] = $recruitmentType;
ems_public_recruitment_gate_set([
    'citizen_id' => $citizenId,
    'applicant_id' => (int)$applicantId,
    'recruitment_type' => $recruitmentType,
    'stage' => 'ai_test',
    'updated_at' => time(),
]);
header('Location: ' . ems_url('/public/ai_test.php?applicant_id=' . $applicantId . '&track=' . urlencode($recruitmentType)));
exit;
