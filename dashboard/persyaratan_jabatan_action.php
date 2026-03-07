<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
$req = $_POST['req'] ?? [];

if (!is_array($req)) {
    $_SESSION['flash_errors'][] = 'Data tidak valid.';
    header('Location: persyaratan_jabatan.php');
    exit;
}

$allowedTransitions = [
    'trainee:paramedic',
    'paramedic:co_asst',
    'co_asst:general_practitioner',
    'general_practitioner:specialist',
];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO position_promotion_requirements
            (from_position, to_position, min_days_since_join, min_operations, notes, is_active, updated_by, updated_at)
        VALUES
            (?, ?, ?, ?, ?, 1, ?, NOW())
        ON DUPLICATE KEY UPDATE
            min_days_since_join = VALUES(min_days_since_join),
            min_operations = VALUES(min_operations),
            notes = VALUES(notes),
            is_active = 1,
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ");

    foreach ($req as $key => $val) {
        $key = strtolower(trim((string)$key));
        if (!in_array($key, $allowedTransitions, true)) {
            continue;
        }

        [$from, $to] = explode(':', $key, 2);

        $minDays = isset($val['min_days_since_join']) && $val['min_days_since_join'] !== ''
            ? (int)$val['min_days_since_join']
            : null;
        $minOps = isset($val['min_operations']) && $val['min_operations'] !== ''
            ? (int)$val['min_operations']
            : null;
        $notes = isset($val['notes']) ? trim((string)$val['notes']) : null;
        if ($notes === '') $notes = null;

        $stmt->execute([
            $from,
            $to,
            $minDays,
            $minOps,
            $notes,
            $userId ?: null,
        ]);
    }

    $pdo->commit();
    $_SESSION['flash_messages'][] = 'Syarat kenaikan jabatan berhasil diperbarui.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_errors'][] = 'Gagal menyimpan: ' . $e->getMessage();
}

header('Location: persyaratan_jabatan.php');
exit;

