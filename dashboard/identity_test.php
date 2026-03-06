<?php
session_start();

/* ===============================
   CONFIG
   =============================== */
define('OCR_API_KEY', 'K85527757488957');
define('UPLOAD_DIR', __DIR__ . '/../storage/identity/');
define('MAX_WIDTH', 1200);
define('TARGET_FILE_SIZE', 300 * 1024); // Target 300KB
define('MIN_QUALITY', 70); // Kualitas minimum

/* ===============================
   DB & AUTH
   =============================== */
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

/* ===============================
   FUNGSI KOMPRESI
   =============================== */
function compressImage($image, $targetPath, $targetSize, $minQuality = 70)
{
    $quality = 90;
    $step = 5;

    while ($quality >= $minQuality) {
        imagejpeg($image, $targetPath, $quality);
        $fileSize = filesize($targetPath);

        if ($fileSize <= $targetSize) {
            return ['success' => true, 'quality' => $quality, 'size' => $fileSize];
        }

        $quality -= $step;
    }

    return ['success' => false, 'quality' => $minQuality, 'size' => filesize($targetPath)];
}

/* ===============================
   FUNGSI CEK DATA SAMA
   =============================== */
function isDataSame($master, $newData)
{
    return (
        $master['first_name'] === $newData['first_name'] &&
        $master['last_name'] === $newData['last_name'] &&
        $master['dob'] === $newData['dob'] &&
        $master['sex'] === $newData['sex'] &&
        $master['nationality'] === $newData['nationality']
    );
}

/* ===============================
   FUNGSI GET NEXT VERSION
   =============================== */
