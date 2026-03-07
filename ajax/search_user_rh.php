<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Lanjut ke query

// Gunakan LOWER() untuk pencarian case-insensitive
$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        batch,
        position,
        jenis_kelamin
    FROM user_rh
    WHERE LOWER(full_name) LIKE LOWER(CONCAT('%', ?, '%'))
      AND is_active = 1
    ORDER BY full_name ASC
    LIMIT 10
");

$stmt->execute([$q]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
    $r['position'] = ems_position_label($r['position'] ?? '');
}
unset($r);

echo json_encode($rows);
