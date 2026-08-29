<?php

/**
 * Announcement / push-notification-modal module ("Kelola Pengumuman").
 * Modal pop-up broadcast ke user tertentu (bukan cuma inbox biasa) —
 * dipanggil dari partials/header.php di setiap halaman dashboard, mirip
 * pola modal "Ulang Tahun Hari Ini" yang sudah ada, tapi dikontrol admin
 * (target + frekuensi tampil bisa diatur), bukan otomatis dari tanggal lahir.
 */

require_once __DIR__ . '/helpers.php';

function ems_announcement_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `announcements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `target_type` enum('scope','user') NOT NULL DEFAULT 'scope',
            `target_scope` varchar(60) DEFAULT NULL,
            `target_user_id` int(11) DEFAULT NULL,
            `target_user_name_snapshot` varchar(150) DEFAULT NULL,
            `frequency` enum('once','every_login','every_visit') NOT NULL DEFAULT 'once',
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_by` int(11) DEFAULT NULL,
            `created_by_name_snapshot` varchar(150) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_announcements_unit_active` (`unit_code`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `announcement_dismissals` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `announcement_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `dismissed_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_announcement_dismissal` (`announcement_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

// ===================================================================
// Permission
// ===================================================================

function ems_announcement_can_manage(array $user): bool
{
    return ems_is_manager_plus_role($user['role'] ?? '');
}

// Broadcast ke "Semua User"/"Semua Division" (scope terluas) dibatasi
// Executive-manager-plus saja — blast radius-nya lintas division, sama
// pola dengan Executive-only actions di modul Dokumen.
function ems_announcement_can_target_all(array $user): bool
{
    return ems_normalize_division($user['division'] ?? '') === 'Executive'
        && ems_is_manager_plus_role($user['role'] ?? '');
}

// ===================================================================
// CRUD
// ===================================================================

function ems_announcement_create(PDO $pdo, string $unitCode, array $data, array $actor): int
{
    $stmt = $pdo->prepare("
        INSERT INTO announcements (
            unit_code, title, message, target_type, target_scope, target_user_id,
            target_user_name_snapshot, frequency, is_active, created_by, created_by_name_snapshot,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $unitCode,
        $data['title'],
        $data['message'],
        $data['target_type'],
        $data['target_scope'] ?? null,
        $data['target_user_id'] ?? null,
        $data['target_user_name_snapshot'] ?? null,
        $data['frequency'],
        (int)($actor['id'] ?? 0) ?: null,
        trim((string)($actor['full_name'] ?? $actor['name'] ?? '')),
    ]);

    $newId = (int)$pdo->lastInsertId();
    ems_announcement_mirror_to_inbox($pdo, $unitCode, $newId, $data);

    return $newId;
}

// Setiap pengumuman juga disalin ke inbox tiap user yang ditarget (snapshot
// audiens saat pengumuman dibuat), supaya tetap ada jejaknya walau
// modalnya sudah ditutup/tidak muncul lagi (mis. karena frequency=once).
function ems_announcement_mirror_to_inbox(PDO $pdo, string $unitCode, int $announcementId, array $data): void
{
    require_once __DIR__ . '/inbox_helper.php';

    $title = 'Pengumuman: ' . (string)$data['title'];
    $message = (string)$data['message'];

    if ($data['target_type'] === 'user') {
        $targetUserId = (int)($data['target_user_id'] ?? 0);
        if ($targetUserId > 0) {
            sendInbox($pdo, $targetUserId, $title, $message, 'announcement');
        }
        return;
    }

    $scope = (string)($data['target_scope'] ?? '');
    $unitColumnExists = ems_column_exists($pdo, 'user_rh', 'unit_code');
    $stmt = $unitColumnExists
        ? $pdo->prepare("SELECT id, division FROM user_rh WHERE is_active = 1 AND COALESCE(unit_code, 'roxwood') = ?")
        : $pdo->prepare("SELECT id, division FROM user_rh WHERE is_active = 1");
    $stmt->execute($unitColumnExists ? [$unitCode] : []);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (ems_division_scope_matches_division($scope, (string)$row['division'])) {
            sendInbox($pdo, (int)$row['id'], $title, $message, 'announcement');
        }
    }
}

function ems_announcement_fetch_all(PDO $pdo, string $unitCode): array
{
    $stmt = $pdo->prepare("
        SELECT a.*,
               (SELECT COUNT(*) FROM announcement_dismissals ad WHERE ad.announcement_id = a.id) AS dismissal_count
        FROM announcements a
        WHERE a.unit_code = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$unitCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ems_announcement_set_active(PDO $pdo, string $unitCode, int $id, bool $active): bool
{
    $stmt = $pdo->prepare("UPDATE announcements SET is_active = ?, updated_at = NOW() WHERE id = ? AND unit_code = ?");
    $stmt->execute([$active ? 1 : 0, $id, $unitCode]);
    return $stmt->rowCount() > 0;
}

function ems_announcement_delete(PDO $pdo, string $unitCode, int $id): bool
{
    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ? AND unit_code = ?");
    $stmt->execute([$id, $unitCode]);
    $pdo->prepare("DELETE FROM announcement_dismissals WHERE announcement_id = ?")->execute([$id]);
    return $stmt->rowCount() > 0;
}

function ems_announcement_target_label(array $announcement): string
{
    if ($announcement['target_type'] === 'user') {
        return 'User: ' . (string)($announcement['target_user_name_snapshot'] ?: '-');
    }

    $scope = (string)($announcement['target_scope'] ?? '');
    foreach (ems_division_scope_options() as $opt) {
        if ($opt['value'] === $scope) {
            return (string)$opt['label'];
        }
    }
    return $scope !== '' ? $scope : '-';
}

function ems_announcement_frequency_label(string $frequency): string
{
    return match ($frequency) {
        'once' => '1x tampil',
        'every_login' => 'Setiap login',
        'every_visit' => 'Setiap buka halaman',
        default => $frequency,
    };
}

// ===================================================================
// Resolusi tampilan — dipanggil sekali di partials/header.php tiap
// page-load. Efek samping yang disengaja: begitu satu pengumuman
// diputuskan untuk tampil, statusnya langsung ditandai "sudah
// ditampilkan" di sini juga (bukan lewat AJAX terpisah saat modal
// ditutup) — lebih sederhana & tidak bergantung JS benar-benar sempat
// mengirim request dismiss sebelum user pindah halaman.
// ===================================================================

function ems_announcement_matches_user(array $announcement, array $user): bool
{
    if ($announcement['target_type'] === 'user') {
        return (int)($announcement['target_user_id'] ?? 0) === (int)($user['id'] ?? 0);
    }

    return ems_division_scope_matches_division((string)($announcement['target_scope'] ?? ''), (string)($user['division'] ?? ''));
}

function ems_announcement_mark_dismissed(PDO $pdo, int $announcementId, int $userId): void
{
    $stmt = $pdo->prepare("INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id, dismissed_at) VALUES (?, ?, NOW())");
    $stmt->execute([$announcementId, $userId]);
}

function ems_announcement_is_dismissed(PDO $pdo, int $announcementId, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM announcement_dismissals WHERE announcement_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$announcementId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function ems_announcement_resolve_for_display(PDO $pdo, array $user, string $unitCode): ?array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE unit_code = ? AND is_active = 1 ORDER BY created_at DESC");
    $stmt->execute([$unitCode]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as $announcement) {
        if (!ems_announcement_matches_user($announcement, $user)) {
            continue;
        }

        $announcementId = (int)$announcement['id'];
        $frequency = (string)$announcement['frequency'];

        if ($frequency === 'once') {
            if (ems_announcement_is_dismissed($pdo, $announcementId, $userId)) {
                continue;
            }
            ems_announcement_mark_dismissed($pdo, $announcementId, $userId);
            return $announcement;
        }

        if ($frequency === 'every_login') {
            $sessionKey = 'ems_announcement_shown_' . $announcementId;
            if (!empty($_SESSION[$sessionKey])) {
                continue;
            }
            $_SESSION[$sessionKey] = true;
            return $announcement;
        }

        // every_visit — selalu tampil, tanpa penanda apa pun.
        return $announcement;
    }

    return null;
}
