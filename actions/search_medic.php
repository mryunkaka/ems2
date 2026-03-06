<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_rh'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['success' => false, 'medics' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            full_name,
            position
        FROM user_rh
        WHERE 
            full_name LIKE :q
            AND is_active = 1
        ORDER BY full_name ASC
        LIMIT 10
    ");

    $stmt->execute([':q' => "%$query%"]);
    $medics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'medics' => $medics
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
