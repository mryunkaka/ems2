<?php

/**
 * =========================================================
 * PUSH SEND — FARMASI EMS
 * Dipanggil dari CRON (auto offline / notif sistem)
 * =========================================================
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/* =========================================================
   LOAD CONFIG PUSH
   ========================================================= */

$config = require __DIR__ . '/../config/push.php';

foreach (['subject', 'public_key', 'private_key'] as $key) {
    if (empty($config[$key])) {
        throw new RuntimeException("Push config '{$key}' belum diset");
    }
}

/* =========================================================
   AMBIL USER YANG PERLU DIKIRIM PUSH
   (DARI CRON SEBELUMNYA)
   ========================================================= */
// Ambil data user dari cron
$usersAutoOffline = $PUSH_USERS ?? [];

if (empty($usersAutoOffline) || !is_array($usersAutoOffline)) {
    return;
}


/* =========================================================
   VAPID AUTH
   ========================================================= */
$auth = [
    'VAPID' => [
        'subject'    => $config['subject'],
        'publicKey'  => $config['public_key'],
        'privateKey' => $config['private_key'],
    ],
];

$webPush = new WebPush($auth);
$webPush->setAutomaticPadding(true);

/* =========================================================
   PREPARE QUERY SUBSCRIPTION
   ========================================================= */
$stmtSubs = $pdo->prepare("
    SELECT endpoint, p256dh, auth
    FROM user_push_subscriptions
    WHERE user_id = ?
");

/* =========================================================
   LOOP USER & KIRIM PUSH
   ========================================================= */
foreach ($usersAutoOffline as $user) {

    $userId   = (int) $user['user_id'];
    $fullName = $user['full_name'] ?? 'Petugas Farmasi';

    $stmtSubs->execute([$userId]);
    $subs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

    if (!$subs) {
        continue; // user tidak punya device push
    }

    // Tentukan jenis push
    switch ($PUSH_TYPE ?? '') {

        case 'idle_warning':
            $title = '⏳ Masih Online?';
            $body  = 'Tidak ada aktivitas terdeteksi. Status Anda akan OFFLINE dalam ±2 menit.';
            $url   = '/dashboard/rekap_farmasi.php';
            break;

        case 'offline':
            $title = '🔴 Status Anda OFFLINE';
            $body  = 'Sistem otomatis mengubah status Anda menjadi OFFLINE karena tidak ada aktivitas.';
            $url   = '/dashboard/rekap_farmasi.php';
            break;

        case 'operasi_plastik_request':
            $title = '🩺 Permohonan Operasi Plastik';
            $body  = 'Ada permintaan operasi plastik yang menunggu persetujuan Anda.';
            $url   = '/dashboard/operasi_plastik.php';
            break;

        default:
            continue 2; // skip user ini
    }

    $payload = json_encode([
        'title' => $title,
        'body'  => $body,
        'icon'  => '/assets/img/ems-icon.png',
        'url'   => $url,
    ], JSON_UNESCAPED_UNICODE);

    foreach ($subs as $row) {
        try {
            $subscription = Subscription::create([
                'endpoint' => $row['endpoint'],
                'keys' => [
                    'p256dh' => $row['p256dh'],
                    'auth'   => $row['auth'],
                ],
            ]);

            $webPush->queueNotification($subscription, $payload);
        } catch (Throwable $e) {
            error_log('[PUSH PREP ERROR] ' . $e->getMessage());
        }
    }
}

/* =========================================================
   FLUSH PUSH QUEUE
   ========================================================= */
foreach ($webPush->flush() as $report) {

    if (!$report->isSuccess()) {
        error_log(
            '[PUSH FAILED] ' .
                $report->getReason() .
                ' | ' .
                $report->getRequest()->getUri()
        );
    }
}
