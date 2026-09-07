<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/forensic_private_access.php';

$user = $_SESSION['user_rh'] ?? [];
ems_forensic_private_ensure_tables($pdo);
$forensicPerms = ems_forensic_private_effective_permissions($pdo, $user);
if (!$forensicPerms['can_create']) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Akses Rekam Medis Private ditolak.'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json');
$userId = (int) ($user['id'] ?? 0);

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            record_code,
            patient_name,
            patient_citizen_id,
            patient_dob
        FROM medical_records
        WHERE visibility_scope = 'forensic_private'
          AND (? = 1 OR created_by = ?)
          AND (
            LOWER(patient_name) LIKE LOWER(CONCAT('%', ?, '%'))
            OR LOWER(COALESCE(patient_citizen_id, '')) LIKE LOWER(CONCAT('%', ?, '%'))
            OR LOWER(COALESCE(record_code, '')) LIKE LOWER(CONCAT('%', ?, '%'))
          )
        ORDER BY created_at DESC, id DESC
        LIMIT 10
    ");
    $stmt->execute([$forensicPerms['can_view_all'] ? 1 : 0, $userId, $q, $q, $q]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo json_encode([]);
}
