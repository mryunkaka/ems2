<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/inbox_helper.php';

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

// Validate CSRF token
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

// Get user from session
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userDivision = ems_normalize_division($user['division'] ?? '');

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: rekam_medis.php');
    exit;
}

try {
    // =====================
    // 1. VALIDATION
    // =====================
    
    $patientName = trim($_POST['patient_name'] ?? '');
    $patientCitizenId = trim($_POST['patient_citizen_id'] ?? '');
    $patientDob = $_POST['patient_dob'] ?? '';
    $patientGender = $_POST['patient_gender'] ?? '';
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $operasiType = $_POST['operasi_type'] ?? 'minor';
    $jenisOperasi = trim((string) ($_POST['jenis_operasi'] ?? ''));
    $visibilityScope = $_POST['visibility_scope'] ?? 'standard';
    $redirectTo = trim($_POST['redirect_to'] ?? 'rekam_medis.php');
    $hasJenisOperasiColumn = ems_column_exists($pdo, 'medical_records', 'jenis_operasi');
    
    // Required fields validation
    if ($patientName === '') {
        throw new Exception('Nama pasien wajib diisi.');
    }

    if ($patientCitizenId === '') {
        throw new Exception('Citizen ID pasien wajib diisi.');
    }
    
    if (empty($patientDob)) {
        throw new Exception('Tanggal lahir pasien wajib diisi.');
    }
    
    // Validate date format (YYYY-MM-DD)
    $dobPattern = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($dobPattern, $patientDob)) {
        throw new Exception('Format tanggal lahir tidak valid. Gunakan format YYYY-MM-DD.');
    }
    
    // Validate date is reasonable (between 1900 and today)
    $dobDateTime = DateTime::createFromFormat('Y-m-d', $patientDob);
    if (!$dobDateTime || $dobDateTime->format('Y-m-d') !== $patientDob) {
        throw new Exception('Tanggal lahir tidak valid.');
    }
    
    $minDate = new DateTime('1900-01-01');
    $maxDate = new DateTime();
    if ($dobDateTime < $minDate || $dobDateTime > $maxDate) {
        throw new Exception('Tanggal lahir tidak masuk akal. Harus antara tahun 1900 sampai hari ini.');
    }
    
    if (empty($patientGender)) {
        throw new Exception('Jenis kelamin pasien wajib dipilih.');
    }
    
    if ($doctorId <= 0) {
        throw new Exception('Dokter DPJP wajib dipilih.');
    }
    
    if (!in_array($operasiType, ['major', 'minor'])) {
        throw new Exception('Jenis operasi tidak valid.');
    }

    if (!in_array($visibilityScope, ['standard', 'forensic_private'], true)) {
        throw new Exception('Scope rekam medis tidak valid.');
    }

    if ($visibilityScope === 'forensic_private' && !ems_can_access_division_menu($userDivision, 'Forensic')) {
        throw new Exception('Akses rekam medis private ditolak.');
    }
    
    // =====================
    // 2. FILE UPLOAD KTP (WAJIB)
    // =====================
    
    $ktpPath = null;
    if (isset($_FILES['ktp_file']) && $_FILES['ktp_file']['error'] === UPLOAD_ERR_OK) {
        $ktpPath = uploadAndCompressFile($_FILES['ktp_file'], 'medical_records/ktp', 300000, 5000000);
        if (!$ktpPath) {
            throw new Exception('Gagal upload KTP. Pastikan file berupa gambar JPG/PNG dan ukuran tidak melebihi ' . emsUploadLimitLabel() . '.');
        }
    } else {
        throw new Exception('Upload KTP wajib dilakukan.');
    }
    
    // =====================
    // 3. FILE UPLOAD FOTO PENDUKUNG
    // =====================

    $supportingImageFiles = ems_normalize_uploaded_files_array($_FILES['supporting_image_files'] ?? []);
    $mriPath = null;
    $additionalSupportingImageFiles = [];

    if ($supportingImageFiles !== []) {
        $mriPath = uploadAndCompressFile(array_shift($supportingImageFiles), 'medical_records/mri', 500000, 5000000);
        if (!$mriPath) {
            throw new Exception('Gagal upload foto pendukung utama. Pastikan file berupa gambar JPG/PNG dan ukuran tidak melebihi ' . emsUploadLimitLabel() . '.');
        }

        $additionalSupportingImageFiles = $supportingImageFiles;
    } elseif (isset($_FILES['mri_file']) && ($_FILES['mri_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $mriPath = uploadAndCompressFile($_FILES['mri_file'], 'medical_records/mri', 500000, 5000000);
        if (!$mriPath) {
            throw new Exception('Gagal upload foto pendukung utama. Pastikan file berupa gambar JPG/PNG dan ukuran tidak melebihi ' . emsUploadLimitLabel() . '.');
        }
    } elseif ($visibilityScope === 'forensic_private') {
        throw new Exception('Upload foto pendukung wajib dilakukan minimal 1 file.');
    }
    
    // =====================
    // 4. HTML SANITIZATION
    // =====================
    
    $medicalResultHtml = $_POST['medical_result_html'] ?? '';
    // Basic XSS prevention - strip script tags
    $medicalResultHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $medicalResultHtml);
    
    // =====================
    // 5. INSERT TO DATABASE
    // =====================
    
    // Handle multiple assistants - get first one for assistant_id (backward compatibility)
    $assistantIds = ems_normalize_assistant_ids((array) ($_POST['assistant_ids'] ?? []));
    if ($assistantIds === []) {
        throw new Exception('Asisten operasi wajib diisi minimal 1 orang.');
    }
    $assistantId = $assistantIds[0] ?? null;
    $recordCode = 'MR-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $columns = [
        'record_code',
        'patient_name',
        'patient_citizen_id',
        'patient_occupation',
        'patient_dob',
        'patient_phone',
        'patient_gender',
        'patient_address',
        'patient_status',
        'ktp_file_path',
        'mri_file_path',
        'medical_result_html',
        'doctor_id',
        'assistant_id',
        'operasi_type',
    ];
    $values = [
        $recordCode,
        $patientName,
        $patientCitizenId,
        trim($_POST['patient_occupation'] ?? 'Civilian'),
        $patientDob,
        trim($_POST['patient_phone'] ?? null),
        $patientGender,
        trim($_POST['patient_address'] ?? 'INDONESIA'),
        trim($_POST['patient_status'] ?? null),
        $ktpPath,
        $mriPath,
        $medicalResultHtml,
        $doctorId,
        $assistantId,
        $operasiType,
    ];

    if ($hasJenisOperasiColumn) {
        $columns[] = 'jenis_operasi';
        $values[] = $jenisOperasi !== '' ? $jenisOperasi : null;
    }

    $columns[] = 'visibility_scope';
    $columns[] = 'created_by';
    $values[] = $visibilityScope;
    $values[] = $userId;

    $quotedColumns = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    ems_ensure_medical_record_assistants_table($pdo);
    ems_ensure_medical_record_supporting_images_table($pdo);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO medical_records ({$quotedColumns})
        VALUES ({$placeholders})
    ");
    $stmt->execute($values);

    $recordId = (int) $pdo->lastInsertId();
    ems_save_medical_record_assistants($pdo, $recordId, $assistantIds);
    ems_store_medical_record_supporting_images($pdo, $recordId, $additionalSupportingImageFiles);

    $notificationUserIds = ems_medical_record_notification_user_ids($doctorId, $assistantIds);
    if ($notificationUserIds !== []) {
        $operationTier = $operasiType === 'major' ? 'Mayor' : 'Minor';
        $operationLabel = $jenisOperasi !== '' ? $jenisOperasi : ('Operasi ' . $operationTier);
        $senderName = trim((string) ($user['full_name'] ?? 'System'));
        $title = 'Kontak Masuk Rekam Medis';
        $message = '<b>Pasien:</b> ' . htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8')
            . '<br><b>No. Rekam Medis:</b> ' . htmlspecialchars($recordCode, ENT_QUOTES, 'UTF-8')
            . '<br><b>Jenis Operasi:</b> ' . htmlspecialchars($operationLabel, ENT_QUOTES, 'UTF-8')
            . '<br><b>Kategori:</b> ' . htmlspecialchars($operationTier, ENT_QUOTES, 'UTF-8')
            . '<br><b>Diinput oleh:</b> ' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');

        foreach ($notificationUserIds as $notificationUserId) {
            sendInbox($pdo, $notificationUserId, $title, $message, 'medical_record');
        }
    }

    $pdo->commit();

    if (!empty($notificationUserIds)) {
        $pushUsers = [];
        $pushStmt = $pdo->prepare("
            SELECT id AS user_id, full_name
            FROM user_rh
            WHERE id = ?
            LIMIT 1
        ");
        foreach ($notificationUserIds as $notificationUserId) {
            $pushStmt->execute([$notificationUserId]);
            $pushUser = $pushStmt->fetch(PDO::FETCH_ASSOC);
            if ($pushUser) {
                $pushUsers[] = $pushUser;
            }
        }

        if ($pushUsers !== []) {
            try {
                $PUSH_USERS = $pushUsers;
                $PUSH_TYPE = 'medical_record_contact_incoming';
                require __DIR__ . '/../actions/push_send.php';
            } catch (Throwable $pushError) {
                error_log('[MEDICAL RECORD PUSH ERROR] ' . $pushError->getMessage());
            }
        }
    }
    
    // =====================
    // 6. SUCCESS
    // =====================
    
    $_SESSION['flash_messages'][] = 'Rekam medis berhasil disimpan.';
    
    header('Location: ' . $redirectTo . '?saved=1');
    exit;
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = $e->getMessage();
    $redirectTo = trim($_POST['redirect_to'] ?? 'rekam_medis.php');
    header('Location: ' . $redirectTo);
    exit;
}
