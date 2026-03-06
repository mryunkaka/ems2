<?php
/**
 * TEST CRON PUSH — DEBUG VERSION (FIXED)
 */

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

/* ======================================================
   LOG SETUP
   ====================================================== */
$logFile = __DIR__ . '/../storage/cron_push.log';

function cron_log($msg)
{
    global $logFile;
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

cron_log('=== CRON TEST START ===');

/* ======================================================
   USE NAMESPACE (HARUS DI SINI, BUKAN DI TRY)
   ====================================================== */
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

try {

    cron_log('Load autoload');
    require __DIR__ . '/../vendor/autoload.php';

    cron_log('Load database');
    require __DIR__ . '/../config/database.php';

    cron_log('Load push config');
    $config = require __DIR__ . '/../config/push.php';

    cron_log('Init WebPush');
    $webPush = new WebPush([
        'VAPID' => [
            'subject'    => $config['subject'],
            'publicKey'  => $config['public_key'],
            'privateKey' => $config['private_key'],
        ],
    ]);

    cron_log('Query subscriptions');
    $subs = $pdo->query("
        SELECT endpoint, p256dh, auth
        FROM user_push_subscriptions
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    cron_log('Subscription count: ' . count($subs));

    if (!$subs) {
        cron_log('NO SUBSCRIPTION FOUND');
        exit('NO SUBSCRIPTION');
    }

    $payload = json_encode([
        'title' => '🧪 CRON adam TEST',
        'body'  => 'Jika ini muncul, cron + push AMAN.',
        'url'   => '/dashboard/rekap_farmasi.php',
    ], JSON_UNESCAPED_UNICODE);

    foreach ($subs as $row) {

        cron_log('Queue push');

        $subscription = Subscription::create([
            'endpoint' => $row['endpoint'],
            'keys' => [
                'p256dh' => $row['p256dh'],
                'auth'   => $row['auth'],
            ],
        ]);

        $webPush->queueNotification($subscription, $payload);
    }

    cron_log('Flush push');

    foreach ($webPush->flush() as $report) {
        cron_log(
            $report->isSuccess()
                ? 'PUSH OK'
                : 'PUSH FAIL: ' . $report->getReason()
        );
    }

    cron_log('=== CRON TEST END SUCCESS ===');
    echo 'OK';

} catch (Throwable $e) {

    cron_log('FATAL ERROR: ' . $e->getMessage());
    cron_log($e->getTraceAsString());

    http_response_code(500);
    echo 'ERROR';
}