function getNextVersion($citizenId, $pdo)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM identity_versions 
        WHERE citizen_id = ?
    ");
    $stmt->execute([$citizenId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($result['total'] ?? 0) + 1;
}

/* ===============================
   RESPONSE AJAX OCR
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ocr_ajax') {

    header('Content-Type: application/json');

    if (empty($_FILES['image']['tmp_name'])) {
        echo json_encode(['error' => 'File tidak ditemukan']);
        exit;
    }

    $tmp = $_FILES['image']['tmp_name'];

    if (!is_uploaded_file($tmp)) {
        echo json_encode([
            'error' => 'Temporary upload file tidak valid (kamera HP diblok hosting)'
        ]);
        exit;
    }

    $mime = mime_content_type($tmp);
    $allowed = ['image/jpeg', 'image/png'];

    if (!in_array($mime, $allowed, true)) {
        echo json_encode([
            'error' => 'Format gambar tidak didukung (hanya JPG/PNG)'
        ]);
        exit;
    }

    /* ===============================
       COMPRESS IMAGE
       =============================== */
    $src = imagecreatefromstring(file_get_contents($_FILES['image']['tmp_name']));
    if (!$src) {
        echo json_encode(['error' => 'Gambar tidak valid']);
        exit;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    if ($w > MAX_WIDTH) {
        $ratio = MAX_WIDTH / $w;
        $nw = MAX_WIDTH;
        $nh = (int)($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
    } else {
        $dst = $src;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }

    $tmpFile = UPLOAD_DIR . 'tmp_' . uniqid() . '.jpg';

    imageinterlace($dst, true);
    $compress = compressImage($dst, $tmpFile, TARGET_FILE_SIZE, MIN_QUALITY);

    imagedestroy($dst);

    /* ===============================
       OCR API CALL
       =============================== */
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.ocr.space/parse/image',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'apikey' => OCR_API_KEY,
            'language' => 'eng',
            'OCREngine' => '2',
            'scale' => 'true',
            'isTable' => 'true',
            'detectOrientation' => 'true',
            'isOverlayRequired' => 'false',
            'file' => new CURLFile($tmpFile)
        ]
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);

    if ($httpCode !== 200 || !isset($json['ParsedResults'])) {
        echo json_encode([
            'error' => 'OCR API error',
            'debug' => $json
        ]);
        exit;
    }

    $parsed = $json['ParsedResults'][0] ?? [];

    if (isset($parsed['ErrorMessage']) && $parsed['ErrorMessage']) {
        echo json_encode([
            'error' => $parsed['ErrorMessage'],
            'debug' => $json
        ]);
        exit;
    }

    $text = trim($parsed['ParsedText'] ?? '');

    if ($text === '') {
        @unlink($tmpFile);
        echo json_encode(['error' => 'OCR tidak menghasilkan teks']);
        exit;
    }

    if ($text === '' && !empty($parsed['TextOverlay']['Lines'])) {
        $lines = [];
        foreach ($parsed['TextOverlay']['Lines'] as $line) {
            $lineText = [];
            foreach ($line['Words'] as $word) {
                $lineText[] = $word['WordText'];
            }
            $lines[] = implode(' ', $lineText);
        }
        $text = implode("\n", $lines);
    }

    if ($text === '') {
        echo json_encode([
            'error' => 'OCR tidak menghasilkan teks',
            'debug' => $json
        ]);
        exit;
    }

    /* ===============================
       PARSING
       =============================== */
    function extractField($text, $patterns)
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }

    $textUpper = strtoupper($text);

    $data = [
        'first_name' => extractField($textUpper, [
            '/FIRST\s*NAME[:\s]+([A-Z]+)/i',
            '/FIRSTNAME[:\s]+([A-Z]+)/i',
            '/FIRST[:\s]+([A-Z]+)/i'
        ]),
        'last_name' => extractField($textUpper, [
            '/LAST\s*NAME[:\s]+([A-Z]+)/i',
            '/LASTNAME[:\s]+([A-Z]+)/i',
            '/LAST[:\s]+([A-Z]+)/i',
            '/SURNAME[:\s]+([A-Z]+)/i'
        ]),
        'dob' => extractField($text, [
            '/DOB[:\s]+([\d\-\/]+)/i',
            '/DATE\s*OF\s*BIRTH[:\s]+([\d\-\/]+)/i',
            '/BIRTH[:\s]+([\d\-\/]+)/i',
            '/(\d{4}-\d{2}-\d{2})/',
            '/(\d{2}[-\/]\d{2}[-\/]\d{4})/',
        ]),
        'sex' => extractField($textUpper, [
            '/SEX[:\s]+([MF])/i',
            '/GENDER[:\s]+([MF])/i',
            '/\b(MALE|FEMALE)\b/i'
        ]),
        'nationality' => extractField($textUpper, [
            '/NATIONALITY[:\s]+([A-Z]+)/i',
            '/CITIZEN[:\s]+([A-Z]+)/i'
        ]),
        'citizen_id' => extractField($text, [
            '/CITIZEN\s*ID[:\s]+([A-Z0-9]+)/i',
            '/ID[:\s]+([A-Z0-9]{8,18})/i',
            '/\b([A-Z]{1,3}\d{5,}[A-Z0-9]*)\b/',
            '/\b([Y]\d[A-Z0-9]{6,})\b/',
        ])
    ];

    if ($data['sex']) {
        $data['sex'] = strtoupper(substr($data['sex'], 0, 1));
        if ($data['sex'] !== 'M' && $data['sex'] !== 'F') {
            $data['sex'] = '';
        }
    }

    if ($data['dob'] && strpos($data['dob'], '/') !== false) {
        $parts = explode('/', $data['dob']);
        if (count($parts) === 3) {
            $data['dob'] = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
    }

    // ===============================
    // HANYA RETURN DATA, TIDAK SAVE
    // ===============================
    $data['temp_file'] = $tmpFile; // Simpan path temporary untuk nanti
    $data['compressed_size'] = round(filesize($tmpFile) / 1024, 2) . ' KB';

    echo json_encode($data);
    exit;
}
/* ===============================
   SAVE BASE64 IMAGE (MANUAL INPUT)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_base64') {

    header('Content-Type: application/json');

    if (empty($_FILES['image']['tmp_name'])) {
        echo json_encode(['error' => 'File tidak ditemukan']);
        exit;
    }

    $tmp = $_FILES['image']['tmp_name'];

    if (!is_uploaded_file($tmp)) {
        echo json_encode(['error' => 'Upload file tidak valid']);
        exit;
    }

    $mime = mime_content_type($tmp);
    $allowed = ['image/jpeg', 'image/png'];

    if (!in_array($mime, $allowed, true)) {
        echo json_encode(['error' => 'Format gambar tidak didukung (hanya JPG/PNG)']);
        exit;
    }

    // Compress image
    $src = imagecreatefromstring(file_get_contents($tmp));
    if (!$src) {
        echo json_encode(['error' => 'Gambar tidak valid']);
        exit;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    if ($w > MAX_WIDTH) {
        $ratio = MAX_WIDTH / $w;
        $nw = MAX_WIDTH;
        $nh = (int)($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
    } else {
        $dst = $src;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }

    $tmpFile = UPLOAD_DIR . 'tmp_manual_' . uniqid() . '.jpg';

    imageinterlace($dst, true);
    $compress = compressImage($dst, $tmpFile, TARGET_FILE_SIZE, MIN_QUALITY);

    imagedestroy($dst);

    echo json_encode([
        'success' => true,
        'temp_file' => $tmpFile,
        'compressed_size' => round(filesize($tmpFile) / 1024, 2) . ' KB'
    ]);
    exit;
}
/* ===============================
   SAVE DATA SETELAH KONFIRMASI
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_identity') {

    header('Content-Type: application/json');

    // Ambil data dari POST
    $data = [
        'citizen_id' => trim($_POST['citizen_id'] ?? ''),
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'dob' => trim($_POST['dob'] ?? ''),
        'sex' => trim($_POST['sex'] ?? ''),
        'nationality' => trim($_POST['nationality'] ?? ''),
        'change_reason' => trim($_POST['change_reason'] ?? ''),
        'temp_file' => trim($_POST['temp_file'] ?? '')
    ];

    if (empty($data['citizen_id'])) {
        echo json_encode(['error' => 'Citizen ID tidak boleh kosong']);
        exit;
    }

    if (empty($data['temp_file']) || !file_exists($data['temp_file'])) {
        echo json_encode(['error' => 'File temporary tidak ditemukan, silakan upload ulang']);
        exit;
    }

    $pdo->beginTransaction();

    try {
        // ===============================
        // 1. Cek identity_master
        // ===============================
        $stmt = $pdo->prepare("
            SELECT id, citizen_id, first_name, last_name, dob, sex, 
                   nationality, image_path, active_version_id 
            FROM identity_master 
            WHERE citizen_id = ?
        ");
        $stmt->execute([$data['citizen_id']]);
        $master = $stmt->fetch(PDO::FETCH_ASSOC);

        // ===============================
        // 2. CEK APAKAH DATA SAMA
        // ===============================
        $dataSame = false;
        $existingImagePath = null;

        if ($master && isDataSame($master, $data)) {
            $dataSame = true;
            $existingImagePath = $master['image_path'];
        }

        // ===============================
        // 3. TENTUKAN FILE PATH
        // ===============================
        $citizenFolder = UPLOAD_DIR . $data['citizen_id'] . '/';

        if (!is_dir($citizenFolder)) {
            mkdir($citizenFolder, 0777, true);
        }

        if ($dataSame) {
            // Data sama, gunakan foto yang sudah ada
            $finalRelativePath = $existingImagePath;
            $finalPath = __DIR__ . '/../' . $existingImagePath;

            // Hapus file temporary
            @unlink($data['temp_file']);

            $identityId = $master['id'];
            $versionId = $master['active_version_id'];
        } else {
            // Data berbeda, buat versi baru
            $versionNumber = getNextVersion($data['citizen_id'], $pdo);
            $versionFilename = 'v' . $versionNumber . '.jpg';
            $finalPath = $citizenFolder . $versionFilename;
            $finalRelativePath = 'storage/identity/' . $data['citizen_id'] . '/' . $versionFilename;

            // Copy dan hapus temp
            copy($data['temp_file'], $finalPath);
            @unlink($data['temp_file']);

            // ===============================
            // 4. INSERT/UPDATE identity_master
            // ===============================
            if ($master) {
                $identityId = $master['id'];
                $stmt = $pdo->prepare("
                    UPDATE identity_master
                    SET first_name = ?,
                        last_name = ?,
                        dob = ?,
                        sex = ?,
                        nationality = ?,
                        image_path = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['first_name'],
                    $data['last_name'],
                    $data['dob'],
                    $data['sex'],
                    $data['nationality'],
                    $finalRelativePath,
                    $identityId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO identity_master 
                    (citizen_id, first_name, last_name, dob, sex, nationality, image_path)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['citizen_id'],
                    $data['first_name'],
                    $data['last_name'],
                    $data['dob'],
                    $data['sex'],
                    $data['nationality'],
                    $finalRelativePath
                ]);
                $identityId = $pdo->lastInsertId();
            }

            // ===============================
            // 5. INSERT identity_versions
            // ===============================
            $userId = $_SESSION['user_id'] ?? null;

            // Gunakan change_reason dari input user
            if (empty($data['change_reason'])) {
                $changeReason = $master ? 'Data updated via OCR (v' . $versionNumber . ')' : 'Initial OCR scan (v1)';
            } else {
                $changeReason = $data['change_reason'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO identity_versions
                (identity_id, citizen_id, first_name, last_name, dob, sex, nationality, image_path, change_reason, changed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $identityId,
                $data['citizen_id'],
                $data['first_name'],
                $data['last_name'],
                $data['dob'],
                $data['sex'],
                $data['nationality'],
                $finalRelativePath,
                $changeReason,
                $userId
            ]);

            $versionId = $pdo->lastInsertId();

            // ===============================
            // 6. Update active_version_id
            // ===============================
            $stmt = $pdo->prepare("
                UPDATE identity_master
                SET active_version_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$versionId, $identityId]);
        }

        $pdo->commit();

        // ===============================
        // 7. Response
        // ===============================
        echo json_encode([
            'success' => true,
            'message' => $dataSame ? 'Data sama dengan sebelumnya' : 'Data berhasil disimpan',
            'image_path' => $finalRelativePath,
            'data_same' => $dataSame,
            'new_version' => !$dataSame,
            'identity_id' => $identityId,
            'version_id' => $versionId
        ]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        @unlink($data['temp_file']);
        echo json_encode([
            'error' => 'Gagal menyimpan identitas',
            'detail' => $e->getMessage()
        ]);
        exit;
    }
}
/* ===============================
   CHECK IDENTITY EXISTENCE
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_identity') {

    header('Content-Type: application/json');

    $citizenId = trim($_POST['citizen_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');

    if (empty($citizenId)) {
        echo json_encode(['error' => 'Citizen ID tidak boleh kosong']);
        exit;
    }

    try {
        // Cek di database
        $stmt = $pdo->prepare("
            SELECT id, citizen_id, first_name, last_name, dob, sex, 
                   nationality, image_path, active_version_id 
            FROM identity_master 
            WHERE citizen_id = ?
        ");
        $stmt->execute([$citizenId]);
        $master = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$master) {
            // Data tidak ada di database, perlu save
            echo json_encode([
                'exists' => false,
                'message' => 'Data baru, silakan simpan'
            ]);
            exit;
        }

        // Data ada, cek apakah nama sama
        $nameChanged = (
            $master['first_name'] !== $firstName ||
            $master['last_name'] !== $lastName
        );

        if ($nameChanged) {
            // Nama berubah, perlu konfirmasi
            echo json_encode([
                'exists' => true,
                'name_changed' => true,
                'message' => 'Citizen ID sama tetapi nama berbeda',
                'old_data' => [
                    'first_name' => $master['first_name'],
                    'last_name' => $master['last_name'],
                    'dob' => $master['dob'],
                    'sex' => $master['sex'],
                    'nationality' => $master['nationality']
                ],
                'new_data' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName
                ]
            ]);
            exit;
        }

        // Data sama persis, auto close
        echo json_encode([
            'exists' => true,
            'name_changed' => false,
            'auto_close' => true,
            'message' => 'Data sudah ada dan sama persis',
            'identity_id' => $master['id'],
            'data' => $master
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'error' => 'Gagal mengecek database',
            'detail' => $e->getMessage()
        ]);
        exit;
    }
}

/* ===============================
   DELETE TEMP FILE (AUTO CLEAN)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_temp') {

    header('Content-Type: application/json');

    $tempFile = trim($_POST['temp_file'] ?? '');

    if (!$tempFile) {
        echo json_encode(['error' => 'Temp file kosong']);
        exit;
    }

    // SECURITY: pastikan hanya folder identity
    $realBase = realpath(UPLOAD_DIR);
    $realFile = realpath($tempFile);

    if ($realFile && strpos($realFile, $realBase) === 0 && file_exists($realFile)) {
        unlink($realFile);
        echo json_encode(['success' => true, 'message' => 'Temp file dihapus']);
    } else {
        echo json_encode(['error' => 'File tidak valid atau sudah terhapus']);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Identity OCR Scanner</title>
    <style>
        /* ===== SMOOTH SCROLL ===== */
        html {
            scroll-behavior: smooth;
        }

        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-overflow-scrolling: touch;
        }

        html,
        body {
            overflow-x: hidden;
            width: 100%;
            height: 100%;
        }

        /* ===== BODY - SCROLLABLE CONTAINER ===== */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #0c4a6e 0%, #075985 50%, #0369a1 100%);
            min-height: 100vh;
            padding: 20px 16px;
            color: #0f172a;
            margin: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* ===== CONTAINER ===== */
        .ocr-container {
            width: 100%;
            max-width: 580px;
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
            margin: 0 auto 20px auto;
            /* 👈 PENTING: margin bottom untuk spacing */
            position: relative;
        }

        /* ===== HEADER ===== */
        .ocr-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .ocr-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #0ea5e9, #06b6d4);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 12px;
            box-shadow: 0 10px 30px rgba(14, 165, 233, 0.35);
        }

        .ocr-title {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .ocr-subtitle {
            font-size: 14px;
            color: #64748b;
            font-weight: 400;
        }

        /* ===== UPLOAD AREA ===== */
        .upload-area {
            position: relative;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid #0ea5e9;
            border-radius: 12px;
            padding: 0;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 20px;
            display: block;
        }

        .upload-area:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.2);
            border-color: #0284c7;
        }

        .upload-area.active {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #0284c7;
        }

        .upload-content {
            padding: 24px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .upload-icon-wrapper {
            display: none;
        }

        .upload-icon {
            font-size: 40px;
            margin-bottom: 6px;
        }

        .upload-text-wrapper {
            text-align: center;
        }

        .upload-text {
            font-size: 15px;
            font-weight: 600;
            color: #0369a1;
            margin-bottom: 4px;
            display: block;
        }

        .upload-hint {
            font-size: 12px;
            color: #0284c7;
            font-weight: 500;
        }

        .upload-button {
            margin-top: 4px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #0ea5e9, #06b6d4);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 3px 10px rgba(14, 165, 233, 0.25);
        }

        .upload-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(14, 165, 233, 0.35);
        }

        #img {
            position: absolute;
            width: 0;
            height: 0;
            opacity: 0;
            pointer-events: none;
        }

        /* ===== LOADING ===== */
        .loading-area {
            display: none;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-left: 4px solid #f59e0b;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }

        .loading-area.show {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .loading-spinner {
            width: 22px;
            height: 22px;
            border: 3px solid #f59e0b;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            flex-shrink: 0;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .loading-text {
            font-size: 14px;
            font-weight: 600;
            color: #92400e;
        }

        /* ===== ERROR ALERT ===== */
        .error-area {
            display: none;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-left: 4px solid #ef4444;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }

        .error-area.show {
            display: block;
        }

        .error-title {
            font-size: 14px;
            font-weight: 700;
            color: #991b1b;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error-message {
            font-size: 13px;
            color: #dc2626;
            line-height: 1.6;
        }

        /* ===== SUCCESS INFO ===== */
        .info-area {
            display: none;
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border-left: 4px solid #10b981;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }

        .info-area.show {
            display: block;
        }

        .info-title {
            font-size: 14px;
            font-weight: 700;
            color: #065f46;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-item {
            font-size: 13px;
            color: #047857;
            line-height: 1.6;
        }

        /* ===== PREVIEW IMAGE ===== */
        .preview-area {
            display: none;
            margin-bottom: 20px;
            text-align: center;
        }

        .preview-area.show {
            display: block;
        }

        .preview-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        #preview {
            max-width: 100%;
            max-height: 280px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border: 3px solid #e2e8f0;
        }

        /* ===== FORM FIELDS ===== */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px 14px;
            font-size: 14px;
            font-weight: 500;
            color: #0f172a;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #0ea5e9;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        /* ===== SAVE BUTTON ===== */
        .save-button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            margin-top: 20px;
        }

        .save-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
            background: linear-gradient(135deg, #059669, #047857);
        }

        .save-button:active {
            transform: translateY(0);
        }

        .save-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* ===== RESET BUTTON ===== */
        .reset-button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #64748b, #475569);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(100, 116, 139, 0.3);
            margin-top: 10px;
        }

        .reset-button:hover {
            transform: translateY(-1px);
            background: linear-gradient(135deg, #475569, #334155);
        }

        /* ===== CUSTOM REASON GROUP ===== */
        #customReasonGroup {
            margin-top: -6px;
        }

        /* ===== HIGHLIGHT CHANGED FIELDS ===== */
        .form-input.changed {
            border-color: #ef4444 !important;
            background: #fef2f2 !important;
        }

        .form-input.changed:focus {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 640px) {
            body {
                padding: 16px;
                align-items: flex-start;
            }

            .ocr-container {
                padding: 20px;
                margin: 10px auto;
                border-radius: 14px;
            }

            .ocr-icon {
                width: 56px;
                height: 56px;
                font-size: 28px;
            }

            .ocr-title {
                font-size: 20px;
            }

            .ocr-subtitle {
                font-size: 13px;
            }

            .upload-content {
                padding: 20px 16px;
                gap: 8px;
            }

            .upload-icon {
                font-size: 36px;
            }

            .upload-text {
                font-size: 14px;
            }

            .upload-button {
                padding: 9px 18px;
                font-size: 12px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            #preview {
                max-height: 240px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            .ocr-container {
                padding: 18px;
                margin: 8px auto;
            }

            .ocr-icon {
                width: 52px;
                height: 52px;
                font-size: 26px;
                margin-bottom: 10px;
            }

            .ocr-title {
                font-size: 18px;
            }

            .ocr-subtitle {
                font-size: 12px;
            }

            .upload-content {
                padding: 18px 14px;
            }

            .upload-icon {
                font-size: 32px;
            }

            .upload-text {
                font-size: 13px;
            }

            .upload-hint {
                font-size: 11px;
            }

            .upload-button {
                padding: 8px 16px;
                font-size: 12px;
            }

            .form-input {
                padding: 11px 12px;
                font-size: 13px;
            }

            .form-label {
                font-size: 12px;
            }

            .save-button {
                padding: 13px;
                font-size: 14px;
            }

            .reset-button {
                padding: 11px;
                font-size: 13px;
            }

            #preview {
                max-height: 220px;
            }
        }

        /* ===== EXTRA SMALL MOBILE ===== */
        @media (max-width: 360px) {
            body {
                padding: 10px;
            }

            .ocr-container {
                padding: 16px;
                margin: 6px auto;
            }

            .ocr-header {
                margin-bottom: 20px;
            }
        }

        /* ===== RESPONSIVE IFRAME MODE ===== */
        @media (max-height: 800px) {
            .ocr-container {
                padding: 16px;
                margin: 8px auto;
            }

            .ocr-header {
                margin-bottom: 16px;
            }

            .ocr-icon {
                width: 48px;
                height: 48px;
                font-size: 24px;
                margin-bottom: 8px;
            }

            .ocr-title {
                font-size: 18px;
            }

            #preview {
                max-height: 180px;
            }

            .form-group {
                margin-bottom: 12px;
                /* 👈 KURANGI SPACING */
            }

            .form-row {
                gap: 12px;
                margin-bottom: 12px;
            }
        }

        @media (max-height: 600px) {
            body {
                padding: 12px 10px;
            }

            .ocr-container {
                padding: 14px;
            }

            #preview {
                max-height: 140px;
            }

            .upload-content {
                padding: 16px 14px;
            }

            .form-input {
                padding: 10px 12px;
            }
        }

        @media (max-width: 640px) {
            #identityModal>div {
                margin: 0;
                border-radius: 0;
                max-height: 100vh;
            }

            #identityModal>div>iframe {
                height: calc(100vh - 60px);
            }
        }
    </style>
