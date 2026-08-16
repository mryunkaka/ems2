<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/forensic_private_access.php';

ems_forensic_private_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];

if (!ems_forensic_private_can_manage_access($user)) {
    $_SESSION['flash_errors'][] = 'Anda tidak memiliki akses untuk mengelola izin grup Forensic.';
    header('Location: forensic_private_access_manage.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_errors'][] = 'Token keamanan form tidak valid atau sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    header('Location: forensic_private_access_manage.php');
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));

try {
    if ($action === 'save') {
        $medicUserId = (int) ($_POST['medic_user_id'] ?? 0);
        if ($medicUserId <= 0) {
            throw new Exception('Pilih nama medis terlebih dahulu.');
        }

        $perms = [
            // Rekam Medis Private
            'can_view_all' => !empty($_POST['can_view_all']),
            'can_view_own' => !empty($_POST['can_view_own']),
            'can_create' => !empty($_POST['can_create']),
            'can_edit' => !empty($_POST['can_edit']),
            'can_delete' => !empty($_POST['can_delete']),
            // List Medis (toggle sederhana, roster read-only)
            'can_view_forensic_medics' => !empty($_POST['can_view_forensic_medics']),
            // Data Pasien Private
            'patients_view_all' => !empty($_POST['patients_view_all']),
            'patients_view_own' => !empty($_POST['patients_view_own']),
            'patients_create' => !empty($_POST['patients_create']),
            'patients_edit' => !empty($_POST['patients_edit']),
            'patients_delete' => !empty($_POST['patients_delete']),
            // Hasil Visum
            'visum_view_all' => !empty($_POST['visum_view_all']),
            'visum_view_own' => !empty($_POST['visum_view_own']),
            'visum_create' => !empty($_POST['visum_create']),
            'visum_edit' => !empty($_POST['visum_edit']),
            'visum_delete' => !empty($_POST['visum_delete']),
            // Arsip Forensic
            'archive_view_all' => !empty($_POST['archive_view_all']),
            'archive_view_own' => !empty($_POST['archive_view_own']),
            'archive_create' => !empty($_POST['archive_create']),
            'archive_edit' => !empty($_POST['archive_edit']),
            'archive_delete' => !empty($_POST['archive_delete']),
        ];

        if (!in_array(true, $perms, true)) {
            throw new Exception('Pilih minimal satu izin/halaman untuk medis ini.');
        }

        ems_forensic_private_save_grant($pdo, $medicUserId, $perms, $user);
        $_SESSION['flash_messages'][] = 'Izin akses grup Forensic berhasil disimpan.';
    } elseif ($action === 'revoke') {
        $medicUserId = (int) ($_POST['medic_user_id'] ?? 0);
        if ($medicUserId <= 0) {
            throw new Exception('Medic tidak valid.');
        }

        ems_forensic_private_revoke_grant($pdo, $medicUserId);
        $_SESSION['flash_messages'][] = 'Izin akses grup Forensic berhasil dicabut.';
    } else {
        throw new Exception('Aksi tidak dikenali.');
    }
} catch (Exception $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
}

header('Location: forensic_private_access_manage.php');
exit;
