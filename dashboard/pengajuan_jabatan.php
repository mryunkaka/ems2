<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Pengajuan Kenaikan Jabatan';

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, full_name, position, batch, tanggal_masuk
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$fullName = (string)($userDb['full_name'] ?? '');
$position = ems_normalize_position($userDb['position'] ?? '');
$batch = (int)($userDb['batch'] ?? 0);
$joinDateRaw = $userDb['tanggal_masuk'] ?? null;
$joinDate = $joinDateRaw ? (new DateTime($joinDateRaw)) : null;

$comingSoon = ($position === 'general_practitioner');

$expectedTo = ems_next_position($position);
$toPosition = $expectedTo;

// Detect misconfigured requirements (e.g. trainee -> co_asst) and ignore them for UI flow.
$misconfiguredTargets = [];
if ($expectedTo !== '') {
    $stmt = $pdo->prepare("
        SELECT to_position
        FROM position_promotion_requirements
        WHERE from_position = ?
          AND is_active = 1
    ");
    $stmt->execute([$position]);
    $targets = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($targets as $t) {
        $norm = ems_normalize_position((string)$t);
        if ($norm !== '' && $norm !== $expectedTo) {
            $misconfiguredTargets[] = $norm;
        }
    }
}

$req = null;
if ($toPosition !== '') {
    $stmt = $pdo->prepare("
        SELECT from_position, to_position, min_days_since_join, min_operations, min_operations_minor, min_operations_major, dpjp_minor, dpjp_major, notes, required_documents, operation_type, operation_role
        FROM position_promotion_requirements
        WHERE from_position = ?
          AND to_position = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$position, $toPosition]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$reqMissing = ($toPosition !== '' && !$req);
$minDays = isset($req['min_days_since_join']) ? (int)$req['min_days_since_join'] : null;
$minOps = isset($req['min_operations']) ? (int)$req['min_operations'] : null;
$minOpsMinor = isset($req['min_operations_minor']) ? (int)$req['min_operations_minor'] : null;
$minOpsMajor = isset($req['min_operations_major']) ? (int)$req['min_operations_major'] : null;
$dpjpMinor = isset($req['dpjp_minor']) ? (int)$req['dpjp_minor'] : 0;
$dpjpMajor = isset($req['dpjp_major']) ? (int)$req['dpjp_major'] : 0;
$operationType = isset($req['operation_type']) ? trim((string)$req['operation_type']) : '';
$operationRole = isset($req['operation_role']) ? trim((string)$req['operation_role']) : '';
$requiredDocuments = isset($req['required_documents']) ? trim((string)$req['required_documents']) : '';
$requiredDocumentsArray = $requiredDocuments ? explode(',', $requiredDocuments) : [];
$notes = (string)($req['notes'] ?? '');

// Make notes dynamic by replacing min_days value if present
if ($minDays !== null && $notes !== '') {
    // Replace patterns like "minimal 7 hari" with actual minDays value
    $notes = preg_replace('/minimal\s+\d+\s+hari/i', "minimal {$minDays} hari", $notes);
}

if ($reqMissing) {
    $notes = 'Syarat untuk jalur ini belum diatur oleh manager. Silakan hubungi manager.';
}

$pending = null;
if ($toPosition !== '') {
    $stmt = $pdo->prepare("
        SELECT id, status, submitted_at
        FROM position_promotion_requests
        WHERE user_id = ?
          AND from_position = ?
          AND to_position = ?
          AND status = 'pending'
        ORDER BY submitted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $position, $toPosition]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.from_position,
        r.to_position,
        r.status,
        r.submitted_at,
        r.reviewed_at,
        rb.full_name AS reviewed_by_name,
        r.reviewer_note
    FROM position_promotion_requests
    r
    LEFT JOIN user_rh rb ON rb.id = r.reviewed_by
    WHERE r.user_id = ?
    ORDER BY r.submitted_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$daysSinceJoin = null;
$eligibleJoin = true;
if ($minDays !== null) {
    if (!$joinDate) {
        $eligibleJoin = false;
    } else {
        $daysSinceJoin = (int)$joinDate->diff(new DateTime('today'))->days;
        $eligibleJoin = ($daysSinceJoin >= $minDays);
    }
}

// Fetch medical records where user is assistant (for paramedic/co_asst auto-fill)
$medicalRecordsAsAssistant = [];
$hasSertifikatParamedic = false;
$hasRequiredOps = false;
$minorOpsCount = 0;
$majorOpsCount = 0;
$certificateStatus = [];

if (in_array($position, ['paramedic', 'co_asst'], true)) {
    // Check for required certificates
    $stmt = $pdo->prepare("SELECT sertifikat_class_paramedic, sertifikat_class_co_asst, sertifikat_operasi_kecil, sertifikat_operasi_plastik FROM user_rh WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $userCert = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Map certificate requirements to database columns (dynamic based on position)
    $certMapping = [
        'sertifikat_medical_class' => $position === 'co_asst' ? 'sertifikat_class_co_asst' : 'sertifikat_class_paramedic',
        'sertifikat_operasi_minor' => 'sertifikat_operasi_kecil',
        'sertifikat_operasi_plastik_basic' => 'sertifikat_operasi_plastik',
    ];
    
    foreach ($requiredDocumentsArray as $reqDoc) {
        $dbColumn = $certMapping[$reqDoc] ?? null;
        if ($dbColumn) {
            $certificateStatus[$reqDoc] = !empty($userCert[$dbColumn]);
        }
    }
    
    $hasSertifikatParamedic = !empty($userCert['sertifikat_class_paramedic']);

    // Fetch medical records where user is assistant, excluding those already used in approved requests
    $hasAssistantsTable = ems_table_exists($pdo, 'medical_record_assistants');
    
    // For co_asst -> general_practitioner, we need both:
    // - Records where user is assistant (for major operations)
    // - Records where user is DPJP (for minor operations)
    $medicalRecordsAsAssistant = [];
    
    if ($position === 'co_asst' && $toPosition === 'general_practitioner') {
        // Get records where user is assistant (for major operations)
        if ($hasAssistantsTable) {
            $stmt = $pdo->prepare("
                SELECT
                    r.id AS medical_record_id,
                    r.patient_name,
                    r.operasi_type,
                    r.ktp_file_path,
                    r.mri_file_path,
                    u.full_name AS dpjp_name,
                    u.position AS dpjp_position,
                    'assistant' AS user_role
                FROM medical_records r
                INNER JOIN medical_record_assistants mra ON mra.medical_record_id = r.id
                LEFT JOIN user_rh u ON u.id = r.doctor_id
                WHERE mra.assistant_user_id = ?
                    AND r.operasi_type = 'major'
                    AND r.id NOT IN (
                        SELECT pro.medical_record_id
                        FROM position_promotion_request_operations pro
                        INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                        WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
                    )
                    AND r.id NOT IN (
                        SELECT r2.id
                        FROM medical_records r2
                        INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                            AND pro.operation_role = 'assistant'
                            AND pro.operation_level = 'major'
                        INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                        WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
                        AND r2.id = r.id
                    )
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$userId, $userId, $userId]);
            $assistantRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $medicalRecordsAsAssistant = array_merge($medicalRecordsAsAssistant, $assistantRecords);
        }
        
        // Get records where user is DPJP (for minor operations)
        $stmt = $pdo->prepare("
            SELECT
                r.id AS medical_record_id,
                r.patient_name,
                r.operasi_type,
                r.ktp_file_path,
                r.mri_file_path,
                u.full_name AS dpjp_name,
                u.position AS dpjp_position,
                'dpjp' AS user_role
            FROM medical_records r
            LEFT JOIN user_rh u ON u.id = r.doctor_id
            WHERE r.doctor_id = ?
                AND r.operasi_type = 'minor'
                AND r.id NOT IN (
                    SELECT pro.medical_record_id
                    FROM position_promotion_request_operations pro
                    INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                    WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
                )
                AND r.id NOT IN (
                    SELECT r2.id
                    FROM medical_records r2
                    INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                        AND pro.operation_role = 'dpjp'
                        AND pro.operation_level = 'minor'
                    INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                    WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
                    AND r2.id = r.id
                )
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $dpjpRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $medicalRecordsAsAssistant = array_merge($medicalRecordsAsAssistant, $dpjpRecords);

        // Also get DPJP major records for display (even though not required for this promotion)
        $stmt = $pdo->prepare("
            SELECT
                r.id AS medical_record_id,
                r.patient_name,
                r.operasi_type,
                r.ktp_file_path,
                r.mri_file_path,
                u.full_name AS dpjp_name,
                u.position AS dpjp_position,
                'dpjp' AS user_role
            FROM medical_records r
            LEFT JOIN user_rh u ON u.id = r.doctor_id
            WHERE r.doctor_id = ?
                AND r.operasi_type = 'major'
                AND r.id NOT IN (
                    SELECT pro.medical_record_id
                    FROM position_promotion_request_operations pro
                    INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                    WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
                )
                AND r.id NOT IN (
                    SELECT r2.id
                    FROM medical_records r2
                    INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                        AND pro.operation_role = 'dpjp'
                        AND pro.operation_level = 'major'
                    INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                    WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
                    AND r2.id = r.id
                )
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $dpjpMajorRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $medicalRecordsAsAssistant = array_merge($medicalRecordsAsAssistant, $dpjpMajorRecords);

        // Sort by created_at descending
        usort($medicalRecordsAsAssistant, function($a, $b) {
            return strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0');
        });
    } else {
        // For other transitions, use original logic
        // Build WHERE clause based on operation_type and operation_role requirements
        $whereConditions = ["mra.assistant_user_id = ?"];
        $params = [$userId];
        
        // Only filter by operation_type if specifically set to single type
        // For mixed requirements (both minor and major), show all records
        if ($operationType === 'minor' && $minOpsMajor === null) {
            $whereConditions[] = "r.operasi_type = 'minor'";
        } elseif ($operationType === 'major' && $minOpsMinor === null) {
            $whereConditions[] = "r.operasi_type = 'major'";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        if ($hasAssistantsTable) {
            $whereConditions[] = "r.id NOT IN (
                SELECT pro.medical_record_id
                FROM position_promotion_request_operations pro
                INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
                AND pr.from_position = ? AND pr.to_position = ?
            )";
            $params[] = $userId;
            $params[] = $position;
            $params[] = $toPosition;

            // Fallback exclusion for old data without medical_record_id
            $whereConditions[] = "r.id NOT IN (
                SELECT r2.id
                FROM medical_records r2
                INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                    AND pro.operation_role = 'assistant'
                INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
                AND pr.from_position = ? AND pr.to_position = ?
                AND r2.id = r.id
            )";
            $params[] = $userId;
            $params[] = $position;
            $params[] = $toPosition;

            $whereClause = implode(' AND ', $whereConditions);

            $stmt = $pdo->prepare("
                SELECT
                    r.id AS medical_record_id,
                    r.patient_name,
                    r.operasi_type,
                    r.ktp_file_path,
                    r.mri_file_path,
                    u.full_name AS dpjp_name,
                    u.position AS dpjp_position
                FROM medical_records r
                INNER JOIN medical_record_assistants mra ON mra.medical_record_id = r.id
                LEFT JOIN user_rh u ON u.id = r.doctor_id
                WHERE {$whereClause}
                ORDER BY r.created_at DESC
            ");
            $stmt->execute($params);
            $medicalRecordsAsAssistant = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Fallback for old schema using assistant_id
            $whereConditions = ["r.assistant_id = ?"];
            $params = [$userId];

            if ($operationType === 'minor') {
                $whereConditions[] = "r.operasi_type = 'minor'";
            } elseif ($operationType === 'major') {
                $whereConditions[] = "r.operasi_type = 'major'";
            }

            $whereConditions[] = "r.id NOT IN (
                SELECT pro.medical_record_id
                FROM position_promotion_request_operations pro
                INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
                AND pr.from_position = ? AND pr.to_position = ?
            )";
            $params[] = $userId;
            $params[] = $position;
            $params[] = $toPosition;

            // Fallback exclusion for old data without medical_record_id
            $whereConditions[] = "r.id NOT IN (
                SELECT r2.id
                FROM medical_records r2
                INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                    AND pro.operation_role = 'assistant'
                INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
                AND pr.from_position = ? AND pr.to_position = ?
                AND r2.id = r.id
            )";
            $params[] = $userId;
            $params[] = $position;
            $params[] = $toPosition;

            $whereClause = implode(' AND ', $whereConditions);

            $stmt = $pdo->prepare("
                SELECT
                    r.id AS medical_record_id,
                    r.patient_name,
                    r.operasi_type,
                    r.ktp_file_path,
                    r.mri_file_path,
                    u.full_name AS dpjp_name,
                    u.position AS dpjp_position
                FROM medical_records r
                LEFT JOIN user_rh u ON u.id = r.doctor_id
                WHERE {$whereClause}
                ORDER BY r.created_at DESC
            ");
            $stmt->execute($params);
            $medicalRecordsAsAssistant = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Count minor and major operations
    $minorDpjpCount = 0;
    $majorDpjpCount = 0;
    $minorOpsCount = 0;
    $majorOpsCount = 0;
    $minorAssistantCount = 0;
    $majorAssistantCount = 0;
    
    // Count operations where user is assistant (already fetched)
    foreach ($medicalRecordsAsAssistant as $record) {
        if ($record['operasi_type'] === 'minor') {
            $minorAssistantCount++;
        } elseif ($record['operasi_type'] === 'major') {
            $majorAssistantCount++;
        }
    }
    
    // Also count operations where user is DPJP (doctor_id = user_id)
    if ($dpjpMinor || $dpjpMajor) {
        // Build WHERE clause for DPJP operations
        $dpjpWhereConditions = ["r.doctor_id = ?"];
        $dpjpParams = [$userId];

        // Only filter by operation_type if specifically required
        if ($dpjpMinor && !$dpjpMajor) {
            $dpjpWhereConditions[] = "r.operasi_type = 'minor'";
        } elseif ($dpjpMajor && !$dpjpMinor) {
            $dpjpWhereConditions[] = "r.operasi_type = 'major'";
        }

        $dpjpWhereConditions[] = "r.id NOT IN (
            SELECT pro.medical_record_id
            FROM position_promotion_request_operations pro
            INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
            WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
        )";
        $dpjpParams[] = $userId;

        $dpjpWhereConditions[] = "r.id NOT IN (
            SELECT r2.id
            FROM medical_records r2
            INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                AND pro.operation_role = 'dpjp'
            INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
            WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
            AND r2.id = r.id
        )";
        $dpjpParams[] = $userId;

        $dpjpWhereClause = implode(' AND ', $dpjpWhereConditions);

        $stmt = $pdo->prepare("
            SELECT r.operasi_type
            FROM medical_records r
            WHERE {$dpjpWhereClause}
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($dpjpParams);
        $dpjpRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dpjpRecords as $record) {
            if ($record['operasi_type'] === 'minor') {
                $minorDpjpCount++;
            } elseif ($record['operasi_type'] === 'major') {
                $majorDpjpCount++;
            }
        }
    }
    
    // Total counts
    $minorOpsCount = $minorAssistantCount + $minorDpjpCount;
    $majorOpsCount = $majorAssistantCount + $majorDpjpCount;

    // Check if has required operations based on new logic
    $hasRequiredOps = true;
    if ($minOpsMinor !== null) {
        if ($dpjpMinor) {
            // Must be DPJP
            if ($minorDpjpCount < $minOpsMinor) {
                $hasRequiredOps = false;
            }
        } else {
            // Any role (assistant or DPJP)
            if ($minorOpsCount < $minOpsMinor) {
                $hasRequiredOps = false;
            }
        }
    }
    if ($minOpsMajor !== null) {
        if ($dpjpMajor) {
            // Must be DPJP
            if ($majorDpjpCount < $minOpsMajor) {
                $hasRequiredOps = false;
            }
        } else {
            // Any role (assistant or DPJP)
            if ($majorOpsCount < $minOpsMajor) {
                $hasRequiredOps = false;
            }
        }
    }
    // Fallback to old logic if new fields are not set
    if ($minOpsMinor === null && $minOpsMajor === null && $minOps !== null) {
        $hasRequiredOps = count($medicalRecordsAsAssistant) >= $minOps;
    }
}

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];

// Hapus flash error division yang mungkin tersisa dari redirect halaman lain
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$m) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars((string)$w) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$e) ?></div>
        <?php endforeach; ?>

        <div class="card card-section">
            <div class="card-header">Data Medis</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div><strong>Nama</strong><div><?= htmlspecialchars($fullName) ?></div></div>
                <div><strong>Jabatan Saat Ini</strong><div><?= htmlspecialchars(ems_position_label($position)) ?></div></div>
                <div><strong>Batch</strong><div><?= $batch > 0 ? (int)$batch : '-' ?></div></div>
                <div><strong>Tanggal Masuk</strong><div><?= $joinDate ? htmlspecialchars($joinDate->format('Y-m-d')) : '-' ?></div></div>
            </div>
            <?php if (!empty($_GET['debug'])): ?>
                <div class="mt-3 text-xs text-slate-600">
                    Debug: raw_position=<?= htmlspecialchars((string)($userDb['position'] ?? '')) ?>,
                    normalized=<?= htmlspecialchars($position) ?>,
                    expected_to=<?= htmlspecialchars($expectedTo) ?>,
                    to=<?= htmlspecialchars($toPosition) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($toPosition === ''): ?>
            <div class="card access-card">
                <h3 class="access-title"><?= $comingSoon ? 'Menyusul' : 'Belum ada jalur kenaikan' ?></h3>
                <p class="access-copy">
                    <?= $comingSoon
                        ? 'Pengajuan kenaikan dari Dokter Umum ke Dokter Spesialis menyusul.'
                        : 'Jabatan Anda saat ini belum memiliki jalur pengajuan kenaikan jabatan di sistem.' ?>
                </p>
            </div>
        <?php elseif ($pending): ?>
            <div class="alert alert-info">
                Pengajuan Anda untuk <strong><?= htmlspecialchars(ems_position_label($position)) ?></strong> → <strong><?= htmlspecialchars(ems_position_label($toPosition)) ?></strong>
                masih <strong>pending</strong> (<?= htmlspecialchars($pending['submitted_at'] ?? '') ?>).
            </div>
        <?php else: ?>
            <div class="card card-section">
                <div class="card-header">
                    Pengajuan: <?= htmlspecialchars(ems_position_label($position)) ?> → <?= htmlspecialchars(ems_position_label($toPosition)) ?>
                </div>

                <?php if (!empty($misconfiguredTargets)): ?>
                    <div class="alert alert-error" style="margin-bottom:12px;">
                        <strong>Perhatian</strong><br>
                        Terdeteksi konfigurasi syarat jabatan yang tidak sesuai jalur sistem:
                        <strong><?= htmlspecialchars(ems_position_label($position)) ?></strong> →
                        <strong><?= htmlspecialchars(implode(', ', array_map('ems_position_label', $misconfiguredTargets))) ?></strong>.<br>
                        Silakan manager cek menu <strong>Syarat Jabatan</strong>.
                    </div>
                <?php endif; ?>

                <?php if ($notes !== ''): ?>
                    <div class="alert alert-warning" style="margin-bottom:12px;">
                        <strong>Catatan / Syarat</strong><br>
                        <?= nl2br(htmlspecialchars($notes)) ?>
                        <?php if ($joinDate && $position === 'trainee' && $toPosition === 'paramedic'): ?>
                            <br><br>
                            <strong>Total Join di Roxwood Hospital:</strong> <?= (int)$daysSinceJoin ?> hari
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($minDays !== null && !$eligibleJoin): ?>
                    <div class="alert alert-error">
                        Belum memenuhi syarat join minimal <strong><?= (int)$minDays ?></strong> hari.
                        <?php if ($joinDate): ?>
                            Saat ini: <strong><?= (int)$daysSinceJoin ?></strong> hari.
                        <?php else: ?>
                            Tanggal masuk belum terdata.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (in_array($position, ['paramedic', 'co_asst'], true)): ?>
                    <div class="card card-section" style="margin-bottom:12px;">
                        <div class="card-header">Status Syarat</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <?php if (!empty($certificateStatus)): ?>
                                <?php foreach ($certificateStatus as $certKey => $hasCert): ?>
                                    <div>
                                        <strong>
                                            <?php
                                            $certLabels = [
                                                'sertifikat_medical_class' => 'Sertifikat Medical Class ' . ems_position_label($position),
                                                'sertifikat_operasi_minor' => 'Sertifikat Operasi Minor',
                                                'sertifikat_operasi_plastik_basic' => 'Sertifikat Operasi Plastik (Basic)',
                                            ];
                                            echo htmlspecialchars($certLabels[$certKey] ?? $certKey);
                                            ?>
                                        </strong>
                                        <div>
                                            <?php if ($hasCert): ?>
                                                <span class="badge badge-success">✓ Sudah Upload</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#ef4444; color:white;">✗ Belum Upload</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div>
                                    <strong>Sertifikat Class <?= ems_position_label($position) ?></strong>
                                    <div>
                                        <?php if ($hasSertifikatParamedic): ?>
                                            <span class="badge badge-success">✓ Sudah Upload</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#ef4444; color:white;">✗ Belum Upload</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong>Operasi Mayor/Minor</strong>
                                <div>
                                    <?php if ($minOpsMinor !== null || $minOpsMajor !== null): ?>
                                        <?php if ($minOpsMinor !== null): ?>
                                            Minor: <?= $dpjpMinor ? $minorDpjpCount : $minorOpsCount ?> / <?= (int)$minOpsMinor ?>
                                            <?php if ($dpjpMinor): ?>
                                                (DPJP)
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($minOpsMajor !== null): ?>
                                            <?php if ($minOpsMinor !== null): ?>, <?php endif; ?>
                                            Mayor: <?= $dpjpMajor ? $majorDpjpCount : $majorOpsCount ?> / <?= (int)$minOpsMajor ?>
                                            <?php if ($dpjpMajor): ?>
                                                (DPJP)
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= count($medicalRecordsAsAssistant) ?> / <?= (int)($minOps ?? 0) ?> operasi
                                    <?php endif; ?>
                                    <?php if ($hasRequiredOps): ?>
                                        <span class="badge badge-success">✓ Terpenuhi</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#ef4444; color:white;">✗ Belum Terpenuhi</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="pengajuan_jabatan_action.php" class="form" id="promoForm">
                    <?= csrfField(); ?>
                    <input type="hidden" name="to_position" value="<?= htmlspecialchars($toPosition, ENT_QUOTES) ?>">

                    <?php if (in_array($position, ['paramedic', 'co_asst'], true)): ?>
                        <h3 class="section-form-title">Riwayat Operasi yang Pernah Dilakukan</h3>
                        <p class="page-subtitle">
                            <?php if ($minOpsMinor !== null || $minOpsMajor !== null): ?>
                                <?php if ($minOpsMinor !== null): ?>
                                    Minimal: <strong><?= (int)$minOpsMinor ?></strong> operasi minor
                                    <?php if ($dpjpMinor): ?>
                                        sebagai <strong>DPJP</strong>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($minOpsMajor !== null): ?>
                                    <?php if ($minOpsMinor !== null): ?>, <?php endif; ?>
                                    <strong><?= (int)$minOpsMajor ?></strong> operasi mayor
                                    <?php if ($dpjpMajor): ?>
                                        sebagai <strong>DPJP</strong>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                Minimal: <strong><?= (int)($minOps ?? 0) ?></strong> entri.
                            <?php endif; ?>
                        </p>

                        <div class="alert alert-warning" style="margin-bottom:12px;">
                            <strong>Catatan:</strong> Kasus operasi yang sudah digunakan untuk pengajuan kenaikan jabatan sebelumnya (misalnya: Paramedic → Co. Asst) tidak dapat digunakan kembali untuk pengajuan ini.
                        </div>

                        <datalist id="dpjpDatalist"></datalist>
                        <div id="opsList" class="space-y-3"></div>
                    <?php endif; ?>

                    <?php if ($position === 'co_asst'): ?>
                        <hr class="section-divider">
                        <h3 class="section-form-title">Laporan Kasus</h3>

                        <label>Judul Kasus <span class="required">*</span></label>
                        <input type="text" name="case_title" required placeholder="Operasi ______">

                        <label>Perihal <span class="required">*</span></label>
                        <textarea name="case_subject" required rows="3"
                            placeholder="Kenaikan Jabatan Dari ______ Ke ______"></textarea>
                    <?php endif; ?>

                    <hr class="section-divider">

                    <?php
                    $submitBlocked = ($reqMissing || ($minDays !== null && !$eligibleJoin));
                    $blockedMsg = null;
                    if ($submitBlocked) {
                        if ($reqMissing) {
                            $blockedMsg = 'Belum bisa ajukan. Syarat jalur kenaikan jabatan ini belum diatur oleh manager.';
                        } elseif ($joinDate) {
                            $blockedMsg = "Belum bisa ajukan. Syarat join minimal {$minDays} hari (saat ini {$daysSinceJoin} hari).";
                        } else {
                            $blockedMsg = "Belum bisa ajukan. Tanggal masuk belum terdata.";
                        }
                    }

                    // Additional validation for paramedic/co_asst
                    $certBlocked = false;
                    $opsBlocked = false;
                    if (in_array($position, ['paramedic', 'co_asst'], true)) {
                        // Check all required certificates
                        if (!empty($certificateStatus)) {
                            $missingCerts = [];
                            foreach ($certificateStatus as $certKey => $hasCert) {
                                if (!$hasCert) {
                                    $certLabels = [
                                        'sertifikat_medical_class' => 'Sertifikat Medical Class',
                                        'sertifikat_operasi_minor' => 'Sertifikat Operasi Minor',
                                        'sertifikat_operasi_plastik_basic' => 'Sertifikat Operasi Plastik (Basic)',
                                    ];
                                    $missingCerts[] = $certLabels[$certKey] ?? $certKey;
                                }
                            }
                            if (!empty($missingCerts)) {
                                $certBlocked = true;
                                $submitBlocked = true;
                                if ($blockedMsg) {
                                    $blockedMsg .= ' ';
                                }
                                $blockedMsg .= 'Belum upload sertifikat yang wajib: ' . implode(', ', $missingCerts) . '.';
                            }
                        } elseif (!$hasSertifikatParamedic) {
                            // Fallback for old logic
                            $certBlocked = true;
                            $submitBlocked = true;
                            if ($blockedMsg) {
                                $blockedMsg .= ' ';
                            }
                            $blockedMsg .= 'Belum upload Sertifikat Class Paramedic.';
                        }
                        
                        if (!$hasRequiredOps) {
                            $opsBlocked = true;
                            $submitBlocked = true;
                            if ($blockedMsg) {
                                $blockedMsg .= ' ';
                            }
                            if ($minOpsMinor !== null || $minOpsMajor !== null) {
                                $msgParts = [];
                                if ($minOpsMinor !== null) {
                                    $msgParts[] = "minimal {$minOpsMinor} operasi minor (saat ini {$minorOpsCount})";
                                }
                                if ($minOpsMajor !== null) {
                                    $msgParts[] = "minimal {$minOpsMajor} operasi mayor (saat ini {$majorOpsCount})";
                                }
                                $blockedMsg .= 'Belum memenuhi syarat: ' . implode(', ', $msgParts) . '.';
                            } else {
                                $blockedMsg .= "Belum memenuhi syarat minimal {$minOps} operasi.";
                            }
                        }
                    }
                    ?>

	                    <?php if ($submitBlocked): ?>
	                        <button type="button"
	                            class="btn-success opacity-60 cursor-not-allowed"
	                            data-toast-type="error"
	                            data-toast-message="<?= htmlspecialchars((string)$blockedMsg, ENT_QUOTES) ?>"
	                            onclick="window.emsShowToast && window.emsShowToast(this.getAttribute('data-toast-message') || 'Aksi tidak tersedia.', this.getAttribute('data-toast-type') || 'info'); return false;">
	                            <?= ems_icon('x-mark', 'h-4 w-4') ?> <span>Ajukan Sekarang</span>
	                        </button>
	                        <small class="hint-warning" style="display:block;margin-top:8px;">
	                            <?= htmlspecialchars((string)$blockedMsg) ?>
	                        </small>
	                    <?php else: ?>
                        <button type="submit" class="btn-success">
                            <?= ems_icon('arrow-up-tray', 'h-4 w-4') ?> <span>Ajukan Sekarang</span>
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <div class="card card-section">
            <div class="card-header">Riwayat Pengajuan (Terakhir 10)</div>
            <div class="table-wrapper-sm">
                <table id="promotionHistoryTable" class="table-custom" data-auto-datatable="true" data-dt-order='[[0,"desc"]]'>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Dari</th>
                            <th>Ke</th>
                            <th>Status</th>
                            <th>Diproses</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$history): ?>
                            <tr><td colspan="6" class="muted-placeholder">Belum ada pengajuan.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['submitted_at'] ?? '') ?></td>
                                    <td><?= htmlspecialchars(ems_position_label($h['from_position'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(ems_position_label($h['to_position'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($h['status'] ?? '') ?></td>
                                    <td>
                                        <?php if (!empty($h['reviewed_at'])): ?>
                                            <div><strong><?= htmlspecialchars((string)($h['reviewed_by_name'] ?? '-')) ?></strong></div>
                                            <small class="meta-text"><?= htmlspecialchars((string)$h['reviewed_at']) ?></small>
                                        <?php else: ?>
                                            <span class="muted-placeholder">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($h['reviewer_note'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
    (function() {
        const fromPos = <?= json_encode($position) ?>;
        const minOps = <?= json_encode($minOps) ?>;
        const minOpsMinor = <?= json_encode($minOpsMinor) ?>;
        const minOpsMajor = <?= json_encode($minOpsMajor) ?>;
        const dpjpMinor = <?= json_encode($dpjpMinor) ?>;
        const dpjpMajor = <?= json_encode($dpjpMajor) ?>;
        const medicalRecords = <?= json_encode($medicalRecordsAsAssistant) ?>;
        const list = document.getElementById('opsList');

        if (!list) return;

        function makeRow(i, data = null) {
            const wrap = document.createElement('div');
            wrap.className = 'card card-section';

            const patientName = data ? (data.patient_name || '') : '';
            const dpjpName = data ? (data.dpjp_name || '') : '';
            const dpjpPosition = data ? (data.dpjp_position || '') : '';
            const operasiType = data ? (data.operasi_type || 'minor') : 'minor';

            // Determine role dynamically based on requirement flags and operation type
            let userRole = 'assistant';
            if (operasiType === 'minor' && dpjpMinor) {
                userRole = 'dpjp';
            } else if (operasiType === 'major' && dpjpMajor) {
                userRole = 'dpjp';
            } else {
                userRole = 'assistant';
            }

            const medicalRecordId = data ? (data.medical_record_id || '') : '';
            const hasKtp = data ? (data.ktp_file_path || '') : '';
            const hasMri = data ? (data.mri_file_path || '') : '';

            wrap.innerHTML = `
                <div class="card-header">Operasi #${i}</div>
                <input type="hidden" name="ops_medical_record_id[]" value="${medicalRecordId}">
                <label>Nama Pasien <span class="required">*</span></label>
                <input type="text" name="ops_patient_name[]" required readonly value="${patientName ? patientName.replace(/"/g, '&quot;') : ''}">

                <label>Tindakan Operasi <span class="required">*</span></label>
                <input type="text" name="ops_procedure_name[]" required readonly value="${data ? 'Operasi ' + (operasiType === 'major' ? 'Mayor' : 'Minor') : ''}">

                <label>DPJP <span class="required">*</span></label>
                <input type="text" name="ops_dpjp[]" list="dpjpDatalist" required readonly placeholder="Ketik nama DPJP..." value="${dpjpName ? dpjpName.replace(/"/g, '&quot;') : ''}">

                <div class="row-form-2" style="margin-top:10px;">
                    <div>
                        <label>Peran <span class="required">*</span></label>
                        <select name="ops_role[]" required>
                            <option value="">-- Pilih --</option>
                            <option value="assistant" ${userRole === 'assistant' ? 'selected' : ''}>Asisten</option>
                            <option value="dpjp" ${userRole === 'dpjp' ? 'selected' : ''}>DPJP</option>
                        </select>
                    </div>
                    <div>
                        <label>Tingkat <span class="required">*</span></label>
                        <select name="ops_level[]" required>
                            <option value="">-- Pilih --</option>
                            <option value="minor" ${operasiType === 'minor' ? 'selected' : ''}>Minor</option>
                            <option value="major" ${operasiType === 'major' ? 'selected' : ''}>Mayor</option>
                        </select>
                    </div>
                </div>
                ${data ? `
                <div style="margin-top:10px; font-size:0.85rem; color:#64748b;">
                    <div>Dokumen: ${hasKtp ? '✓ KTP' : '✗ KTP'} ${hasMri ? '✓ MRI' : '✗ MRI'}</div>
                </div>
                ` : ''}
            `;
            return wrap;
        }

        function countRows() {
            return list.querySelectorAll('input[name="ops_patient_name[]"]').length;
        }

        function ensureMin() {
            const need = Math.max(0, Number(minOps || 0));
            while (countRows() < need) {
                list.appendChild(makeRow(countRows() + 1));
            }
        }

        function autoFillFromMedicalRecords() {
            // Clear existing rows
            list.innerHTML = '';

            if (!medicalRecords || medicalRecords.length === 0) {
                // Show empty rows up to minOps
                const need = Math.max(0, Number(minOps || 0));
                for (let i = 0; i < need; i++) {
                    list.appendChild(makeRow(i + 1, null));
                }
                return;
            }

            // Add ALL rows from medical records
            for (let i = 0; i < medicalRecords.length; i++) {
                list.appendChild(makeRow(i + 1, medicalRecords[i]));
            }
        }

        if (fromPos === 'paramedic' || fromPos === 'co_asst') {
            autoFillFromMedicalRecords();
        }
    })();
    // v2 - force cache bust
</script>

<script>
    (function() {
        const datalist = document.getElementById('dpjpDatalist');
        if (!datalist) return;

        let timer = null;
        let lastQ = '';

        function setOptions(items) {
            datalist.innerHTML = '';
            (items || []).forEach((it) => {
                const opt = document.createElement('option');
                opt.value = it.full_name || '';
                opt.textContent = it.position_label ? `(${it.position_label})` : '';
                datalist.appendChild(opt);
            });
        }

        async function search(q) {
            if (!q || q.length < 2) {
                setOptions([]);
                return;
            }
            if (q === lastQ) return;
            lastQ = q;
            try {
                const res = await fetch(`/ajax/search_dpjp.php?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                setOptions(Array.isArray(data) ? data : []);
            } catch (e) {
                // ignore
                console.error('Search error:', e);
            }
        }

        document.addEventListener('input', (e) => {
            const el = e.target;
            if (!(el instanceof HTMLInputElement)) return;
            if (el.name !== 'ops_dpjp[]') return;
            const q = (el.value || '').trim();
            clearTimeout(timer);
            timer = setTimeout(() => {
                try {
                    search(q);
                } catch (e) {
                    console.error('Search error:', e);
                }
            }, 200);
        });
    })();
</script>

<script>
    (function() {
        function ensureToastContainer() {
            let c = document.getElementById('toast-container');
            if (c) return c;
            c = document.createElement('div');
            c.id = 'toast-container';
            // Fallback minimal styling if CSS not loaded
            c.style.position = 'fixed';
            c.style.right = '16px';
            c.style.top = '16px';
            c.style.zIndex = '9999';
            document.body.appendChild(c);
            return c;
        }

        function showToast(message, type) {
            const container = ensureToastContainer();
            const t = document.createElement('div');
            t.className = 'toast ' + (type || 'info');
            t.textContent = message || 'Aksi tidak tersedia.';
            t.style.padding = '10px 12px';
            t.style.borderRadius = '12px';
            t.style.marginBottom = '10px';
            t.style.boxShadow = '0 10px 24px rgba(2,6,23,.18)';
            t.style.background = (type === 'error') ? '#fee2e2' : (type === 'success') ? '#dcfce7' : '#e0f2fe';
            t.style.color = '#0f172a';
            container.appendChild(t);
            setTimeout(() => t.remove(), 3200);
        }

	        window.emsShowToast = function(msg, type) {
	            try {
	                showToast(msg, type);
	            } catch (e) {}
	        };
	    })();
	</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
