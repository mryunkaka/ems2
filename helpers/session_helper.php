<?php

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
            kode_nomor_induk_rs
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
        'position'            => $user['position'],
        'batch'               => $user['batch'],
        'tanggal_masuk'       => $user['tanggal_masuk'],
        'citizen_id'          => $user['citizen_id'],
        'no_hp_ic'            => $user['no_hp_ic'],
        'jenis_kelamin'       => $user['jenis_kelamin'],
        'kode_nomor_induk_rs' => $user['kode_nomor_induk_rs']
    ];
}
