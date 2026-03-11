<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

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
          AND (
            LOWER(patient_name) LIKE LOWER(CONCAT('%', ?, '%'))
            OR LOWER(COALESCE(patient_citizen_id, '')) LIKE LOWER(CONCAT('%', ?, '%'))
            OR LOWER(COALESCE(record_code, '')) LIKE LOWER(CONCAT('%', ?, '%'))
          )
        ORDER BY created_at DESC, id DESC
        LIMIT 10
    ");
    $stmt->execute([$q, $q, $q]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo json_encode([]);
}
