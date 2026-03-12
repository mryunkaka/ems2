<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userDivision = ems_normalize_division($user['division'] ?? '');

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: rekam_medis_list.php');
    exit;
}

try {
    // Get record ID
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('ID rekam medis tidak valid.');
    }
    
    // Check if record exists
    $stmt = $pdo->prepare("SELECT * FROM medical_records WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        throw new Exception('Rekam medis tidak ditemukan.');
    }

    $recordScope = $record['visibility_scope'] ?? 'standard';
    if ($recordScope !== 'forensic_private' && (int) ($record['created_by'] ?? 0) !== $userId) {
        throw new Exception('Hanya pembuat rekam medis yang dapat mengedit data ini.');
    }
    if ($recordScope === 'forensic_private' && !ems_can_access_division_menu($userDivision, 'Forensic')) {
        throw new Exception('Akses rekam medis private ditolak.');
    }
    
    // =====================
    // 1. VALIDATION
    // =====================
    
    $patientName = trim($_POST['patient_name'] ?? '');
    $patientCitizenId = trim($_POST['patient_citizen_id'] ?? '');
    $patientDob = $_POST['patient_dob'] ?? '';
    $patientGender = $_POST['patient_gender'] ?? '';
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $operasiType = $_POST['operasi_type'] ?? 'minor';
    $visibilityScope = $_POST['visibility_scope'] ?? ($record['visibility_scope'] ?? 'standard');
    $redirectTo = trim($_POST['redirect_to'] ?? 'rekam_medis_list.php');
    
    if ($patientName === '') {
        throw new Exception('Nama pasien wajib diisi.');
    }
    
    if (empty($patientDob)) {
        throw new Exception('Tanggal lahir pasien wajib diisi.');
    }
    
    // Validate date format
    $dobPattern = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($dobPattern, $patientDob)) {
        throw new Exception('Format tanggal lahir tidak valid.');
    }
    
    // Validate date is reasonable
    $dobDateTime = DateTime::createFromFormat('Y-m-d', $patientDob);
    if (!$dobDateTime || $dobDateTime->format('Y-m-d') !== $patientDob) {
        throw new Exception('Tanggal lahir tidak valid.');
    }
    
    $minDate = new DateTime('1900-01-01');
    $maxDate = new DateTime();
    if ($dobDateTime < $minDate || $dobDateTime > $maxDate) {
        throw new Exception('Tanggal lahir tidak masuk akal.');
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
    // 2. FILE UPLOAD KTP (OPTIONAL - REPLACE)
    // =====================
    
    $ktpPath = $record['ktp_file_path']; // Keep existing
    if (isset($_FILES['ktp_file']) && $_FILES['ktp_file']['error'] === UPLOAD_ERR_OK) {
        // Delete old file
        if (file_exists(__DIR__ . '/../' . $ktpPath)) {
            unlink(__DIR__ . '/../' . $ktpPath);
        }
        
        $ktpPath = uploadAndCompressFile($_FILES['ktp_file'], 'medical_records/ktp', 300000, 5000000);
        if (!$ktpPath) {
            throw new Exception('Gagal upload KTP baru.');
        }
    }
    
    // =====================
    // 3. FILE UPLOAD MRI (OPTIONAL - REPLACE)
    // =====================
    
    $mriPath = $record['mri_file_path']; // Keep existing
    if (isset($_FILES['mri_file']) && $_FILES['mri_file']['error'] === UPLOAD_ERR_OK) {
        // Delete old file if exists
        if ($mriPath && file_exists(__DIR__ . '/../' . $mriPath)) {
            unlink(__DIR__ . '/../' . $mriPath);
        }
        
        $mriPath = uploadAndCompressFile($_FILES['mri_file'], 'medical_records/mri', 500000, 5000000);
    }
    
    // =====================
    // 4. HTML SANITIZATION
    // =====================
    
    $medicalResultHtml = $_POST['medical_result_html'] ?? '';
    $medicalResultHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $medicalResultHtml);
    $assistantIds = ems_normalize_assistant_ids((array) ($_POST['assistant_ids'] ?? []));
    
    // =====================
    // 5. UPDATE DATABASE
    // =====================
    
    $stmt = $pdo->prepare("
        UPDATE medical_records 
        SET patient_name = ?,
            patient_citizen_id = ?,
            patient_occupation = ?,
            patient_dob = ?,
            patient_phone = ?,
            patient_gender = ?,
            patient_address = ?,
            patient_status = ?,
            ktp_file_path = ?,
            mri_file_path = ?,
            medical_result_html = ?,
            doctor_id = ?,
            operasi_type = ?,
            visibility_scope = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
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
        $operasiType,
        $visibilityScope,
        $id
    ]);

    ems_save_medical_record_assistants($pdo, $id, $assistantIds);
    
    $_SESSION['flash_messages'][] = 'Rekam medis berhasil diupdate.';
    header('Location: ' . $redirectTo);
    exit;
    
} catch (Exception $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
    $mode = trim($_POST['mode'] ?? '');
    $suffix = $mode !== '' ? '&mode=' . urlencode($mode) : '';
    header('Location: rekam_medis_edit.php?id=' . ($id ?? 0) . $suffix);
    exit;
}
