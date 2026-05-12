<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

/*
|--------------------------------------------------------------------------
| HARD GUARD & CONFIG
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| USER SESSION
|--------------------------------------------------------------------------
*/
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    http_response_code(403);
    exit('Session tidak valid');
}

/*
|--------------------------------------------------------------------------
| AMBIL & VALIDASI INPUT
|--------------------------------------------------------------------------
*/
$code  = trim($_POST['reimbursement_code'] ?? '');
$type  = trim($_POST['billing_source_type'] ?? '');
$name  = trim($_POST['billing_source_name'] ?? '');
$item  = trim($_POST['item_name'] ?? '');
$qty   = (int)($_POST['qty'] ?? 0);
$price = (int)($_POST['price'] ?? 0);

if (
    $code === '' ||
    $type === '' ||
    $name === '' ||
    $item === '' ||
    $qty <= 0 ||
    $price < 0
) {
    $_SESSION['flash_errors'][] = 'Data reimbursement tidak lengkap.';
    header('Location: reimbursement.php');
    exit;
}

$amount = $qty * $price;

/*
|--------------------------------------------------------------------------
| UPLOAD & COMPRESS BUKTI PEMBAYARAN (OPSIONAL)
|--------------------------------------------------------------------------
*/
$receiptPath = null;

if (
    isset($_FILES['receipt_file']) &&
    $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK
) {
    $tmp  = $_FILES['receipt_file']['tmp_name'];
    $info = getimagesize($tmp);

    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        $_SESSION['flash_errors'][] = 'Bukti pembayaran harus JPG atau PNG.';
        header('Location: reimbursement.php');
        exit;
    }

    // Folder per reimbursement_code
    $folder = __DIR__ . '/../storage/reimbursements/' . $code;

    if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
        $_SESSION['flash_errors'][] = 'Gagal membuat folder penyimpanan.';
        header('Location: reimbursement.php');
        exit;
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $target = $folder . '/receipt.' . $ext;

    if (!compressImageSmart($tmp, $target)) {
        $_SESSION['flash_errors'][] = 'Gagal memproses bukti pembayaran.';
        header('Location: reimbursement.php');
        exit;
    }

    $receiptPath = 'storage/reimbursements/' . $code . '/receipt.' . $ext;
}

/*
|--------------------------------------------------------------------------
| INSERT DATABASE
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    INSERT INTO reimbursements (
        reimbursement_code,
        billing_source_type,
        billing_source_name,
        item_name,
        qty,
        price,
        amount,
        receipt_file,
        status,
        created_by,
        submitted_at,
        created_at
    ) VALUES (
        ?,?,?,?,?,?,?,?,
        'submitted',
        ?,NOW(),NOW()
    )
");

$stmt->execute([
    $code,
    $type,
    $name,
    $item,
    $qty,
    $price,
    $amount,
    $receiptPath,
    $userId
]);

$_SESSION['flash_messages'][] = 'Reimbursement berhasil disimpan.';
header('Location: reimbursement.php?range=week4');
exit;
