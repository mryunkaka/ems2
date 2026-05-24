<?php

require_once __DIR__ . '/env.php';

$apiKey = trim((string) ems_env('FIREBASE_API_KEY', ''));
$authDomain = trim((string) ems_env('FIREBASE_AUTH_DOMAIN', ''));
$databaseUrl = trim((string) ems_env('FIREBASE_DATABASE_URL', ''));
$projectId = trim((string) ems_env('FIREBASE_PROJECT_ID', ''));
$appId = trim((string) ems_env('FIREBASE_APP_ID', ''));
$messagingSenderId = trim((string) ems_env('FIREBASE_MESSAGING_SENDER_ID', ''));
$measurementId = trim((string) ems_env('FIREBASE_MEASUREMENT_ID', ''));

$presencePath = trim((string) ems_env('FIREBASE_PRESENCE_PATH', 'ems_presence/live_visitors'));
$chatRoomPath = trim((string) ems_env('FIREBASE_CHAT_ROOM_PATH', 'ems_live_chat/global_room/messages'));
$maxMessages = (int) ems_env('FIREBASE_CHAT_MAX_MESSAGES', 40);

return [
    'enabled' => $apiKey !== ''
        && $authDomain !== ''
        && $databaseUrl !== ''
        && $projectId !== ''
        && $appId !== ''
        && $messagingSenderId !== '',
    'firebase' => [
        'apiKey' => $apiKey,
        'authDomain' => $authDomain,
        'databaseURL' => $databaseUrl,
        'projectId' => $projectId,
        'appId' => $appId,
        'messagingSenderId' => $messagingSenderId,
        'measurementId' => $measurementId,
    ],
    'paths' => [
        'presence' => $presencePath !== '' ? $presencePath : 'ems_presence/live_visitors',
        'messages' => $chatRoomPath !== '' ? $chatRoomPath : 'ems_live_chat/global_room/messages',
    ],
    'ui' => [
        'maxMessages' => max(10, min(100, $maxMessages)),
    ],
];
