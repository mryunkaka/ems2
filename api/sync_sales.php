<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

validate_api_request($pdo);

// Ambil data BELUM disync
$stmt = $pdo->query("
    SELECT
        id,
        medic_user_id,
        medic_name,
        package_name,
        price,
        qty_bandage,
        qty_ifaks,
        qty_painkiller,
        created_at,
        tx_hash
    FROM sales
    WHERE synced_to_sheet = 0
    ORDER BY id ASC
    LIMIT 100
");

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
exit;
