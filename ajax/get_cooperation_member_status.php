<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';

try {
    $user = $_SESSION['user_rh'] ?? [];
    $effectiveUnit = ems_effective_unit($pdo, $user);
    $citizenId = ems_normalize_citizen_id($_GET['citizen_id'] ?? '');

    if ($citizenId === '' || !ems_looks_like_citizen_id($citizenId)) {
        echo json_encode([
            'success' => true,
            'is_cooperation_member' => false,
            'member' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $member = gaCooperationResolveMember($pdo, $citizenId, $effectiveUnit);

    echo json_encode([
        'success' => true,
        'is_cooperation_member' => $member !== null,
        'member' => $member,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal memuat status kerja sama instansi.',
    ], JSON_UNESCAPED_UNICODE);
}
