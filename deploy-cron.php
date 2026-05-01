<?php

date_default_timezone_set('Asia/Jakarta');

$repo = '/home/hark8423/public_html/rh-ems2';
$log = '/home/hark8423/git-deploy.log';
$git = is_executable('/usr/bin/git') ? '/usr/bin/git' : 'git';

function respond(string $message, string $logFile, bool $writeLog = true, int $exitCode = 0): void
{
    $date = date('Y-m-d H:i:s');

    if ($writeLog) {
        file_put_contents($logFile, $date . ' - ' . $message . PHP_EOL, FILE_APPEND);
    }

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }

    exit($exitCode);
}

if (!is_dir($repo . '/.git')) {
    respond('ERROR: folder .git tidak ditemukan di ' . $repo, $log, true, 1);
}

chdir($repo);

$old = trim((string) shell_exec($git . ' rev-parse HEAD 2>&1'));
if ($old === '' || preg_match('/fatal:|not found|unable to/i', $old)) {
    respond('ERROR: gagal membaca HEAD. output=' . $old, $log, true, 1);
}

$pullOutput = trim((string) shell_exec($git . ' pull origin main 2>&1'));
$new = trim((string) shell_exec($git . ' rev-parse HEAD 2>&1'));

if (
    $new === '' ||
    preg_match('/fatal:|not found|unable to/i', $new)
) {
    respond('ERROR: git pull / rev-parse gagal. pull=' . $pullOutput . ' | head=' . $new, $log, true, 1);
}

if ($old === $new) {
    respond('OK: already up to date', $log, false, 0);
}

$commits = trim((string) shell_exec(
    $git . ' log ' . escapeshellarg($old . '..' . $new) . " --pretty=format:'%h | %an | %s' 2>&1"
));

if ($commits === '' || preg_match('/fatal:|not found|unable to/i', $commits)) {
    $singleCommit = trim((string) shell_exec(
        $git . " log -1 --pretty=format:'%h | %an | %s' " . escapeshellarg($new) . ' 2>&1'
    ));

    if ($singleCommit === '' || preg_match('/fatal:|not found|unable to/i', $singleCommit)) {
        respond('OK: deploy berhasil, tapi detail commit tidak terbaca', $log, false, 0);
    }

    $commits = $singleCommit;
}

$date = date('Y-m-d H:i:s');

foreach (preg_split('/\r\n|\r|\n/', $commits) as $commit) {
    $commit = trim((string) $commit);
    if ($commit === '') {
        continue;
    }

    file_put_contents($log, $date . ' - Deploy ' . $commit . PHP_EOL, FILE_APPEND);
}

respond('OK: deploy selesai', $log, false, 0);
