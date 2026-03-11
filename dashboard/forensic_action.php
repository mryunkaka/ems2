<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

ems_require_division_access(['Forensic'], '/dashboard/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

function forensicRedirect(string $fallback = 'forensic_private_patients.php'): void
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '' || strpos($redirectTo, '://') !== false || str_starts_with($redirectTo, '//')) {
        $redirectTo = $fallback;
    }

    header('Location: ' . $redirectTo);
    exit;
}

function forensicGenerateCode(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function forensicAssertAllowed(string $value, array $allowed, string $message): string
{
    if (!in_array($value, $allowed, true)) {
        throw new Exception($message);
    }

    return $value;
}

function forensicDateOrNull(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new Exception('Format tanggal tidak valid.');
    }

    return $value;
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    forensicRedirect();
}

try {
    if ($action === 'save_private_patient') {
        $medicalRecordId = (int) ($_POST['medical_record_id'] ?? 0);
        $patientName = trim((string) ($_POST['patient_name'] ?? ''));
        $medicalRecordNo = trim((string) ($_POST['medical_record_no'] ?? ''));
        $identityNumber = trim((string) ($_POST['identity_number'] ?? ''));
        $birthDate = forensicDateOrNull($_POST['birth_date'] ?? null);
        $gender = forensicAssertAllowed(trim((string) ($_POST['gender'] ?? 'unknown')), ['male', 'female', 'unknown'], 'Gender tidak valid.');
        $caseType = trim((string) ($_POST['case_type'] ?? ''));
        $incidentDate = forensicDateOrNull($_POST['incident_date'] ?? null);
        $incidentLocation = trim((string) ($_POST['incident_location'] ?? ''));
        $confidentialityLevel = forensicAssertAllowed(trim((string) ($_POST['confidentiality_level'] ?? 'confidential')), ['restricted', 'confidential', 'sealed'], 'Level kerahasiaan tidak valid.');
        $status = forensicAssertAllowed(trim((string) ($_POST['status'] ?? 'draft')), ['draft', 'active', 'closed', 'archived'], 'Status kasus tidak valid.');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($patientName === '' || $caseType === '' || $incidentDate === null || $incidentLocation === '') {
            throw new Exception('Data kasus forensic wajib lengkap.');
        }

        if ($medicalRecordId > 0) {
            $stmt = $pdo->prepare("SELECT record_code FROM medical_records WHERE id = ? AND COALESCE(visibility_scope, 'standard') = 'forensic_private' LIMIT 1");
            $stmt->execute([$medicalRecordId]);
            $recordCode = $stmt->fetchColumn();
            if (!$recordCode) {
                throw new Exception('Rekam medis private tidak ditemukan.');
            }
            $medicalRecordNo = (string) $recordCode;
        }

        $stmt = $pdo->prepare("
            INSERT INTO forensic_private_patients
                (case_code, patient_name, medical_record_no, medical_record_id, identity_number, birth_date, gender,
                 case_type, incident_date, incident_location, confidentiality_level, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            forensicGenerateCode('FCP'),
            $patientName,
            $medicalRecordNo !== '' ? $medicalRecordNo : null,
            $medicalRecordId > 0 ? $medicalRecordId : null,
            $identityNumber !== '' ? $identityNumber : null,
            $birthDate,
            $gender,
            $caseType,
            $incidentDate,
            $incidentLocation,
            $confidentialityLevel,
            $status,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        $_SESSION['flash_messages'][] = 'Kasus forensic berhasil disimpan.';
        forensicRedirect('forensic_private_patients.php');
    }

    if ($action === 'update_case_status') {
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $status = forensicAssertAllowed(trim((string) ($_POST['status'] ?? 'draft')), ['draft', 'active', 'closed', 'archived'], 'Status kasus tidak valid.');
        if ($caseId <= 0) {
            throw new Exception('Kasus forensic tidak valid.');
        }

        $stmt = $pdo->prepare("UPDATE forensic_private_patients SET status = ?, updated_by = ? WHERE id = ?");
        $stmt->execute([$status, $userId, $caseId]);

        $_SESSION['flash_messages'][] = 'Status kasus forensic diperbarui.';
        forensicRedirect('forensic_private_patients.php');
    }

    if ($action === 'save_visum') {
        $privatePatientId = (int) ($_POST['private_patient_id'] ?? 0);
        $doctorUserId = (int) ($_POST['doctor_user_id'] ?? 0);
        $examinationDate = forensicDateOrNull($_POST['examination_date'] ?? null);
        $requestingParty = trim((string) ($_POST['requesting_party'] ?? ''));
        $findingSummary = trim((string) ($_POST['finding_summary'] ?? ''));
        $conclusionText = trim((string) ($_POST['conclusion_text'] ?? ''));
        $recommendationText = trim((string) ($_POST['recommendation_text'] ?? ''));
        $status = forensicAssertAllowed(trim((string) ($_POST['status'] ?? 'draft')), ['draft', 'issued', 'revised', 'archived'], 'Status visum tidak valid.');

        if ($privatePatientId <= 0 || $doctorUserId <= 0 || $examinationDate === null || $requestingParty === '' || $findingSummary === '') {
            throw new Exception('Data hasil visum wajib lengkap.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO forensic_visum_results
                (visum_code, private_patient_id, examination_date, doctor_user_id, requesting_party,
                 finding_summary, conclusion_text, recommendation_text, status, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            forensicGenerateCode('FVR'),
            $privatePatientId,
            $examinationDate,
            $doctorUserId,
            $requestingParty,
            $findingSummary,
            $conclusionText !== '' ? $conclusionText : null,
            $recommendationText !== '' ? $recommendationText : null,
            $status,
            $userId,
        ]);

        $_SESSION['flash_messages'][] = 'Hasil visum berhasil disimpan.';
        forensicRedirect('forensic_visum_results.php');
    }

    if ($action === 'update_visum_status') {
        $visumId = (int) ($_POST['visum_id'] ?? 0);
        $status = forensicAssertAllowed(trim((string) ($_POST['status'] ?? 'draft')), ['draft', 'issued', 'revised', 'archived'], 'Status visum tidak valid.');
        if ($visumId <= 0) {
            throw new Exception('Data visum tidak valid.');
        }

        $stmt = $pdo->prepare("UPDATE forensic_visum_results SET status = ?, updated_by = ? WHERE id = ?");
        $stmt->execute([$status, $userId, $visumId]);

        $_SESSION['flash_messages'][] = 'Status visum diperbarui.';
        forensicRedirect('forensic_visum_results.php');
    }

    if ($action === 'save_archive') {
        $privatePatientId = (int) ($_POST['private_patient_id'] ?? 0);
        $visumResultId = (int) ($_POST['visum_result_id'] ?? 0);
        $archiveTitle = trim((string) ($_POST['archive_title'] ?? ''));
        $documentType = trim((string) ($_POST['document_type'] ?? ''));
        $retentionUntil = forensicDateOrNull($_POST['retention_until'] ?? null);
        $status = forensicAssertAllowed(trim((string) ($_POST['status'] ?? 'stored')), ['stored', 'sealed', 'released'], 'Status arsip tidak valid.');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($archiveTitle === '' || $documentType === '') {
            throw new Exception('Data arsip forensic wajib lengkap.');
        }

        if ($privatePatientId <= 0 && $visumResultId <= 0) {
            throw new Exception('Arsip harus terkait ke kasus atau hasil visum.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO forensic_archives
                (archive_code, private_patient_id, visum_result_id, archive_title, document_type,
                 retention_until, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            forensicGenerateCode('FAR'),
            $privatePatientId > 0 ? $privatePatientId : null,
            $visumResultId > 0 ? $visumResultId : null,
            $archiveTitle,
            $documentType,
            $retentionUntil,
            $status,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        $_SESSION['flash_messages'][] = 'Arsip forensic berhasil disimpan.';
        forensicRedirect('forensic_archive.php');
    }

    if ($action === 'update_archive_status') {
        $archiveId = (int) ($_POST['archive_id'] ?? 0);
        $status = forensicAssertAllowed(trim((string) ($_POST['status'] ?? 'stored')), ['stored', 'sealed', 'released'], 'Status arsip tidak valid.');
        if ($archiveId <= 0) {
            throw new Exception('Arsip forensic tidak valid.');
        }

        $stmt = $pdo->prepare("UPDATE forensic_archives SET status = ?, updated_by = ? WHERE id = ?");
        $stmt->execute([$status, $userId, $archiveId]);

        $_SESSION['flash_messages'][] = 'Status arsip forensic diperbarui.';
        forensicRedirect('forensic_archive.php');
    }

    throw new Exception('Aksi forensic tidak dikenali.');
} catch (Throwable $e) {
    $_SESSION['flash_errors'][] = 'Gagal memproses forensic: ' . $e->getMessage();
    forensicRedirect();
}
