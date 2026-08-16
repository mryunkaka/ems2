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

// Sengaja TIDAK memakai ems_column_exists() di file ini — fungsi itu
// meng-cache hasilnya di static array untuk sisa REQUEST yang sama. Kalau
// ensure_tables() cek "belum ada" lalu langsung ADD COLUMN di request yang
// sama, cache-nya tetap ke-poison ke `false` untuk pemanggilan berikutnya
// (mis. saat rekam_medis_action.php mau memutuskan kolom itu perlu
// di-INSERT atau tidak) — bug yang sama seperti pada ems_table_exists() yang
// pernah ditemukan di modul Cloudflare Workers AI (lihat CLAUDE.md §10).
// Query INFORMATION_SCHEMA langsung di sini supaya tidak menyentuh cache itu
// sama sekali.
function ems_forensic_private_column_exists_raw(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);

    return (bool) $stmt->fetchColumn();
}

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
            `can_view_forensic_medics` tinyint(1) NOT NULL DEFAULT 0,
            `can_view_private_patients` tinyint(1) NOT NULL DEFAULT 0,
            `can_view_visum_results` tinyint(1) NOT NULL DEFAULT 0,
            `can_view_archive` tinyint(1) NOT NULL DEFAULT 0,
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

    if (!ems_forensic_private_column_exists_raw($pdo, 'medical_records', 'visum_letter_file_path')) {
        $pdo->exec("ALTER TABLE `medical_records` ADD COLUMN `visum_letter_file_path` VARCHAR(255) DEFAULT NULL AFTER `mri_file_path`");
    }

    // Defensif untuk instalasi yang tabelnya sudah dibuat sebelum migrasi 68
    // (4 kolom toggle akses per-halaman) / 69 (15 kolom CRUD granular untuk
    // Data Pasien Private, Hasil Visum, Arsip Forensic + resource_type log).
    foreach ([
        'can_view_forensic_medics', 'can_view_private_patients', 'can_view_visum_results', 'can_view_archive',
        'patients_view_all', 'patients_view_own', 'patients_create', 'patients_edit', 'patients_delete',
        'visum_view_all', 'visum_view_own', 'visum_create', 'visum_edit', 'visum_delete',
        'archive_view_all', 'archive_view_own', 'archive_create', 'archive_edit', 'archive_delete',
    ] as $column) {
        if (!ems_forensic_private_column_exists_raw($pdo, 'forensic_private_access_grants', $column)) {
            $pdo->exec("ALTER TABLE `forensic_private_access_grants` ADD COLUMN `{$column}` TINYINT(1) NOT NULL DEFAULT 0");
        }
    }

    if (!ems_forensic_private_column_exists_raw($pdo, 'forensic_private_record_logs', 'resource_type')) {
        $pdo->exec("ALTER TABLE `forensic_private_record_logs` ADD COLUMN `resource_type` VARCHAR(30) NOT NULL DEFAULT 'rekam_medis_private' AFTER `medical_record_id`");
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

    // Kolom CRUD granular berulang untuk 4 resource (Rekam Medis Private
    // pakai nama kolom lama tanpa prefix untuk backward-compat, 3 resource
    // baru pakai prefix eksplisit) — dibangun dari daftar supaya tidak
    // menulis ulang boilerplate INSERT/UPDATE yang sama 4x.
    $columnMap = [
        'can_view_all' => 'can_view_all', 'can_view_own' => 'can_view_own',
        'can_create' => 'can_create', 'can_edit' => 'can_edit', 'can_delete' => 'can_delete',
        'can_view_forensic_medics' => 'can_view_forensic_medics',
        'patients_view_all' => 'patients_view_all', 'patients_view_own' => 'patients_view_own',
        'patients_create' => 'patients_create', 'patients_edit' => 'patients_edit', 'patients_delete' => 'patients_delete',
        'visum_view_all' => 'visum_view_all', 'visum_view_own' => 'visum_view_own',
        'visum_create' => 'visum_create', 'visum_edit' => 'visum_edit', 'visum_delete' => 'visum_delete',
        'archive_view_all' => 'archive_view_all', 'archive_view_own' => 'archive_view_own',
        'archive_create' => 'archive_create', 'archive_edit' => 'archive_edit', 'archive_delete' => 'archive_delete',
    ];

    $columns = ['medic_user_id', 'medic_name_snapshot'];
    $placeholders = ['?', '?'];
    $values = [$medicUserId, $medicName];
    $updateParts = ['medic_name_snapshot = VALUES(medic_name_snapshot)'];

    foreach ($columnMap as $permKey => $column) {
        $columns[] = "`{$column}`";
        $placeholders[] = '?';
        $values[] = !empty($perms[$permKey]) ? 1 : 0;
        $updateParts[] = "`{$column}` = VALUES(`{$column}`)";
    }

    $grantedById = (int) ($grantedByUser['id'] ?? 0);
    $grantedByName = (string) ($grantedByUser['full_name'] ?? $grantedByUser['name'] ?? '');
    $columns[] = 'granted_by';
    $columns[] = 'granted_by_name_snapshot';
    $placeholders[] = '?';
    $placeholders[] = '?';
    $values[] = $grantedById ?: null;
    $values[] = $grantedByName !== '' ? $grantedByName : null;
    $updateParts[] = 'granted_by = VALUES(granted_by)';
    $updateParts[] = 'granted_by_name_snapshot = VALUES(granted_by_name_snapshot)';

    $stmt = $pdo->prepare("
        INSERT INTO forensic_private_access_grants (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $placeholders) . ")
        ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts) . "
    ");
    $stmt->execute($values);
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
 * Inti generik untuk menghitung izin CRUD granular ke salah satu dari 3
 * resource forensic lain (Data Pasien Private / Hasil Visum / Arsip
 * Forensic) — bentuknya identik dengan ems_forensic_private_effective_permissions()
 * di atas (yang khusus Rekam Medis Private, dipertahankan terpisah untuk
 * backward-compat karena nama kolomnya tidak berprefix), tapi dipakai ulang
 * lewat 1 implementasi untuk 3 resource sekaligus lewat $columnPrefix
 * ('patients'|'visum'|'archive') alih-alih copy-paste 3x.
 */
function ems_forensic_private_resource_permissions(PDO $pdo, array $user, string $columnPrefix): array
{
    $division = ems_normalize_division($user['division'] ?? '');

    if (ems_can_access_division_menu($division, 'Forensic') || ems_forensic_private_can_manage_access($user)) {
        return [
            'can_view_all' => true, 'can_view_own' => true, 'can_create' => true,
            'can_edit' => true, 'can_delete' => true, 'has_any_access' => true, 'is_native' => true,
        ];
    }

    $userId = (int) ($user['id'] ?? 0);
    $grant = ems_forensic_private_get_grant($pdo, $userId);
    if (!$grant) {
        return [
            'can_view_all' => false, 'can_view_own' => false, 'can_create' => false,
            'can_edit' => false, 'can_delete' => false, 'has_any_access' => false, 'is_native' => false,
        ];
    }

    $canViewAll = (bool) ($grant["{$columnPrefix}_view_all"] ?? false);
    $canCreate = (bool) ($grant["{$columnPrefix}_create"] ?? false);
    $canEdit = (bool) ($grant["{$columnPrefix}_edit"] ?? false);
    $canDelete = (bool) ($grant["{$columnPrefix}_delete"] ?? false);
    $canViewOwn = (bool) ($grant["{$columnPrefix}_view_own"] ?? false) || $canViewAll || $canCreate || $canEdit || $canDelete;

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

function ems_forensic_patients_permissions(PDO $pdo, array $user): array
{
    return ems_forensic_private_resource_permissions($pdo, $user, 'patients');
}

function ems_forensic_visum_permissions(PDO $pdo, array $user): array
{
    return ems_forensic_private_resource_permissions($pdo, $user, 'visum');
}

function ems_forensic_archive_permissions(PDO $pdo, array $user): array
{
    return ems_forensic_private_resource_permissions($pdo, $user, 'archive');
}

/**
 * Akses per-halaman untuk 4 halaman grup Forensic lainnya (List Medis, Data
 * Pasien Private, Hasil Visum, Arsip Forensic). List Medis tetap toggle
 * sederhana "bisa buka atau tidak" (roster read-only, tidak ada data
 * per-baris created_by yang relevan) — 3 lainnya sekarang derive dari
 * has_any_access model CRUD granular di atas, sama seperti Rekam Medis
 * Private. Dipakai langsung di masing-masing halaman DAN di
 * forensic_action.php (controller bersama untuk mutasi
 * private_patient/visum/archive).
 */
function ems_forensic_private_page_access(PDO $pdo, array $user): array
{
    $division = ems_normalize_division($user['division'] ?? '');

    if (ems_can_access_division_menu($division, 'Forensic') || ems_forensic_private_can_manage_access($user)) {
        return [
            'forensic_medics' => true,
            'rekam_medis_private' => true,
            'private_patients' => true,
            'visum_results' => true,
            'archive' => true,
            'is_native' => true,
        ];
    }

    $userId = (int) ($user['id'] ?? 0);
    $grant = ems_forensic_private_get_grant($pdo, $userId);
    if (!$grant) {
        return [
            'forensic_medics' => false,
            'rekam_medis_private' => false,
            'private_patients' => false,
            'visum_results' => false,
            'archive' => false,
            'is_native' => false,
        ];
    }

    return [
        'forensic_medics' => (bool) $grant['can_view_forensic_medics'],
        'rekam_medis_private' => ems_forensic_private_effective_permissions($pdo, $user)['has_any_access'],
        'private_patients' => ems_forensic_patients_permissions($pdo, $user)['has_any_access'],
        'visum_results' => ems_forensic_visum_permissions($pdo, $user)['has_any_access'],
        'archive' => ems_forensic_archive_permissions($pdo, $user)['has_any_access'],
        'is_native' => false,
    ];
}

/**
 * "History/log grup Forensic hanya bisa dilihat oleh tim Forensic" (aturan
 * eksplisit user) — dipakai untuk menyembunyikan section history/log di
 * forensic_private_patients.php / forensic_visum_results.php /
 * forensic_archive.php dari medis yang diberi grant, SEKALIPUN grant itu
 * full CRUD. Cuma native access (division Forensic, atau
 * ems_forensic_private_can_manage_access — Head Manager Forensic/Director/
 * Vice Director/Executive/Programmer Roxwood) yang boleh melihatnya.
 */
function ems_forensic_private_can_view_history(array $user): bool
{
    $division = ems_normalize_division($user['division'] ?? '');

    return ems_can_access_division_menu($division, 'Forensic') || ems_forensic_private_can_manage_access($user);
}

/**
 * Guard 1-baris dipakai di awal forensic_medics.php / forensic_private_patients.php
 * / forensic_visum_results.php / forensic_archive.php, menggantikan
 * ems_require_division_access(['Forensic'], ...) yang lama — kalau tidak
 * native Forensic DAN tidak ada toggle grant untuk halaman itu, redirect
 * dengan flash error, sama seperti perilaku ems_require_division_access().
 */
function ems_forensic_private_require_page_access(PDO $pdo, array $user, string $pageKey, string $redirectTo = '/dashboard/index.php'): void
{
    $access = ems_forensic_private_page_access($pdo, $user);
    if (!empty($access[$pageKey])) {
        return;
    }

    $_SESSION['flash_errors'][] = 'Akses division ditolak.';
    header('Location: ' . $redirectTo);
    exit;
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
 * 'deleted' karena baris resource aslinya akan hilang setelahnya, jadi log
 * ini jadi satu-satunya jejak yang tersisa. $resourceType membedakan
 * resource mana yang dicatat (default 'rekam_medis_private' — di sana
 * $recordId merujuk medical_records.id — untuk resource lain merujuk id di
 * tabel forensic_private_patients/forensic_visum_results/forensic_archives
 * masing-masing; kolomnya tetap bernama medical_record_id di skema karena
 * fitur ini awalnya cuma untuk Rekam Medis Private, tapi sekarang dipakai
 * generik sebagai "id record di resource_type ini").
 */
function ems_forensic_private_log_action(PDO $pdo, int $recordId, string $action, array $actor, string $notes = '', string $resourceType = 'rekam_medis_private'): void
{
    if ($recordId <= 0 || !in_array($action, ['created', 'viewed', 'edited', 'deleted'], true)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO forensic_private_record_logs (medical_record_id, resource_type, action, actor_user_id, actor_name_snapshot, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $recordId,
        $resourceType,
        $action,
        (int) ($actor['id'] ?? 0) ?: null,
        trim((string) ($actor['full_name'] ?? $actor['name'] ?? '')) ?: null,
        $notes !== '' ? $notes : null,
    ]);
}

function ems_forensic_private_get_logs(PDO $pdo, int $recordId, string $resourceType = 'rekam_medis_private'): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM forensic_private_record_logs
        WHERE medical_record_id = ? AND resource_type = ?
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([$recordId, $resourceType]);

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
