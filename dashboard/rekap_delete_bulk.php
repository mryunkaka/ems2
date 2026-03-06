<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

$ids = $_POST['ids'] ?? [];

if (!is_array($ids) || count($ids) === 0) {
    $_SESSION['flash_errors'][] = 'Tidak ada data yang dipilih';
    header('Location: ems_services.php');
    exit;
}

// sanitasi
$ids = array_map('intval', $ids);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare("DELETE FROM ems_sales WHERE id IN ($placeholders)");
$stmt->execute($ids);

$_SESSION['flash_messages'][] = count($ids) . ' data berhasil dihapus';

header('Location: ems_services.php');
exit;
