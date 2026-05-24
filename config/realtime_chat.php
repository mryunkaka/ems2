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
$musicQueuePath = trim((string) ems_env('FIREBASE_MUSIC_QUEUE_PATH', 'ems_live_music/global_room/queue'));
$musicStatePath = trim((string) ems_env('FIREBASE_MUSIC_STATE_PATH', 'ems_live_music/global_room/state'));
$maxQueueItems = (int) ems_env('FIREBASE_MUSIC_QUEUE_MAX_ITEMS', 25);

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
        'musicQueue' => $musicQueuePath !== '' ? $musicQueuePath : 'ems_live_music/global_room/queue',
        'musicState' => $musicStatePath !== '' ? $musicStatePath : 'ems_live_music/global_room/state',
    ],
    'ui' => [
        'maxMessages' => max(10, min(100, $maxMessages)),
        'maxQueueItems' => max(5, min(100, $maxQueueItems)),
    ],
];
