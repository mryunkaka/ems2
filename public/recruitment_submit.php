<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';

const EMS_ASSISTANT_MANAGER_DOC_BYPASS_CITIZEN_ID = 'RH39IQLC';

/* ===============================
   FUNGSI
   =============================== */
function compressJpegSmart(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1200,
    int $targetSize = 300000,
    int $minQuality = 70
): bool {
    $src = imagecreatefromstring(file_get_contents($sourcePath));
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);

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

    imageinterlace($dst, true);

    for ($q = 90; $q >= $minQuality; $q -= 5) {
        imagejpeg($dst, $targetPath, $q);
        if (filesize($targetPath) <= $targetSize) {
            imagedestroy($dst);
            return true;
        }
    }

    imagejpeg($dst, $targetPath, $minQuality);
    imagedestroy($dst);
    return true;
}

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

    $cache = ['ktp_ic', 'skb', 'sim'];
    return $cache;
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

/* ===============================
   VALIDASI REQUEST
   =============================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

$required = ['ic_name', 'ic_phone', 'ooc_age', 'academy_ready', 'rule_commitment'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        http_response_code(400);
        exit;
    }
}

/* ===============================
   PREP DATA
   =============================== */
$icName  = trim($_POST['ic_name']);
$icPhone = trim($_POST['ic_phone']);
$citizenId = trim($_POST['citizen_id'] ?? '');
$jenisKelamin = trim($_POST['jenis_kelamin'] ?? '');

if ($citizenId === '' || $jenisKelamin === '') {
    http_response_code(400);
    exit;
}

if (!in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
    http_response_code(400);
    exit;
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

        foreach (['ktp_ic', 'skb', 'sim'] as $doc) {
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
                throw new Exception("Upload {$doc} gagal");
            }

            $tmp = $_FILES[$doc]['tmp_name'];
            $imgInfo = getimagesize($tmp);
            if ($imgInfo === false || $imgInfo['mime'] !== 'image/jpeg') {
                throw new Exception("File {$doc} bukan JPG valid");
            }

            if (!function_exists('imagejpeg')) {
                throw new Exception('PHP GD extension tidak aktif');
            }

            $finalPath = $uploadDir . '/' . $doc . '.jpg';

            if (!compressJpegSmart($tmp, $finalPath)) {
                throw new Exception("Gagal memproses {$doc}");
            }

            recruitmentInsertApplicantDocument(
                $pdo,
                (int)$applicantId,
                $doc,
                'storage/applicants/' . $folderName . '/' . $doc . '.jpg'
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
header('Location: ai_test.php?applicant_id=' . $applicantId . '&track=' . urlencode($recruitmentType));
exit;
