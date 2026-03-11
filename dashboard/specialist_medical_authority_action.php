<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ems_url('/dashboard/index.php'));
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$currentUserId = (int) ($_SESSION['user_rh']['id'] ?? 0);

function smaRedirect(string $fallback = 'specialist_training_recap.php'): void
{
    $target = trim((string) ($_POST['redirect_to'] ?? ''));
    if ($target === '') {
        $target = $fallback;
    }

    header('Location: ' . ems_url('/dashboard/' . ltrim($target, '/')));
    exit;
}

function smaCode(string $prefix): string
{
    try {
        $random = strtoupper(bin2hex(random_bytes(2)));
    } catch (Throwable $exception) {
        $random = strtoupper((string) mt_rand(1000, 9999));
    }

    return strtoupper($prefix) . '-' . date('Ymd-His') . '-' . $random;
}

try {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_training') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $trainingName = trim((string) ($_POST['training_name'] ?? ''));
        $providerName = trim((string) ($_POST['provider_name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $certificateNumber = trim((string) ($_POST['certificate_number'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'planned'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($userId <= 0 || $trainingName === '' || $category === '' || $startDate === '') {
            throw new RuntimeException('Data pelatihan belum lengkap.');
        }

        $allowedStatus = ['planned', 'ongoing', 'completed', 'expired'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'planned';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO specialist_training_records (
                training_code, user_id, training_name, provider_name, category,
                certificate_number, start_date, end_date, status, notes, created_by
            ) VALUES (
                :training_code, :user_id, :training_name, :provider_name, :category,
                :certificate_number, :start_date, :end_date, :status, :notes, :created_by
            )"
        );
        $stmt->execute([
            ':training_code' => smaCode('STR'),
            ':user_id' => $userId,
            ':training_name' => $trainingName,
            ':provider_name' => $providerName !== '' ? $providerName : null,
            ':category' => $category,
            ':certificate_number' => $certificateNumber !== '' ? $certificateNumber : null,
            ':start_date' => $startDate,
            ':end_date' => $endDate !== '' ? $endDate : null,
            ':status' => $status,
            ':notes' => $notes !== '' ? $notes : null,
            ':created_by' => $currentUserId,
        ]);

        $_SESSION['flash_success'] = 'Pelatihan medis berhasil disimpan.';
        smaRedirect('specialist_training_recap.php');
    }

    if ($action === 'save_assessment') {
        $promotionRequestId = (int) ($_POST['promotion_request_id'] ?? 0);
        $clinicalScore = (float) ($_POST['clinical_score'] ?? 0);
        $trainingScore = (float) ($_POST['training_score'] ?? 0);
        $readinessScore = (float) ($_POST['readiness_score'] ?? 0);
        $recommendation = trim((string) ($_POST['recommendation'] ?? 'recommended'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($promotionRequestId <= 0) {
            throw new RuntimeException('Pengajuan jabatan wajib dipilih.');
        }

        $allowedRecommendation = ['recommended', 'follow_up_required', 'not_recommended'];
        if (!in_array($recommendation, $allowedRecommendation, true)) {
            $recommendation = 'recommended';
        }

        $requestStmt = $pdo->prepare("SELECT user_id FROM position_promotion_requests WHERE id = :id LIMIT 1");
        $requestStmt->execute([':id' => $promotionRequestId]);
        $requestData = $requestStmt->fetch(PDO::FETCH_ASSOC);

        if (!$requestData) {
            throw new RuntimeException('Pengajuan jabatan tidak ditemukan.');
        }

        $totalScore = $clinicalScore + $trainingScore + $readinessScore;

        $stmt = $pdo->prepare(
            "INSERT INTO specialist_promotion_assessments (
                assessment_code, promotion_request_id, assessed_user_id, assessor_user_id,
                clinical_score, training_score, readiness_score, total_score,
                recommendation, notes, assessed_at
            ) VALUES (
                :assessment_code, :promotion_request_id, :assessed_user_id, :assessor_user_id,
                :clinical_score, :training_score, :readiness_score, :total_score,
                :recommendation, :notes, NOW()
            )
            ON DUPLICATE KEY UPDATE
                assessor_user_id = VALUES(assessor_user_id),
                clinical_score = VALUES(clinical_score),
                training_score = VALUES(training_score),
                readiness_score = VALUES(readiness_score),
                total_score = VALUES(total_score),
                recommendation = VALUES(recommendation),
                notes = VALUES(notes),
                assessed_at = NOW()"
        );
        $stmt->execute([
            ':assessment_code' => smaCode('SPA'),
            ':promotion_request_id' => $promotionRequestId,
            ':assessed_user_id' => (int) $requestData['user_id'],
            ':assessor_user_id' => $currentUserId,
            ':clinical_score' => $clinicalScore,
            ':training_score' => $trainingScore,
            ':readiness_score' => $readinessScore,
            ':total_score' => $totalScore,
            ':recommendation' => $recommendation,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $_SESSION['flash_success'] = 'Penilaian kenaikan jabatan berhasil disimpan.';
        smaRedirect('specialist_promotion_assessment.php');
    }

    if ($action === 'save_authorization') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
        $specialtyName = trim((string) ($_POST['specialty_name'] ?? ''));
        $privilegeScope = trim((string) ($_POST['privilege_scope'] ?? ''));
        $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
        $expiryDate = trim((string) ($_POST['expiry_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($userId <= 0 || $specialtyName === '' || $privilegeScope === '' || $effectiveDate === '') {
            throw new RuntimeException('Data otorisasi belum lengkap.');
        }

        $allowedStatus = ['active', 'expired', 'revoked'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'active';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO specialist_authorizations (
                authorization_code, user_id, specialty_name, privilege_scope,
                effective_date, expiry_date, status, assessment_id, approved_by,
                created_by, notes
            ) VALUES (
                :authorization_code, :user_id, :specialty_name, :privilege_scope,
                :effective_date, :expiry_date, :status, :assessment_id, :approved_by,
                :created_by, :notes
            )"
        );
        $stmt->execute([
            ':authorization_code' => smaCode('SAU'),
            ':user_id' => $userId,
            ':specialty_name' => $specialtyName,
            ':privilege_scope' => $privilegeScope,
            ':effective_date' => $effectiveDate,
            ':expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            ':status' => $status,
            ':assessment_id' => $assessmentId > 0 ? $assessmentId : null,
            ':approved_by' => $currentUserId,
            ':created_by' => $currentUserId,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $_SESSION['flash_success'] = 'Otorisasi medis spesialis berhasil disimpan.';
        smaRedirect('specialist_authorizations.php');
    }

    throw new RuntimeException('Aksi Specialist Medical Authority tidak dikenali.');
} catch (Throwable $exception) {
    $_SESSION['flash_errors'] = ['Gagal memproses Specialist Medical Authority: ' . $exception->getMessage()];
    smaRedirect();
}
