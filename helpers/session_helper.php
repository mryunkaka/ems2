<?php
require_once __DIR__ . '/../config/helpers.php';

/**
 * =========================================================
 * SESSION HELPER — FORCE RELOAD USER
 * =========================================================
 */

function forceReloadUserSession(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $specialistDegreesColumn = ems_column_exists($pdo, 'user_rh', 'specialist_degrees') ? ', specialist_degrees' : '';
    $stmt = $pdo->prepare("
        SELECT
            id,
            full_name,
            role,
            position,
            batch,
            tanggal_masuk,
            citizen_id,
            no_hp_ic,
            jenis_kelamin,
            kode_nomor_induk_rs{$specialistDegreesColumn}
        FROM user_rh
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return;
    }

    // 🔐 Session utama (dipakai di seluruh sistem)
    $_SESSION['user_rh'] = [
        'id'                  => $user['id'],
        'full_name'           => $user['full_name'],
        'name'                => $user['full_name'], // 🔥 TAMBAHKAN INI untuk backward compatibility
        'role'                => $user['role'],
        'position'            => ems_normalize_position($user['position'] ?? ''),
        'batch'               => $user['batch'],
        'tanggal_masuk'       => $user['tanggal_masuk'],
        'citizen_id'          => $user['citizen_id'],
        'no_hp_ic'            => $user['no_hp_ic'],
        'jenis_kelamin'       => $user['jenis_kelamin'],
        'kode_nomor_induk_rs' => $user['kode_nomor_induk_rs'],
        'specialist_degrees' => $user['specialist_degrees'] ?? null,
    ];
}
