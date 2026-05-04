<?php

date_default_timezone_set('Asia/Jakarta');

$repo = '/home/fouf9972/public_html/roxwoodhospitalime';
$log = '/home/fouf9972/git-deploy.log';
$branch = 'main';
$git = '/usr/bin/git';
$cronCommand = "/bin/bash -lc 'cd /home/fouf9972/public_html/roxwoodhospitalime && /usr/bin/git fetch origin main && /usr/bin/git checkout -B main origin/main && /usr/bin/git reset --hard origin/main >> /home/fouf9972/git-deploy.log 2>&1'";

function respond(string $message, string $logFile, bool $writeLog = true, int $exitCode = 0): void
{
    $date = date('Y-m-d H:i:s');

    if ($writeLog) {
        file_put_contents($logFile, $date . ' - ' . str_replace(PHP_EOL, ' | ', $message) . PHP_EOL, FILE_APPEND);
    }

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo $message;
    exit($exitCode);
}

if (!is_dir($repo)) {
    respond('ERROR: folder repo tidak ditemukan di ' . $repo, $log, true, 1);
}

if (!is_dir($repo . '/.git')) {
    respond('ERROR: folder .git tidak ditemukan di ' . $repo, $log, true, 1);
}

if (!is_executable($git)) {
    respond('ERROR: git tidak ditemukan atau tidak executable di ' . $git, $log, true, 1);
}

if (!function_exists('shell_exec') || !function_exists('escapeshellarg')) {
    $message = implode(PHP_EOL, [
        'ERROR: fungsi shell PHP dinonaktifkan oleh server.',
        'Deploy git tidak bisa dijalankan lewat file PHP ini.',
        'Gunakan cron command shell berikut:',
        $cronCommand,
        'Repo: ' . $repo,
        'Branch: ' . $branch,
    ]);

    respond($message, $log, true, 1);
}

respond('OK: shell PHP tersedia. Namun pada server ini tetap disarankan memakai cron command shell langsung.', $log, true, 0);
