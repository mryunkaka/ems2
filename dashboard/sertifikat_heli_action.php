<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/csrf.php';

$redirectTo = ems_url('/dashboard/sertifikat_heli_pendaftaran.php');
$action = trim((string)($_POST['action'] ?? ''));
$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
$userDivision = ems_normalize_division($_SESSION['user_rh']['division'] ?? '');

// Only require General Affair access for save_settings action
if ($action === 'save_settings') {
    if ($userDivision !== 'General Affair') {
        $_SESSION['flash_errors'][] = 'Anda tidak memiliki akses untuk menyimpan setting.';
        header('Location: ' . $redirectTo);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_errors'][] = 'Method tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_errors'][] = 'CSRF token tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

// Check if tables exist
$tablesExist = $pdo->query("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('sertifikat_heli_settings', 'sertifikat_heli_registrations')
")->fetchColumn() == 2;

if (!$tablesExist) {
    $_SESSION['flash_errors'][] = 'Tabel sertifikat heli belum tersedia. Jalankan migration SQL terlebih dahulu.';
    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'register') {
    // Get current settings
    $settingsStmt = $pdo->query("SELECT * FROM sertifikat_heli_settings ORDER BY id DESC LIMIT 1");
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $_SESSION['flash_errors'][] = 'Belum ada setting pendaftaran sertifikat heli.';
        header('Location: ' . $redirectTo);
        exit;
    }

    $now = new DateTime();
    $startDatetime = new DateTime($settings['start_datetime']);
    $endDatetime = new DateTime($settings['end_datetime']);
    $maxSlots = (int)$settings['max_slots'];
    $minJabatan = trim($settings['min_jabatan'] ?? '');

    // Check if registration is open
    if ($now < $startDatetime) {
        $_SESSION['flash_errors'][] = 'Pendaftaran belum dibuka.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($now > $endDatetime) {
        $_SESSION['flash_errors'][] = 'Pendaftaran sudah ditutup.';
        header('Location: ' . $redirectTo);
        exit;
    }

    // Check if slots are available
    $countStmt = $pdo->query("SELECT COUNT(*) FROM sertifikat_heli_registrations WHERE status = 'registered'");
    $registeredCount = (int)$countStmt->fetchColumn();

    if ($registeredCount >= $maxSlots) {
        $_SESSION['flash_errors'][] = 'Slot pendaftaran sudah penuh.';
        header('Location: ' . $redirectTo);
        exit;
    }

    // Check if user already registered
    $checkStmt = $pdo->prepare("SELECT id FROM sertifikat_heli_registrations WHERE user_id = ?");
    $checkStmt->execute([$userId]);
    if ($checkStmt->fetch()) {
        $_SESSION['flash_errors'][] = 'Anda sudah terdaftar untuk pendaftaran sertifikat heli.';
        header('Location: ' . $redirectTo);
        exit;
    }

    // Check position requirement
    if ($minJabatan !== '') {
        $userStmt = $pdo->prepare("SELECT position FROM user_rh WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $userPosition = $user['position'] ?? '';

        $allowedPositions = array_map('trim', explode(',', $minJabatan));
        if (!in_array($userPosition, $allowedPositions, true)) {
            $_SESSION['flash_errors'][] = 'Jabatan Anda tidak memenuhi syarat untuk mendaftar.';
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    // Get user details
    $userStmt = $pdo->prepare("
        SELECT
            full_name,
            position,
            division
        FROM user_rh
        WHERE id = ?
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['flash_errors'][] = 'Data user tidak ditemukan.';
        header('Location: ' . $redirectTo);
        exit;
    }

    try {
        // Insert registration
        $stmt = $pdo->prepare("
            INSERT INTO sertifikat_heli_registrations
            (user_id, user_name, user_jabatan, user_division, status)
            VALUES (?, ?, ?, ?, 'registered')
        ");

        $stmt->execute([
            $userId,
            $user['full_name'],
            $user['position'],
            $user['division'] ?? null,
        ]);

        $_SESSION['flash_messages'][] = 'Pendaftaran sertifikat heli berhasil!';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Gagal mendaftar: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'save_settings') {
    $startDatetime = trim((string)($_POST['start_datetime'] ?? ''));
    $endDatetime = trim((string)($_POST['end_datetime'] ?? ''));
    $maxSlots = (int)($_POST['max_slots'] ?? 10);
    $minJabatan = trim((string)($_POST['min_jabatan'] ?? ''));

    // Validate inputs
    if ($startDatetime === '' || $endDatetime === '') {
        $_SESSION['flash_errors'][] = 'Tanggal dan jam mulai serta selesai wajib diisi.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($maxSlots < 1 || $maxSlots > 100) {
        $_SESSION['flash_errors'][] = 'Maksimal pendaftaran harus antara 1-100.';
        header('Location: ' . $redirectTo);
        exit;
    }

    $start = new DateTime($startDatetime);
    $end = new DateTime($endDatetime);

    if ($end <= $start) {
        $_SESSION['flash_errors'][] = 'Tanggal dan jam selesai harus setelah tanggal dan jam mulai.';
        header('Location: ' . $redirectTo);
        exit;
    }

    try {
        // Delete existing settings (only one row allowed)
        $pdo->exec("DELETE FROM sertifikat_heli_settings");

        // Insert new settings
        $stmt = $pdo->prepare("
            INSERT INTO sertifikat_heli_settings
            (start_datetime, end_datetime, max_slots, min_jabatan)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $startDatetime,
            $endDatetime,
            $maxSlots,
            $minJabatan !== '' ? $minJabatan : null,
        ]);

        $_SESSION['flash_messages'][] = 'Setting sertifikat heli berhasil disimpan.';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Gagal menyimpan setting sertifikat heli: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

$_SESSION['flash_errors'][] = 'Action tidak dikenali.';
header('Location: ' . $redirectTo);
exit;
