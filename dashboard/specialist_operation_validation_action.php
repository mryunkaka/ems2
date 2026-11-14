<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/inbox_helper.php';

ems_require_division_access(['Medical Affair'], '/dashboard/index.php');
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$back = '/dashboard/specialist_operation_recap.php#medical-record-validation';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken(csrfRequestToken())) {
    $_SESSION['flash_errors'][] = 'Permintaan tidak valid atau sesi kedaluwarsa.';
    header('Location: ' . $back);
    exit;
}

$id = (int) ($_POST['finding_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$stmt = $pdo->prepare("SELECT f.*, r.created_at, COALESCE(u.full_name,'Tenaga medis') AS subject_name FROM medical_record_validation_findings f INNER JOIN medical_records r ON r.id=f.medical_record_id LEFT JOIN user_rh u ON u.id=f.subject_user_id WHERE f.id=? AND f.status='pending' AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) LIMIT 1");
$stmt->execute([$id]);
$finding = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$finding) {
    $_SESSION['flash_errors'][] = 'Temuan sudah ditindaklanjuti atau tidak ditemukan.';
    header('Location: ' . $back);
    exit;
}

try {
    if ($action === 'ignore') {
        $pdo->prepare("UPDATE medical_record_validation_findings SET status='ignored' WHERE id=? AND status='pending'")->execute([$id]);
        $_SESSION['flash_messages'][] = 'Temuan ditandai untuk diabaikan.';
    } elseif ($action === 'notice') {
        if ((int) $finding['subject_user_id'] <= 0) throw new RuntimeException('Petugas medis terkait belum bisa ditentukan.');
        $message = 'Mohon periksa rekam medis #' . (int) $finding['medical_record_id'] . ': ' . (string) $finding['finding'] . ' Tindak lanjut: ' . (string) ($finding['recommendation'] ?? 'Periksa dan lengkapi data yang sesuai.');
        sendInbox($pdo, (int) $finding['subject_user_id'], 'Mohon periksa data rekam medis', $message, 'medical_record_validation');
        $pdo->prepare("UPDATE medical_record_validation_findings SET status='notice_sent' WHERE id=? AND status='pending'")->execute([$id]);
        $_SESSION['flash_messages'][] = 'Permintaan perbaikan data dikirim ke petugas terkait.';
    } elseif ($action === 'report') {
        $subjectId = (int) ($finding['subject_user_id'] ?? 0);
        if ($subjectId <= 0) throw new RuntimeException('Petugas medis terkait belum bisa ditentukan.');
        $reporterReason = trim((string)($_POST['reporter_reason'] ?? ''));
        if ($reporterReason === '' || mb_strlen($reporterReason) > 2000) throw new RuntimeException('Alasan pelapor wajib diisi dan maksimal 2.000 karakter.');
        $pdo->beginTransaction();
        $caseCode = 'MR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $summary = 'Temuan otomatis rekam medis #' . (int) $finding['medical_record_id'] . ":\n" . (string) $finding['finding'] . "\nTindak lanjut yang disarankan: " . (string) ($finding['recommendation'] ?? '-') . "\n\nAlasan pelapor:\n" . $reporterReason . "\n\nKeputusan pasal, poin, dan tindak lanjut diserahkan kepada Komdis. Temuan otomatis ini bukan kesimpulan pelanggaran.";
        $insert = $pdo->prepare("INSERT INTO disciplinary_cases (case_code,subject_user_id,case_name,case_date,summary,created_by,status,total_points,tolerable_count,non_tolerable_count,tolerance_summary,letter_status) VALUES (?,?,?,DATE(?),?,?,'open',0,0,0,'tolerable','not_needed')");
        $caseName = 'Tinjauan rekam medis #' . (int) $finding['medical_record_id'];
        $createdBy = (int) ($_SESSION['user_rh']['id'] ?? 0);
        $insert->execute([$caseCode, $subjectId, $caseName, (string) $finding['created_at'], $summary, $createdBy]);
        $caseId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE medical_record_validation_findings SET status='reported',disciplinary_case_id=? WHERE id=? AND status='pending'")->execute([$caseId, $id]);
        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Temuan dan alasan pelapor dikirim ke Komdis. Pasal dan poin belum ditetapkan; Komdis yang menentukannya.';
    } else {
        throw new RuntimeException('Tindakan tidak dikenal.');
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_errors'][] = 'Tindak lanjut gagal: ' . $error->getMessage();
}

header('Location: ' . $back);
exit;
