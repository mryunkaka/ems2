<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

function tanggalIndo($date)
{
    if (!$date) return '-';

    $bulan = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];

    $exp = explode('-', $date);
    return (int)$exp[2] . ' ' . $bulan[(int)$exp[1]] . ' ' . $exp[0];
}

/*
|--------------------------------------------------------------------------
| HARD GUARD
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';

/*
|--------------------------------------------------------------------------
| AMBIL USER SESSION
|--------------------------------------------------------------------------
*/
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| VALIDASI BATAS 25 HARI (SERVER SIDE)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT tanggal
    FROM medic_operasi_plastik
    WHERE id_user = ?
    ORDER BY tanggal DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$lastTanggal = $stmt->fetchColumn();

if ($lastTanggal) {
    $lastDate = new DateTime($lastTanggal);
    $today    = new DateTime(date('Y-m-d'));
    $diffHari = $today->diff($lastDate)->days;

    if ($diffHari < 25) {
        $sisa = 25 - $diffHari;
        $_SESSION['flash_errors'][] =
            "Operasi plastik hanya bisa dilakukan 1 bulan sekali. Tunggu {$sisa} hari lagi.";
        header('Location: operasi_plastik.php');
        exit;
    }
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid. Silakan login ulang.';
    header('Location: operasi_plastik.php');
    exit;
}
/*
|--------------------------------------------------------------------------
| APPROVE / REJECT OPERASI PLASTIK (POST DARI MODAL)
|--------------------------------------------------------------------------
*/
if (
    isset($_POST['action'], $_POST['id'])
    && in_array($_POST['action'], ['approve', 'reject'], true)
) {
    $opId   = (int) $_POST['id'];
    $action = $_POST['action'];

    // hanya non-staff yang boleh approve / reject
    if (strtolower($user['role'] ?? '') === 'staff') {
        $_SESSION['flash_errors'][] = 'Anda tidak memiliki akses.';
        header('Location: operasi_plastik.php');
        exit;
    }

    // ambil data operasi
    $stmt = $pdo->prepare("
        SELECT status
        FROM medic_operasi_plastik
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$opId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        $_SESSION['flash_errors'][] = 'Data operasi tidak ditemukan.';
        header('Location: operasi_plastik.php');
        exit;
    }

    if ($data['status'] !== 'pending') {
        $_SESSION['flash_errors'][] = 'Data ini sudah diproses.';
        header('Location: operasi_plastik.php');
        exit;
    }

    // tentukan status
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';

    $stmt = $pdo->prepare("
        UPDATE medic_operasi_plastik
        SET
            status = ?,
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $userId, $opId]);

    $_SESSION['flash_messages'][] =
        $action === 'approve'
        ? 'Operasi plastik berhasil di-approve.'
        : 'Operasi plastik berhasil di-reject.';

    header('Location: operasi_plastik.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| APPROVE OPERASI PLASTIK
|--------------------------------------------------------------------------
*/
if (
    ($_GET['action'] ?? '') === 'approve'
    && isset($_GET['id'])
) {
    $opId = (int) $_GET['id'];

    // hanya non-staff yang boleh approve
    if (strtolower($user['role'] ?? '') === 'staff') {
        $_SESSION['flash_errors'][] = 'Anda tidak memiliki akses untuk approve.';
        header('Location: operasi_plastik.php');
        exit;
    }

    // pastikan data ada & masih pending
    $stmt = $pdo->prepare("
        SELECT status
        FROM medic_operasi_plastik
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$opId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        $_SESSION['flash_errors'][] = 'Data operasi tidak ditemukan.';
        header('Location: operasi_plastik.php');
        exit;
    }

    if ($data['status'] !== 'pending') {
        $_SESSION['flash_errors'][] = 'Data ini sudah diproses.';
        header('Location: operasi_plastik.php');
        exit;
    }

    // update approve
    $stmt = $pdo->prepare("
        UPDATE medic_operasi_plastik
        SET
            status = 'approved',
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId, $opId]);

    $_SESSION['flash_messages'][] = 'Operasi plastik berhasil di-approve.';
    header('Location: operasi_plastik.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL INPUT FORM
|--------------------------------------------------------------------------
*/
$tanggal             = $_POST['tanggal'] ?? '';
$jenisOperasi        = $_POST['jenis_operasi'] ?? '';
$alasan              = trim($_POST['alasan'] ?? '');
$idPenanggungJawab   = (int)($_POST['id_penanggung_jawab'] ?? 0);

/*
|--------------------------------------------------------------------------
| VALIDASI DASAR
|--------------------------------------------------------------------------
*/
if (
    empty($tanggal) ||
    empty($jenisOperasi) ||
    empty($alasan) ||
    $idPenanggungJawab <= 0
) {
    $_SESSION['flash_errors'][] = 'Semua field wajib diisi.';
    header('Location: operasi_plastik.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDASI JENIS OPERASI
|--------------------------------------------------------------------------
*/
$allowedJenis = [
    'Rekonstruksi Wajah',
    'Suntik Putih'
];

if (!in_array($jenisOperasi, $allowedJenis, true)) {
    $_SESSION['flash_errors'][] = 'Jenis operasi tidak valid.';
    header('Location: operasi_plastik.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDASI PENANGGUNG JAWAB (MIN CO.AST)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id
    FROM user_rh
    WHERE id = ?
      AND position IN ('(Co.Ast)', 'Dokter Umum', 'Dokter Spesialis')
    LIMIT 1
");
$stmt->execute([$idPenanggungJawab]);

if (!$stmt->fetch()) {
    $_SESSION['flash_errors'][] = 'Penanggung jawab tidak valid.';
    header('Location: operasi_plastik.php');
    exit;
}
$pdo->beginTransaction();
try {
    /*
|--------------------------------------------------------------------------
| INSERT DATA OPERASI PLASTIK
|--------------------------------------------------------------------------
*/
    $stmt = $pdo->prepare("
    INSERT INTO medic_operasi_plastik
        (id_user, tanggal, jenis_operasi, alasan, id_penanggung_jawab)
    VALUES
        (?, ?, ?, ?, ?)
");

    $stmt->execute([
        $userId,
        $tanggal,
        $jenisOperasi,
        $alasan,
        $idPenanggungJawab
    ]);

    /*
|--------------------------------------------------------------------------
| INBOX UNTUK PENANGGUNG JAWAB OPERASI
|--------------------------------------------------------------------------
*/

    $stmt = $pdo->prepare("
    SELECT full_name
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
    $stmt->execute([$userId]);
    $namaPengaju = $stmt->fetchColumn() ?: 'User';

    $title = '🩺 Permohonan Operasi Plastik';
    $message = "
<b>Pengaju:</b> {$namaPengaju}<br>
<b>Jenis Operasi:</b> {$jenisOperasi}<br>
<b>Tanggal:</b> " . tanggalIndo($tanggal) . "<br>
<b>Alasan:</b><br>{$alasan}
";

    $stmt = $pdo->prepare("
    INSERT INTO user_inbox
        (user_id, title, message, type, is_read, created_at)
    VALUES
        (?, ?, ?, 'operasi', 0, NOW())
");
    $stmt->execute([
        $idPenanggungJawab,
        $title,
        $message
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
/*
|--------------------------------------------------------------------------
| PUSH NOTIFICATION KE PENANGGUNG JAWAB
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id AS user_id, full_name
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$idPenanggungJawab]);

$PUSH_USERS = [];

if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $PUSH_USERS[] = $u;
}

if (!empty($PUSH_USERS)) {
    $PUSH_TYPE = 'operasi_plastik_request';
    require __DIR__ . '/../actions/push_send.php';
}

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/
$_SESSION['flash_messages'][] = 'Data operasi plastik berhasil disimpan.';
$_SESSION['flash_messages'][] =
    'Permohonan telah dikirim ke penanggung jawab untuk ditinjau.';


/*
|--------------------------------------------------------------------------
| REDIRECT (PRG PATTERN)
|--------------------------------------------------------------------------
*/
header('Location: operasi_plastik.php');
exit;
