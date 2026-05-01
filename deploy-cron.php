<?php

declare(strict_types=1);

$repo = '/home/hark8423/public_html/rh-ems2';
$log = '/home/hark8423/git-deploy.log';
$branch = 'main';
$remote = 'origin';

date_default_timezone_set('Asia/Jakarta');

if (!is_dir($repo)) {
    exit(1);
}

chdir($repo);

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

$currentHead = runCommand('git rev-parse HEAD', $exitCode);
if ($exitCode !== 0 || $currentHead === '') {
    exit(1);
}

runCommand(sprintf('git fetch %s %s', escapeshellarg($remote), escapeshellarg($branch)), $exitCode);
if ($exitCode !== 0) {
    exit(1);
}

$remoteHead = runCommand(sprintf('git rev-parse %s/%s', escapeshellarg($remote), escapeshellarg($branch)), $exitCode);
if ($exitCode !== 0 || $remoteHead === '') {
    exit(1);
}

if ($currentHead === $remoteHead) {
    exit(0);
}

runCommand(
    sprintf('git merge-base --is-ancestor %s %s/%s', escapeshellarg($currentHead), escapeshellarg($remote), escapeshellarg($branch)),
    $exitCode
);
if ($exitCode !== 0) {
    exit(1);
}

runCommand(sprintf('git merge --ff-only %s/%s', escapeshellarg($remote), escapeshellarg($branch)), $exitCode);
if ($exitCode !== 0) {
    exit(1);
}

$newHead = runCommand('git rev-parse HEAD', $exitCode);
if ($exitCode !== 0 || $newHead === '' || $newHead === $currentHead) {
    exit(0);
}

$commit = runCommand(sprintf('git log -1 --pretty=format:"%%h | %%an | %%s" %s', escapeshellarg($newHead)), $exitCode);
if ($exitCode !== 0 || $commit === '') {
    exit(0);
}

appendLog($log, 'Deploy ' . $commit);
