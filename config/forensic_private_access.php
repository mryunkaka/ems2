<?php

/**
 * Akses granular ke Rekam Medis Private (forensic_private) untuk medis di
 * luar division Forensic. Sebelum fitur ini, satu-satunya jalur akses adalah
 * blanket division check (ems_can_access_division_menu($division,'Forensic'))
 * — sekarang Head Manager Forensic / Director / Vice Director / Executive
 * bisa memberi izin spesifik (lihat semua, lihat punya sendiri, input, edit,
 * hapus) ke medis tertentu lewat tabel forensic_private_access_grants, tanpa
 * mengubah division mereka. Aturan akses "native" (division Forensic, dsb)
 * TIDAK berubah — fungsi di file ini murni MENAMBAH jalur akses baru di atas
 * aturan lama, bukan menggantikannya.
 */

function ems_forensic_private_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `forensic_private_access_grants` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `medic_user_id` int(11) NOT NULL,
            `medic_name_snapshot` varchar(150) DEFAULT NULL,
            `can_view_all` tinyint(1) NOT NULL DEFAULT 0,
            `can_view_own` tinyint(1) NOT NULL DEFAULT 0,
            `can_create` tinyint(1) NOT NULL DEFAULT 0,
            `can_edit` tinyint(1) NOT NULL DEFAULT 0,
            `can_delete` tinyint(1) NOT NULL DEFAULT 0,
            `granted_by` int(11) DEFAULT NULL,
            `granted_by_name_snapshot` varchar(150) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_forensic_private_access_medic` (`medic_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `forensic_private_record_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `medical_record_id` int(11) NOT NULL,
            `action` enum('created','viewed','edited','deleted') NOT NULL,
            `actor_user_id` int(11) DEFAULT NULL,
            `actor_name_snapshot` varchar(150) DEFAULT NULL,
            `notes` varchar(255) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_forensic_private_logs_record` (`medical_record_id`),
            KEY `idx_forensic_private_logs_record_created` (`medical_record_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Sengaja TIDAK memakai ems_column_exists() di sini — fungsi itu meng-cache
    // hasilnya di static array untuk sisa REQUEST yang sama. Kalau kita cek
    // "belum ada" lalu langsung ADD COLUMN di request yang sama, cache-nya
    // tetap ke-poison ke `false` untuk pemanggilan berikutnya (mis. saat
    // rekam_medis_action.php mau memutuskan kolom ini perlu di-INSERT atau
    // tidak) — bug yang sama seperti pada ems_table_exists() yang pernah
    // ditemukan di modul Cloudflare Workers AI (lihat CLAUDE.md §10). Query
    // INFORMATION_SCHEMA langsung di sini supaya tidak menyentuh cache itu
    // sama sekali.
    $columnCheckStmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'medical_records'
          AND COLUMN_NAME = 'visum_letter_file_path'
        LIMIT 1
    ");
    $columnCheckStmt->execute();
    if (!$columnCheckStmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE `medical_records` ADD COLUMN `visum_letter_file_path` VARCHAR(255) DEFAULT NULL AFTER `mri_file_path`");
    }
}

/**
 * Siapa yang boleh membuka modal "Kelola Akses Rekam Medis Private" dan
 * memberi/mencabut izin medis lain. Sesuai keputusan eksplisit: Head Manager
 * di division Forensic, ATAU role Director/Vice Director (divisi apa pun),
 * ATAU division Executive, ATAU superuser "Programmer Roxwood". Karena orang
 * yang bisa MENGELOLA akses logisnya juga harus bisa mengoperasikan modul ini
 * sepenuhnya, set yang sama ini JUGA dipakai sebagai "akses penuh native" di
 * ems_forensic_private_effective_permissions() di bawah.
 */
function ems_forensic_private_can_manage_access(array $user): bool
{
    $division = ems_normalize_division($user['division'] ?? '');
    $role = ems_normalize_role($user['role'] ?? '');
    $fullName = (string) ($user['full_name'] ?? $user['name'] ?? '');

    if ($division === 'Forensic' && $role === 'head manager') {
        return true;
    }

    if (ems_is_director_role($role)) {
        return true;
    }

    if ($division === 'Executive') {
        return true;
    }

    return ems_is_programmer_roxwood_name($fullName);
}

function ems_forensic_private_get_grant(PDO $pdo, int $medicUserId): ?array
{
    if ($medicUserId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM forensic_private_access_grants WHERE medic_user_id = ? LIMIT 1");
    $stmt->execute([$medicUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Daftar semua grant aktif (dipakai render tabel di modal Kelola Akses),
 * diurutkan dari yang terbaru diubah.
 */
function ems_forensic_private_list_grants(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT g.*, u.full_name AS medic_current_name, u.position AS medic_position, u.division AS medic_division
        FROM forensic_private_access_grants g
        LEFT JOIN user_rh u ON u.id = g.medic_user_id
        ORDER BY g.updated_at DESC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_forensic_private_save_grant(PDO $pdo, int $medicUserId, array $perms, array $grantedByUser): void
{
    if ($medicUserId <= 0) {
        throw new InvalidArgumentException('Medic tidak valid.');
    }

    $medicStmt = $pdo->prepare("SELECT full_name FROM user_rh WHERE id = ? LIMIT 1");
    $medicStmt->execute([$medicUserId]);
    $medicName = (string) ($medicStmt->fetchColumn() ?: '');
    if ($medicName === '') {
        throw new InvalidArgumentException('User medis tidak ditemukan.');
    }

    $canViewAll = !empty($perms['can_view_all']) ? 1 : 0;
    $canViewOwn = !empty($perms['can_view_own']) ? 1 : 0;
    $canCreate = !empty($perms['can_create']) ? 1 : 0;
    $canEdit = !empty($perms['can_edit']) ? 1 : 0;
    $canDelete = !empty($perms['can_delete']) ? 1 : 0;

    $grantedById = (int) ($grantedByUser['id'] ?? 0);
    $grantedByName = (string) ($grantedByUser['full_name'] ?? $grantedByUser['name'] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO forensic_private_access_grants
            (medic_user_id, medic_name_snapshot, can_view_all, can_view_own, can_create, can_edit, can_delete, granted_by, granted_by_name_snapshot)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            medic_name_snapshot = VALUES(medic_name_snapshot),
            can_view_all = VALUES(can_view_all),
            can_view_own = VALUES(can_view_own),
            can_create = VALUES(can_create),
            can_edit = VALUES(can_edit),
            can_delete = VALUES(can_delete),
            granted_by = VALUES(granted_by),
            granted_by_name_snapshot = VALUES(granted_by_name_snapshot)
    ");
    $stmt->execute([
        $medicUserId,
        $medicName,
        $canViewAll,
        $canViewOwn,
        $canCreate,
        $canEdit,
        $canDelete,
        $grantedById ?: null,
        $grantedByName !== '' ? $grantedByName : null,
    ]);
}

function ems_forensic_private_revoke_grant(PDO $pdo, int $medicUserId): void
{
    $stmt = $pdo->prepare("DELETE FROM forensic_private_access_grants WHERE medic_user_id = ?");
    $stmt->execute([$medicUserId]);
}

/**
 * Hitung izin efektif user saat ini terhadap modul Rekam Medis Private.
 * Akses "native" (division Forensic / Executive / Director / Vice Director /
 * Programmer Roxwood) selalu dapat akses penuh ke SEMUA rekam medis private
 * — aturan lama, tidak berubah. Selain itu, dicek tabel grant untuk medis
 * yang diberi izin spesifik. can_create/can_edit/can_delete otomatis
 * menyiratkan minimal bisa melihat record yang relevan (create -> lihat
 * punya sendiri; edit/delete -> ikut scope view_all/view_own yang sama)
 * supaya tidak ada kombinasi izin yang aneh (mis. bisa input tapi tidak
 * pernah bisa lihat hasil inputnya sendiri).
 */
function ems_forensic_private_effective_permissions(PDO $pdo, array $user): array
{
    $division = ems_normalize_division($user['division'] ?? '');

    if (ems_can_access_division_menu($division, 'Forensic') || ems_forensic_private_can_manage_access($user)) {
        return [
            'can_view_all' => true,
            'can_view_own' => true,
            'can_create' => true,
            'can_edit' => true,
            'can_delete' => true,
            'has_any_access' => true,
            'is_native' => true,
        ];
    }

    $userId = (int) ($user['id'] ?? 0);
    $grant = ems_forensic_private_get_grant($pdo, $userId);
    if (!$grant) {
        return [
            'can_view_all' => false,
            'can_view_own' => false,
            'can_create' => false,
            'can_edit' => false,
            'can_delete' => false,
            'has_any_access' => false,
            'is_native' => false,
        ];
    }

    $canViewAll = (bool) $grant['can_view_all'];
    $canCreate = (bool) $grant['can_create'];
    $canEdit = (bool) $grant['can_edit'];
    $canDelete = (bool) $grant['can_delete'];
    $canViewOwn = (bool) $grant['can_view_own'] || $canViewAll || $canCreate || $canEdit || $canDelete;

    return [
        'can_view_all' => $canViewAll,
        'can_view_own' => $canViewOwn,
        'can_create' => $canCreate,
        'can_edit' => $canEdit,
        'can_delete' => $canDelete,
        'has_any_access' => $canViewAll || $canViewOwn || $canCreate || $canEdit || $canDelete,
        'is_native' => false,
    ];
}

/**
 * Cek per-baris: kalau bukan can_view_all, edit/delete/view hanya berlaku
 * untuk record yang DIA sendiri buat (created_by cocok).
 */
function ems_forensic_private_can_view_row(array $perms, array $record, int $userId): bool
{
    if ($perms['can_view_all']) {
        return true;
    }

    return $perms['can_view_own'] && (int) ($record['created_by'] ?? 0) === $userId;
}

function ems_forensic_private_can_edit_row(array $perms, array $record, int $userId): bool
{
    if (!$perms['can_edit']) {
        return false;
    }

    return $perms['can_view_all'] || (int) ($record['created_by'] ?? 0) === $userId;
}

function ems_forensic_private_can_delete_row(array $perms, array $record, int $userId): bool
{
    if (!$perms['can_delete']) {
        return false;
    }

    return $perms['can_view_all'] || (int) ($record['created_by'] ?? 0) === $userId;
}

/**
 * Catat aktivitas (dibuat/dilihat/diedit/dihapus) ke forensic_private_record_logs.
 * $notes dipakai untuk snapshot ringkas — penting terutama untuk action
 * 'deleted' karena baris medical_records-nya akan hilang setelahnya, jadi log
 * ini jadi satu-satunya jejak yang tersisa.
 */
function ems_forensic_private_log_action(PDO $pdo, int $medicalRecordId, string $action, array $actor, string $notes = ''): void
{
    if ($medicalRecordId <= 0 || !in_array($action, ['created', 'viewed', 'edited', 'deleted'], true)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO forensic_private_record_logs (medical_record_id, action, actor_user_id, actor_name_snapshot, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $medicalRecordId,
        $action,
        (int) ($actor['id'] ?? 0) ?: null,
        trim((string) ($actor['full_name'] ?? $actor['name'] ?? '')) ?: null,
        $notes !== '' ? $notes : null,
    ]);
}

function ems_forensic_private_get_logs(PDO $pdo, int $medicalRecordId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM forensic_private_record_logs
        WHERE medical_record_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([$medicalRecordId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_forensic_private_action_label(string $action): string
{
    return match ($action) {
        'created' => 'Dibuat',
        'viewed' => 'Dilihat',
        'edited' => 'Diedit',
        'deleted' => 'Dihapus',
        default => ucfirst($action),
    };
}
