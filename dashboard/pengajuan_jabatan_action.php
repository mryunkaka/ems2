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
$userDb = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$userDb) {
    http_response_code(404);
    exit('User not found');
}

$fromPosition = ems_normalize_position($userDb['position'] ?? '');
$expectedTo = ems_next_position($fromPosition);
$toPosition = ems_normalize_position($_POST['to_position'] ?? '');

if ($expectedTo === '' || $toPosition !== $expectedTo) {
    $_SESSION['flash_errors'][] = 'Tujuan kenaikan jabatan tidak valid.';
    header('Location: pengajuan_jabatan.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT from_position, to_position, min_days_since_join, min_operations, min_operations_minor, min_operations_major, dpjp_minor, dpjp_major, notes, required_documents
    FROM position_promotion_requirements
    WHERE from_position = ?
      AND to_position = ?
      AND is_active = 1
    LIMIT 1
");
$stmt->execute([$fromPosition, $toPosition]);
$req = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$minDays = array_key_exists('min_days_since_join', $req) ? (int)$req['min_days_since_join'] : null;
$minOps = array_key_exists('min_operations', $req) ? (int)$req['min_operations'] : null;
$minOpsMinor = array_key_exists('min_operations_minor', $req) ? (int)$req['min_operations_minor'] : null;
$minOpsMajor = array_key_exists('min_operations_major', $req) ? (int)$req['min_operations_major'] : null;
$dpjpMinor = array_key_exists('dpjp_minor', $req) ? (int)$req['dpjp_minor'] : 0;
$dpjpMajor = array_key_exists('dpjp_major', $req) ? (int)$req['dpjp_major'] : 0;
$requiredDocuments = array_key_exists('required_documents', $req) ? trim((string)$req['required_documents']) : '';
$requiredDocumentsArray = $requiredDocuments ? explode(',', $requiredDocuments) : [];
$notes = (string)($req['notes'] ?? '');

// Prevent duplicate pending
$stmt = $pdo->prepare("
    SELECT id
    FROM position_promotion_requests
    WHERE user_id = ?
      AND from_position = ?
      AND to_position = ?
      AND status = 'pending'
    LIMIT 1
");
$stmt->execute([$userId, $fromPosition, $toPosition]);
if ($stmt->fetchColumn()) {
    $_SESSION['flash_warnings'][] = 'Masih ada pengajuan pending untuk jalur ini.';
    header('Location: pengajuan_jabatan.php');
    exit;
}

// Validation: join date (trainee -> paramedic)
$joinDateRaw = $userDb['tanggal_masuk'] ?? null;
$joinDate = $joinDateRaw ? (new DateTime($joinDateRaw)) : null;
if ($minDays !== null) {
    if (!$joinDate) {
        $_SESSION['flash_errors'][] = 'Tanggal masuk belum terdata.';
        header('Location: pengajuan_jabatan.php');
        exit;
    }
    $days = (int)$joinDate->diff(new DateTime('today'))->days;
    if ($days < $minDays) {
        $_SESSION['flash_errors'][] = "Belum memenuhi syarat join minimal {$minDays} hari.";
        header('Location: pengajuan_jabatan.php');
        exit;
    }
}

// Validation: operations
$ops = [];
if (in_array($fromPosition, ['paramedic', 'co_asst'], true)) {
    // Validation: required certificates
    if (!empty($requiredDocumentsArray)) {
        $certMapping = [
            'sertifikat_medical_class' => 'sertifikat_class_paramedic',
            'sertifikat_operasi_minor' => 'sertifikat_operasi_kecil',
            'sertifikat_operasi_plastik_basic' => 'sertifikat_operasi_plastik',
        ];
        
        $certLabels = [
            'sertifikat_medical_class' => 'Sertifikat Medical Class',
            'sertifikat_operasi_minor' => 'Sertifikat Operasi Minor',
            'sertifikat_operasi_plastik_basic' => 'Sertifikat Operasi Plastik (Basic)',
        ];
        
        $stmt = $pdo->prepare("SELECT sertifikat_class_paramedic, sertifikat_operasi_kecil, sertifikat_operasi_plastik FROM user_rh WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $userCert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $missingCerts = [];
        foreach ($requiredDocumentsArray as $reqDoc) {
            $dbColumn = $certMapping[$reqDoc] ?? null;
            if ($dbColumn && empty($userCert[$dbColumn])) {
                $missingCerts[] = $certLabels[$reqDoc] ?? $reqDoc;
            }
        }
        
        if (!empty($missingCerts)) {
            $_SESSION['flash_errors'][] = 'Belum upload sertifikat yang wajib: ' . implode(', ', $missingCerts) . '.';
            header('Location: pengajuan_jabatan.php');
            exit;
        }
    }
    
    $patients = $_POST['ops_patient_name'] ?? [];
    $procedures = $_POST['ops_procedure_name'] ?? [];
    $dpjps = $_POST['ops_dpjp'] ?? [];
    $roles = $_POST['ops_role'] ?? [];
    $levels = $_POST['ops_level'] ?? [];
    $medicalRecordIds = $_POST['ops_medical_record_id'] ?? [];

    if (!is_array($patients) || !is_array($procedures) || !is_array($dpjps) || !is_array($roles) || !is_array($levels) || !is_array($medicalRecordIds)) {
        $_SESSION['flash_errors'][] = 'Format riwayat operasi tidak valid.';
        header('Location: pengajuan_jabatan.php');
        exit;
    }

    $count = min(count($patients), count($procedures), count($dpjps), count($roles), count($levels), count($medicalRecordIds));
    for ($i = 0; $i < $count; $i++) {
        $p = trim((string)$patients[$i]);
        $t = trim((string)$procedures[$i]);
        $d = trim((string)$dpjps[$i]);
        $r = trim((string)$roles[$i]);
        $l = trim((string)$levels[$i]);
        if ($p === '' && $t === '' && $d === '') {
            continue;
        }
        if ($p === '' || $t === '' || $d === '' || $r === '' || $l === '') {
            $_SESSION['flash_errors'][] = 'Setiap riwayat operasi wajib mengisi: Nama Pasien, Tindakan Operasi, DPJP, Peran, Tingkat.';
            header('Location: pengajuan_jabatan.php');
            exit;
        }

        if (!in_array($r, ['assistant', 'dpjp'], true) || !in_array($l, ['minor', 'major'], true)) {
            $_SESSION['flash_errors'][] = 'Peran/Tingkat operasi tidak valid.';
            header('Location: pengajuan_jabatan.php');
            exit;
        }

        // Transition-specific validation based on role/level
        if ($fromPosition === 'paramedic') {
            // Paramedic -> Co. Asst : Asisten operasi Minor atau Mayor
            if ($r !== 'assistant') {
                $_SESSION['flash_errors'][] = 'Untuk kenaikan Paramedic → Co. Asst, peran operasi harus sebagai Asisten.';
                header('Location: pengajuan_jabatan.php');
                exit;
            }
        }

        if ($fromPosition === 'co_asst') {
            // Co. Asst -> Dokter Umum : DPJP Minor ATAU Asisten Mayor
            $ok = ($r === 'dpjp' && $l === 'minor') || ($r === 'assistant' && $l === 'major');
            if (!$ok) {
                $_SESSION['flash_errors'][] = 'Untuk kenaikan Co. Asst → Dokter Umum, setiap entri harus DPJP (Minor) atau Asisten (Mayor).';
                header('Location: pengajuan_jabatan.php');
                exit;
            }
        }

        $medicalRecordId = trim((string)($medicalRecordIds[$i] ?? ''));
        $ops[] = ['patient_name' => $p, 'procedure_name' => $t, 'dpjp' => $d, 'operation_role' => $r, 'operation_level' => $l, 'medical_record_id' => $medicalRecordId ?: null];
    }

    // Validation: check operation counts based on actual medical records in database
    if ($minOpsMinor !== null || $minOpsMajor !== null) {
        // Count actual medical records for this user
        $hasAssistantsTable = ems_table_exists($pdo, 'medical_record_assistants');
        
        $minorCount = 0;
        $majorCount = 0;
        $minorDpjpCount = 0;
        $majorDpjpCount = 0;
        
        if ($hasAssistantsTable) {
            $stmt = $pdo->prepare("
                SELECT r.operasi_type, r.doctor_id, r.id
                FROM medical_records r
                INNER JOIN medical_record_assistants mra ON mra.medical_record_id = r.id
                WHERE mra.assistant_user_id = ?
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
                        INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                        WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
                        AND r2.id = r.id
                    )
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$userId, $userId, $userId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($records as $record) {
                if ($record['operasi_type'] === 'minor') {
                    $minorCount++;
                } elseif ($record['operasi_type'] === 'major') {
                    $majorCount++;
                }
            }
        } else {
            $stmt = $pdo->prepare("
                SELECT operasi_type, doctor_id, id
                FROM medical_records
                WHERE assistant_id = ?
                    AND id NOT IN (
                        SELECT pro.medical_record_id
                        FROM position_promotion_request_operations pro
                        INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                        WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
                    )
                    AND id NOT IN (
                        SELECT r2.id
                        FROM medical_records r2
                        INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                            AND pro.operation_role = 'assistant'
                        INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
                        WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
                        AND r2.id = medical_records.id
                    )
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId, $userId, $userId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($records as $record) {
                if ($record['operasi_type'] === 'minor') {
                    $minorCount++;
                } elseif ($record['operasi_type'] === 'major') {
                    $majorCount++;
                }
            }
        }
        
        // Also count operations where user is DPJP (doctor_id = user_id)
        // Always fetch DPJP operations regardless of flags, because:
        // - If dpjpMinor is true, we need DPJP minor count
        // - If dpjpMajor is true, we need DPJP major count
        // - If both are false, we still need DPJP counts for "any role" validation
        $dpjpWhereConditions = ["doctor_id = ?"];
        $dpjpParams = [$userId];

        $dpjpWhereConditions[] = "id NOT IN (
            SELECT pro.medical_record_id
            FROM position_promotion_request_operations pro
            INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
            WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NOT NULL
        )";
        $dpjpParams[] = $userId;

        $dpjpWhereConditions[] = "id NOT IN (
            SELECT r2.id
            FROM medical_records r2
            INNER JOIN position_promotion_request_operations pro ON pro.patient_name = r2.patient_name
                AND pro.operation_role = 'dpjp'
            INNER JOIN position_promotion_requests pr ON pr.id = pro.request_id
            WHERE pr.user_id = ? AND pr.status = 'approved' AND pro.medical_record_id IS NULL
            AND r2.id = medical_records.id
        )";
        $dpjpParams[] = $userId;

        $dpjpWhereClause = implode(' AND ', $dpjpWhereConditions);

        $stmt = $pdo->prepare("
            SELECT operasi_type, id
            FROM medical_records
            WHERE {$dpjpWhereClause}
            ORDER BY created_at DESC
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
        
        $msgParts = [];
        if ($minOpsMinor !== null) {
            if ($dpjpMinor) {
                // Must be DPJP
                if ($minorDpjpCount < $minOpsMinor) {
                    $msgParts[] = "minimal {$minOpsMinor} operasi minor sebagai DPJP (saat ini {$minorDpjpCount})";
                }
            } else {
                // Any role (assistant or DPJP)
                if ($minorCount < $minOpsMinor) {
                    $msgParts[] = "minimal {$minOpsMinor} operasi minor (saat ini {$minorCount})";
                }
            }
        }
        if ($minOpsMajor !== null) {
            if ($dpjpMajor) {
                // Must be DPJP
                if ($majorDpjpCount < $minOpsMajor) {
                    $msgParts[] = "minimal {$minOpsMajor} operasi mayor sebagai DPJP (saat ini {$majorDpjpCount})";
                }
            } else {
                // Any role (assistant or DPJP) - combine counts
                $totalMajorCount = $majorCount + $majorDpjpCount;
                if ($totalMajorCount < $minOpsMajor) {
                    $msgParts[] = "minimal {$minOpsMajor} operasi mayor (saat ini {$totalMajorCount})";
                }
            }
        }
        
        if (!empty($msgParts)) {
            $_SESSION['flash_errors'][] = 'Belum memenuhi syarat: ' . implode(', ', $msgParts) . '.';
            header('Location: pengajuan_jabatan.php');
            exit;
        }
    } else {
        // Fallback to old logic
        $requiredOps = max(0, (int)($minOps ?? 0));
        if ($requiredOps > 0 && count($ops) < $requiredOps) {
            $_SESSION['flash_errors'][] = "Minimal riwayat operasi: {$requiredOps} entri.";
            header('Location: pengajuan_jabatan.php');
            exit;
        }
    }
}

// Validation: case report (co_asst -> dokter umum)
$caseTitle = null;
$caseSubject = null;
if ($fromPosition === 'co_asst') {
    $caseTitle = trim((string)($_POST['case_title'] ?? ''));
    $caseSubject = trim((string)($_POST['case_subject'] ?? ''));
    if ($caseTitle === '' || $caseSubject === '') {
        $_SESSION['flash_errors'][] = 'Laporan kasus wajib diisi.';
        header('Location: pengajuan_jabatan.php');
        exit;
    }
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO position_promotion_requests
            (user_id, from_position, to_position,
             requirement_notes_snapshot, min_days_since_join_snapshot, min_operations_snapshot,
             join_date_snapshot, batch_snapshot,
             duty_note_snapshot,
             case_title, case_subject)
        VALUES
            (?, ?, ?,
             ?, ?, ?,
             ?, ?,
             ?,
             ?, ?)
    ");

    $dutyNoteSnapshot = ($fromPosition === 'trainee') ? $notes : null;

    $stmt->execute([
        $userId,
        $fromPosition,
        $toPosition,
        ($notes !== '' ? $notes : null),
        $minDays,
        $minOps,
        $joinDate ? $joinDate->format('Y-m-d') : null,
        ((int)($userDb['batch'] ?? 0)) ?: null,
        $dutyNoteSnapshot,
        $caseTitle !== '' ? $caseTitle : null,
        $caseSubject !== '' ? $caseSubject : null,
    ]);

    $requestId = (int)$pdo->lastInsertId();

    if ($ops) {
        $stmtOp = $pdo->prepare("
            INSERT INTO position_promotion_request_operations
                (request_id, sort_order, patient_name, procedure_name, dpjp, operation_role, operation_level, medical_record_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $order = 1;
        foreach ($ops as $op) {
            $stmtOp->execute([
                $requestId,
                $order++,
                $op['patient_name'],
                $op['procedure_name'],
                $op['dpjp'],
                $op['operation_role'] ?? null,
                $op['operation_level'] ?? null,
                $op['medical_record_id'] ?? null,
            ]);
        }
    }

    $pdo->commit();
    $_SESSION['flash_messages'][] = 'Pengajuan berhasil dibuat dan menunggu verifikasi manager.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = 'Gagal membuat pengajuan: ' . $e->getMessage();
}

header('Location: pengajuan_jabatan.php');
exit;