</head>

<body>
    <div class="ocr-container">
        <!-- Header -->
        <div class="ocr-header">
            <div class="ocr-icon">📷</div>
            <h1 class="ocr-title">Identity OCR Scanner</h1>
            <p class="ocr-subtitle">Scan your identity document automatically</p>
        </div>

        <!-- Upload Area -->
        <label for="img" class="upload-area" id="uploadArea">
            <div class="upload-content">
                <div class="upload-icon-wrapper">
                    <div class="upload-icon">📸</div>
                </div>
                <div class="upload-text-wrapper">
                    <span class="upload-text">Click to capture or upload</span>
                    <div class="upload-hint">Supports JPG, PNG • Max 5MB</div>
                </div>
                <button type="button" class="upload-button">Choose File / Take Photo</button>
            </div>
            <input
                type="file"
                id="img"
                accept="image/jpeg,image/png"
                capture="environment">

        </label>

        <!-- Loading -->
        <div class="loading-area" id="loading">
            <div class="loading-spinner"></div>
            <span class="loading-text">Scanning identity document...</span>
        </div>

        <!-- Error -->
        <div class="error-area" id="error">
            <div class="error-title">
                <span>❌</span>
                <span>Scan Failed</span>
            </div>
            <div class="error-message" id="errorMessage"></div>
        </div>

        <!-- Success Info -->
        <div class="info-area" id="info">
            <div class="info-title">
                <span>✅</span>
                <span>Scan Successful</span>
            </div>
            <div class="info-item" id="infoMessage"></div>
        </div>

        <!-- Preview -->
        <div class="preview-area" id="previewArea">
            <div class="preview-label">Scanned Image</div>
            <img id="preview" alt="Preview">
        </div>

        <!-- Form Results -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">First Name</label>
                <input type="text" name="first_name" class="form-input" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" name="last_name" class="form-input" readonly>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="text" name="dob" class="form-input" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Sex</label>
                <input type="text" name="sex" class="form-input" readonly>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Nationality</label>
            <input type="text" name="nationality" class="form-input" readonly>
        </div>

        <div class="form-group">
            <label class="form-label">Citizen ID</label>
            <input type="text" name="citizen_id" class="form-input" readonly>
        </div>

        <div class="form-group">
            <label class="form-label">Alasan</label>
            <select name="change_reason" class="form-input" id="changeReason">
                <option value="Daftar Baru" selected>Daftar Baru</option>
                <option value="Oplas">Oplas</option>
                <option value="Karena Keinginan Sendiri">Karena Keinginan Sendiri</option>
                <option value="Lainnya">Lainnya</option>
                <option value="custom">Custom (Isi Manual)</option>
            </select>
        </div>

        <div class="form-group" id="customReasonGroup" style="display: none;">
            <label class="form-label">Alasan Custom</label>
            <input type="text" name="custom_reason" class="form-input" id="customReasonInput" placeholder="Tulis alasan Anda...">
        </div>

        <!-- Hidden field untuk menyimpan temp_file path -->
        <input type="hidden" name="temp_file" id="tempFile">

        <!-- Tombol Simpan -->
        <button type="button" id="saveBtn" class="save-button" style="display: none;">
            💾 Simpan Data
        </button>

        <!-- Tombol Reset (Tambahan) -->
        <button type="button" id="resetBtn" class="reset-button" style="display: none;">
            🔄 Scan Ulang
        </button>
    </div>

    <script>
        // ========================================
        // LOCALSTORAGE FUNCTIONS
        // ========================================
        const STORAGE_KEY = 'ocr_temp_data';

        function saveToLocalStorage(imageData, ocrData = null) {
            try {
                const data = {
                    image: imageData,
                    ocr: ocrData,
                    timestamp: Date.now()
                };
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            } catch (e) {
                console.error('Failed to save to localStorage:', e);
            }
        }

        function loadFromLocalStorage() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return null;

                const data = JSON.parse(raw);
                // Hapus data jika lebih dari 1 jam
                if (Date.now() - data.timestamp > 3600000) {
                    localStorage.removeItem(STORAGE_KEY);
                    return null;
                }
                return data;
            } catch (e) {
                console.error('Failed to load from localStorage:', e);
                return null;
            }
        }

        function clearLocalStorage() {
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {
                console.error('Failed to clear localStorage:', e);
            }
        }

        const uploadArea = document.getElementById('uploadArea');
        const imgInput = document.getElementById('img');
        const loading = document.getElementById('loading');
        const error = document.getElementById('error');
        const errorMessage = document.getElementById('errorMessage');
        const info = document.getElementById('info');
        const infoMessage = document.getElementById('infoMessage');
        const previewArea = document.getElementById('previewArea');
        const preview = document.getElementById('preview');
        const saveBtn = document.getElementById('saveBtn');
        const changeReason = document.getElementById('changeReason');
        const customReasonGroup = document.getElementById('customReasonGroup');
        const customReasonInput = document.getElementById('customReasonInput');
        const tempFileInput = document.getElementById('tempFile');

        // Drag & Drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('active');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('active');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('active');
            if (e.dataTransfer.files.length) {
                imgInput.files = e.dataTransfer.files;
                handleUpload();
            }
        });

        // File Input Change
        imgInput.addEventListener('change', handleUpload);

        document.querySelector('.upload-button').addEventListener('click', (e) => {
            e.preventDefault();
            imgInput.click();
        });

        // Toggle custom reason input
        changeReason.addEventListener('change', function() {
            if (this.value === 'custom') {
                customReasonGroup.style.display = 'block';
                customReasonInput.required = true;
            } else {
                customReasonGroup.style.display = 'none';
                customReasonInput.required = false;
                customReasonInput.value = '';
            }
        });

        function handleUpload() {
            if (!imgInput.files.length) return;

            // SIMPAN FOTO KE LOCALSTORAGE
            const file = imgInput.files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
                const imageData = e.target.result;
                saveToLocalStorage(imageData);

                // Show preview immediately
                preview.src = imageData;
                previewArea.classList.add('show');

                // Lanjut OCR
                performOCR(file);
            };

            reader.readAsDataURL(file);
        }

        function showOCRError(errorMsg) {
            error.classList.add('show');
            errorMessage.innerHTML = `
        <strong>❌ OCR Gagal:</strong><br>
        ${errorMsg}<br><br>
        <button onclick="retryOCR()" style="
            padding: 8px 16px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-right: 8px;
        ">🔄 Scan Ulang (Foto Sama)</button>
        <button onclick="fillManually()" style="
            padding: 8px 16px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        ">✍️ Isi Manual</button>
    `;

            // Show reset button
            resetBtn.style.display = 'block';
        }

        function retryOCR() {
            const stored = loadFromLocalStorage();
            if (!stored || !stored.image) {
                alert('❌ Foto tidak ditemukan, silakan upload ulang');
                return;
            }

            // Convert base64 to blob
            fetch(stored.image)
                .then(res => res.blob())
                .then(blob => {
                    const file = new File([blob], 'retry.jpg', {
                        type: 'image/jpeg'
                    });
                    performOCR(file);
                })
                .catch(err => {
                    alert('❌ Gagal memuat foto: ' + err.message);
                });
        }

        function fillManually() {
            const stored = loadFromLocalStorage();

            if (stored && stored.image) {
                // Tampilkan foto yang bisa diperbesar
                preview.src = stored.image;
                preview.style.cursor = 'pointer';
                preview.onclick = function() {
                    const lightbox = document.createElement('div');
                    lightbox.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.9);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            `;

                    const img = document.createElement('img');
                    img.src = stored.image;
                    img.style.cssText = 'max-width: 95%; max-height: 95%; border-radius: 8px;';

                    lightbox.appendChild(img);
                    lightbox.onclick = () => document.body.removeChild(lightbox);

                    document.body.appendChild(lightbox);
                };

                // ========================================
                // UPLOAD BASE64 KE SERVER (DAPAT TEMP_FILE)
                // ========================================
                loading.classList.add('show');

                fetch(stored.image)
                    .then(res => res.blob())
                    .then(blob => {
                        const fd = new FormData();
                        fd.append('action', 'save_base64');
                        fd.append('image', blob, 'manual.jpg');

                        return fetch('', {
                            method: 'POST',
                            body: fd
                        });
                    })
                    .then(res => res.json())
                    .then(d => {
                        loading.classList.remove('show');

                        if (d.error) {
                            alert('❌ Gagal menyimpan foto: ' + d.error);
                            return;
                        }

                        // SET TEMP_FILE DARI SERVER
                        tempFileInput.value = d.temp_file;

                        // Make inputs editable
                        document.querySelectorAll('.form-input').forEach(input => {
                            if (input.name !== 'change_reason' && input.name !== 'custom_reason') {
                                input.removeAttribute('readonly');
                                input.style.background = '#fff7ed';
                                input.style.borderColor = '#f59e0b';
                            }
                        });

                        // Hide error, show info
                        error.classList.remove('show');
                        info.classList.add('show');
                        infoMessage.innerHTML = `
                    ⚠️ Silakan isi data secara manual berdasarkan foto KTP<br>
                    📷 Klik foto untuk memperbesar jika tidak jelas
                `;

                        // Show save button
                        saveBtn.style.display = 'block';
                        resetBtn.style.display = 'block';

                        // Set default reason
                        changeReason.value = 'Daftar Baru';
                    })
                    .catch(err => {
                        loading.classList.remove('show');
                        alert('❌ Gagal upload foto: ' + err.message);
                    });
            } else {
                alert('❌ Foto tidak ditemukan di localStorage');
            }
        }

        function performOCR(file) {
            const fd = new FormData();
            fd.append('action', 'ocr_ajax');
            fd.append('image', file);

            // Reset UI
            loading.classList.add('show');
            error.classList.remove('show');
            info.classList.remove('show');
            saveBtn.style.display = 'none';
            resetBtn.style.display = 'none';

            fetch('', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    loading.classList.remove('show');

                    if (d.error) {
                        // SIMPAN HASIL OCR ERROR KE LOCALSTORAGE
                        const stored = loadFromLocalStorage();
                        if (stored) {
                            saveToLocalStorage(stored.image, d);
                        }

                        showOCRError(d.error);
                        return;
                    }

                    // Fill form fields
                    document.querySelector('[name="first_name"]').value = d.first_name || '';
                    document.querySelector('[name="last_name"]').value = d.last_name || '';
                    document.querySelector('[name="dob"]').value = d.dob || '';
                    document.querySelector('[name="sex"]').value = d.sex || '';
                    document.querySelector('[name="nationality"]').value = d.nationality || '';
                    document.querySelector('[name="citizen_id"]').value = d.citizen_id || '';

                    // Simpan temp_file path
                    tempFileInput.value = d.temp_file || '';

                    // SIMPAN HASIL OCR KE LOCALSTORAGE
                    const stored = loadFromLocalStorage();
                    if (stored) {
                        saveToLocalStorage(stored.image, d);
                    }

                    // ========================================
                    // AUTO-CHECK DATABASE
                    // ========================================
                    if (d.citizen_id && d.first_name && d.last_name) {
                        checkIdentityInDatabase(d);
                    } else {
                        // Data tidak lengkap, tampilkan form
                        showEditForm('Data OCR tidak lengkap, silakan lengkapi dan simpan');
                    }
                })
                .catch(err => {
                    loading.classList.remove('show');

                    // SIMPAN ERROR KE LOCALSTORAGE
                    const stored = loadFromLocalStorage();
                    if (stored) {
                        saveToLocalStorage(stored.image, {
                            error: err.message
                        });
                    }

                    showOCRError('Network error: ' + err.message);
                });
        }

        // ========================================
        // FUNGSI CHECK DATABASE
        // ========================================
        function checkIdentityInDatabase(ocrData) {
            loading.classList.add('show');

            const fd = new FormData();
            fd.append('action', 'check_identity');
            fd.append('citizen_id', ocrData.citizen_id);
            fd.append('first_name', ocrData.first_name);
            fd.append('last_name', ocrData.last_name);

            fetch('', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    loading.classList.remove('show');

                    if (d.error) {
                        showEditForm('Error: ' + d.error);
                        return;
                    }

                    if (d.auto_close) {
                        // ========================================
                        // CASE 1: DATA SAMA PERSIS - AUTO CLOSE
                        // ========================================
                        info.classList.add('show');
                        infoMessage.innerHTML = `✅ ${d.message}<br>📦 Identity ID: ${d.identity_id}<br>🔒 Data sudah ada, tidak perlu upload ulang`;

                        // HAPUS TEMP FILE (PENTING)
                        deleteTempFile(tempFileInput.value);
                        tempFileInput.value = '';

                        // Make inputs readonly
                        document.querySelectorAll('.form-input').forEach(input => {
                            input.setAttribute('readonly', true);
                        });

                        // Send to parent and close after 2 seconds
                        if (window.parent) {
                            window.parent.postMessage({
                                identity_id: d.identity_id,
                                first_name: d.data.first_name,
                                last_name: d.data.last_name,
                                citizen_id: d.data.citizen_id,
                                auto_closed: true
                            }, '*');
                        }

                        alert('✅ Data sudah ada dan identik!\nIdentity ID: ' + d.identity_id);

                        setTimeout(() => {
                            if (window.parent && window.parent !== window) {
                                window.parent.postMessage({
                                    action: 'close_modal'
                                }, '*');
                            }
                        }, 1500);

                    } else if (d.name_changed) {
                        // ========================================
                        // CASE 2: NAMA BERUBAH - TAMPILKAN WARNING
                        // ========================================
                        error.classList.add('show');
                        errorMessage.innerHTML = `
                    <strong>⚠️ ${d.message}</strong><br><br>
                    <strong>Data Lama:</strong><br>
                    Nama: ${d.old_data.first_name} ${d.old_data.last_name}<br>
                    DOB: ${d.old_data.dob || '-'}<br>
                    Sex: ${d.old_data.sex || '-'}<br>
                    Nationality: ${d.old_data.nationality || '-'}<br><br>
                    <strong>Data Baru:</strong><br>
                    Nama: ${d.new_data.first_name} ${d.new_data.last_name}<br><br>
                    <strong style="color: #b91c1c;">Mohon koreksi data dan berikan alasan perubahan!</strong>
                `;

                        // Highlight nama yang berubah
                        document.querySelector('[name="first_name"]').style.borderColor = '#ef4444';
                        document.querySelector('[name="first_name"]').style.background = '#fef2f2';
                        document.querySelector('[name="last_name"]').style.borderColor = '#ef4444';
                        document.querySelector('[name="last_name"]').style.background = '#fef2f2';

                        showEditForm('Nama berubah, mohon verifikasi!');

                        // Set default reason ke "Lainnya"
                        changeReason.value = 'Lainnya';

                    } else {
                        // ========================================
                        // CASE 3: DATA BARU - TAMPILKAN FORM
                        // ========================================
                        showEditForm('Data baru, silakan koreksi jika perlu lalu simpan');
                    }
                })
                .catch(err => {
                    loading.classList.remove('show');
                    showEditForm('Error checking database: ' + err.message);
                });
        }

        // ========================================
        // FUNGSI SHOW EDIT FORM
        // ========================================
        function showEditForm(message) {
            info.classList.add('show');
            infoMessage.innerHTML = `📦 Compressed: ${document.querySelector('[name="citizen_id"]').value ? 'Ready' : 'N/A'}<br>⚠️ ${message}`;

            // Show buttons
            saveBtn.style.display = 'block';
            resetBtn.style.display = 'block';

            // Make inputs editable
            document.querySelectorAll('.form-input').forEach(input => {
                if (input.name !== 'change_reason' && input.name !== 'custom_reason') {
                    input.removeAttribute('readonly');
                }
            });
        }

        // Handle Save Button Click
        saveBtn.addEventListener('click', function() {
            // Validasi field wajib
            const citizenId = document.querySelector('[name="citizen_id"]').value.trim();
            const firstName = document.querySelector('[name="first_name"]').value.trim();
            const lastName = document.querySelector('[name="last_name"]').value.trim();

            if (!citizenId) {
                alert('❌ Citizen ID tidak boleh kosong');
                document.querySelector('[name="citizen_id"]').focus();
                return;
            }

            if (!firstName) {
                alert('❌ First Name tidak boleh kosong');
                document.querySelector('[name="first_name"]').focus();
                return;
            }

            if (!lastName) {
                alert('❌ Last Name tidak boleh kosong');
                document.querySelector('[name="last_name"]').focus();
                return;
            }

            const tempFile = tempFileInput.value;
            if (!tempFile) {
                alert('❌ File tidak ditemukan, silakan upload ulang');
                return;
            }

            // Ambil alasan
            let reason = changeReason.value;
            if (reason === 'custom') {
                reason = customReasonInput.value.trim();
                if (!reason) {
                    alert('❌ Alasan custom tidak boleh kosong');
                    customReasonInput.focus();
                    return;
                }
            }

            // Disable button
            saveBtn.disabled = true;
            saveBtn.textContent = '⏳ Menyimpan...';

            // Prepare data
            const fd = new FormData();
            fd.append('action', 'save_identity');
            fd.append('citizen_id', document.querySelector('[name="citizen_id"]').value);
            fd.append('first_name', document.querySelector('[name="first_name"]').value);
            fd.append('last_name', document.querySelector('[name="last_name"]').value);
            fd.append('dob', document.querySelector('[name="dob"]').value);
            fd.append('sex', document.querySelector('[name="sex"]').value);
            fd.append('nationality', document.querySelector('[name="nationality"]').value);
            fd.append('change_reason', reason);
            fd.append('temp_file', tempFile);

            fetch('', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (d.error) {
                        alert('❌ ' + d.error);
                        saveBtn.disabled = false;
                        saveBtn.textContent = '💾 Simpan Data';
                        return;
                    }

                    // Success
                    info.classList.add('show');
                    infoMessage.innerHTML = `✅ ${d.message}<br>📦 Identity ID: ${d.identity_id}<br>📝 Version ID: ${d.version_id}`;

                    // Update preview dengan gambar yang sudah tersimpan
                    if (d.image_path) {
                        // Pastikan path dimulai dengan /
                        const imagePath = d.image_path.startsWith('/') ? d.image_path : '/' + d.image_path;
                        preview.src = imagePath + '?t=' + Date.now();
                    }

                    // Send to parent window
                    if (window.parent) {
                        window.parent.postMessage({
                            identity_id: d.identity_id,
                            first_name: document.querySelector('[name="first_name"]').value,
                            last_name: document.querySelector('[name="last_name"]').value,
                            citizen_id: document.querySelector('[name="citizen_id"]').value
                        }, '*');
                    }

                    alert('✅ Data berhasil disimpan!');

                    // CLEAR LOCALSTORAGE
                    clearLocalStorage();

                    // Reset form
                    saveBtn.style.display = 'none';
                    saveBtn.disabled = false;
                    saveBtn.textContent = '💾 Simpan Data';

                    // Make inputs readonly again
                    document.querySelectorAll('.form-input').forEach(input => {
                        if (input.name !== 'change_reason' && input.name !== 'custom_reason') {
                            input.setAttribute('readonly', true);
                        }
                    });
                })
                .catch(err => {
                    alert('❌ Error: ' + err.message);
                    saveBtn.disabled = false;
                    saveBtn.textContent = '💾 Simpan Data';
                });
        });

        const resetBtn = document.getElementById('resetBtn');

        // Tampilkan reset button bersamaan dengan save button
        // Di dalam handleUpload() setelah saveBtn.style.display = 'block';
        resetBtn.style.display = 'block';

        // Handler untuk reset
        resetBtn.addEventListener('click', function() {
            if (confirm('Yakin ingin scan ulang? Data yang belum disimpan akan hilang.')) {
                // Hapus temp file jika ada
                const tempFile = tempFileInput.value;
                if (tempFile) {
                    deleteTempFile(tempFile);
                }

                // Clear localStorage
                clearLocalStorage();

                location.reload();
            }
        });

        // Reset highlight when user starts editing
        document.querySelectorAll('[name="first_name"], [name="last_name"]').forEach(input => {
            input.addEventListener('input', function() {
                this.style.borderColor = '';
                this.style.background = '';
            });
        });

        function deleteTempFile(tempFile) {
            if (!tempFile) return;

            const fd = new FormData();
            fd.append('action', 'delete_temp');
            fd.append('temp_file', tempFile);

            fetch('', {
                method: 'POST',
                body: fd
            }).catch(() => {});
        }

        // ========================================
        // RESTORE DATA ON PAGE LOAD
        // ========================================
        window.addEventListener('DOMContentLoaded', function() {
            const stored = loadFromLocalStorage();

            if (stored && stored.image) {
                // Restore preview
                preview.src = stored.image;
                previewArea.classList.add('show');

                // Jika ada data OCR
                if (stored.ocr) {
                    if (stored.ocr.error) {
                        // Ada error sebelumnya
                        showOCRError(stored.ocr.error);
                    } else {
                        // Ada data OCR valid
                        document.querySelector('[name="first_name"]').value = stored.ocr.first_name || '';
                        document.querySelector('[name="last_name"]').value = stored.ocr.last_name || '';
                        document.querySelector('[name="dob"]').value = stored.ocr.dob || '';
                        document.querySelector('[name="sex"]').value = stored.ocr.sex || '';
                        document.querySelector('[name="nationality"]').value = stored.ocr.nationality || '';
                        document.querySelector('[name="citizen_id"]').value = stored.ocr.citizen_id || '';
                        tempFileInput.value = stored.ocr.temp_file || '';

                        showEditForm('Data dipulihkan dari sesi sebelumnya');
                    }
                }
            }
        });
    </script>
</body>

</html>