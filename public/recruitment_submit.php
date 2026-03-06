<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

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

$folderName = slugName($icName) . '_' . $icPhone;

$pdo->beginTransaction();

try {

    /* ===============================
       INSERT PELAMAR
       =============================== */
    $stmt = $pdo->prepare("
        INSERT INTO medical_applicants (
            ic_name, ooc_age, ic_phone,
            medical_experience, city_duration, online_schedule,
            other_city_responsibility, motivation, work_principle,
            academy_ready, rule_commitment, duty_duration,
            status
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'ai_test')
    ");

    $stmt->execute([
        $icName,
        $_POST['ooc_age'],
        $icPhone,
        $_POST['medical_experience'] ?? null,
        $_POST['city_duration'] ?? null,
        $_POST['online_schedule'] ?? null,
        $_POST['other_city_responsibility'] ?? null,
        $_POST['motivation'] ?? null,
        $_POST['work_principle'] ?? null,
        $_POST['academy_ready'],
        $_POST['rule_commitment'],
        $_POST['duty_duration'] ?? null
    ]);

    $applicantId = $pdo->lastInsertId();

    /* ===============================
       UPLOAD FILE
       =============================== */
    $baseDir = __DIR__ . '/../storage/applicants/';
    $uploadDir = $baseDir . $folderName;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Gagal membuat folder upload');
    }

    foreach (['ktp_ic', 'skb', 'sim'] as $doc) {

        // SIM OPSIONAL
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

        // Validasi JPG (AMAN, TANPA fileinfo)
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

        $pdo->prepare("
        INSERT INTO applicant_documents
        (applicant_id, document_type, file_path)
        VALUES (?, ?, ?)
    ")->execute([
            $applicantId,
            $doc,
            'storage/applicants/' . $folderName . '/' . $doc . '.jpg'
        ]);
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


header('Location: ai_test.php?applicant_id=' . $applicantId);
exit;
