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

    // Debug: log entire POST data to file
    $debugLog = "DEBUG POST: " . date('Y-m-d H:i:s') . "\n";
    $debugLog .= "POST data: " . var_export($_POST, true) . "\n";
    
    // Debug: check if dpjp columns exist
    $stmt = $pdo->prepare("SHOW COLUMNS FROM position_promotion_requirements LIKE 'dpjp_minor'");
    $stmt->execute();
    $dpjpMinorExists = $stmt->fetch() !== false;
    $debugLog .= "dpjp_minor column exists: " . var_export($dpjpMinorExists, true) . "\n";
    
    $stmt = $pdo->prepare("SHOW COLUMNS FROM position_promotion_requirements LIKE 'dpjp_major'");
    $stmt->execute();
    $dpjpMajorExists = $stmt->fetch() !== false;
    $debugLog .= "dpjp_major column exists: " . var_export($dpjpMajorExists, true) . "\n";

    $stmt = $pdo->prepare("
        INSERT INTO position_promotion_requirements
            (from_position, to_position, min_days_since_join, min_operations, min_operations_minor, min_operations_major, dpjp_minor, dpjp_major, notes, required_documents, operation_type, operation_role, is_active, updated_by, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ON DUPLICATE KEY UPDATE
            min_days_since_join = VALUES(min_days_since_join),
            min_operations = VALUES(min_operations),
            min_operations_minor = VALUES(min_operations_minor),
            min_operations_major = VALUES(min_operations_major),
            dpjp_minor = VALUES(dpjp_minor),
            dpjp_major = VALUES(dpjp_major),
            notes = VALUES(notes),
            required_documents = VALUES(required_documents),
            operation_type = VALUES(operation_type),
            operation_role = VALUES(operation_role),
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
        $minOpsMinor = isset($val['min_operations_minor']) && $val['min_operations_minor'] !== ''
            ? (int)$val['min_operations_minor']
            : null;
        $minOpsMajor = isset($val['min_operations_major']) && $val['min_operations_major'] !== ''
            ? (int)$val['min_operations_major']
            : null;
        $dpjpMinor = isset($val['dpjp_minor']) ? 1 : 0;
        $dpjpMajor = isset($val['dpjp_major']) ? 1 : 0;
        
        // Debug: log dpjp values
        $debugLog .= "DEBUG: dpjp_minor raw: " . var_export(isset($val['dpjp_minor']), true) . ", dpjp_minor: " . $dpjpMinor . "\n";
        $debugLog .= "DEBUG: dpjp_major raw: " . var_export(isset($val['dpjp_major']), true) . ", dpjp_major: " . $dpjpMajor . "\n";
        
        $notes = isset($val['notes']) ? trim((string)$val['notes']) : null;
        if ($notes === '') $notes = null;
        
        // Handle required_documents as array (checkboxes)
        $requiredDocs = isset($val['required_documents']) && is_array($val['required_documents'])
            ? implode(',', array_filter(array_map('trim', $val['required_documents'])))
            : null;
        if ($requiredDocs === '') $requiredDocs = null;
        
        $operationType = isset($val['operation_type']) ? trim((string)$val['operation_type']) : null;
        if ($operationType === '') $operationType = null;
        $operationRole = isset($val['operation_role']) ? trim((string)$val['operation_role']) : null;
        if ($operationRole === '') $operationRole = null;

        $stmt->execute([
            $from,
            $to,
            $minDays,
            $minOps,
            $minOpsMinor,
            $minOpsMajor,
            $dpjpMinor,
            $dpjpMajor,
            $notes,
            $requiredDocs,
            $operationType,
            $operationRole,
            $userId ?: null,
        ]);
        
        // Debug: log query execution
        $debugLog .= "DEBUG: Executed for $key - dpjp_minor=$dpjpMinor, dpjp_major=$dpjpMajor\n";
    }

    $pdo->commit();
    $_SESSION['flash_messages'][] = 'Syarat kenaikan jabatan berhasil diperbarui.';
    
    // Debug: verify database values after commit
    $stmt = $pdo->prepare("SELECT from_position, to_position, dpjp_minor, dpjp_major FROM position_promotion_requirements");
    $stmt->execute();
    $allReqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debugLog .= "DEBUG: Database values after commit:\n";
    foreach ($allReqs as $req) {
        $debugLog .= "  {$req['from_position']}:{$req['to_position']} - dpjp_minor={$req['dpjp_minor']}, dpjp_major={$req['dpjp_major']}\n";
    }
    
    // Write debug log to file
    file_put_contents(__DIR__ . '/../logs/debug_persyaratan.log', $debugLog, FILE_APPEND);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_errors'][] = 'Gagal menyimpan: ' . $e->getMessage();
    
    // Write debug log to file even on error
    file_put_contents(__DIR__ . '/../logs/debug_persyaratan.log', $debugLog . "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}

header('Location: persyaratan_jabatan.php');
exit;

