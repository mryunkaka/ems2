<?php

/**
 * CRON JOB: Cleanup Identity Temp Files
 * ------------------------------------
 * - Jalan 1x sehari (00:00)
 * - Hanya hapus file temp (tmp_*.jpg)
 * - Hapus jika umur >= 24 jam
 * - Tidak menyentuh folder citizen_id
 */

date_default_timezone_set('Asia/Jakarta');

/* ===============================
   CONFIG
   =============================== */
$TEMP_DIR = realpath(__DIR__ . '/../storage/identity');
$MAX_AGE_SECONDS = 24 * 60 * 60; // 24 jam
// $MAX_AGE_SECONDS = 10 * 60; // 10 menit (MODE TEST)

if (!$TEMP_DIR || !is_dir($TEMP_DIR)) {
    error_log('[CRON][IDENTITY] Folder identity tidak ditemukan');
    exit;
}

$now = time();
$deleted = 0;
$skipped = 0;

/* ===============================
   SCAN FILE
   =============================== */
$files = scandir($TEMP_DIR);

foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }

    $filePath = $TEMP_DIR . DIRECTORY_SEPARATOR . $file;

    // ❌ Skip folder (misal citizen_id)
    if (is_dir($filePath)) {
        continue;
    }

    // ✅ HANYA file temp
    if (!preg_match('/^tmp(_manual)?_[a-z0-9]+\.jpg$/i', $file)) {
        continue;
    }

    $fileTime = filemtime($filePath);
    if ($fileTime === false) {
        continue;
    }

    $age = $now - $fileTime;

    // ⏳ Belum 24 jam → JANGAN HAPUS
    if ($age < $MAX_AGE_SECONDS) {
        $skipped++;
        continue;
    }

    // 🗑️ HAPUS
    if (@unlink($filePath)) {
        $deleted++;
    }
}

/* ===============================
   LOG
   =============================== */
$logMessage = sprintf(
    '[CRON][IDENTITY] %s | Deleted: %d | Skipped (<24h): %d',
    date('Y-m-d H:i:s'),
    $deleted,
    $skipped
);

error_log($logMessage);

// Optional: echo untuk testing manual
echo $logMessage . PHP_EOL;
