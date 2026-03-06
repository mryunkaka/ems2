<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| ROLE GUARD
|--------------------------------------------------------------------------
*/
$role = $_SESSION['user_rh']['role'] ?? '';

// Staff tidak boleh kelola event
if ($role === 'Staff') {
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL & VALIDASI INPUT
|--------------------------------------------------------------------------
*/
$id        = (int)($_POST['event_id'] ?? 0);
$nama      = trim($_POST['nama_event'] ?? '');
$tanggal   = $_POST['tanggal_event'] ?? '';
$lokasi    = trim($_POST['lokasi'] ?? '');
$ket       = trim($_POST['keterangan'] ?? '');
$is_active = isset($_POST['is_active']) ? 1 : 0;

if ($nama === '' || $tanggal === '') {
    $_SESSION['flash_errors'][] = 'Nama event dan tanggal wajib diisi.';
    header('Location: event_manage.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| SIMPAN DATA (INSERT / UPDATE)
|--------------------------------------------------------------------------
*/
try {

    if ($id > 0) {
        // UPDATE
        $stmt = $pdo->prepare("
            UPDATE events SET
                nama_event    = ?,
                tanggal_event = ?,
                lokasi        = ?,
                keterangan    = ?,
                is_active     = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $nama,
            $tanggal,
            $lokasi,
            $ket,
            $is_active,
            $id
        ]);

        $_SESSION['flash_messages'][] = 'Event berhasil diperbarui.';
    } else {
        // INSERT
        $stmt = $pdo->prepare("
            INSERT INTO events
                (nama_event, tanggal_event, lokasi, keterangan, is_active)
            VALUES (?,?,?,?,?)
        ");
        $stmt->execute([
            $nama,
            $tanggal,
            $lokasi,
            $ket,
            $is_active
        ]);

        $_SESSION['flash_messages'][] = 'Event berhasil ditambahkan.';
    }
} catch (PDOException $e) {
    $_SESSION['flash_errors'][] = 'Gagal menyimpan data event.';
}

/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/
header('Location: event_manage.php');
exit;
