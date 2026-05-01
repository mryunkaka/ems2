<?php

declare(strict_types=1);

$repo = __DIR__;
$log = $repo . '/logs/git-deploy.log';
$statusLog = $repo . '/logs/deploy-cron-status.log';
$branch = 'main';
$remote = 'origin';

date_default_timezone_set('Asia/Jakarta');

function writeStatus(string $statusFile, string $message): void
{
    $date = date('Y-m-d H:i:s');
    @file_put_contents($statusFile, $date . ' - ' . $message . PHP_EOL);
}

function runCommand(string $command, ?int &$exitCode = null): string
{
    $output = [];
    exec($command . ' 2>&1', $output, $exitCode);
    return trim(implode("\n", $output));
}

function appendLog(string $logFile, string $message): void
{
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFile, $date . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

function finish(string $message, int $exitCode = 0, bool $logStatus = true): void
{
    global $statusLog;

    if ($logStatus) {
        writeStatus($statusLog, $message);
    }

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }

    exit($exitCode);
}

if (!is_dir($repo)) {
    finish('ERROR: repo path tidak ditemukan: ' . $repo, 1);
}

if (!is_dir($repo . '/.git')) {
    finish('ERROR: folder .git tidak ditemukan di: ' . $repo, 1);
}

chdir($repo);

$currentHead = runCommand('git rev-parse HEAD', $exitCode);
if ($exitCode !== 0 || $currentHead === '') {
    finish('ERROR: gagal membaca HEAD. output=' . $currentHead, 1);
}

runCommand(sprintf('git fetch %s %s', escapeshellarg($remote), escapeshellarg($branch)), $exitCode);
if ($exitCode !== 0) {
    finish('ERROR: git fetch gagal.', 1);
}

$remoteHead = runCommand(sprintf('git rev-parse %s/%s', escapeshellarg($remote), escapeshellarg($branch)), $exitCode);
if ($exitCode !== 0 || $remoteHead === '') {
    finish('ERROR: gagal membaca remote HEAD.', 1);
}

if ($currentHead === $remoteHead) {
    finish('OK: already up to date', 0);
}

runCommand(
    sprintf('git merge-base --is-ancestor %s %s/%s', escapeshellarg($currentHead), escapeshellarg($remote), escapeshellarg($branch)),
    $exitCode
);
if ($exitCode !== 0) {
    finish('ERROR: branch lokal divergen, fast-forward tidak bisa dilakukan.', 1);
}

runCommand(sprintf('git merge --ff-only %s/%s', escapeshellarg($remote), escapeshellarg($branch)), $exitCode);
if ($exitCode !== 0) {
    finish('ERROR: git merge --ff-only gagal.', 1);
}

$newHead = runCommand('git rev-parse HEAD', $exitCode);
if ($exitCode !== 0 || $newHead === '' || $newHead === $currentHead) {
    finish('OK: tidak ada perubahan setelah merge', 0);
}

$commit = runCommand(sprintf('git log -1 --pretty=format:"%%h | %%an | %%s" %s', escapeshellarg($newHead)), $exitCode);
if ($exitCode !== 0 || $commit === '') {
    finish('OK: deploy berhasil, tetapi detail commit tidak terbaca', 0);
}

appendLog($log, 'Deploy ' . $commit);
finish('OK: deployed ' . $commit, 0);
