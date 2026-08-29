<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/announcement.php';

$redirectTo = '/dashboard/announcement_manage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

ems_announcement_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$unitCode = ems_effective_unit($pdo, $user);
$canTargetAll = ems_announcement_can_target_all($user);

if (!ems_announcement_can_manage($user)) {
    $_SESSION['flash_errors'][] = 'Hanya manager ke atas yang bisa mengelola pengumuman.';
    header('Location: ' . $redirectTo);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $targetType = trim((string)($_POST['target_type'] ?? 'scope'));
        $frequency = trim((string)($_POST['frequency'] ?? 'once'));

        if ($title === '' || $message === '') {
            throw new RuntimeException('Judul dan pesan wajib diisi.');
        }
        if (!in_array($frequency, ['once', 'every_login', 'every_visit'], true)) {
            throw new RuntimeException('Frekuensi tidak valid.');
        }

        $data = ['title' => $title, 'message' => $message, 'frequency' => $frequency];

        if ($targetType === 'user') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            if ($targetUserId <= 0) {
                throw new RuntimeException('Pilih user tujuan lewat pencarian nama.');
            }
            $stmt = $pdo->prepare("SELECT full_name FROM user_rh WHERE id = ? LIMIT 1");
            $stmt->execute([$targetUserId]);
            $targetName = $stmt->fetchColumn();
            if (!$targetName) {
                throw new RuntimeException('User tujuan tidak ditemukan.');
            }
            $data['target_type'] = 'user';
            $data['target_user_id'] = $targetUserId;
            $data['target_user_name_snapshot'] = $targetName;
        } else {
            $scope = ems_normalize_division_scope((string)($_POST['target_scope'] ?? ''));
            $broadValues = [ems_all_division_scope_value(), ems_management_division_scope_value()];
            if (in_array($scope, $broadValues, true) && !$canTargetAll) {
                throw new RuntimeException('Hanya Executive yang bisa mengirim pengumuman ke Semua User/Semua Division Manajemen.');
            }
            $data['target_type'] = 'scope';
            $data['target_scope'] = $scope;
        }

        $newId = ems_announcement_create($pdo, $unitCode, $data, $user);
        $_SESSION['flash_messages'][] = 'Pengumuman "' . $title . '" berhasil dikirim (ID #' . $newId . ').';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'quick_broadcast_update') {
        if (!$canTargetAll) {
            throw new RuntimeException('Hanya Executive yang bisa broadcast ke semua user.');
        }

        $data = [
            'title' => 'Ada Update Baru',
            'message' => 'Website baru saja diperbarui oleh tim developer. Silakan refresh/muat ulang halaman untuk mendapatkan versi terbaru.',
            'target_type' => 'scope',
            'target_scope' => ems_all_division_scope_value(),
            'frequency' => 'once',
        ];
        ems_announcement_create($pdo, $unitCode, $data, $user);
        $_SESSION['flash_messages'][] = 'Broadcast "Ada Update Baru" berhasil dikirim ke semua user.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT is_active FROM announcements WHERE id = ? AND unit_code = ? LIMIT 1");
        $stmt->execute([$id, $unitCode]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('Pengumuman tidak ditemukan.');
        }

        ems_announcement_set_active($pdo, $unitCode, $id, !((int)$current === 1));
        $_SESSION['flash_messages'][] = 'Status pengumuman berhasil diperbarui.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        ems_announcement_delete($pdo, $unitCode, $id);
        $_SESSION['flash_messages'][] = 'Pengumuman berhasil dihapus.';
        header('Location: ' . $redirectTo);
        exit;
    }

    throw new RuntimeException('Aksi tidak dikenali.');
} catch (\Throwable $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
    header('Location: ' . $redirectTo);
    exit;
}
