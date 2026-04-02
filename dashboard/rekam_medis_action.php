<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

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
    $visibilityScope = $_POST['visibility_scope'] ?? 'standard';
    $redirectTo = trim($_POST['redirect_to'] ?? 'rekam_medis.php');
    
    // Required fields validation
    if ($patientName === '') {
        throw new Exception('Nama pasien wajib diisi.');
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
            throw new Exception('Gagal upload KTP. Pastikan file berupa gambar JPG/PNG dan ukuran tidak melebihi 5MB.');
        }
    } else {
        throw new Exception('Upload KTP wajib dilakukan.');
    }
    
    // =====================
    // 3. FILE UPLOAD MRI
    // =====================
    
    $mriPath = null;
    if (isset($_FILES['mri_file']) && $_FILES['mri_file']['error'] === UPLOAD_ERR_OK) {
        $mriPath = uploadAndCompressFile($_FILES['mri_file'], 'medical_records/mri', 500000, 5000000);
        if (!$mriPath && $visibilityScope === 'forensic_private') {
            throw new Exception('Gagal upload Foto MRI. Pastikan file berupa gambar JPG/PNG dan ukuran tidak melebihi 5MB.');
        }
        // MRI tetap optional untuk mode standard
    } elseif ($visibilityScope === 'forensic_private') {
        throw new Exception('Upload Foto MRI wajib dilakukan.');
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
    $assistantId = $assistantIds[0] ?? null;
    
    $stmt = $pdo->prepare("
        INSERT INTO medical_records 
        (record_code, patient_name, patient_citizen_id, patient_occupation, patient_dob, patient_phone, 
         patient_gender, patient_address, patient_status, ktp_file_path, 
         mri_file_path, medical_result_html, doctor_id, assistant_id, 
         operasi_type, visibility_scope, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        'MR-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2))),
        $patientName,
        $patientCitizenId !== '' ? $patientCitizenId : null,
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
        $visibilityScope,
        $userId
    ]);

    $recordId = (int) $pdo->lastInsertId();
    ems_save_medical_record_assistants($pdo, $recordId, $assistantIds);
    
    // =====================
    // 6. SUCCESS
    // =====================
    
    $_SESSION['flash_messages'][] = 'Rekam medis berhasil disimpan.';
    
    header('Location: ' . $redirectTo . '?saved=1');
    exit;
    
} catch (Exception $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
    $redirectTo = trim($_POST['redirect_to'] ?? 'rekam_medis.php');
    header('Location: ' . $redirectTo);
    exit;
}
